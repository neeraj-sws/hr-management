<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Models\PolicyRule;
use App\Models\EmployeePolicyOverride;
use App\Models\EmployeePenalty;
use App\Models\SalaryAdjustment;
use App\Models\SalaryAdvance;
use App\Models\SalaryAdvanceRepayment;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryPayment;
use App\Models\SalaryProfile;
use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Support\LeaveYear;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

class HrWorkflowService
{
    /**
     * A finished day checked in after the cut-off: paid as net hours at work ÷ the working day (at most one day).
     * Null when the rule does not apply (in before the cut-off, still checked in, a shift that starts after the
     * cut-off, a half-day holiday). The calendar uses the same answer for its "paid 0.76 day" note.
     *
     * @return array{share: float, seconds: int, check_in: string}|null
     */
    public function lateCheckInPay(AttendanceEmployee $employee, AttendanceSession $session, ?string $after = null, ?array $calendar = null): ?array
    {
        if (! $session->check_in || ! $session->check_out) return null;
        $date = $session->work_date->toDateString();
        if ($after === null) {
            $policy = app(PolicyConfigurationService::class)->forEmployee($employee, 'payroll.calculation', $date, []);
            if (! ($policy['late_checkin_hours_pay'] ?? true) || $date < (string) ($policy['half_day_pay_from'] ?? self::HALF_DAY_PAY_FROM)) return null;
            $after = preg_match('/^\d{2}:\d{2}$/', (string) ($policy['late_checkin_after'] ?? '')) ? (string) $policy['late_checkin_after'] : '12:00';
        }
        if ($session->check_in->format('H:i') <= $after) return null;
        $calendar ??= $this->policyConfiguration($employee, 'calendar.working_days', $date, ['days' => [1, 2, 3, 4, 5], 'holidays' => []]);
        if (\App\Support\WorkCalendar::halfDayHoliday($calendar, $date) || ! $this->isConfiguredWorkday($date, $calendar)) return null;
        $attendance = app(AttendanceService::class);
        $ownStart = $attendance->rules($employee, $date)['work_start'] ?? null;
        if ($ownStart && substr((string) $ownStart, 0, 5) >= $after) return null; // afternoon / night shift
        $net = (int) $attendance->officeSeconds($session);
        $standard = max(1, (int) round($attendance->dailyHours($employee, $date) * 3600));

        return ['share' => round(min(1, $net / $standard), 4), 'seconds' => $net, 'standard' => $standard, 'check_in' => $session->check_in->format('H:i')];
    }

    /** Half day pays half a day for days from this date (Settings > Payroll can change it or switch it off). */
    public const HALF_DAY_PAY_FROM = '2026-10-01';

    /** Old salary sheet working days per month, looked up once per request. */
    private array $sheetDaysCache = [];

    public function __construct(private readonly AuditLogger $audit) {}

    public function createEmployee(array $data, ?int $userId = null): AttendanceEmployee
    {
        return DB::transaction(function () use ($data, $userId) {
            $payBasis = ($data['pay_basis'] ?? 'monthly') === 'daily' ? 'daily' : 'monthly';
            $dailyRate = $payBasis === 'daily' ? (float) ($data['daily_rate'] ?? 0) : null;
            unset($data['pay_basis'], $data['daily_rate']);
            // Daily wage: the monthly figure is an estimate (rate × the record's working days) used only in lists.
            if ($payBasis === 'daily') $data['monthly_salary'] = round($dailyRate * 22, 2);
            $employee = AttendanceEmployee::create($data);
            if (isset($data['monthly_salary'])) {
                // The salary record carries the hours entered for the employee, so Payroll shows the same working day.
                SalaryProfile::create(['employee_id' => $employee->id, 'monthly_salary' => $data['monthly_salary'], 'pay_basis' => $payBasis, 'daily_rate' => $dailyRate, 'effective_from' => $data['joined_at'] ?? today(), 'configured_by' => $userId, 'standard_daily_hours' => (float) ($data['daily_work_hours'] ?? 0) > 0 ? (float) $data['daily_work_hours'] : 8]);
            }
            foreach (LeaveType::where('is_active', true)->get() as $leaveType) {
                LeaveBalance::open($employee->id, $leaveType, LeaveYear::current());
            }
            $this->audit->record('employee.created', $employee, ['employee_code' => $employee->employee_code]);
            return $employee;
        });
    }

    public function startTask(AttendanceEmployee $employee, Task $task): TaskTimeEntry
    {
        if ($task->assignee_id !== $employee->id) throw ValidationException::withMessages(['task' => 'Task is not assigned to this employee.']);
        return DB::transaction(function () use ($employee, $task) {
            AttendanceEmployee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            app(AttendanceService::class)->stopStaleTimers($employee); // a timer forgotten yesterday must not block today
            $active = TaskTimeEntry::where('employee_id', $employee->id)->where('status', 'running')->lockForUpdate()->first();
            if ($active) throw ValidationException::withMessages(['timer' => 'Stop or pause the current timer before starting another.']);
            if ($task->status === 'completed') throw ValidationException::withMessages(['timer' => 'This task is already completed.']);
            if (AttendanceSession::where('employee_id', $employee->id)->whereDate('work_date', today())->where('status', 'on_break')->exists()) throw ValidationException::withMessages(['timer' => 'Resume work from your break before starting a task timer.']);
            $task->update(['status' => 'in_progress', 'started_at' => $task->started_at ?? now()]);
            $task->increment('server_version');
            $entry = $task->timeEntries()->create(['employee_id' => $employee->id, 'started_at' => now(), 'status' => 'running']);
            $this->audit->record('task.timer_started', $task, ['entry_id' => $entry->id]);
            return $entry;
        });
    }

    public function stopTaskTimer(AttendanceEmployee $employee, TaskTimeEntry $entry, bool $complete = false): TaskTimeEntry
    {
        if ($entry->employee_id !== $employee->id || $entry->status !== 'running') throw ValidationException::withMessages(['timer' => 'No active timer belongs to this employee.']);
        $stopped = DB::transaction(function () use ($entry, $complete) {
            $end = now();
            $entry->update(['ended_at' => $end, 'duration_seconds' => $entry->started_at->diffInSeconds($end), 'status' => 'stopped']);
            if ($complete) $entry->task->update(['status' => 'completed', 'completed_at' => $end]);
            $entry->task->increment('server_version');
            $this->audit->record($complete ? 'task.completed' : 'task.timer_stopped', $entry->task, ['entry_id' => $entry->id, 'duration_seconds' => $entry->duration_seconds]);
            return $entry->fresh();
        });
        if ($complete) app(WorkService::class)->taskCompleted($stopped->task, $employee->user);

        return $stopped;
    }

    /** Completes a task for its assignee, stopping a running timer if there is one. */
    public function completeTask(AttendanceEmployee $employee, Task $task): Task
    {
        if ($task->assignee_id !== $employee->id) throw ValidationException::withMessages(['task' => 'Task is not assigned to this employee.']);
        if ($task->status === 'completed') throw ValidationException::withMessages(['task' => 'This task is already completed.']);
        $running = TaskTimeEntry::where('task_id', $task->id)->where('employee_id', $employee->id)->where('status', 'running')->first();
        if ($running) return $this->stopTaskTimer($employee, $running, true)->task;

        $completed = DB::transaction(function () use ($task) {
            $task->update(['status' => 'completed', 'completed_at' => now()]);
            $task->increment('server_version');
            $this->audit->record('task.completed', $task);

            return $task->fresh();
        });
        app(WorkService::class)->taskCompleted($completed, $employee->user);

        return $completed;
    }

