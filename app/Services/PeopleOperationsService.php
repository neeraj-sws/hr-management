<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\AttendanceEmployee;
use App\Models\CredentialVaultEntry;
use App\Models\EmployeeFinalSettlement;
use App\Models\EmployeeLifecycleCase;
use App\Models\EmployeePenalty;
use App\Models\EmployeePolicyOverride;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Models\PolicyRule;
use App\Models\SalaryAdjustment;
use App\Models\SalaryAdvance;
use App\Models\SalaryAdvanceRepayment;
use App\Models\Team;
use App\Models\User;
use App\Notifications\ApprovalRequested;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class PeopleOperationsService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function requestOvertime(AttendanceEmployee $employee, string $date, int $minutes, string $reason): OvertimeRequest
    {
        return DB::transaction(function () use ($employee, $date, $minutes, $reason) {
            $request = OvertimeRequest::create(['employee_id'=>$employee->id,'work_date'=>$date,'requested_minutes'=>$minutes,'reason'=>$reason,'status'=>'pending']);
            $this->approval(OvertimeRequest::class, $request->id, $employee->manager?->user_id);
            $this->audit->record('overtime.requested', $request, ['minutes'=>$minutes]);
            return $request;
        });
    }

    public function decideOvertime(OvertimeRequest $request, int $approver, bool $approve, ?int $minutes = null, ?string $note = null): OvertimeRequest
    {
        return DB::transaction(function () use ($request, $approver, $approve, $minutes, $note) {
            $request = OvertimeRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($request->status !== 'pending') throw ValidationException::withMessages(['overtime'=>'This overtime request has already been decided.'])->status(409);
            if ($approve) app(PayrollLockGuard::class)->changed($request->employee_id, $request->work_date->toDateString(), 'overtime');
            $approved = $approve ? ($minutes ?? $request->requested_minutes) : null;
            if ($approved !== null && ($approved < 1 || $approved > $request->requested_minutes)) throw ValidationException::withMessages(['minutes'=>'Approved minutes must be within the requested amount.']);
            if ($approved !== null) {
                $policy = $this->policy($request->employee, 'overtime.multiplier', [], $request->work_date->toDateString());
                $sameDay = (int) OvertimeRequest::where('employee_id',$request->employee_id)->where('status','approved')->whereDate('work_date',$request->work_date)->sum('approved_minutes');
                if (isset($policy['max_minutes_per_day']) && $sameDay + $approved > (int) $policy['max_minutes_per_day']) throw ValidationException::withMessages(['minutes'=>'This would exceed the daily overtime limit of '.$policy['max_minutes_per_day'].' minutes.']);
                $month = (int) OvertimeRequest::where('employee_id',$request->employee_id)->where('status','approved')->whereYear('work_date',$request->work_date->year)->whereMonth('work_date',$request->work_date->month)->sum('approved_minutes');
                if (isset($policy['max_minutes_per_month']) && $month + $approved > (int) $policy['max_minutes_per_month']) throw ValidationException::withMessages(['minutes'=>'This would exceed the monthly overtime limit of '.round($policy['max_minutes_per_month']/60,1).' hours ('.round($month/60,1).' h already approved).']);
            }
            $request->update(['status'=>$approve?'approved':'rejected','approved_minutes'=>$approved,'decided_by'=>$approver,'decided_at'=>now(),'decision_note'=>$note]);
            $this->decideApproval(OvertimeRequest::class, $request->id, $approver, $approve, $note);
            $this->audit->record($approve?'overtime.approved':'overtime.rejected', $request, ['approved_minutes'=>$approved]);
            $request->employee?->user?->notify(new ApprovalRequested('OvertimeRequest', $request->id, $approve ? 'Your overtime request was approved.' : 'Your overtime request was rejected.'));
            return $request->fresh();
        });
    }

    /** What the attendance shows for an overtime day, for the approver. */
    public function overtimeEvidence(OvertimeRequest $request): array
    {
        $session = \App\Models\AttendanceSession::where('employee_id',$request->employee_id)->whereDate('work_date',$request->work_date)->first();
        $standard = (int) round(($request->employee ? app(AttendanceService::class)->dailyHours($request->employee, $request->work_date->toDateString()) : 8) * 3600);
        $worked = $session ? app(AttendanceService::class)->workedSeconds($session) : 0;

        return ['session'=>$session, 'worked_seconds'=>$worked, 'standard_seconds'=>$standard, 'extra_minutes'=>max(0, intdiv($worked-$standard, 60)), 'holiday'=>! $this->isConfiguredWorkday($request->work_date->toDateString(), $this->policy($request->employee,'calendar.working_days',['days'=>[1,2,3,4,5],'holidays'=>[]],$request->work_date->toDateString()))];
    }

    public function requestAdjustment(AttendanceEmployee $employee, float $amount, string $kind, string $effectiveOn, string $reason, int $userId, bool $recurring = false, ?string $recurringUntil = null): SalaryAdjustment
    {
        if (! isset(SalaryAdjustment::KINDS[$kind]) || $amount <= 0) throw ValidationException::withMessages(['amount'=>'Enter a positive amount and valid adjustment type.']);
        if ($kind === 'increment' && $recurring) throw ValidationException::withMessages(['recurring'=>'An increment changes the monthly salary itself; it cannot also be recurring.']);
        if ($kind === 'increment' && ! $employee->salaryProfiles()->exists()) throw ValidationException::withMessages(['kind'=>'Set a salary for this employee before adding an increment.']);
        if (! $recurring) $recurringUntil = null;
        if ($recurringUntil !== null && $recurringUntil < $effectiveOn) throw ValidationException::withMessages(['recurringUntil'=>'The last month cannot be before the effective date.']);
        $adjustment = SalaryAdjustment::create(['employee_id'=>$employee->id,'amount'=>$amount,'kind'=>$kind,'is_recurring'=>$recurring,'recurring_until'=>$recurringUntil,'effective_on'=>$effectiveOn,'reason'=>$reason,'requested_by'=>$userId,'status'=>'pending']);
        $this->approval(SalaryAdjustment::class, $adjustment->id);
        $this->audit->record('salary.adjustment_requested', $adjustment);
        return $adjustment;
    }

    /**
     * An approved increment becomes a new salary revision from its effective date:
     * the salary in force then plus the increment (earning components scale with it).
     * Earlier revisions are untouched, so salary history is kept.
     */
    private function applyIncrement(SalaryAdjustment $increment, int $approver): void
    {
        $employee = $increment->employee;
        $date = $increment->effective_on->toDateString();
        $base = $employee->salaryProfiles()->whereDate('effective_from', '<=', $date)->orderByDesc('effective_from')->orderByDesc('id')->first()
            ?? $employee->salaryProfiles()->orderBy('effective_from')->first();
        if (! $base) throw ValidationException::withMessages(['approval'=>'This employee has no salary to increment.']);
        $old = (float) $base->monthly_salary;
        $new = round($old + (float) $increment->amount, 2);
        $components = $base->components;
        if (is_array($components) && $components && $old > 0) {
            // Keep the structure: earnings grow in proportion, fixed deductions stay as they are.
            $earningRows = array_keys(array_filter($components, fn ($c) => ($c['type'] ?? 'earning') === 'earning'));
            foreach ($earningRows as $i) $components[$i]['amount'] = round((float) $components[$i]['amount'] * $new / $old, 2);
            $drift = round($new - array_sum(array_map(fn ($i) => (float) $components[$i]['amount'], $earningRows)), 2);
            if ($earningRows && $drift != 0.0) $components[$earningRows[0]]['amount'] = round((float) $components[$earningRows[0]]['amount'] + $drift, 2);
        }
        $values = ['monthly_salary' => $new, 'working_days_per_month' => $base->working_days_per_month, 'standard_daily_hours' => $base->standard_daily_hours, 'components' => $components ?: null, 'configured_by' => $approver];
        $profile = $employee->salaryProfiles()->whereDate('effective_from', $date)->first();
        if ($profile) $profile->update($values); else $profile = $employee->salaryProfiles()->create(['effective_from' => $date, ...$values]);
        $latest = $employee->salaryProfiles()->orderByDesc('effective_from')->orderByDesc('id')->first();
        $employee->update(['monthly_salary' => $latest->monthly_salary]);
        $this->audit->record('salary.increment_applied', $profile, ['adjustment_id' => $increment->id, 'from' => $old, 'to' => $new, 'effective_from' => $date]);
    }

    public function requestAdvance(AttendanceEmployee $employee, float $amount, float $installment, string $reason, int $userId): SalaryAdvance
    {
        if ($amount <= 0 || $installment <= 0 || $installment > $amount) throw ValidationException::withMessages(['amount'=>'Advance and installment amounts must be positive; installment cannot exceed advance.']);
        $advance = SalaryAdvance::create(['employee_id'=>$employee->id,'amount'=>$amount,'installment_amount'=>$installment,'requested_on'=>today(),'reason'=>$reason,'requested_by'=>$userId,'status'=>'pending']);
        $this->approval(SalaryAdvance::class, $advance->id);
        $this->audit->record('salary.advance_requested', $advance);
        return $advance;
    }

    /** $amount lets an approver reduce a penalty (never increase it). */
    public function decideFinancialRequest(string $modelClass, int $id, int $approver, bool $approve, ?string $note = null, ?float $amount = null): object
    {
        if (! in_array($modelClass, [SalaryAdjustment::class, SalaryAdvance::class, EmployeePenalty::class], true)) throw ValidationException::withMessages(['approval'=>'Unsupported financial approval type.']);
        return DB::transaction(function () use ($modelClass, $id, $approver, $approve, $note, $amount) {
            $record = $modelClass::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($record->status !== 'pending') throw ValidationException::withMessages(['approval'=>'This request has already been decided.'])->status(409);
            if ($approve && $record->effective_on) app(PayrollLockGuard::class)->changed($record->employee_id, $record->effective_on->toDateString(), $modelClass === EmployeePenalty::class ? 'penalty' : 'salary adjustment', $record instanceof SalaryAdjustment && ($record->is_recurring || $record->isIncrement()) ? ($record->recurring_until?->copy()->endOfMonth()->toDateString() ?? '2999-12-31') : null);
            if ($approve && $record instanceof SalaryAdjustment && $record->isIncrement()) $this->applyIncrement($record, $approver);
            $changes = ['status'=>$approve?'approved':'rejected','decided_by'=>$approver,'decided_at'=>now()];
            if ($approve && $amount !== null && $modelClass === EmployeePenalty::class) {
                if ($amount <= 0 || $amount > (float) $record->amount) throw ValidationException::withMessages(['amount'=>'A penalty can only be reduced, to an amount above zero.']);
                $changes['amount'] = round($amount, 2);
            }
            $record->update($changes);
            $this->decideApproval($modelClass, $id, $approver, $approve, $note);
            $this->audit->record($approve?'financial_request.approved':'financial_request.rejected', $record, ['type'=>$modelClass]);
            return $record->fresh();
        });
    }

    /**
     * Waives a pending or approved penalty. If it was already in a draft
     * payroll run it is taken out and the run must be recalculated; a penalty
     * in an approved or locked run cannot be waived.
     */
    public function waivePenalty(EmployeePenalty $penalty, int $actor, string $note): EmployeePenalty
    {
        return DB::transaction(function () use ($penalty, $actor, $note) {
            $penalty = EmployeePenalty::whereKey($penalty->id)->lockForUpdate()->firstOrFail();
            if (! in_array($penalty->status, ['pending', 'approved'], true)) throw ValidationException::withMessages(['penalty'=>'Only pending or approved penalties can be waived.'])->status(409);
            if (trim($note) === '') throw ValidationException::withMessages(['note'=>'Say why the penalty is waived.']);
            app(PayrollLockGuard::class)->changed($penalty->employee_id, $penalty->effective_on->toDateString(), 'penalty');
            $penalty->update(['status'=>'waived','payroll_item_id'=>null,'decided_by'=>$actor,'decided_at'=>now()]);
            ApprovalRequest::where('approvable_type',EmployeePenalty::class)->where('approvable_id',$penalty->id)->where('status','pending')->update(['approver_id'=>$actor,'status'=>'rejected','decided_at'=>now(),'comment'=>'Waived: '.$note]);
            $this->audit->record('penalty.waived', $penalty, ['amount'=>(float)$penalty->amount,'note'=>$note]);
            $penalty->employee?->user?->notify(new ApprovalRequested('EmployeePenalty',$penalty->id,'A penalty of '.number_format((float)$penalty->amount,2).' was waived.'));
            return $penalty->fresh();
        });
    }

    public function disburseAdvance(SalaryAdvance $advance, float $amount, int $userId): SalaryAdvance
    {
        return DB::transaction(function () use ($advance, $amount, $userId) {
            $advance = SalaryAdvance::whereKey($advance->id)->lockForUpdate()->firstOrFail();
            if ($advance->status !== 'approved' || $amount <= 0 || $amount + (float)$advance->disbursed_amount > (float)$advance->amount) throw ValidationException::withMessages(['advance'=>'Advance must be approved and disbursement cannot exceed the approved amount.']);
            $advance->update(['disbursed_amount'=>(float)$advance->disbursed_amount+$amount,'disbursed_on'=>today(),'status'=>((float)$advance->disbursed_amount+$amount >= (float)$advance->amount)?'disbursed':'approved']);
            $this->audit->record('salary.advance_disbursed', $advance, ['amount'=>$amount,'recorded_by'=>$userId]);
            return $advance->fresh();
        });
    }

    public function accrueLeave(int $year, int $month, int $actor): int
    {
        return app(LeaveAccrualService::class)->accrueThrough($year, $month, $actor);
    }

    public function setPolicy(string $key, string $category, string $name, array $config, ?int $employeeId, int $actor, ?string $effectiveFrom = null, ?string $effectiveUntil = null): void
    {
        $effectiveFrom ??= today()->toDateString();
        if (strtotime($effectiveFrom) === false || ($effectiveUntil !== null && (strtotime($effectiveUntil) === false || $effectiveUntil < $effectiveFrom))) {
            throw ValidationException::withMessages(['policyConfig' => 'Policy dates must be valid and the end date cannot precede the start date.']);
        }
        if ($key === 'payroll.statutory') {
            $components = $config['components'] ?? null;
            if (!is_array($components)) throw ValidationException::withMessages(['policyConfig' => 'Statutory payroll policy requires a components array.']);
            $seen = [];
            foreach ($components as $component) {
                if (!is_array($component) || !preg_match('/^[a-z0-9_.-]{1,80}$/', (string) ($component['key'] ?? '')) || !isset($component['name']) || !in_array($component['type'] ?? null, ['earning', 'deduction'], true) || !in_array($component['basis'] ?? 'gross', ['gross', 'basic_salary'], true)) throw ValidationException::withMessages(['policyConfig' => 'Each statutory component needs a unique key, name, earning/deduction type and supported calculation basis.']);
                if (isset($seen[$component['key']])) throw ValidationException::withMessages(['policyConfig' => 'Statutory component keys must be unique.']);
                $seen[$component['key']] = true;
                $fixed = $component['fixed_amount'] ?? null;
                $rate = $component['rate_bps'] ?? null;
                if (($fixed === null) === ($rate === null) || ($fixed !== null && (!is_numeric($fixed) || (float) $fixed < 0)) || ($rate !== null && (!is_numeric($rate) || (float) $rate < 0)) || (isset($component['cap_amount']) && (!is_numeric($component['cap_amount']) || (float) $component['cap_amount'] < 0))) throw ValidationException::withMessages(['policyConfig' => 'Each statutory component must use exactly one non-negative fixed_amount or rate_bps, with an optional non-negative cap_amount.']);
            }
        }
        if ($key === 'calendar.working_days') {
            $days = $config['days'] ?? null;
            if (!is_array($days) || !$days || collect($days)->contains(fn ($day) => filter_var($day, FILTER_VALIDATE_INT) === false || (int) $day < 1 || (int) $day > 7)) throw ValidationException::withMessages(['policyConfig' => 'Calendar policy days must be a non-empty list of ISO weekday numbers 1 through 7.']);
            if (isset($config['holidays']) && (!is_array($config['holidays']) || collect($config['holidays'])->contains(fn ($date) => !is_string($date) || strtotime($date) === false))) throw ValidationException::withMessages(['policyConfig' => 'Calendar holidays must be a list of valid dates.']);
            $config['days'] = array_values(array_unique(array_map('intval', $days)));
        }
        if ($key === 'work.hours') {
            if (isset($config['start_time']) && ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', (string) $config['start_time'])) throw ValidationException::withMessages(['policyConfig' => 'Work start time must use 24-hour HH:MM format.']);
            if (isset($config['end_time']) && ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', (string) $config['end_time'])) throw ValidationException::withMessages(['policyConfig' => 'Work end time must use 24-hour HH:MM format.']);
            if (isset($config['standard_daily_hours']) && (! is_numeric($config['standard_daily_hours']) || (float) $config['standard_daily_hours'] <= 0 || (float) $config['standard_daily_hours'] > 24)) throw ValidationException::withMessages(['policyConfig' => 'Standard daily hours must be greater than zero and at most 24.']);
        }
        if ($key === 'payroll.calculation') {
            foreach (['working_days_per_month' => [1, 31], 'standard_daily_hours' => [0.01, 24]] as $field => [$min, $max]) {
                if (isset($config[$field]) && (! is_numeric($config[$field]) || (float) $config[$field] < $min || (float) $config[$field] > $max)) throw ValidationException::withMessages(['policyConfig' => "{$field} must be between {$min} and {$max}."]);
            }
            if (isset($config['short_hours_grace']) && (! is_numeric($config['short_hours_grace']) || (float) $config['short_hours_grace'] < 0 || (float) $config['short_hours_grace'] > 200)) throw ValidationException::withMessages(['policyConfig' => 'Short-hours grace must be between 0 and 200 hours.']);
            foreach (['attendance_proration', 'include_paid_leave', 'short_hours_deduction'] as $field) if (isset($config[$field]) && ! is_bool($config[$field])) throw ValidationException::withMessages(['policyConfig' => "{$field} must be true or false."]);
        }
        if ($key === 'overtime.multiplier') {
            if (isset($config['multiplier']) && (! is_numeric($config['multiplier']) || (float) $config['multiplier'] < 0 || (float) $config['multiplier'] > 10)) throw ValidationException::withMessages(['policyConfig' => 'Overtime multiplier must be between 0 and 10.']);
            if (isset($config['max_minutes_per_day']) && (! is_numeric($config['max_minutes_per_day']) || (int) $config['max_minutes_per_day'] < 1 || (int) $config['max_minutes_per_day'] > 1440)) throw ValidationException::withMessages(['policyConfig' => 'Maximum overtime minutes per day must be between 1 and 1440.']);
        }
        if ($employeeId) {
            EmployeePolicyOverride::updateOrCreate(['employee_id'=>$employeeId,'rule_key'=>$key,'effective_from'=>$effectiveFrom], ['configuration'=>$config,'configured_by'=>$actor,'effective_until'=>$effectiveUntil]);
        } else {
            PolicyRule::updateOrCreate(['rule_key'=>$key,'effective_from'=>$effectiveFrom], ['category'=>$category,'name'=>$name,'configuration'=>$config,'is_active'=>true,'effective_until'=>$effectiveUntil,'updated_by'=>$actor]);
        }
        $this->audit->record('policy.updated', null, ['rule_key'=>$key,'employee_id'=>$employeeId,'effective_from'=>$effectiveFrom,'effective_until'=>$effectiveUntil]);
    }

    public function setTeamPolicy(string $key, array $config, int $teamId, int $actor, ?string $effectiveFrom = null): void
    {
        $record = \App\Models\TeamPolicyOverride::updateOrCreate(['team_id' => $teamId, 'rule_key' => $key], ['configuration' => $config, 'effective_from' => $effectiveFrom, 'configured_by' => $actor]);
        $this->audit->record('policy.team_override_saved', $record, ['rule_key' => $key, 'team_id' => $teamId, 'configuration' => $config]);
    }

    public function policy(AttendanceEmployee $employee, string $key, array $fallback=[], ?string $asOf=null): array
    {
        return app(PolicyConfigurationService::class)->forEmployee($employee, $key, $asOf ?? today()->toDateString(), $fallback);
    }

    public function requestPenalty(AttendanceEmployee $employee, float $amount, string $reason, int $actor, ?int $ruleId=null): EmployeePenalty
    {
        if ($amount <= 0) throw ValidationException::withMessages(['amount'=>'Penalty must be greater than zero.']);
        $penalty = EmployeePenalty::create(['employee_id'=>$employee->id,'policy_rule_id'=>$ruleId,'effective_on'=>today(),'amount'=>$amount,'reason'=>$reason,'requested_by'=>$actor,'status'=>'pending']);
        $this->approval(EmployeePenalty::class,$penalty->id);
        $this->audit->record('penalty.requested',$penalty);
        return $penalty;
    }

    public function assessCompliance(string $start,string $end,int $actor):int
    {
        if($end<$start)throw ValidationException::withMessages(['period'=>'The end date must be on or after the start date.']);
        $rule=PolicyRule::where('rule_key','attendance.late_penalty')->where('is_active',true)->first();
        $created=0;
        foreach(\App\Models\AttendanceSession::with('employee')->whereDate('work_date','>=',$start)->whereDate('work_date','<=',$end)->whereNotNull('check_in')->get() as $session){
            $employee=$session->employee;
            if(!$employee)continue;
            $workStart=app(AttendanceService::class)->rules($employee,$session->work_date->toDateString())['work_start'];
            if(!$workStart)continue;
            $config=$this->policy($employee,'attendance.late_penalty',$rule?->configuration??[],$session->work_date->toDateString());
            $perMinute=(float)($config['amount_per_minute']??0);if($perMinute<=0)continue;
            $expected=Carbon::parse($session->work_date->toDateString().' '.$workStart);
            $late=max(0,(int)ceil($expected->diffInMinutes($session->check_in)- (float)($config['grace_minutes']??0)));
            $amount=$this->capped($late*$perMinute,$config);
            if($late<=0||$amount<=0)continue;
            $source="late:{$employee->id}:{$session->work_date->toDateString()}".($rule?":{$rule->id}":'');
            $penalty=EmployeePenalty::firstOrCreate(['source_key'=>$source],['employee_id'=>$employee->id,'policy_rule_id'=>$rule?->id,'effective_on'=>$session->work_date,'amount'=>$amount,'reason'=>"Late by {$late} minutes",'requested_by'=>$actor,'status'=>'pending']);
            if($penalty->wasRecentlyCreated){$this->approval(EmployeePenalty::class,$penalty->id);$this->audit->record('compliance.late_penalty_assessed',$penalty,['late_minutes'=>$late]);$created++;}
        }
        $taskRule=PolicyRule::where('rule_key','task.late_penalty')->where('is_active',true)->first();
        foreach(\App\Models\Task::with('assignee')->whereNotNull('due_on')->whereDate('due_on','>=',$start)->whereDate('due_on','<=',$end)->where('status','!=','completed')->get() as $task){
            $employee=$task->assignee;if(!$employee)continue;$config=$this->policy($employee,'task.late_penalty',$taskRule?->configuration??[],$end);$perDay=max(0,(float)($config['amount_per_day']??0));if($perDay<=0)continue;
            if(Carbon::parse($task->due_on)->gte(Carbon::parse($end)))continue;$days=$this->configuredWorkdays(Carbon::parse($task->due_on)->addDay(),Carbon::parse($end),$employee,$end);if($days===0)continue;$amount=$this->capped($perDay*$days,$config);
            $penalty=EmployeePenalty::firstOrCreate(['source_key'=>"late_task:{$task->id}".($taskRule?":{$taskRule->id}":'')],['employee_id'=>$employee->id,'policy_rule_id'=>$taskRule?->id,'effective_on'=>$task->due_on,'amount'=>$amount,'reason'=>"Task #{$task->id} was overdue by {$days} working days",'requested_by'=>$actor,'status'=>'pending']);
            if($penalty->wasRecentlyCreated){$this->approval(EmployeePenalty::class,$penalty->id);$this->audit->record('compliance.late_task_assessed',$penalty,['task_id'=>$task->id,'late_workdays'=>$days]);$created++;}
        }
        $absenceRule=PolicyRule::where('rule_key','attendance.unauthorized_absence')->where('is_active',true)->first();
        foreach(AttendanceEmployee::where('is_active',true)->get() as $employee){
                $config=$this->policy($employee,'attendance.unauthorized_absence',$absenceRule?->configuration??[],$end);$amount=max(0,(float)($config['amount_per_day']??0));if($amount<=0)continue;
                for($day=Carbon::parse($start)->startOfDay();$day->lte(Carbon::parse($end)->startOfDay());$day->addDay()){
                    $date=$day->toDateString();$calendar=$this->policy($employee,'calendar.working_days',['days'=>[1,2,3,4,5],'holidays'=>[]],$date);if(!$this->isConfiguredWorkday($date,$calendar)||$day->gte(today())||($employee->joined_at&&$day->lt(Carbon::parse($employee->joined_at))))continue;
                    if(\App\Models\AttendanceSession::where('employee_id',$employee->id)->whereDate('work_date',$date)->whereNotNull('check_in')->exists())continue;
                    if(LeaveRequest::where('employee_id',$employee->id)->where('status','approved')->whereDate('starts_on','<=',$date)->whereDate('ends_on','>=',$date)->exists())continue;
                    $penalty=EmployeePenalty::firstOrCreate(['source_key'=>"absence:{$employee->id}:{$date}".($absenceRule?":{$absenceRule->id}":'')],['employee_id'=>$employee->id,'policy_rule_id'=>$absenceRule?->id,'effective_on'=>$date,'amount'=>$amount,'reason'=>'No attendance or approved leave recorded for scheduled workday','requested_by'=>$actor,'status'=>'pending']);
                    if($penalty->wasRecentlyCreated){$this->approval(EmployeePenalty::class,$penalty->id);$this->audit->record('compliance.unauthorized_absence_assessed',$penalty);$created++;}
                }
        }
        $created += $this->assessWorkCompliance($start, $end, $actor);

        return $created;
    }

    /**
     * Early check-out, days worked with no task entered, tasks entered late and
     * missing daily status. Every finding is a *pending* penalty: nothing is
     * deducted until someone approves it (and it can be reduced or waived).
     */
    private function assessWorkCompliance(string $start, string $end, int $actor): int
    {
        $created = 0;
        $attendance = app(AttendanceService::class);
        $raise = function (AttendanceEmployee $employee, string $key, string $source, string $date, float $amount, string $reason, array $meta) use ($actor, &$created) {
            if ($amount <= 0) return;
            $rule = PolicyRule::where('rule_key', $key)->where('is_active', true)->first();
            $penalty = EmployeePenalty::firstOrCreate(['source_key' => $source.($rule ? ":{$rule->id}" : '')], ['employee_id' => $employee->id, 'policy_rule_id' => $rule?->id, 'effective_on' => $date, 'amount' => round($amount, 2), 'reason' => $reason, 'requested_by' => $actor, 'status' => 'pending']);
            if ($penalty->wasRecentlyCreated) {
                $this->approval(EmployeePenalty::class, $penalty->id);
                $this->audit->record('compliance.'.str_replace('.', '_', $key).'_assessed', $penalty, $meta);
                $employee->user?->notify(new ApprovalRequested('EmployeePenalty', $penalty->id, $reason.' — a penalty is pending review.'));
                $created++;
            }
        };
        $now = now();

        $sessions = \App\Models\AttendanceSession::with('employee')->whereDate('work_date', '>=', $start)->whereDate('work_date', '<=', $end)->whereNotNull('check_in')->get();
        foreach ($sessions as $session) {
            $employee = $session->employee;
            if (! $employee) continue;
            $date = $session->work_date->toDateString();
            $dayEnd = Carbon::parse($date)->endOfDay();

            // Early check-out (an auto-closed session is a missed check-out, not an early one).
            if ($session->check_out && ! $session->closed_automatically) {
                $config = $this->policy($employee, 'attendance.early_checkout', [], $date);
                $early = max(0, $attendance->earlyMinutes($session) - (int) ($config['grace_minutes'] ?? 0));
                if ($early > 0) $raise($employee, 'attendance.early_checkout', "early:{$employee->id}:{$date}", $date, $this->capped($early * (float) ($config['amount_per_minute'] ?? 0), $config), "Checked out {$early} minutes early", ['early_minutes' => $early]);
            }

            // Worked, but no task entered for the day.
            $config = $this->policy($employee, 'task.missing_entry', [], $date);
            if ((float) ($config['amount_per_day'] ?? 0) > 0 && $dayEnd->lt($now)) {
                $hasWork = \App\Models\Task::where('assignee_id', $employee->id)->where(fn ($q) => $q->whereDate('work_date', $date)->orWhereHas('timeEntries', fn ($e) => $e->where('employee_id', $employee->id)->whereDate('started_at', $date)))->exists();
                if (! $hasWork) $raise($employee, 'task.missing_entry', "no_task:{$employee->id}:{$date}", $date, (float) $config['amount_per_day'], 'No task entered for '.Carbon::parse($date)->format('d M'), []);
            }

            // Daily status missing after the grace period.
            $config = $this->policy($employee, 'daily_status.missing', [], $date);
            if ((float) ($config['amount_per_day'] ?? 0) > 0 && $dayEnd->copy()->addHours((int) ($config['grace_hours'] ?? 0))->lt($now)) {
                $status = \App\Models\DailyWorkStatus::where('employee_id', $employee->id)->whereDate('work_date', $date)->first();
                $late = $status && $status->submitted_at && $status->submitted_at->gt($dayEnd->copy()->addHours((int) ($config['grace_hours'] ?? 0)));
                if (! $status || $late) $raise($employee, 'daily_status.missing', "no_status:{$employee->id}:{$date}", $date, (float) $config['amount_per_day'], ($status ? 'Daily status submitted late for ' : 'No daily status for ').Carbon::parse($date)->format('d M'), []);
            }
        }

        // Tasks entered after the day they were for (plus grace hours).
        $tasks = \App\Models\Task::with('assignee')->whereNotNull('work_date')->whereDate('work_date', '>=', $start)->whereDate('work_date', '<=', $end)->get();
        foreach ($tasks as $task) {
            $employee = $task->assignee;
            if (! $employee || $task->created_by !== $employee->user_id) continue; // only self-entered tasks
            $config = $this->policy($employee, 'task.late_entry', [], $task->work_date->toDateString());
            $deadline = $task->work_date->copy()->endOfDay()->addHours((int) ($config['grace_hours'] ?? 0));
            if ((float) ($config['amount'] ?? 0) > 0 && $task->created_at->gt($deadline)) {
                $raise($employee, 'task.late_entry', "late_entry:{$task->id}", $task->work_date->toDateString(), (float) $config['amount'], "Task \"{$task->title}\" for {$task->work_date->format('d M')} was entered on {$task->created_at->format('d M, h:i A')}", ['task_id' => $task->id]);
            }
        }

        return $created;
    }

    /** $exit (offboarding): ['type' => resignation|termination|end_of_contract|retirement, 'resigned_on' => ?date] */
    public function openLifecycle(AttendanceEmployee $employee, string $kind, ?string $effectiveOn, ?string $notes, int $actor, ?array $exit = null): EmployeeLifecycleCase
    {
        if (!in_array($kind,['onboarding','offboarding'],true)) throw ValidationException::withMessages(['kind'=>'Choose onboarding or offboarding.']);
        if ($kind === 'offboarding' && $exit && ! in_array($exit['type'] ?? '', ['resignation','termination','end_of_contract','retirement'], true)) throw ValidationException::withMessages(['exitType'=>'Choose the exit type.']);
        return DB::transaction(function () use ($employee,$kind,$effectiveOn,$notes,$actor,$exit) {
            if ($kind === 'offboarding' && $exit) {
                $employee->update(['exit_type'=>$exit['type'],'exit_date'=>$effectiveOn,'exit_reason'=>$notes,'resigned_on'=>$exit['type']==='resignation'?($exit['resigned_on']??today()->toDateString()):null]);
                $this->audit->record('employee.exit_recorded',$employee,['exit_type'=>$exit['type'],'exit_date'=>$effectiveOn]);
            }
            $case = EmployeeLifecycleCase::create(['employee_id'=>$employee->id,'kind'=>$kind,'effective_on'=>$effectiveOn,'notes'=>$notes,'opened_by'=>$actor,'status'=>'open']);
            $checklist=$kind==='offboarding'?['Return assigned assets','Revoke system credentials','Manager clearance','Accounts final settlement']:['Employment documents','System access','Equipment issue','Department orientation'];
            foreach($checklist as $item)$case->clearances()->create(['item'=>$item,'status'=>'pending']);
            $this->audit->record('employee.lifecycle_opened',$case);
            return $case;
        });
    }

    /** Hours after an offboarding case is opened (or closed) during which it may still be corrected or cancelled. */
    public function offboardingWindowHours(): int
    {
        return max(0, (int) (app(PolicyConfigurationService::class)->forEmployee(null, 'lifecycle.offboarding', today()->toDateString(), ['correction_window_hours' => 72])['correction_window_hours'] ?? 72));
    }

    /** Until when this case can be corrected by lifecycle managers (null = never, administrators only). */
    public function correctionDeadline(EmployeeLifecycleCase $case): ?Carbon
    {
        $hours = $this->offboardingWindowHours();
        if ($hours <= 0) return null;
        $from = $case->status === 'closed' && $case->closed_at ? $case->closed_at : $case->created_at;

        return $from ? Carbon::parse($from)->addHours($hours) : null;
    }

    /**
     * Who may correct or cancel an offboarding case opened by mistake: people
     * who manage lifecycle cases while the window is open; afterwards only an
     * administrator. Cancelled cases and paid settlements cannot be undone here.
     */
    public function canCorrectOffboarding(\App\Models\User $user, EmployeeLifecycleCase $case): bool
    {
        if ($case->kind !== 'offboarding' || ! in_array($case->status, ['open', 'closed'], true)) return false;
        if (\App\Support\Access::isAdmin($user)) return true;
        $deadline = $this->correctionDeadline($case);

        return $user->can('lifecycle.manage') && $deadline !== null && now()->lte($deadline);
    }

    /** Fixes the details of an offboarding case (last day, exit type, resignation date, notes). */
    public function correctOffboarding(EmployeeLifecycleCase $case, array $data, string $reason, \App\Models\User $actor): EmployeeLifecycleCase
    {
        abort_unless($this->canCorrectOffboarding($actor, $case), 403, 'The correction window for this offboarding case has passed. Ask an administrator.');
        if (mb_strlen(trim($reason)) < 5) throw ValidationException::withMessages(['correctionReason' => 'Say why the case is being corrected (at least 5 characters).']);
        if (! in_array($data['exit_type'] ?? '', ['resignation','termination','end_of_contract','retirement'], true)) throw ValidationException::withMessages(['exitType' => 'Choose the exit type.']);

        return DB::transaction(function () use ($case, $data, $reason, $actor) {
            $case = EmployeeLifecycleCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            $employee = $case->employee;
            $before = ['effective_on' => $case->effective_on?->toDateString(), 'exit_type' => $employee->exit_type, 'resigned_on' => $employee->resigned_on?->toDateString(), 'notes' => $case->notes];
            $case->update(['effective_on' => $data['effective_on'], 'notes' => $data['notes'] ?? $case->notes]);
            $employee->update(['exit_type' => $data['exit_type'], 'exit_date' => $data['effective_on'], 'exit_reason' => $data['notes'] ?? $employee->exit_reason, 'resigned_on' => $data['exit_type'] === 'resignation' ? ($data['resigned_on'] ?? $employee->resigned_on) : null]);
            $after = ['effective_on' => $case->effective_on?->toDateString(), 'exit_type' => $employee->exit_type, 'resigned_on' => $employee->resigned_on?->toDateString(), 'notes' => $case->notes];
            $this->audit->record('employee.offboarding_corrected', $case, ['employee_id' => $employee->id, 'employee' => $employee->name, 'by' => $actor->name, 'reason' => $reason, 'before' => $before, 'after' => $after]);
            Notifier::send(Notifier::withPermission('lifecycle.manage'), 'EmployeeLifecycleCase', $case->id, "{$actor->name} corrected the offboarding case of {$employee->name}: {$reason}", route('lifecycle.offboarding', ['case' => $case->id], false), $actor->id);

            return $case->fresh();
        });
    }

    /**
     * Cancels an offboarding case opened by mistake. An open case is simply
     * withdrawn; a closed one is reversed (the employee and their login are
     * active again). Exit details are cleared and an unpaid final settlement is
     * cancelled. Not possible once any settlement payment was made.
     */
    public function cancelOffboarding(EmployeeLifecycleCase $case, string $reason, \App\Models\User $actor): EmployeeLifecycleCase
    {
        abort_unless($this->canCorrectOffboarding($actor, $case), 403, 'The correction window for this offboarding case has passed. Ask an administrator.');
        if (mb_strlen(trim($reason)) < 5) throw ValidationException::withMessages(['cancelReason' => 'Say why the case is being cancelled (at least 5 characters).']);

        return DB::transaction(function () use ($case, $reason, $actor) {
            $case = EmployeeLifecycleCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            $settlement = $case->settlement()->with('payments')->first();
            if ($settlement && $settlement->payments->isNotEmpty()) throw ValidationException::withMessages(['cancelReason' => 'A final settlement payment has already been made for this case, so it cannot be cancelled here. Reverse the payment first.']);
            $wasClosed = $case->status === 'closed';
            $employee = $case->employee;
            $before = ['status' => $case->status, 'effective_on' => $case->effective_on?->toDateString(), 'exit_type' => $employee->exit_type, 'employee_active' => (bool) $employee->is_active];

            if ($settlement) {
                $settlement->update(['status' => 'cancelled']);
                ApprovalRequest::where('approvable_type', \App\Models\EmployeeFinalSettlement::class)->where('approvable_id', $settlement->id)->where('status', 'pending')->update(['status' => 'cancelled', 'decided_at' => now()]);
            }
            $case->update(['status' => 'cancelled', 'cancelled_by' => $actor->id, 'cancelled_at' => now(), 'cancel_reason' => mb_substr(trim($reason), 0, 1000)]);
            $employee->update(['exit_type' => null, 'exit_date' => null, 'exit_reason' => null, 'resigned_on' => null, 'is_active' => true]);
            if ($wasClosed) $employee->user?->update(['is_active' => true]);

            $this->audit->record('employee.offboarding_cancelled', $case, ['employee_id' => $employee->id, 'employee' => $employee->name, 'by' => $actor->name, 'reason' => $reason, 'was_closed' => $wasClosed, 'reactivated' => $wasClosed, 'settlement_cancelled' => (bool) $settlement, 'before' => $before]);
            Notifier::send(Notifier::withPermission('lifecycle.manage')->push($employee->manager?->user), 'EmployeeLifecycleCase', $case->id, "{$actor->name} cancelled the offboarding of {$employee->name}".($wasClosed ? ' and re-activated their account' : '').": {$reason}", route('lifecycle.offboarding', ['case' => $case->id, 'status' => 'all'], false), $actor->id);
            if ($wasClosed) Notifier::send($employee->user, 'EmployeeLifecycleCase', $case->id, 'Your account is active again; the offboarding was cancelled. Sign in again on your devices.', route('dashboard', [], false), $actor->id);

            return $case->fresh();
        });
    }

    public function closeLifecycle(EmployeeLifecycleCase $case, int $actor): EmployeeLifecycleCase
    {
        return DB::transaction(function () use ($case,$actor) {
            $case = EmployeeLifecycleCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            if ($case->clearances()->where('status','!=','cleared')->exists()) throw ValidationException::withMessages(['clearance'=>'All lifecycle checklist items must be complete before closing the case.']);
            if ($case->kind==='offboarding') {
                if (\App\Models\EmployeeAsset::where('employee_id',$case->employee_id)->whereIn('status',['assigned','lost'])->exists()) throw ValidationException::withMessages(['clearance'=>'All assigned assets must be returned, and lost assets written off or sent for recovery, before offboarding can close.']);
                $settlement=$case->settlement()->with('payments')->first();
                if (!$settlement || !in_array($settlement->status,['approved','partially_paid','paid'],true)) throw ValidationException::withMessages(['settlement'=>'The final settlement must be approved before offboarding can close.']);
                if ((float)$settlement->net_amount > (float)$settlement->payments->sum('amount')) throw ValidationException::withMessages(['settlement'=>'The approved final settlement must be fully paid before offboarding can close.']);
            }
            $case->update(['status'=>'closed','closed_by'=>$actor,'closed_at'=>now()]);
            if ($case->kind==='offboarding') {
                $employee=$case->employee;
                $deviceTokens=$employee->devices()->whereNotNull('personal_access_token_id')->pluck('personal_access_token_id');
                if($deviceTokens->isNotEmpty())\Laravel\Sanctum\PersonalAccessToken::whereIn('id',$deviceTokens)->delete();
                $employee->devices()->update(['is_active'=>false,'personal_access_token_id'=>null]);
                $employee->user?->tokens()->delete();
                $employee->user?->update(['is_active'=>false]);
                $employee->update(['is_active'=>false]);
            }
            $this->audit->record('employee.lifecycle_closed',$case);
            return $case->fresh();
        });
    }

    private function approval(string $type, int $id, ?int $approver=null): void
    {
        ApprovalRequest::create(['approvable_type'=>$type,'approvable_id'=>$id,'approver_id'=>$approver,'status'=>'pending']);
        $label=[OvertimeRequest::class=>'An overtime',SalaryAdjustment::class=>'A salary adjustment',SalaryAdvance::class=>'A salary advance',EmployeePenalty::class=>'A penalty'][$type]??'A';
        $notification=new ApprovalRequested(class_basename($type),$id,"{$label} request needs review.");
        if($approver){User::whereKey($approver)->first()?->notify($notification);return;}
        $permission=match($type){SalaryAdjustment::class=>'salary.adjust',SalaryAdvance::class=>'salary.advance',EmployeePenalty::class=>'penalties.manage',OvertimeRequest::class=>'overtime.manage',default=>null};
        if($permission)foreach(User::permission($permission)->get() as $user)$user->notify($notification);
    }

    private function decideApproval(string $type, int $id, int $approver, bool $approve, ?string $note): void
    {
        ApprovalRequest::where('approvable_type',$type)->where('approvable_id',$id)->where('status','pending')->update(['approver_id'=>$approver,'status'=>$approve?'approved':'rejected','decided_at'=>now(),'comment'=>$note]);
    }

    private function configuredWorkdays(Carbon $start,Carbon $end,AttendanceEmployee $employee,string $asOf): int
    {
        $days=0;for($day=$start->copy()->startOfDay();$day->lte($end);$day->addDay()){$date=$day->toDateString();$calendar=$this->policy($employee,'calendar.working_days',['days'=>[1,2,3,4,5],'holidays'=>[]],$asOf);if($this->isConfiguredWorkday($date,$calendar))$days++;}return $days;
    }

    /** A max_amount of zero or less means "no cap". */
    private function capped(float $amount, array $config): float
    {
        $cap = (float) ($config['max_amount'] ?? 0);

        return round($cap > 0 ? min($cap, $amount) : $amount, 2);
    }

    private function isConfiguredWorkday(string $date,array $calendar): bool
    {
        return \App\Support\WorkCalendar::isWorkday($date, $calendar);
    }
}
