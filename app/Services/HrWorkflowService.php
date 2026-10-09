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
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

class HrWorkflowService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function createEmployee(array $data, ?int $userId = null): AttendanceEmployee
    {
        return DB::transaction(function () use ($data, $userId) {
            $employee = AttendanceEmployee::create($data);
            if (isset($data['monthly_salary'])) {
                SalaryProfile::create(['employee_id' => $employee->id, 'monthly_salary' => $data['monthly_salary'], 'effective_from' => $data['joined_at'] ?? today(), 'configured_by' => $userId]);
            }
            foreach (LeaveType::where('is_active', true)->get() as $leaveType) {
                LeaveBalance::firstOrCreate(
                    ['employee_id' => $employee->id, 'leave_type_id' => $leaveType->id, 'year' => now()->year],
                    ['entitled_days' => (float) $leaveType->monthly_accrual > 0 ? 0 : $leaveType->annual_allowance]
                );
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
        return DB::transaction(function () use ($entry, $complete) {
            $end = now();
            $entry->update(['ended_at' => $end, 'duration_seconds' => $entry->started_at->diffInSeconds($end), 'status' => 'stopped']);
            if ($complete) $entry->task->update(['status' => 'completed', 'completed_at' => $end]);
            $entry->task->increment('server_version');
            $this->audit->record($complete ? 'task.completed' : 'task.timer_stopped', $entry->task, ['entry_id' => $entry->id, 'duration_seconds' => $entry->duration_seconds]);
            return $entry->fresh();
        });
    }

    /** Completes a task for its assignee, stopping a running timer if there is one. */
    public function completeTask(AttendanceEmployee $employee, Task $task): Task
    {
        if ($task->assignee_id !== $employee->id) throw ValidationException::withMessages(['task' => 'Task is not assigned to this employee.']);
        if ($task->status === 'completed') throw ValidationException::withMessages(['task' => 'This task is already completed.']);
        $running = TaskTimeEntry::where('task_id', $task->id)->where('employee_id', $employee->id)->where('status', 'running')->first();
        if ($running) return $this->stopTaskTimer($employee, $running, true)->task;

        return DB::transaction(function () use ($task) {
            $task->update(['status' => 'completed', 'completed_at' => now()]);
            $task->increment('server_version');
            $this->audit->record('task.completed', $task);

            return $task->fresh();
        });
    }

    public function requestLeave(AttendanceEmployee $employee, array $data): LeaveRequest
    {
        if ($data['ends_on']->lt($data['starts_on'])) throw ValidationException::withMessages(['ends_on' => 'The end date must be on or after the start date.']);
        if ($data['starts_on']->year !== $data['ends_on']->year) throw ValidationException::withMessages(['starts_on' => 'A leave request must stay within one leave year. Split requests that cross years.']);
        $days = $this->businessDaysBetween($data['starts_on'], $data['ends_on'], $employee);
        if ($days < 1) throw ValidationException::withMessages(['starts_on' => 'The selected date range contains no configured working days.']);
        $year = (int) $data['starts_on']->format('Y');
        return DB::transaction(function () use ($employee, $data, $days, $year) {
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
                $balance = LeaveBalance::create(['employee_id' => $employee->id, 'leave_type_id' => $leaveType->id, 'year' => $year, 'entitled_days' => (float) $leaveType->monthly_accrual > 0 ? 0 : $leaveType->annual_allowance]);
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
            $approverId=$employee->manager?->user_id;
            ApprovalRequest::create(['approvable_type' => LeaveRequest::class, 'approvable_id' => $leave->id, 'approver_id' => $approverId, 'status' => 'pending']);
            $notification=new \App\Notifications\ApprovalRequested('LeaveRequest',$leave->id,'A leave request needs review.');
            if($approverId) \App\Models\User::whereKey($approverId)->first()?->notify($notification);else foreach(\App\Models\User::permission('leave.manage')->get() as $approver)$approver->notify($notification);
            $this->audit->record('leave.requested', $leave, ['days' => $days]);
            return $leave;
        });
    }

    public function decideLeave(LeaveRequest $leave, int $approverId, bool $approve, ?string $note = null): LeaveRequest
    {
        return DB::transaction(function () use ($leave, $approverId, $approve, $note) {
            $leave = LeaveRequest::whereKey($leave->id)->lockForUpdate()->firstOrFail();
            if ($leave->status !== 'pending') throw ValidationException::withMessages(['leave' => 'This request has already been decided.'])->status(409);
            if ($approve) app(PayrollLockGuard::class)->changed($leave->employee_id, $leave->starts_on->toDateString(), 'leave', $leave->ends_on->toDateString());
            $leave->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $approverId, 'decided_at' => now(), 'decision_note' => $note]);
            ApprovalRequest::where('approvable_type', LeaveRequest::class)->where('approvable_id', $leave->id)->where('status', 'pending')->update(['approver_id' => $approverId, 'status' => $approve ? 'approved' : 'rejected', 'decided_at' => now(), 'comment' => $note]);
            if ($approve) {
                $type=LeaveType::findOrFail($leave->leave_type_id);
                if($type->paid){
                    $balance = LeaveBalance::where('employee_id', $leave->employee_id)->where('leave_type_id', $leave->leave_type_id)->where('year', $leave->starts_on->year)->lockForUpdate()->first();
                    if(!$balance)throw ValidationException::withMessages(['leave'=>'Leave balance is not configured for this employee.']);
                    $snapshot=app(LeaveAccrualService::class)->balance($leave->employee,$type,$leave->starts_on->year,$leave->starts_on->toDateString());
                    $available=(float)$snapshot['remaining']+(float)$leave->days;
                    $deficit=(float)$leave->days-$available;
                    if($deficit>0&&!$type->allow_advance)throw ValidationException::withMessages(['leave'=>'Approving this request would exceed the available paid leave balance.']);
                    if($deficit>0){$limit=$this->policyConfiguration($leave->employee,'leave.advance_limit',$leave->starts_on->toDateString(),['max_days'=>5]);if($deficit>(float)($limit['max_days']??5))throw ValidationException::withMessages(['leave'=>'Approving this request would exceed the advance leave limit.']);}
                    $balance->increment('used_days', $leave->days);
                }
            }
            $this->audit->record($approve ? 'leave.approved' : 'leave.rejected', $leave, ['approver_id' => $approverId]);
            $leave->employee?->user?->notify(new \App\Notifications\ApprovalRequested('LeaveRequest',$leave->id,$approve?'Your leave request was approved.':'Your leave request was rejected.'));
            return $leave->fresh();
        });
    }

    public function calculatePayroll(string $start, string $end, int $userId): PayrollRun
    {
        if ($end < $start) throw ValidationException::withMessages(['period' => 'The end date must be on or after the start date.']);
        return DB::transaction(function () use ($start, $end, $userId) {
            $run=PayrollRun::whereDate('period_starts_on',$start)->whereDate('period_ends_on',$end)->lockForUpdate()->first();
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
            $employees = AttendanceEmployee::where('is_active', true)->get();
            foreach ($employees as $employee) {
                $profile = $employee->salaryProfiles()->whereDate('effective_from','<=',$end)->orderByDesc('effective_from')->orderByDesc('id')->first();
                if (! $profile) continue;
                $policyResolver = app(PolicyConfigurationService::class);
                $payrollPolicy = $policyResolver->forEmployee($employee, 'payroll.calculation', $end, []);
                $hoursPolicy = $policyResolver->forEmployee($employee, 'work.hours', $end, []);
                $workingDaysPerMonth = (int) ($payrollPolicy['working_days_per_month'] ?? $profile->working_days_per_month);
                $standardDailyHours = (float) ($payrollPolicy['standard_daily_hours'] ?? $hoursPolicy['standard_daily_hours'] ?? $profile->standard_daily_hours);
                $attendanceProration = (bool) ($payrollPolicy['attendance_proration'] ?? true);
                $includePaidLeave = (bool) ($payrollPolicy['include_paid_leave'] ?? true);
                $startDate = now()->parse($start)->startOfDay();
                $endDate = now()->parse($end)->startOfDay();
                if ($profile->effective_from?->gt($endDate)) continue;
                $calendar = $this->policyConfiguration($employee, 'calendar.working_days', $endDate->toDateString(), ['days' => [1,2,3,4,5], 'holidays' => []]);
                $presentDates = AttendanceSession::where('employee_id', $employee->id)->whereDate('work_date','>=',$start)->whereDate('work_date','<=',$end)->whereNotNull('check_in')->distinct()->pluck('work_date')->map(fn ($date) => now()->parse($date)->toDateString())->filter(fn ($date) => $this->isConfiguredWorkday($date,$calendar))->values()->all();
                $presentSet = array_fill_keys($presentDates, true);
                foreach ($presentSet as $date => $_) {
                    if (now()->parse($date)->lt($startDate) || now()->parse($date)->gt($endDate)) unset($presentSet[$date]);
                }
                $paidLeaveSet = [];
                $unpaidLeaveSet = [];
                $leaveRequests = LeaveRequest::with('leaveType')->where('employee_id',$employee->id)->where('status','approved')->whereDate('starts_on','<=',$end)->whereDate('ends_on','>=',$start)->get();
                $conflictRule = (string) ($this->policyConfiguration($employee, 'attendance.rules', $endDate->toDateString(), [])['leave_conflict'] ?? 'attendance');
                $resolved = \App\Models\LeaveConflictResolution::where('employee_id',$employee->id)->whereDate('work_date','>=',$start)->whereDate('work_date','<=',$end)->get()->mapWithKeys(fn ($r) => [$r->work_date->toDateString() => $r->resolution])->all();
                foreach ($leaveRequests as $leave) {
                    $from = max($startDate->toDateString(), $leave->starts_on->toDateString());
                    $to = min($endDate->toDateString(), $leave->ends_on->toDateString());
                    for ($day = now()->parse($from); $day->lte(now()->parse($to)); $day->addDay()) {
                        if (! $this->isConfiguredWorkday($day->toDateString(),$calendar)) continue;
                        $date = $day->toDateString();
                        if (isset($presentSet[$date])) {
                            // Worked on a leave day: count it once — as work (default) or as leave.
                            if (($resolved[$date] ?? ($conflictRule === 'leave' ? 'leave' : 'worked')) !== 'leave') continue;
                            unset($presentSet[$date]);
                        }
                        if ($leave->leaveType->paid) $paidLeaveSet[$date] = true; else $unpaidLeaveSet[$date] = true;
                    }
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
                $structure = app(SalaryStructureService::class);
                $componentTotals = [];
                foreach ($months as $monthKey) {
                    $monthStart = now()->parse($monthKey.'-01');
                    $rangeFrom = $monthStart->copy()->max($startDate);
                    $rangeTo = $monthStart->copy()->endOfMonth()->min($endDate);
                    // Group this month's days by the salary profile in effect on each day.
                    $segments = [];
                    for ($day=$rangeFrom->copy(); $day->lte($rangeTo); $day->addDay()) {
                        $date = $day->toDateString();
                        $dayProfile = $profileOn($date);
                        if (! $dayProfile) continue;
                        $segment = &$segments[$dayProfile->id];
                        $segment ??= ['profile' => $dayProfile, 'scheduled' => 0, 'eligible' => 0];
                        if (! $this->isConfiguredWorkday($date,$calendar)) { unset($segment); continue; }
                        $segment['scheduled']++;
                        if (isset($presentSet[$date]) || ($includePaidLeave && isset($paidLeaveSet[$date]))) $segment['eligible']++;
                        unset($segment);
                    }
                    $scheduledMonth = array_sum(array_column($segments, 'scheduled'));
                    $cap = min($scheduledMonth, $workingDaysPerMonth);
                    $scheduledTotal += $cap;
                    $paidMonth = array_sum(array_map(fn ($seg) => $attendanceProration ? min($seg['scheduled'], $seg['eligible']) : $seg['scheduled'], $segments));
                    $scale = $paidMonth > $cap && $paidMonth > 0 ? $cap / $paidMonth : 1;
                    foreach ($segments as $seg) {
                        $segDays = ($attendanceProration ? min($seg['scheduled'], $seg['eligible']) : $seg['scheduled']) * $scale;
                        $segDivisor = (int) ($payrollPolicy['working_days_per_month'] ?? $seg['profile']->working_days_per_month);
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
                $approvedOvertime = OvertimeRequest::where('employee_id',$employee->id)->where('status','approved')->whereDate('work_date','>=',$start)->whereDate('work_date','<=',$end)->get(['work_date','approved_minutes']);
                $approvedMinutes = 0;
                $holidayMinutes = 0;
                foreach ($approvedOvertime as $otRequest) {
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
                $hourly = (float)$profile->monthly_salary / max(1,$workingDaysPerMonth*$standardDailyHours);
                $overtimeHours = $approvedMinutes/60;
                $overtimeAmount = round($hourly*(($approvedMinutes-$holidayMinutes)/60)*$multiplier + $hourly*($holidayMinutes/60)*$holidayMultiplier,2);
                $penalties = (float)EmployeePenalty::where('employee_id',$employee->id)->where('status','approved')->whereDate('effective_on','>=',$start)->whereDate('effective_on','<=',$end)->whereNull('payroll_item_id')->sum('amount');
                $adjustments = SalaryAdjustment::where('employee_id',$employee->id)->where('status','approved')->whereDate('effective_on','>=',$start)->whereDate('effective_on','<=',$end)->whereNull('payroll_item_id')->get();
                $adjustmentTotal = $adjustments->sum(fn ($adjustment) => $adjustment->kind==='earning' ? (float)$adjustment->amount : -(float)$adjustment->amount);
                $advanceRecovery = 0.0;
                $advanceRows = SalaryAdvance::where('employee_id',$employee->id)->whereIn('status',['approved','disbursed'])->whereColumn('disbursed_amount','>','recovered_amount')->lockForUpdate()->get();
                foreach ($advanceRows as $advance) $advanceRecovery += min((float)$advance->installment_amount,max(0,(float)$advance->disbursed_amount-(float)$advance->recovered_amount));
                $preStatutoryGross = round($basePay+$overtimeAmount+$adjustments->where('kind','earning')->sum('amount'),2);
                // Fixed monthly deductions from the structure in effect at the end of the period (not prorated).
                $fixedDeductions = array_values(array_filter($structure->components($profileOn($endDate->toDateString()) ?? $profile, $employee, $end), fn ($c) => $c['type'] === 'deduction' && $c['amount'] > 0));
                $fixedDeductionTotal = round(array_sum(array_column($fixedDeductions, 'amount')), 2);
                $basicEarned = array_sum(array_filter($componentTotals, fn ($amount, $name) => stripos($name, 'basic') !== false, ARRAY_FILTER_USE_BOTH));
                $statutory = $this->statutoryComponents($employee, $endDate->toDateString(), $basicEarned > 0 ? $basicEarned : $basePay, $preStatutoryGross);
                $gross = round($preStatutoryGross + $statutory['earnings'], 2);
                // Approved expense claims are reimbursed on top of pay (not part of gross, never reduced by deductions).
                $claims = \App\Models\ExpenseClaim::where('employee_id',$employee->id)->where('status','approved')->whereNull('payroll_item_id')->whereDate('spent_on','<=',$end)->lockForUpdate()->get();
                $reimbursements = round((float)$claims->sum('amount'),2);
                $net = round(max(0,round($gross-(float)$adjustments->where('kind','deduction')->sum('amount')-$penalties-$advanceRecovery-$statutory['deductions']-$fixedDeductionTotal,2))+$reimbursements,2);
                $item = PayrollItem::create([
                    'payroll_run_id'=>$run->id,'employee_id'=>$employee->id,'gross_amount'=>$gross,'adjustments'=>$adjustmentTotal,'net_amount'=>$net,
                    'working_days'=>$scheduledTotal,'present_days'=>$presentDays,'paid_leave_days'=>$paidLeaveDays,'unpaid_leave_days'=>$unpaidLeaveDays,
                    'overtime_hours'=>$overtimeHours,'overtime_amount'=>$overtimeAmount,'penalties_amount'=>$penalties,'recovery_amount'=>$advanceRecovery,'adjustments_amount'=>$adjustmentTotal,
                    'statutory_earnings'=>$statutory['earnings'],'statutory_deductions'=>$statutory['deductions'],'reimbursements_amount'=>$reimbursements,
                    'calculation_data'=>['monthly_salary'=>(float)$profile->monthly_salary,'working_days_per_month'=>$workingDaysPerMonth,'standard_daily_hours'=>$standardDailyHours,'attendance_proration'=>$attendanceProration,'include_paid_leave'=>$includePaidLeave,'statutory_components'=>$statutory['components']],
                    'calculation_breakdown'=>['base_salary'=>round($basePay,2),'salary_components'=>array_map(fn ($v) => round($v, 2), $componentTotals),'fixed_deductions'=>$fixedDeductions,'fixed_deductions_total'=>$fixedDeductionTotal,'salary_segments'=>$salarySegments,'working_days'=>$scheduledTotal,'present_days'=>$presentDays,'paid_leave_days'=>$paidLeaveDays,'unpaid_leave_days'=>$unpaidLeaveDays,'approved_overtime_minutes'=>$approvedMinutes,'holiday_overtime_minutes'=>$holidayMinutes,'overtime_multiplier'=>$multiplier,'holiday_overtime_multiplier'=>$holidayMultiplier,'overtime_amount'=>$overtimeAmount,'salary_adjustments'=>$adjustmentTotal,'penalties'=>$penalties,'advances_disbursed'=>(float)$advanceRows->sum('disbursed_amount'),'advance_recovery'=>$advanceRecovery,'statutory_earnings'=>$statutory['earnings'],'statutory_deductions'=>$statutory['deductions'],'statutory_components'=>$statutory['components'],'reimbursements'=>$reimbursements,'gross_salary'=>$gross,'payable_salary'=>$net,'employer_policy'=>['working_days_per_month'=>$workingDaysPerMonth,'standard_daily_hours'=>$standardDailyHours,'attendance_proration'=>$attendanceProration,'include_paid_leave'=>$includePaidLeave]],
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
            $run->update(['status' => 'locked', 'locked_by' => $userId, 'locked_at' => now()]);
            $this->audit->record('payroll.locked', $run);
            foreach ($run->items()->with('employee.user')->get() as $item) $item->employee?->user?->notify(new \App\Notifications\ApprovalRequested('PayrollRun', $run->id, 'The payroll period has been locked.'));
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
            Payslip::firstOrCreate(['payroll_item_id' => $item->id], ['employee_id' => $item->employee_id, 'issued_at' => now(), 'reference' => 'PS-'.str_pad((string) $item->id, 8, '0', STR_PAD_LEFT)]);
            $this->audit->record('payroll.payment_recorded', $payment, ['payroll_item_id' => $item->id]);
            $item->employee?->user?->notify(new \App\Notifications\ApprovalRequested('SalaryPayment', $payment->id, 'A salary payment of '.$payment->amount.' has been recorded.'));
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
        $day=(int)\Carbon\Carbon::parse($date)->isoWeekday();
        return in_array($day,$calendar['days']??[1,2,3,4,5],true) && !in_array($date,$calendar['holidays']??[],true);
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
}