    public function requestLeave(AttendanceEmployee $employee, array $data, bool $notify = true): LeaveRequest
    {
        $duration = $data['duration'] ?? 'full';
        if (! isset(LeaveRequest::DURATION_DAYS[$duration])) throw ValidationException::withMessages(['duration' => 'Choose a valid leave duration.']);
        if ($data['ends_on']->lt($data['starts_on'])) throw ValidationException::withMessages(['ends_on' => 'The end date must be on or after the start date.']);
        if (LeaveYear::of($data['starts_on']) !== LeaveYear::of($data['ends_on'])) throw ValidationException::withMessages(['starts_on' => 'A leave request must stay within one leave year ('.LeaveYear::label(LeaveYear::of($data['starts_on'])).' ends on '.LeaveYear::end(LeaveYear::of($data['starts_on']))->format('d M Y').'). Split requests that cross leave years.']);
        // Half-day and short leave apply to a single working day; a full-day leave can span a range.
        if ($duration !== 'full') {
            if (! $data['starts_on']->isSameDay($data['ends_on'])) throw ValidationException::withMessages(['ends_on' => 'A half-day or short leave is for a single day.']);
            $calendar = $employee ? $this->policyConfiguration($employee, 'calendar.working_days', $data['starts_on']->toDateString(), ['days' => [1, 2, 3, 4, 5], 'holidays' => []]) : ['days' => [1, 2, 3, 4, 5], 'holidays' => []];
            if (! $this->isConfiguredWorkday($data['starts_on']->toDateString(), $calendar)) {
                throw ValidationException::withMessages(['starts_on' => $data['starts_on']->format('D d M Y').' is not a working day for you ('.\App\Support\WorkCalendar::offDayReason($data['starts_on']->toDateString(), $calendar).'), so no leave is needed.']);
            }
            $days = LeaveRequest::DURATION_DAYS[$duration];
        } else {
            $days = $this->businessDaysBetween($data['starts_on'], $data['ends_on'], $employee);
            if ($days < 1) {
                $calendar = $employee ? $this->policyConfiguration($employee, 'calendar.working_days', $data['starts_on']->toDateString(), ['days' => [1, 2, 3, 4, 5], 'holidays' => []]) : ['days' => [1, 2, 3, 4, 5], 'holidays' => []];
                throw ValidationException::withMessages(['starts_on' => $data['starts_on']->isSameDay($data['ends_on'])
                    ? $data['starts_on']->format('D d M Y').' is not a working day for you ('.\App\Support\WorkCalendar::offDayReason($data['starts_on']->toDateString(), $calendar).'), so no leave is needed.'
                    : $data['starts_on']->format('d M').' – '.$data['ends_on']->format('d M Y').' has no working days for you (only weekly offs or holidays), so no leave is needed.']);
            }
        }
        $year = LeaveYear::of($data['starts_on']);
        return DB::transaction(function () use ($employee, $data, $days, $year, $notify) {
            AttendanceEmployee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $overlap = LeaveRequest::where('employee_id', $employee->id)
                ->whereIn('status', ['pending', 'approved'])
                ->whereDate('starts_on', '<=', $data['ends_on'])
                ->whereDate('ends_on', '>=', $data['starts_on'])
                ->exists();
            if ($overlap) throw ValidationException::withMessages(['leave' => 'This request overlaps another pending or approved leave request.']);
            $balance = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $data['leave_type_id'])->where('year', $year)->lockForUpdate()->first();
            $leaveType = LeaveType::whereKey($data['leave_type_id'])->firstOrFail();
            if (! $balance) {
                $balance = LeaveBalance::open($employee->id, $leaveType, $year);
            }
            $snapshot = app(LeaveAccrualService::class)->balance($employee,$leaveType,$year,$data['starts_on']->toDateString());
            $available = (float)$snapshot['remaining'];
            $deficit = $leaveType->paid ? $days-$available : 0;
            if ($deficit > 0 && ! $leaveType->allow_advance) throw ValidationException::withMessages(['leave' => 'Insufficient leave balance.']);
            if ($deficit > 0) {
                $limit=$this->policyConfiguration($employee,'leave.advance_limit',$data['starts_on']->toDateString(),['max_days'=>5]);
                if ($deficit > (float)($limit['max_days']??5)) throw ValidationException::withMessages(['leave'=>'Requested leave exceeds the advance leave limit.']);
            }
            $leave = LeaveRequest::create(['employee_id' => $employee->id, ...$data, 'days' => $days, 'status' => 'pending']);
            $approverId = ApprovalInbox::approverFor($employee); // admins/HR when the manager is unavailable
            ApprovalRequest::create(['approvable_type' => LeaveRequest::class, 'approvable_id' => $leave->id, 'approver_id' => $approverId, 'status' => 'pending']);
            // The manager decides; HR (leave.manage) is told as well, so a request is never stuck with one person.
            // A missing permission or channel failure must not sink the request.
            $type = LeaveType::find($data['leave_type_id']);
            $range = $data['starts_on']->isSameDay($data['ends_on']) ? $data['starts_on']->format('d M Y') : $data['starts_on']->format('d M').' – '.$data['ends_on']->format('d M Y');
            if ($notify) Notifier::send(Notifier::withPermission('leave.manage')->push($approverId ? \App\Models\User::find($approverId) : null), 'LeaveRequest', $leave->id, "A leave request from {$employee->name} needs review: {$type?->name}, {$range} ({$days} day/s).", route('leave.requests', ['tab' => 'approvals'], false), $employee->user_id);
            $this->audit->record('leave.requested', $leave, ['days' => $days]);
            if ($notify) app(\App\Services\WhatsAppNotifier::class)->leaveApplied($leave->load(['employee.manager', 'leaveType']));
            // Asked for too late (Compliance > Leave applied late): a pending fine. Not for leave HR records or imports.
            if ($notify) app(PeopleOperationsService::class)->lateLeaveFine($leave);
            return $leave;
        });
    }

    /** $notify = false for bulk history (attendance import): no message per imported day. */
    public function decideLeave(LeaveRequest $leave, int $approverId, bool $approve, ?string $note = null, bool $notify = true): LeaveRequest
    {
        $decided = DB::transaction(function () use ($leave, $approverId, $approve, $note, $notify) {
            $leave = LeaveRequest::whereKey($leave->id)->lockForUpdate()->firstOrFail();
            if ($leave->status !== 'pending') throw ValidationException::withMessages(['leave' => 'This request has already been decided.'])->status(409);
            // Leave asked for a day already gone: approving it needs a reason, kept with the decision.
            if ($approve && $leave->isBackdated() && trim((string) $note) === '') {
                throw ValidationException::withMessages(['decisionNote' => 'This leave is for a past date (asked on '.$leave->created_at->format('d M').' for '.$leave->starts_on->format('d M').'). Write why it is approved.']);
            }
            $carry = $approve && app(PayrollLockGuard::class)->changedOrCarry($leave->employee_id, $leave->starts_on->toDateString(), 'leave', $leave->ends_on->toDateString());
            $leave->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $approverId, 'decided_at' => now(), 'decision_note' => $note]);
            ApprovalRequest::where('approvable_type', LeaveRequest::class)->where('approvable_id', $leave->id)->where('status', 'pending')->update(['approver_id' => $approverId, 'status' => $approve ? 'approved' : 'rejected', 'decided_at' => now(), 'comment' => $note]);
            if ($approve) {
                $type=LeaveType::findOrFail($leave->leave_type_id);
                if($type->paid){
                    $leaveYear = LeaveYear::of($leave->starts_on);
                    $balance = LeaveBalance::where('employee_id', $leave->employee_id)->where('leave_type_id', $leave->leave_type_id)->where('year', $leaveYear)->lockForUpdate()->first();
                    if(!$balance)throw ValidationException::withMessages(['leave'=>'Leave balance is not configured for this employee.']);
                    $snapshot=app(LeaveAccrualService::class)->balance($leave->employee,$type,$leaveYear,$leave->starts_on->toDateString());
                    $available=(float)$snapshot['remaining']+(float)$leave->days;
                    $deficit=(float)$leave->days-$available;
                    if($deficit>0&&!$type->allow_advance)throw ValidationException::withMessages(['leave'=>'Approving this request would exceed the available paid leave balance.']);
                    if($deficit>0){$limit=$this->policyConfiguration($leave->employee,'leave.advance_limit',$leave->starts_on->toDateString(),['max_days'=>5]);if($deficit>(float)($limit['max_days']??5))throw ValidationException::withMessages(['leave'=>'Approving this request would exceed the advance leave limit.']);}
                    $balance->increment('used_days', $leave->days);
                    \App\Models\LeaveLedgerEntry::record($balance, 'used', -(float) $leave->days, $type->name.' '.$leave->starts_on->format('d M Y').($leave->ends_on->isSameDay($leave->starts_on) ? '' : ' – '.$leave->ends_on->format('d M Y')), $leave, $approverId);
                }
            }
            $this->audit->record($approve ? 'leave.approved' : 'leave.rejected', $leave, ['approver_id' => $approverId]);
            $by = \App\Models\User::find($approverId)?->name ?? 'Your approver';
            $range = $leave->starts_on->format('d M').($leave->ends_on->isSameDay($leave->starts_on) ? '' : ' – '.$leave->ends_on->format('d M Y'));
            if ($carry) app(PayrollCarryOver::class)->settle($leave->employee_id, $leave->starts_on->toDateString(), $leave->ends_on->toDateString(), ($leave->leaveType?->name ?? 'Leave')." {$range}", $approverId);
            if ($notify) Notifier::send($leave->employee?->user, 'LeaveRequest', $leave->id, $approve ? "{$by} approved your leave ({$range})." : "{$by} rejected your leave ({$range})".($note ? ": {$note}" : '.'));
            return $leave->fresh();
        });
        // Past days of the leave: fines follow the decision (approved = no absence fine; rejected = absent, fined if
        // the rule says so). Today and later are checked by the nightly assessment as usual.
        if ($decided->employee) {
            $people = app(PeopleOperationsService::class);
            for ($day = $decided->starts_on->copy(); $day->lte($decided->ends_on) && $day->lt(today()); $day->addDay()) {
                $people->reassessDay($decided->employee, $day->toDateString(), $approverId, 'leave '.($approve ? 'approved' : 'rejected'));
            }
        }

        return $decided;
    }

    public function calculatePayroll(string $start, string $end, int $userId): PayrollRun
    {
        if ($end < $start) throw ValidationException::withMessages(['period' => 'The end date must be on or after the start date.']);
        return DB::transaction(function () use ($start, $end, $userId) {
            $run=PayrollRun::whereDate('period_starts_on',$start)->whereDate('period_ends_on',$end)->lockForUpdate()->first();
            if(!$run && ($clash = PayrollRun::whereDate('period_starts_on','<=',$end)->whereDate('period_ends_on','>=',$start)->orderBy('period_starts_on')->first())){
                throw ValidationException::withMessages(['period' => 'These dates overlap the payroll for '.$clash->period_starts_on->format('d M').' – '.$clash->period_ends_on->format('d M Y').' ('.$clash->status.'). Recalculate that one'.($clash->status === 'draft' ? ', or delete it first' : '').', so nobody is paid twice for the same days.']);
            }
            if(!$run){
                try{$run=PayrollRun::create(['period_starts_on'=>$start,'period_ends_on'=>$end,'created_by'=>$userId,'status'=>'draft']);}
                catch(QueryException $exception){$run=PayrollRun::whereDate('period_starts_on',$start)->whereDate('period_ends_on',$end)->first();if(!$run)throw $exception;}
            }
            $hasPendingApproval=ApprovalRequest::where('approvable_type',PayrollRun::class)->where('approvable_id',$run->id)->where('status','pending')->exists();
            if(!$hasPendingApproval){ApprovalRequest::create(['approvable_type'=>PayrollRun::class,'approvable_id'=>$run->id,'step'=>1,'status'=>'pending']);foreach(\App\Models\User::permission('payroll.approve')->get() as $approver)$approver->notify(new \App\Notifications\ApprovalRequested('PayrollRun',$run->id,'A payroll run needs approval.'));}
            if ($run->status !== 'draft') throw ValidationException::withMessages(['payroll' => 'Only draft payroll can be recalculated.']);
            foreach ($run->items as $oldItem) {
                foreach (SalaryAdvanceRepayment::where('payroll_item_id', $oldItem->id)->get() as $repayment) {
                    SalaryAdvance::whereKey($repayment->salary_advance_id)->decrement('recovered_amount', $repayment->amount);
                    SalaryAdvance::whereKey($repayment->salary_advance_id)->where('status', 'recovered')->update(['status' => 'disbursed']);
                    $repayment->delete();
                }
            }
            $run->items()->delete();
            $employees = AttendanceEmployee::where('is_active', true)->onPayroll()->get(); // trainees pay a fee, they are not paid
            // A month that was already paid from the old salary sheet (Payroll > Legacy Salary) takes the sheet's
            // figures: that is what was really calculated and paid. Advances, adjustments and penalties are not
            // attached to it — the sheet already settled that month.
            $sheetMonth = substr($start, 0, 7) === substr($end, 0, 7) ? substr($start, 0, 7) : null;
            $sheets = $sheetMonth ? \App\Models\LegacySalaryRecord::where('payroll_month', $sheetMonth)->whereNotNull('employee_id')->get()->keyBy('employee_id') : collect();
            foreach ($employees as $employee) {
                if ($sheet = $sheets->get($employee->id)) {
                    $this->storeOldSheetItem($run, $employee, $sheet);
                    continue;
                }
                // Everything is computed first (no writes), then stored as this run's item.
                $figures = $this->employeePayFigures($employee, $start, $end, true);
                if ($figures === null) continue;
                extract($figures);
                $item = PayrollItem::create([
                    'payroll_run_id'=>$run->id,'employee_id'=>$employee->id,'gross_amount'=>$gross,'adjustments'=>$adjustmentTotal,'net_amount'=>$net,
                    'working_days'=>$scheduledTotal,'present_days'=>$presentDays,'paid_leave_days'=>$paidLeaveDays,'unpaid_leave_days'=>$unpaidLeaveDays,
                    'overtime_hours'=>$overtimeHours,'overtime_amount'=>$overtimeAmount,'penalties_amount'=>$penalties,'recovery_amount'=>$advanceRecovery,'adjustments_amount'=>$adjustmentTotal,
                    'statutory_earnings'=>$statutory['earnings'],'statutory_deductions'=>$statutory['deductions'],'reimbursements_amount'=>$reimbursements,
                    'calculation_data'=>['monthly_salary'=>(float)$profile->monthly_salary,'pay_basis'=>$profile->pay_basis ?? 'monthly','daily_rate'=>$profile->isDaily() ? (float)$profile->daily_rate : null,'working_days_per_month'=>$workingDaysPerMonth,'divisor'=>$calendarDivisor ? 'calendar' : 'fixed','month_divisors'=>$monthDivisors,'standard_daily_hours'=>$standardDailyHours,'attendance_proration'=>$attendanceProration,'include_paid_leave'=>$includePaidLeave,'statutory_components'=>$statutory['components']],
                    'calculation_breakdown'=>['base_salary'=>round($basePay,2),'salary_components'=>array_map(fn ($v) => round($v, 2), $componentTotals),'fixed_deductions'=>$fixedDeductions,'fixed_deductions_total'=>$fixedDeductionTotal,'salary_segments'=>$salarySegments,'working_days'=>$scheduledTotal,'present_days'=>$presentDays,'paid_leave_days'=>$paidLeaveDays,'unpaid_leave_days'=>$unpaidLeaveDays,'paid_holiday_days'=>count($paidHolidaySet),'half_days'=>array_keys($halfDaySet),'hours_paid_days'=>$hoursPaidSet,'half_holiday_paid'=>array_keys($halfHolidayAbsent),'unpaid_holiday_days'=>count($unpaidHolidaySet),'approved_overtime_minutes'=>$approvedMinutes,'holiday_overtime_minutes'=>$holidayMinutes,'overtime_multiplier'=>$multiplier,'holiday_overtime_multiplier'=>$holidayMultiplier,'overtime_amount'=>$overtimeAmount,'extra_work'=>$extraWork,'salary_adjustments'=>$adjustmentTotal,'salary_adjustment_lines'=>$adjustmentLines->all(),'penalties'=>$penalties,'penalty_lines'=>$penaltyLines,'short_hours'=>$short,'advances_disbursed'=>(float)$advanceRows->sum('disbursed_amount'),'advance_recovery'=>$advanceRecovery,'statutory_earnings'=>$statutory['earnings'],'statutory_deductions'=>$statutory['deductions'],'statutory_components'=>$statutory['components'],'reimbursements'=>$reimbursements,'gross_salary'=>$gross,'payable_salary'=>$net,'employer_policy'=>['working_days_per_month'=>$workingDaysPerMonth,'standard_daily_hours'=>$standardDailyHours,'attendance_proration'=>$attendanceProration,'include_paid_leave'=>$includePaidLeave]],
                ]);
                foreach ($adjustments as $adjustment) $adjustment->update(['payroll_item_id'=>$item->id]);
                foreach ($claims as $claim) $claim->update(['payroll_item_id'=>$item->id]);
                EmployeePenalty::where('employee_id',$employee->id)->where('status','approved')->whereDate('effective_on','>=',$start)->whereDate('effective_on','<=',$end)->whereNull('payroll_item_id')->update(['payroll_item_id'=>$item->id]);
                foreach ($advanceRows as $advance) {
                    $recover = min((float)$advance->installment_amount,max(0,(float)$advance->disbursed_amount-(float)$advance->recovered_amount));
                    if ($recover <= 0) continue;
                    SalaryAdvanceRepayment::create(['salary_advance_id'=>$advance->id,'payroll_item_id'=>$item->id,'amount'=>$recover]);
                    $advance->increment('recovered_amount',$recover);
                    if ((float)$advance->recovered_amount >= (float)$advance->disbursed_amount && (float)$advance->disbursed_amount >= (float)$advance->amount) $advance->update(['status'=>'recovered']);
                }
                $item->update(['advances_amount' => (float) $advanceRows->sum('disbursed_amount')]);
            }
            $run->update(['needs_recalculation' => false]);
            $this->audit->record('payroll.calculated', $run, ['items' => $run->items()->count()]);
            return $run->load('items.employee');
        });
    }

    /** A payroll item for a month taken as-is from the old salary sheet (its basic, deductions, net and what was paid). */
    private function storeOldSheetItem(PayrollRun $run, AttendanceEmployee $employee, \App\Models\LegacySalaryRecord $sheet): PayrollItem
    {
        $n = fn ($v) => round((float) $v, 2);
        $gross = $n((float) $sheet->salary_before_adjustment + (float) $sheet->increment_bonus);
        $net = $n($sheet->total_salary_with_bonus ?? $sheet->salary_after_deduction);
        $lines = array_filter([
            'basic_salary' => $n($sheet->basic_salary), 'total_working_days' => (float) $sheet->total_working_days, 'salary_per_day' => $n($sheet->salary_per_day),
            'per_hour_salary' => round((float) $sheet->per_hour_salary, 4), 'present_days' => (float) $sheet->present_days, 'leave_days' => (float) $sheet->leave_days,
            'paid_leave' => (float) $sheet->paid_leave, 'total_salary_paid_days' => (float) $sheet->total_salary_paid_days, 'salary_before_adjustment' => $n($sheet->salary_before_adjustment),
            'late_and_hourly_leave_minutes' => (float) $sheet->late_and_hourly_leave_minutes, 'late_coming_deduction' => $n($sheet->late_coming_deduction), 'late_coming_adjusted' => $n($sheet->late_coming_adjusted),
            'without_application_deduction' => $n($sheet->without_application_deduction), 'short_leave_deduction' => $n($sheet->short_leave_deduction),
            'total_payment_deduction' => $n($sheet->total_payment_deduction), 'increment_bonus' => $n($sheet->increment_bonus), 'tds' => $n($sheet->tds),
            'salary_after_deduction' => $n($sheet->salary_after_deduction), 'total_salary_with_bonus' => $net,
        ], fn ($v) => $v != 0);

        return PayrollItem::create([
            'payroll_run_id' => $run->id, 'employee_id' => $employee->id, 'gross_amount' => $gross, 'adjustments' => 0, 'net_amount' => $net,
            'working_days' => (int) round((float) $sheet->total_working_days), 'present_days' => (float) $sheet->present_days, 'paid_leave_days' => (float) $sheet->paid_leave,
            'unpaid_leave_days' => max(0, (float) $sheet->leave_days - (float) $sheet->paid_leave), 'overtime_hours' => 0, 'overtime_amount' => 0, 'penalties_amount' => 0,
            'recovery_amount' => 0, 'adjustments_amount' => 0, 'statutory_earnings' => 0, 'statutory_deductions' => 0, 'reimbursements_amount' => 0,
            'calculation_data' => ['source' => 'old_sheet', 'monthly_salary' => $n($sheet->basic_salary), 'working_days_per_month' => (int) round((float) $sheet->total_working_days), 'legacy_salary_record_id' => $sheet->id],
            'calculation_breakdown' => ['working_days' => (int) round((float) $sheet->total_working_days), 'present_days' => (float) $sheet->present_days, 'gross_salary' => $gross, 'payable_salary' => $net,
                'old_sheet' => ['lines' => $lines, 'paid' => $n($sheet->salary_paid), 'remains' => $n(max(0, (float) $sheet->salary_remains)), 'payment_date' => $sheet->payment_date?->toDateString(), 'status' => $sheet->payment_status,
                    'file' => $sheet->source_file, 'sheet' => $sheet->source_sheet]],
        ]);
    }

    /**
     * One employee's pay for a period, computed from attendance, leave, overtime,
     * adjustments and policy — without writing anything. calculatePayroll() stores
     * it as a payroll item; PayrollCarryOver uses it to re-price a period whose
     * payroll was already approved/locked. Returns null when the employee has no
     * salary for the period. ($forUpdate locks advances and claims for the run.)
     */
    private function employeePayFigures(AttendanceEmployee $employee, string $start, string $end, bool $forUpdate): ?array
    {
        $profile = $employee->salaryProfiles()->whereDate('effective_from','<=',$end)->orderByDesc('effective_from')->orderByDesc('id')->first();
        if (! $profile) return null;
        $policyResolver = app(PolicyConfigurationService::class);
        $payrollPolicy = $policyResolver->forEmployee($employee, 'payroll.calculation', $end, []);
        $workingDaysPerMonth = (int) ($payrollPolicy['working_days_per_month'] ?? $profile->working_days_per_month);
        // Per-day salary: monthly salary ÷ a fixed number of days (default), or ÷ that month's own working days
        // from the calendar (Settings > Payroll) — e.g. 25 in September, 26 in July, as in a salary sheet.
        $calendarDivisor = ($payrollPolicy['divisor'] ?? 'fixed') === 'calendar';
        $monthDivisors = [];
        // An explicit payroll rule wins; otherwise the employee's own working hours (same value attendance uses).
        $standardDailyHours = (float) ($payrollPolicy['standard_daily_hours'] ?? app(AttendanceService::class)->dailyHours($employee, $end));
        $attendanceProration = (bool) ($payrollPolicy['attendance_proration'] ?? true);
        $includePaidLeave = (bool) ($payrollPolicy['include_paid_leave'] ?? true);
        $startDate = now()->parse($start)->startOfDay();
        $endDate = now()->parse($end)->startOfDay();
        if ($profile->effective_from?->gt($endDate)) return null;
        $calendar = $this->policyConfiguration($employee, 'calendar.working_days', $endDate->toDateString(), ['days' => [1,2,3,4,5], 'holidays' => []]);
        $presentDates = AttendanceSession::where('employee_id', $employee->id)->whereDate('work_date','>=',$start)->whereDate('work_date','<=',$end)->whereNotNull('check_in')->distinct()->pluck('work_date')->map(fn ($date) => now()->parse($date)->toDateString())->filter(fn ($date) => $this->isConfiguredWorkday($date,$calendar))->values()->all();
        $presentSet = array_fill_keys($presentDates, true);
        foreach ($presentSet as $date => $_) {
            if (now()->parse($date)->lt($startDate) || now()->parse($date)->gt($endDate)) unset($presentSet[$date]);
        }
        $paidLeaveSet = [];
        $unpaidLeaveSet = [];
        // Work on a weekly off / 3rd Saturday / holiday or on a full-day leave is "extra working": paid on top,
        // automatically (Settings > Payroll). Extra hours on a normal working day need an approved overtime request.
        $extraAuto = (bool) ($payrollPolicy['extra_work_auto'] ?? true);
        $extraDates = [];
        $leaveRequests = LeaveRequest::with('leaveType')->where('employee_id',$employee->id)->where('status','approved')->whereDate('starts_on','<=',$end)->whereDate('ends_on','>=',$start)->get();
        $conflictRule = (string) ($this->policyConfiguration($employee, 'attendance.rules', $endDate->toDateString(), [])['leave_conflict'] ?? 'attendance');
        $resolved = \App\Models\LeaveConflictResolution::where('employee_id',$employee->id)->whereDate('work_date','>=',$start)->whereDate('work_date','<=',$end)->get()->mapWithKeys(fn ($r) => [$r->work_date->toDateString() => $r->resolution])->all();
        // Approved half-day / short leave (Settings > Payroll, on by default, from the half-day pay date): only the
        // hours actually worked that day are paid, whatever the leave type; the leave itself is not paid.
        $partLeaveHoursOnly = (bool) ($payrollPolicy['half_leave_hours_only'] ?? true);
        $partLeaveFrom = (string) ($payrollPolicy['half_day_pay_from'] ?? self::HALF_DAY_PAY_FROM);
        foreach ($leaveRequests as $leave) {
            $from = max($startDate->toDateString(), $leave->starts_on->toDateString());
            $to = min($endDate->toDateString(), $leave->ends_on->toDateString());
            for ($day = now()->parse($from); $day->lte(now()->parse($to)); $day->addDay()) {
                if (! $this->isConfiguredWorkday($day->toDateString(),$calendar)) continue;
                $date = $day->toDateString();
                if ($partLeaveHoursOnly && $leave->duration !== 'full' && $date >= $partLeaveFrom) {
                    if (! isset($presentSet[$date])) $unpaidLeaveSet[$date] = true; // not at work at all: nothing to pay
                    continue; // at work: paid for the hours worked (below)
                }
                if (isset($presentSet[$date])) {
                    // Worked on a leave day. HR may decide per day (Leave conflicts): "worked" = a normal working day
                    // (the leave is not taken), "leave" = the leave counts and the work is ignored. Otherwise a full-day
                    // leave stays and the work is extra working.
                    $choice = $resolved[$date] ?? ($extraAuto && $leave->duration === 'full' ? 'extra' : ($conflictRule === 'leave' ? 'leave' : 'worked'));
                    if ($choice === 'worked') continue;
                    if ($choice === 'extra') $extraDates[$date] = 'leave';
                    unset($presentSet[$date]);
                }
                if ($leave->leaveType->paid) $paidLeaveSet[$date] = true; else $unpaidLeaveSet[$date] = true;
            }
        }
        // Work on a day that is not a working day (weekly off, 3rd Saturday, holiday) is extra working, not a normal day.
        foreach (AttendanceSession::where('employee_id', $employee->id)->whereDate('work_date', '>=', $start)->whereDate('work_date', '<=', $end)->whereNotNull('check_in')->distinct()->pluck('work_date') as $workDate) {
            $date = now()->parse($workDate)->toDateString();
            if (! $this->isConfiguredWorkday($date, $calendar)) $extraDates[$date] = 'off';
        }
        // Half day (Settings > Payroll, from a start date): a finished working day under the half-day limit
        // (check-in to check-out less breaks; idle and meetings are not taken off) pays half a day, unless an
        // approved paid half-day or short leave covers that day. The same rule marks the day "Half day" in attendance.
        $halfDayPay = (bool) ($payrollPolicy['half_day_pay'] ?? true);
        // Half day paid by the hours at work (Settings > Payroll, on by default): time at work ÷ the working day,
        // not a fixed half day. Untick to pay a fixed half day as before.
        $halfDayHoursOnly = (bool) ($payrollPolicy['half_day_hours_only'] ?? true);
        $halfDayFrom = (string) ($payrollPolicy['half_day_pay_from'] ?? self::HALF_DAY_PAY_FROM);
        $halfDaySet = [];
        // Check-in after the cut-off (Settings > Payroll, default 12:00): that day is paid by the hours at work —
        // net hours (check-out − check-in − breaks taken; idle not taken off) ÷ the working day, at most one day —
        // instead of a full or half day. Attendance still shows Late / Half day as usual (pay and status are
        // separate). Not for people whose own day starts after the cut-off (afternoon / night shift), not on a
        // half-day holiday, and not when an approved paid half-day / short leave covers the day.
        $lateHoursPay = (bool) ($payrollPolicy['late_checkin_hours_pay'] ?? true);
        $lateAfter = preg_match('/^\d{2}:\d{2}$/', (string) ($payrollPolicy['late_checkin_after'] ?? '')) ? (string) $payrollPolicy['late_checkin_after'] : '12:00';
        $hoursPaidSet = [];
        if (($halfDayPay || $lateHoursPay) && $presentSet) {
            $halfRules = app(AttendanceService::class);
            $halfSessions = AttendanceSession::with('employee')->where('employee_id', $employee->id)->whereDate('work_date', '>=', max($start, $halfDayFrom))->whereDate('work_date', '<=', $end)->get()->keyBy(fn ($s) => $s->work_date->toDateString());
            $paidPartLeave = $leaveRequests->filter(fn ($l) => in_array($l->duration, ['half', 'short'], true) && $l->leaveType?->paid)->mapWithKeys(fn ($l) => [$l->starts_on->toDateString() => true])->all();
            $partLeaveOn = $leaveRequests->filter(fn ($l) => in_array($l->duration, ['half', 'short'], true))->mapWithKeys(fn ($l) => [$l->starts_on->toDateString() => $l])->all();
            foreach (array_keys($presentSet) as $date) {
                $daySession = $halfSessions->get($date);
                if ($partLeaveHoursOnly && isset($partLeaveOn[$date]) && $date >= $partLeaveFrom) {
                    // Approved half-day / short leave and some work: paid for the hours worked only.
                    if ($daySession && $daySession->check_in && $daySession->check_out) {
                        $net = (int) $halfRules->officeSeconds($daySession);
                        $standard = max(1, (int) round($halfRules->dailyHours($employee, $date) * 3600));
                        $hoursPaidSet[$date] = ['share' => round(min(1, $net / $standard), 4), 'seconds' => $net, 'standard' => $standard, 'check_in' => $daySession->check_in->format('H:i'),
                            'leave' => ($partLeaveOn[$date]->duration === 'half' ? 'Half-day leave' : 'Short leave').' · '.($partLeaveOn[$date]->leaveType?->name ?? 'Leave')];
                    }
                    continue;
                }
                if ($date < $halfDayFrom || isset($paidPartLeave[$date])) continue;
                if (! $daySession) continue;
                if ($lateHoursPay && ($late = $this->lateCheckInPay($employee, $daySession, $lateAfter, $calendar))) {
                    $hoursPaidSet[$date] = $late;
                    continue; // paid by hours, not as a half day
                }
                if ($halfDayPay && $halfRules->isHalfDay($daySession)) {
                    if ($halfDayHoursOnly) {
                        $net = (int) $halfRules->officeSeconds($daySession);
                        $standard = max(1, (int) round($halfRules->dailyHours($employee, $date) * 3600));
                        $hoursPaidSet[$date] = ['share' => round(min(1, $net / $standard), 4), 'seconds' => $net, 'standard' => $standard, 'check_in' => $daySession->check_in->format('H:i'), 'leave' => 'Half day · paid for the hours at work', 'half_day' => true];
                    } else {
                        $halfDaySet[$date] = true;
                    }
                }
            }
            ksort($halfDaySet);
            ksort($hoursPaidSet);
        }
        $months = [];
        for ($month = $startDate->copy()->startOfMonth(); $month->lte($endDate); $month->addMonth()) $months[] = $month->format('Y-m');
        $basePay = 0.0;
        $scheduledTotal = 0;
        $profiles = $employee->salaryProfiles()->whereDate('effective_from','<=',$end)->orderBy('effective_from')->orderBy('id')->get();
        $profileOn = function (string $date) use ($profiles) {
            // Days before the first salary record (e.g. salary entered after joining) use that first salary.
            return $profiles->filter(fn ($p) => $p->effective_from->toDateString() <= $date)->last() ?? $profiles->first();
        };
        $salarySegments = [];
        $halfHolidayAbsent = [];
        $paidHolidaySet = [];
        $unpaidHolidaySet = [];
        $structure = app(SalaryStructureService::class);
        $componentTotals = [];
        foreach ($months as $monthKey) {
            $monthStart = now()->parse($monthKey.'-01');
            $rangeFrom = $monthStart->copy()->max($startDate);
            $rangeTo = $monthStart->copy()->endOfMonth()->min($endDate);
            // Group this month's days by the salary profile in effect on each day.
            $segments = [];
            $monthPaidHolidays = 0;
            $monthUnpaidHolidays = 0;
            for ($day=$rangeFrom->copy(); $day->lte($rangeTo); $day->addDay()) {
                $date = $day->toDateString();
                $dayProfile = $profileOn($date);
                if (! $dayProfile) continue;
                $segment = &$segments[$dayProfile->id];
                $segment ??= ['profile' => $dayProfile, 'scheduled' => 0, 'eligible' => 0, 'holidays' => 0, 'present' => 0];
                if (! $this->isConfiguredWorkday($date,$calendar)) {
                    // A holiday on what would otherwise be a working day: paid or unpaid per Settings > Calendar.
                    if (in_array($date, \App\Support\WorkCalendar::offHolidays($calendar), true) && $this->isConfiguredWorkday($date, [...$calendar, 'holidays' => []])) {
                        if (\App\Support\WorkCalendar::holidayTypeOf($calendar, $date)['paid']) { $paidHolidaySet[$date] = true; $segment['holidays']++; $monthPaidHolidays++; }
                        else { $unpaidHolidaySet[$date] = true; $monthUnpaidHolidays++; }
                    }
                    unset($segment); continue;
                }
                $segment['scheduled']++;
                $dayShare = isset($hoursPaidSet[$date]) ? $hoursPaidSet[$date]['share'] : (isset($halfDaySet[$date]) ? 0.5 : 1); // by hours after a late check-in; a half day counts half
                if (isset($presentSet[$date])) $segment['present'] += $dayShare;
                if (isset($presentSet[$date])) $segment['eligible'] += $dayShare;
                elseif ($includePaidLeave && isset($paidLeaveSet[$date])) $segment['eligible']++;
                elseif (($halfHoliday = \App\Support\WorkCalendar::halfDayHoliday($calendar, $date)) && $halfHoliday['paid']) {
                    // Half-day holiday, not at work: the holiday half is paid.
                    $segment['eligible'] += 0.5;
                    $halfHolidayAbsent[$date] = true;
                }
                unset($segment);
            }
            $scheduledMonth = array_sum(array_column($segments, 'scheduled'));
            // Pay is per day at salary ÷ working days per month. A paid holiday tops a short month up to
            // that divisor (it is paid like a day worked); in a month that already has enough working days
            // it changes nothing. An unpaid holiday takes one day off what the month can pay.
            $sheetDays = $this->sheetWorkingDays($monthKey);
            $monthDivisor = $sheetDays ?: ($calendarDivisor ? $this->calendarDaysInMonth($monthStart, $calendar) : $workingDaysPerMonth);
            $monthDivisors[$monthKey] = $monthDivisor;
            // Judged on the whole month's working days (not only the days counted so far), so the "so far" estimate
            // in the middle of a month pays a holiday exactly as the month's payroll will.
            $topUp = min($monthPaidHolidays, max(0, $monthDivisor - max($scheduledMonth, $this->calendarDaysInMonth($monthStart, $calendar))));
            foreach ($segments as &$seg) {
                $take = min($seg['holidays'], $topUp);
                $seg['scheduled'] += $take;
                $seg['eligible'] += $take;
                $topUp -= $take;
            }
            unset($seg);
            $scheduledMonth = array_sum(array_column($segments, 'scheduled'));
            $cap = max(0, min($scheduledMonth, $monthDivisor - $monthUnpaidHolidays));
            $scheduledTotal += $cap;
            $paidMonth = array_sum(array_map(fn ($seg) => $attendanceProration ? min($seg['scheduled'], $seg['eligible']) : $seg['scheduled'], $segments));
            $scale = $paidMonth > $cap && $paidMonth > 0 ? $cap / $paidMonth : 1;
            foreach ($segments as $seg) {
                // Daily wage: the day's rate for every working day actually attended (no monthly divisor or components).
                if ($seg['profile']->isDaily()) {
                    $basePay += (float) $seg['profile']->daily_rate * $seg['present'];
                    $salarySegments[] = ['month' => $monthKey, 'pay_basis' => 'daily', 'daily_rate' => (float) $seg['profile']->daily_rate, 'monthly_salary' => (float) $seg['profile']->monthly_salary, 'effective_from' => $seg['profile']->effective_from->toDateString(), 'paid_days' => $seg['present']];
                    continue;
                }
                $segDays = ($attendanceProration ? min($seg['scheduled'], $seg['eligible']) : $seg['scheduled']) * $scale;
                $segDivisor = ($calendarDivisor || $sheetDays) ? $monthDivisor : (int) ($payrollPolicy['working_days_per_month'] ?? $seg['profile']->working_days_per_month);
                $basePay += ((float) $seg['profile']->monthly_salary / max(1, $segDivisor)) * $segDays;
                foreach ($structure->components($seg['profile'], $employee, $end) as $component) {
                    if ($component['type'] !== 'earning') continue;
                    $componentTotals[$component['name']] = ($componentTotals[$component['name']] ?? 0) + $component['amount'] / max(1, $segDivisor) * $segDays;
                }
                $salarySegments[] = ['month' => $monthKey, 'monthly_salary' => (float) $seg['profile']->monthly_salary, 'effective_from' => $seg['profile']->effective_from->toDateString(), 'paid_days' => round($segDays, 2)];
            }
        }
        $presentDays = count($presentSet);
        $paidLeaveDays = count($paidLeaveSet);
        $unpaidLeaveDays = count($unpaidLeaveSet);
        $overtimePolicy = $policyResolver->forEmployee($employee, 'overtime.multiplier', $endDate->toDateString(), []);
        // Overtime switched off in Settings: none is paid.
        $approvedOvertime = ($overtimePolicy['enabled'] ?? true) ? OvertimeRequest::where('employee_id',$employee->id)->where('status','approved')->whereDate('work_date','>=',$start)->whereDate('work_date','<=',$end)->get(['work_date','approved_minutes']) : collect();
        $approvedMinutes = 0;
        $holidayMinutes = 0;
        $overtimeOnExtraDays = 0;
        foreach ($approvedOvertime as $otRequest) {
            // An extra-working day is already paid in full below; its overtime request is not paid twice.
            if ($extraAuto && isset($extraDates[$otRequest->work_date->toDateString()])) { $overtimeOnExtraDays += (int) $otRequest->approved_minutes; continue; }
            // Weekly-off/holiday overtime uses the holiday rate if one is configured (never both rates).
            if (! $this->isConfiguredWorkday($otRequest->work_date->toDateString(), $calendar)) $holidayMinutes += (int) $otRequest->approved_minutes;
            $dailyCap = $policyResolver->forEmployee($employee, 'overtime.multiplier', $otRequest->work_date->toDateString(), [])['max_minutes_per_day'] ?? null;
            $minutesThatDay = (int) $approvedOvertime->where('work_date', $otRequest->work_date)->sum('approved_minutes');
            if ($dailyCap !== null && $minutesThatDay > (int) $dailyCap) throw ValidationException::withMessages(['payroll' => 'Approved overtime exceeds the configured daily employer limit.']);
            $approvedMinutes += (int) $otRequest->approved_minutes;
        }
        if ($approvedMinutes > 0 && ! array_key_exists('multiplier', $overtimePolicy)) throw ValidationException::withMessages(['payroll' => 'Configure an employer overtime multiplier before calculating payroll with approved overtime.']);
        $multiplier = (float) ($overtimePolicy['multiplier'] ?? 0);
        $holidayMultiplier = isset($overtimePolicy['holiday_multiplier']) ? (float) $overtimePolicy['holiday_multiplier'] : $multiplier;
        // Per-hour rate = one day's pay ÷ hours (Settings > Payroll "Hours per day for the hourly rate", else the working day).
        $rateHours = (float) ($payrollPolicy['hourly_rate_hours'] ?? 0) > 0 ? (float) $payrollPolicy['hourly_rate_hours'] : $standardDailyHours;
        $hourly = $profile->isDaily()
            ? (float) $profile->daily_rate / max(0.25, $rateHours)
            : (float)$profile->monthly_salary / max(1,($calendarDivisor && $monthDivisors ? (int) end($monthDivisors) : $workingDaysPerMonth)*$rateHours);
        $overtimeHours = $approvedMinutes/60;
        $overtimeAmount = round($hourly*(($approvedMinutes-$holidayMinutes)/60)*$multiplier + $hourly*($holidayMinutes/60)*$holidayMultiplier,2);
        // Extra working: the hours actually worked on those days × hourly rate × the weekly-off / holiday multiplier (1 when not set).
        $extraWork = ['enabled' => $extraAuto, 'days' => 0, 'seconds' => 0, 'multiplier' => isset($overtimePolicy['holiday_multiplier']) ? (float) $overtimePolicy['holiday_multiplier'] : 1.0, 'amount' => 0.0, 'overtime_minutes_not_paid_twice' => $overtimeOnExtraDays, 'dates' => []];
        if ($extraDates) {
            $extraSessions = AttendanceSession::with('employee')->where('employee_id', $employee->id)->whereDate('work_date', '>=', $start)->whereDate('work_date', '<=', $end)->get()->groupBy(fn ($s) => $s->work_date->toDateString());
            foreach ($extraDates as $date => $kind) {
                $seconds = (int) collect($extraSessions->get($date, []))->sum(fn ($s) => app(AttendanceService::class)->workedSeconds($s));
                if ($seconds <= 0) continue;
                $extraWork['dates'][$date] = ['type' => $kind, 'seconds' => $seconds];
                $extraWork['seconds'] += $seconds;
                $extraWork['days']++;
            }
            ksort($extraWork['dates']);
            if ($extraAuto) $extraWork['amount'] = round($hourly * $extraWork['seconds'] / 3600 * $extraWork['multiplier'], 2);
        }
        // Short hours (optional, Settings > Payroll): on the days the employee was present, the time
        // worked below their working day (less any half-day/short leave that day) is deducted at the
        // hourly rate, after a monthly grace. Absent days are already handled by proration above.
        // Also for chosen people only: their late rule (Compliance > Late attendance, e.g. an employee-specific rule)
        // says "Also cut the hours not worked" - then only the days that rule covers are counted.
        $everyone = ! empty($payrollPolicy['short_hours_deduction']);
        $hoursCutOn = fn (string $date) => $everyone || ! empty($policyResolver->forEmployee($employee, 'attendance.late_penalty', $date, [])['hours_cut']);
        $short = ['enabled' => $everyone || collect(array_keys($presentSet))->contains($hoursCutOn), 'required_seconds' => 0, 'actual_seconds' => 0, 'short_seconds' => 0, 'grace_seconds' => 0, 'amount' => 0.0];
        if ($short['enabled']) {
            $partLeave = LeaveRequest::where('employee_id',$employee->id)->where('status','approved')->whereIn('duration',['half','short'])->whereDate('starts_on','>=',$start)->whereDate('starts_on','<=',$end)->get()
                ->groupBy(fn ($l) => $l->starts_on->toDateString())->map(fn ($g) => min(1, (float) $g->sum('days')));
            $daySessions = AttendanceSession::with('employee')->where('employee_id',$employee->id)->whereDate('work_date','>=',$start)->whereDate('work_date','<=',$end)->get()->keyBy(fn ($s) => $s->work_date->toDateString());
            $attendanceService = app(AttendanceService::class);
            $missing = 0;
            foreach (array_keys($presentSet) as $date) {
                if (isset($halfDaySet[$date]) || isset($hoursPaidSet[$date]) || \App\Support\WorkCalendar::halfDayHoliday($calendar, $date)) continue; // never cut twice / office closed early
                if (! $hoursCutOn($date)) continue;
                $daySession = $daySessions->get($date);
                // Only finished days: a day still in progress (not checked out yet) is not short.
                if (! $daySession || ! $daySession->check_out) continue;
                $required = (int) round($standardDailyHours * 3600 * (1 - (float) ($partLeave[$date] ?? 0)));
                // "At work" (check-in to check-out less breaks), like the half-day check: idle is shown for
                // productivity only and never cuts the salary.
                $worked = (int) $attendanceService->officeSeconds($daySession);
                $short['required_seconds'] += $required;
                $short['actual_seconds'] += $worked;
                $missing += max(0, $required - $worked);
            }
            $short['grace_seconds'] = (int) round((float) ($payrollPolicy['short_hours_grace'] ?? 0) * 3600);
            $short['short_seconds'] = max(0, $missing - $short['grace_seconds']);
            $short['amount'] = round($hourly * $short['short_seconds'] / 3600, 2);
        }
        $shortAmount = (float) $short['amount'];
        // For an estimate ("so far"), items a draft payroll has taken still belong to this month: count them, so the
        // estimate and the draft show the same amount. A real calculation frees them first (the draft's rows are replaced).
        $draftItemIds = $forUpdate ? [] : PayrollItem::where('employee_id', $employee->id)->whereHas('run', fn ($q) => $q->where('status', 'draft'))->pluck('id')->all();
        $unclaimed = fn ($q) => $q->where(fn ($q) => $q->whereNull('payroll_item_id')->when($draftItemIds, fn ($q) => $q->orWhereIn('payroll_item_id', $draftItemIds)));
        $penaltyRows = EmployeePenalty::where('employee_id',$employee->id)->where('status','approved')->whereDate('effective_on','>=',$start)->whereDate('effective_on','<=',$end)->where($unclaimed)->orderBy('effective_on')->get();
        $penalties = (float) $penaltyRows->sum('amount');
        // Each approved fine with its date and reason, so the salary page and payslip can list them one by one.
        $penaltyLines = $penaltyRows->map(fn ($p) => ['date' => $p->effective_on->toDateString(), 'reason' => (string) $p->reason, 'amount' => round((float) $p->amount, 2)])->values()->all();
        // One-off adjustments in the period are claimed by this run; recurring ones apply in every month
        // they cover (never claimed). Increments are not paid here: they already raised the salary.
        $adjustments = SalaryAdjustment::where('employee_id',$employee->id)->where('status','approved')->where('is_recurring',false)->where('kind','!=','increment')->whereDate('effective_on','>=',$start)->whereDate('effective_on','<=',$end)->where($unclaimed)->get();
        $recurringAdjustments = SalaryAdjustment::where('employee_id',$employee->id)->where('status','approved')->where('is_recurring',true)->whereDate('effective_on','<=',$end)
            ->where(fn ($q) => $q->whereNull('recurring_until')->orWhereDate('recurring_until','>=',now()->parse($start)->startOfMonth()->toDateString()))->get();
        $adjustmentLines = $adjustments->toBase()->map(fn ($a) => ['id'=>$a->id,'kind'=>$a->kind,'label'=>$a->label(),'reason'=>$a->reason,'recurring'=>false,'months'=>1,'amount'=>$a->signedAmount()])
            ->merge($recurringAdjustments->toBase()->map(fn ($a) => ['id'=>$a->id,'kind'=>$a->kind,'label'=>$a->label(),'reason'=>$a->reason,'recurring'=>true,'months'=>$m = $a->recurringMonthsIn($start,$end),'amount'=>round($a->signedAmount()*$m,2)]))
            ->filter(fn ($line) => $line['amount'] != 0.0)->values();
        $adjustmentEarnings = round((float) $adjustmentLines->where('amount','>',0)->sum('amount'),2);
        $adjustmentDeductions = round(-(float) $adjustmentLines->where('amount','<',0)->sum('amount'),2);
        $adjustmentTotal = round($adjustmentEarnings - $adjustmentDeductions,2);
        $advanceRecovery = 0.0;
        $advanceRows = SalaryAdvance::where('employee_id',$employee->id)->whereIn('status',['approved','disbursed'])->whereColumn('disbursed_amount','>','recovered_amount')->when($forUpdate, fn ($q) => $q->lockForUpdate())->get();
        $draftRepaid = $draftItemIds ? SalaryAdvanceRepayment::whereIn('payroll_item_id', $draftItemIds)->get()->groupBy('salary_advance_id')->map(fn ($r) => (float) $r->sum('amount')) : collect();
        if ($draftRepaid->isNotEmpty()) $advanceRows = $advanceRows->merge(SalaryAdvance::whereIn('id', $draftRepaid->keys())->whereNotIn('id', $advanceRows->pluck('id'))->get());
        foreach ($advanceRows as $advance) $advanceRecovery += min((float)$advance->installment_amount,max(0,(float)$advance->disbursed_amount-(float)$advance->recovered_amount+(float)($draftRepaid[$advance->id] ?? 0)));
        $preStatutoryGross = round($basePay+$overtimeAmount+$extraWork['amount']+$adjustmentEarnings,2);
        // Fixed monthly deductions from the structure in effect at the end of the period (not prorated).
        $fixedDeductions = array_values(array_filter($structure->components($profileOn($endDate->toDateString()) ?? $profile, $employee, $end), fn ($c) => $c['type'] === 'deduction' && $c['amount'] > 0));
        $fixedDeductionTotal = round(array_sum(array_column($fixedDeductions, 'amount')), 2);
        $basicEarned = array_sum(array_filter($componentTotals, fn ($amount, $name) => stripos($name, 'basic') !== false, ARRAY_FILTER_USE_BOTH));
        $statutory = $this->statutoryComponents($employee, $endDate->toDateString(), $basicEarned > 0 ? $basicEarned : $basePay, $preStatutoryGross);
        $gross = round($preStatutoryGross + $statutory['earnings'], 2);
        // Approved expense claims are reimbursed on top of pay (not part of gross, never reduced by deductions).
        $claims = \App\Models\ExpenseClaim::where('employee_id',$employee->id)->where('status','approved')->where($unclaimed)->whereDate('spent_on','<=',$end)->when($forUpdate, fn ($q) => $q->lockForUpdate())->get();
        $reimbursements = round((float)$claims->sum('amount'),2);
        $net = round(max(0,round($gross-$adjustmentDeductions-$penalties-$shortAmount-$advanceRecovery-$statutory['deductions']-$fixedDeductionTotal,2))+$reimbursements,2);
        return get_defined_vars();
    }

    /**
     * What payroll would pay for a period as things stand (nothing is written): the same figures and
     * the same breakdown keys a payroll item stores, for "salary so far this month".
     */
    public function payEstimate(AttendanceEmployee $employee, string $start, string $end): ?array
    {
        $f = $this->employeePayFigures($employee, $start, $end, false);
        if ($f === null) return null;

        return [
            'gross' => (float) $f['gross'], 'net' => (float) $f['net'], 'working_days' => (int) $f['scheduledTotal'], 'present_days' => (float) $f['presentDays'],
            'paid_leave_days' => (float) $f['paidLeaveDays'], 'unpaid_leave_days' => (float) $f['unpaidLeaveDays'],
            'monthly_salary' => (float) $f['profile']->monthly_salary, 'pay_basis' => $f['profile']->pay_basis ?? 'monthly', 'daily_rate' => $f['profile']->isDaily() ? (float) $f['profile']->daily_rate : null,
            'month_divisors' => $f['monthDivisors'], 'working_days_per_month' => $f['workingDaysPerMonth'], 'standard_daily_hours' => $f['standardDailyHours'],
            'breakdown' => [
                'base_salary' => round((float) $f['basePay'], 2), 'paid_holiday_days' => count($f['paidHolidaySet']), 'half_days' => array_keys($f['halfDaySet']), 'hours_paid_days' => $f['hoursPaidSet'], 'half_holiday_paid' => array_keys($f['halfHolidayAbsent']), 'overtime_amount' => (float) $f['overtimeAmount'], 'extra_work' => $f['extraWork'],
                'salary_adjustment_lines' => $f['adjustmentLines']->all(), 'reimbursements' => (float) $f['reimbursements'], 'statutory_components' => $f['statutory']['components'],
                'penalties' => (float) $f['penalties'], 'penalty_lines' => $f['penaltyLines'] ?? [], 'short_hours' => $f['short'], 'advance_recovery' => (float) $f['advanceRecovery'], 'fixed_deductions' => $f['fixedDeductions'],
            ],
        ];
    }

    /**
     * The attendance-driven part of an employee's pay for a period as it stands
     * now: days paid (base), overtime, extra working, and the short-hours deduction.
     */
    public function attendancePay(AttendanceEmployee $employee, string $start, string $end): ?array
    {
        $f = $this->employeePayFigures($employee, $start, $end, false);
        if ($f === null) return null;
        $base = round((float) $f['basePay'], 2);
        $overtime = round((float) $f['overtimeAmount'], 2);
        $short = round((float) $f['shortAmount'], 2);
        $extra = round((float) ($f['extraWork']['amount'] ?? 0), 2);

        return ['base' => $base, 'overtime' => $overtime, 'extra' => $extra, 'short' => $short, 'total' => round($base + $overtime + $extra - $short, 2),
            'present_days' => $f['presentDays'], 'paid_leave_days' => $f['paidLeaveDays'], 'unpaid_leave_days' => $f['unpaidLeaveDays']];
    }

    public function approvePayroll(PayrollRun $run, int $userId): PayrollRun
    {
        return DB::transaction(function () use ($run, $userId) {
            $run = PayrollRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($run->status !== 'draft') throw ValidationException::withMessages(['payroll' => 'Only draft payroll can be approved.'])->status(409);
            if ($run->needs_recalculation) throw ValidationException::withMessages(['payroll' => 'Leave, overtime, penalties or salary changed after this run was calculated. Recalculate it before approving.']);
            if ($run->created_by === $userId && ! \App\Models\User::find($userId)?->hasRole('Master Admin')) throw ValidationException::withMessages(['payroll' => 'The person who calculated a payroll run cannot approve it.']);
            $run->update(['status' => 'approved']);
            $approval=ApprovalRequest::where('approvable_type',PayrollRun::class)->where('approvable_id',$run->id)->where('status','pending')->latest('id')->first();
            if(!$approval)throw ValidationException::withMessages(['payroll'=>'A pending payroll approval is required.']);
            $approval->update(['approver_id'=>$userId,'status'=>'approved','decided_at'=>now()]);
            $this->audit->record('payroll.approved', $run);
            foreach ($run->items()->with('employee.user')->get() as $item) $item->employee?->user?->notify(new \App\Notifications\ApprovalRequested('PayrollRun', $run->id, 'The payroll period was approved.'));
            return $run;
        });
    }

    public function rejectPayroll(PayrollRun $run, int $userId, ?string $note=null): PayrollRun
    {
        return DB::transaction(function()use($run,$userId,$note){
            $run=PayrollRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if($run->status!=='draft')throw ValidationException::withMessages(['payroll'=>'Only a draft payroll can be rejected.']);
            $approval=ApprovalRequest::where('approvable_type',PayrollRun::class)->where('approvable_id',$run->id)->where('status','pending')->latest('id')->first();
            if(!$approval)throw ValidationException::withMessages(['payroll'=>'A pending payroll approval is required.']);
            $approval->update(['approver_id'=>$userId,'status'=>'rejected','comment'=>$note,'decided_at'=>now()]);
            $this->audit->record('payroll.rejected',$run,['approver_id'=>$userId]);
            return $run;
        });
    }

    public function lockPayroll(PayrollRun $run, int $userId): PayrollRun
    {
        return DB::transaction(function () use ($run, $userId) {
            $run = PayrollRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($run->status !== 'approved') throw ValidationException::withMessages(['payroll' => 'Payroll must be approved before it can be locked.']);
            // Locking a month that is still running froze its attendance (no corrections, no admin check-out).
            if ($run->period_ends_on->gte(today())) throw ValidationException::withMessages(['payroll' => 'This payroll period ends on '.$run->period_ends_on->format('d M Y').'. It can be locked from '.$run->period_ends_on->copy()->addDay()->format('d M Y').', after the period is over.']);
            // Requests still waiting for a decision would be stuck forever once the period is locked.
            $from = $run->period_starts_on->toDateString();
            $to = $run->period_ends_on->toDateString();
            $pending = array_filter([
                'leave' => LeaveRequest::where('status', 'pending')->whereDate('starts_on', '<=', $to)->whereDate('ends_on', '>=', $from)->count(),
                'overtime' => OvertimeRequest::where('status', 'pending')->whereBetween('work_date', [$from, $to])->count(),
                'attendance correction' => \App\Models\AttendanceCorrection::where('status', 'pending')->whereBetween('work_date', [$from, $to])->count(),
            ]);
            if ($pending) {
                $list = collect($pending)->map(fn ($n, $what) => "{$n} {$what} ".str('request')->plural($n))->join(', ');
                throw ValidationException::withMessages(['payroll' => "Decide the pending requests of this period first ({$list}) — see Approvals. Once locked they could no longer be approved."]);
            }
            $run->update(['status' => 'locked', 'locked_by' => $userId, 'locked_at' => now()]);
            $this->audit->record('payroll.locked', $run);
            foreach ($run->items()->with('employee.user')->get() as $item) $item->employee?->user?->notify(new \App\Notifications\ApprovalRequested('PayrollRun', $run->id, 'The payroll period has been locked.'));
            return $run->fresh();
        });
    }

    /**
     * Administrator: reopens an approved or locked payroll run (e.g. locked by
     * mistake before the month ended) so attendance and leave can be corrected
     * and the run recalculated. Not possible once a salary payment is recorded.
     */
    /**
     * Deletes a draft payroll run (e.g. one made for the wrong dates). Approved, locked or paid runs cannot be
     * deleted. What the run had taken is given back for the right run to pick up: adjustments, expense claims,
     * penalties and advance recoveries.
     */
    public function deleteDraftPayroll(PayrollRun $run, \App\Models\User $actor, string $reason): void
    {
        abort_unless($actor->can('payroll.manage'), 403);
        if (mb_strlen(trim($reason)) < 5) throw ValidationException::withMessages(['deleteReason' => 'Say why this payroll is being deleted.']);
        DB::transaction(function () use ($run, $actor, $reason) {
            $run = PayrollRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($run->status !== 'draft') throw ValidationException::withMessages(['payroll' => 'Only a draft payroll can be deleted. Approved or locked payroll stays as it is (an administrator can reopen it).']);
            $itemIds = $run->items()->pluck('id');
            if (SalaryPayment::whereIn('payroll_item_id', $itemIds)->exists()) throw ValidationException::withMessages(['payroll' => 'A salary payment is recorded in this payroll, so it cannot be deleted.']);
            foreach (SalaryAdvanceRepayment::whereIn('payroll_item_id', $itemIds)->get() as $repayment) {
                SalaryAdvance::whereKey($repayment->salary_advance_id)->decrement('recovered_amount', $repayment->amount);
                SalaryAdvance::whereKey($repayment->salary_advance_id)->where('status', 'recovered')->update(['status' => 'disbursed']);
                $repayment->delete();
            }
            SalaryAdjustment::whereIn('payroll_item_id', $itemIds)->update(['payroll_item_id' => null]);
            \App\Models\ExpenseClaim::whereIn('payroll_item_id', $itemIds)->update(['payroll_item_id' => null]);
            EmployeePenalty::whereIn('payroll_item_id', $itemIds)->update(['payroll_item_id' => null]);
            ApprovalRequest::where('approvable_type', PayrollRun::class)->where('approvable_id', $run->id)->delete();
            $period = $run->period_starts_on->format('d M').' – '.$run->period_ends_on->format('d M Y');
            $this->audit->record('payroll.deleted', $run, ['period' => $period, 'employees' => $itemIds->count(), 'net' => (float) $run->items()->sum('net_amount'), 'by' => $actor->name, 'reason' => $reason]);
            $run->items()->delete();
            $run->delete();
        });
    }

    public function reopenPayroll(PayrollRun $run, \App\Models\User $actor, string $reason): PayrollRun
    {
        abort_unless(\App\Support\Access::isAdmin($actor), 403, 'Only an administrator can reopen a payroll run.');
        if (mb_strlen(trim($reason)) < 5) throw ValidationException::withMessages(['reopenReason' => 'Say why the payroll is being reopened.']);

        return DB::transaction(function () use ($run, $actor, $reason) {
            $run = PayrollRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if (! in_array($run->status, ['approved', 'locked'], true)) throw ValidationException::withMessages(['payroll' => 'Only an approved or locked payroll can be reopened.']);
            $payments = SalaryPayment::whereIn('payroll_item_id', $run->items()->pluck('id'))->count();
            if ($payments) throw ValidationException::withMessages(['payroll' => "{$payments} salary payment(s) are already recorded for this period, so it cannot be reopened. Corrections for this period go into the next payroll as salary adjustments."]);
            $before = $run->status;
            $run->update(['status' => 'draft', 'locked_by' => null, 'locked_at' => null, 'needs_recalculation' => true]);
            ApprovalRequest::create(['approvable_type' => PayrollRun::class, 'approvable_id' => $run->id, 'step' => 1, 'status' => 'pending']);
            $this->audit->record('payroll.reopened', $run, ['from' => $before, 'by' => $actor->name, 'reason' => $reason]);
            Notifier::send(Notifier::withPermission('payroll.manage', 'payroll.approve'), 'PayrollRun', $run->id, "{$actor->name} reopened the payroll for ".$run->period_starts_on->format('d M').'–'.$run->period_ends_on->format('d M Y').": {$reason}. Recalculate it before approving again.", route('payroll.runs', [], false), $actor->id);

            return $run->fresh();
        });
    }

    public function pay(PayrollItem $item, array $data, int $userId): SalaryPayment
    {
        return DB::transaction(function () use ($item, $data, $userId) {
            $item = PayrollItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $run = PayrollRun::whereKey($item->payroll_run_id)->lockForUpdate()->firstOrFail();
            if ($run->status !== 'locked') throw ValidationException::withMessages(['payment' => 'Payroll must be locked before payment.']);
            $paid = (float) $item->payments()->sum('amount');
            if ((float) $data['amount'] <= 0 || $paid + (float) $data['amount'] > (float) $item->net_amount) throw ValidationException::withMessages(['amount' => 'Payment exceeds the remaining payroll balance.']);
            $payment = $item->payments()->create([...$data, 'recorded_by' => $userId]);
            $payslip = Payslip::firstOrCreate(['payroll_item_id' => $item->id], ['employee_id' => $item->employee_id, 'issued_at' => now(), 'reference' => 'PS-'.str_pad((string) $item->id, 8, '0', STR_PAD_LEFT)]);
            $this->audit->record('payroll.payment_recorded', $payment, ['payroll_item_id' => $item->id]);
            $amount = number_format((float) $payment->amount, 2);
            // The first payment issues the payslip: say so plainly (it is what the employee is waiting for).
            if ($payslip->wasRecentlyCreated) {
                Notifier::send($item->employee?->user, 'Payslip', $payslip->id, 'Your payslip for '.$run->period_starts_on->format('F Y').' is ready. Salary payment of '.$amount.' recorded.', route('hr.payslips.show', $payslip, false));
            } else {
                Notifier::send($item->employee?->user, 'SalaryPayment', $payment->id, 'A further salary payment of '.$amount.' for '.$run->period_starts_on->format('F Y').' has been recorded.');
            }
            return $payment;
        });
    }

    private function businessDaysBetween(\Carbon\CarbonInterface $starts, \Carbon\CarbonInterface $ends, ?AttendanceEmployee $employee = null): int
    {
        $calendar=$employee?$this->policyConfiguration($employee,'calendar.working_days',$starts->toDateString(),['days'=>[1,2,3,4,5],'holidays'=>[]]):['days'=>[1,2,3,4,5],'holidays'=>[]];
        $days = 0;
        for ($day=$starts->copy()->startOfDay(); $day->lte($ends); $day->addDay()) if ($this->isConfiguredWorkday($day->toDateString(),$calendar)) $days++;
        return $days;
    }

    private function isConfiguredWorkday(string $date,array $calendar): bool
    {
        return \App\Support\WorkCalendar::isWorkday($date, $calendar);
    }

    private function policyConfiguration(AttendanceEmployee $employee, string $key, string $asOf, array $fallback): array
    {
        return app(PolicyConfigurationService::class)->forEmployee($employee, $key, $asOf, $fallback);
    }

    private function statutoryComponents(AttendanceEmployee $employee, string $asOf, float $basic, float $gross): array
    {
        $config = $this->policyConfiguration($employee, 'payroll.statutory', $asOf, ['components' => []]);
        $components = [];
        $earnings = 0.0;
        $deductions = 0.0;
        foreach (($config['components'] ?? []) as $rule) {
            if (! is_array($rule) || ! isset($rule['key'], $rule['name'], $rule['type'])) continue;
            if (! in_array($rule['type'], ['earning', 'deduction'], true)) continue;
            $basis = ($rule['basis'] ?? 'gross') === 'basic_salary' ? $basic : $gross;
            $amount = array_key_exists('fixed_amount', $rule)
                ? max(0, (float) $rule['fixed_amount'])
                : max(0, $basis * max(0, (float) ($rule['rate_bps'] ?? 0)) / 10000);
            if (isset($rule['cap_amount']) && is_numeric($rule['cap_amount'])) $amount = min($amount, max(0, (float) $rule['cap_amount']));
            $amount = round($amount, 2);
            if ($amount <= 0) continue;
            if ($rule['type'] === 'earning') $earnings += $amount; else $deductions += $amount;
            $components[] = ['key' => (string) $rule['key'], 'name' => (string) $rule['name'], 'type' => $rule['type'], 'basis' => round($basis, 2), 'amount' => $amount];
        }
        return ['earnings' => round($earnings, 2), 'deductions' => round($deductions, 2), 'components' => $components];
    }

    /**
     * What one day of salary is for this employee in the month of $date — the same number payroll divides by:
     * "auto" = that month's working days from the calendar (Sundays, the off Saturdays and holidays left out),
     * otherwise the fixed number from Settings > Payroll or the salary profile.
     *
     * @return array{days: int, auto: bool, month: string}
     */
    public function salaryDaysFor(AttendanceEmployee $employee, string $date, ?\App\Models\SalaryProfile $profile = null): array
    {
        $policy = $this->policyConfiguration($employee, 'payroll.calculation', $date, []);
        $month = \Illuminate\Support\Carbon::parse($date)->startOfMonth();
        if ($sheet = $this->sheetWorkingDays($month->format('Y-m'))) return ['days' => $sheet, 'auto' => false, 'month' => $month->format('M Y')];
        if (($policy['divisor'] ?? 'fixed') === 'calendar') {
            $calendar = $this->policyConfiguration($employee, 'calendar.working_days', $month->copy()->endOfMonth()->toDateString(), ['days' => [1, 2, 3, 4, 5], 'holidays' => []]);

            return ['days' => $this->calendarDaysInMonth($month, $calendar), 'auto' => true, 'month' => $month->format('M Y')];
        }
        $profile ??= $employee->salaryProfiles()->whereDate('effective_from', '<=', $date)->orderByDesc('effective_from')->orderByDesc('id')->first();

        return ['days' => max(1, (int) ($policy['working_days_per_month'] ?? $profile?->working_days_per_month ?? 22)), 'auto' => false, 'month' => $month->format('M Y')];
    }

    /**
     * Working days the old salary sheet used for a month (the most common value), or 0 when the month is not in it.
     * A month in the sheet uses these days for everyone: the sheet knew that month's holidays (August 2026 = 24),
     * so employees with and without a sheet row get the same per-day rate.
     */
    public function sheetWorkingDays(string $monthKey): int
    {
        return $this->sheetDaysCache[$monthKey] ??= (int) (\App\Models\LegacySalaryRecord::where('payroll_month', $monthKey)->where('total_working_days', '>', 0)
            ->selectRaw('total_working_days, COUNT(*) as n')->groupBy('total_working_days')->orderByDesc('n')->first()?->total_working_days ?? 0);
    }

    /**
     * Working days of the whole month from the calendar: Sundays, the off Saturdays and every holiday left out
     * (October 2026: 31 - 4 Sundays - 3rd Saturday - Dussehra = 25). A paid holiday is still paid: a month
     * worked in full pays the full salary.
     */
    private function calendarDaysInMonth(\Illuminate\Support\Carbon $monthStart, array $calendar): int
    {
        $days = 0;
        for ($day = $monthStart->copy()->startOfMonth(); $day->lte($monthStart->copy()->endOfMonth()); $day->addDay()) {
            if ($this->isConfiguredWorkday($day->toDateString(), $calendar)) $days++;
        }

        return max(1, $days);
    }

}
