<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\AttendanceSession;
use App\Models\EmployeeFinalSettlement;
use App\Models\EmployeeFinalSettlementPayment;
use App\Models\EmployeeLifecycleCase;
use App\Models\User;
use App\Notifications\ApprovalRequested;
use App\Models\EmployeePenalty;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\PayrollRun;
use App\Models\SalaryAdjustment;
use App\Models\SalaryAdvance;
use App\Models\SalaryAdvanceRepayment;
use App\Models\SalaryProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinalSettlementService
{
    public function calculate(EmployeeLifecycleCase $case, string $asOf): array
    {
        abort_unless($case->kind === 'offboarding', 422);
        $employee = $case->employee;
        $end = Carbon::parse($asOf)->startOfDay();
        $lockedRuns = PayrollRun::with('items.payments')
            ->where('status', 'locked')
            ->whereDate('period_starts_on', '<=', $end)
            ->whereHas('items', fn ($q) => $q->where('employee_id', $employee->id))
            ->get();
        $lastCoveredDate = null;
        $lockedUnpaid = 0.0;
        foreach ($lockedRuns as $run) {
            if ($run->locked_at && $run->locked_at->gt($end->copy()->endOfDay())) continue;

            $item = $run->items->firstWhere('employee_id', $employee->id);
            if (! $item) continue;

            $coveredThrough = $run->period_ends_on->copy();
            if ($run->locked_at && $run->locked_at->toDateString() < $coveredThrough->toDateString()) {
                $coveredThrough = $run->locked_at->copy()->startOfDay();
            }
            if ($coveredThrough->gt($end)) $coveredThrough = $end->copy();
            if (! $lastCoveredDate || $coveredThrough->gt($lastCoveredDate)) $lastCoveredDate = $coveredThrough;

            $lockedUnpaid += max(0, (float) $item->net_amount - (float) $item->payments->sum('amount'));
        }
        $start = $lastCoveredDate
            ? $lastCoveredDate->copy()->addDay()->startOfDay()
            : Carbon::parse($employee->joined_at ?? $end)->startOfDay();
        $profiles = SalaryProfile::where('employee_id', $employee->id)->whereDate('effective_from', '<=', $end)->orderBy('effective_from')->get();
        $policyResolver = app(PolicyConfigurationService::class);
        $calendar = $policyResolver->forEmployee($employee, 'calendar.working_days', $end->toDateString(), ['days' => [1,2,3,4,5], 'holidays' => []]);
        $payrollPolicy = $policyResolver->forEmployee($employee, 'payroll.calculation', $end->toDateString(), []);
        $attendanceProration = (bool) ($payrollPolicy['attendance_proration'] ?? true);
        $includePaidLeave = (bool) ($payrollPolicy['include_paid_leave'] ?? true);
        $base = 0.0; $workingDays = 0; $present = []; $paidLeave = []; $unpaidLeave = [];
        $sessions = AttendanceSession::where('employee_id', $employee->id)->whereDate('work_date', '>=', $start->toDateString())->whereDate('work_date', '<=', $end->toDateString())->whereNotNull('check_in')->pluck('work_date')->map(fn ($d) => Carbon::parse($d)->toDateString())->all();
        $present = array_fill_keys($sessions, true);
        $leaves = LeaveRequest::with('leaveType')->where('employee_id', $employee->id)->where('status', 'approved')->whereDate('starts_on', '<=', $end)->whereDate('ends_on', '>=', $start)->get();
        foreach ($leaves as $leave) {
            $leaveStart = $start->copy()->max($leave->starts_on);
            $leaveEnd = $end->copy()->min($leave->ends_on);
            for ($day = $leaveStart; $day->lte($leaveEnd); $day->addDay()) {
                if (!$this->isWorkday($day->toDateString(), $calendar) || isset($present[$day->toDateString()])) continue;
                if ($leave->leaveType?->paid) $paidLeave[$day->toDateString()] = true; else $unpaidLeave[$day->toDateString()] = true;
            }
        }
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            if (!$this->isWorkday($day->toDateString(), $calendar)) continue;
            $workingDays++;
            $profile = $profiles->last(fn ($p) => $p->effective_from->lte($day));
            if (!$profile) continue;
            $monthlyScheduled = max(1, (int) ($payrollPolicy['working_days_per_month'] ?? $profile->working_days_per_month));
            $eligible = isset($present[$day->toDateString()]) || ($includePaidLeave && isset($paidLeave[$day->toDateString()]));
            if (! $attendanceProration || $eligible) $base += (float) $profile->monthly_salary / $monthlyScheduled;
        }
        $overtimePolicy = $policyResolver->forEmployee($employee, 'overtime.multiplier', $end->toDateString(), []);
        // Overtime switched off (Settings > Payroll): none is paid, as in payroll.
        $overtimeOn = (bool) ($overtimePolicy['enabled'] ?? true);
        $approvedMinutes = $overtimeOn ? (int) OvertimeRequest::where('employee_id', $employee->id)->where('status', 'approved')->whereDate('work_date', '>=', $start->toDateString())->whereDate('work_date', '<=', $end->toDateString())->sum('approved_minutes') : 0;
        $profile = $profiles->last();
        if ($approvedMinutes > 0 && ! array_key_exists('multiplier', $overtimePolicy)) throw ValidationException::withMessages(['settlement' => 'Configure an employer overtime multiplier before calculating this settlement.']);
        $dailyOt = ! $overtimeOn ? collect() : OvertimeRequest::where('employee_id', $employee->id)->where('status', 'approved')->whereDate('work_date', '>=', $start->toDateString())->whereDate('work_date', '<=', $end->toDateString())->get(['work_date', 'approved_minutes'])->groupBy(fn ($row) => $row->work_date->toDateString());
        foreach ($dailyOt as $date => $rows) {
            $cap = $policyResolver->forEmployee($employee, 'overtime.multiplier', $date, [])['max_minutes_per_day'] ?? null;
            if ($cap !== null && (int) $rows->sum('approved_minutes') > (int) $cap) throw ValidationException::withMessages(['settlement' => 'Approved overtime exceeds the configured daily employer limit.']);
        }
        $dailyHours = (float) ($payrollPolicy['standard_daily_hours'] ?? app(AttendanceService::class)->dailyHours($employee, $end->toDateString()));
        $workingDaysPerMonth = (int) ($payrollPolicy['working_days_per_month'] ?? $profile?->working_days_per_month ?? 22);
        $multiplier = (float) ($overtimePolicy['multiplier'] ?? 0);
        $overtime = $profile ? round(((float) $profile->monthly_salary / max(1, $workingDaysPerMonth * $dailyHours)) * ($approvedMinutes / 60) * $multiplier, 2) : 0;
        // One-off adjustments not yet paid, plus the final month of any recurring adjustment still running then.
        // Increments are excluded: they already raised the salary used above.
        $adjustments = SalaryAdjustment::where('employee_id', $employee->id)->where('status', 'approved')->where('kind', '!=', 'increment')->whereDate('effective_on', '<=', $end->toDateString())
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('is_recurring', false)->whereNull('payroll_item_id'))
                ->orWhere(fn ($q) => $q->where('is_recurring', true)->where(fn ($q) => $q->whereNull('recurring_until')->orWhereDate('recurring_until', '>=', $end->copy()->startOfMonth()->toDateString()))))->get();
        $earnings = (float) $adjustments->filter(fn ($a) => ! $a->isDeduction())->sum('amount');
        $otherDeductions = (float) $adjustments->filter(fn ($a) => $a->isDeduction())->sum('amount');
        $penalties = (float) EmployeePenalty::where('employee_id', $employee->id)->where('status', 'approved')->whereDate('effective_on', '<=', $end->toDateString())->whereNull('payroll_item_id')->sum('amount');
        $advances = SalaryAdvance::where('employee_id', $employee->id)->whereIn('status', ['approved', 'disbursed'])->get();
        $advanceRecovery = 0.0;
        foreach ($advances as $advance) {
            $lockedRecovery=(float)SalaryAdvanceRepayment::join('payroll_items','salary_advance_repayments.payroll_item_id','=','payroll_items.id')->join('payroll_runs','payroll_items.payroll_run_id','=','payroll_runs.id')->where('salary_advance_repayments.salary_advance_id',$advance->id)->where('payroll_runs.status','locked')->sum('salary_advance_repayments.amount');
            $advanceRecovery += max(0, (float) $advance->disbursed_amount - $lockedRecovery);
        }
        $policy = app(PeopleOperationsService::class)->policy($employee, 'leave.final_settlement', ['leave_encashment_enabled' => false, 'rate_per_day' => 0]);
        $leaveRemaining = 0.0;
        foreach (\App\Models\LeaveType::where('is_active', true)->where('paid', true)->get() as $type) $leaveRemaining += max(0, (float) app(LeaveAccrualService::class)->balance($employee, $type, \App\Support\LeaveYear::of($end))['remaining']);
        $leaveCashout = !empty($policy['leave_encashment_enabled']) ? round($leaveRemaining * max(0, (float) ($policy['rate_per_day'] ?? 0)), 2) : 0;
        $reimbursements = round((float) \App\Models\ExpenseClaim::where('employee_id', $employee->id)->where('status', 'approved')->whereNull('payroll_item_id')->sum('amount'), 2);
        $gross = round($base + $overtime + $earnings + $leaveCashout + $lockedUnpaid + $reimbursements, 2);
        $deductions = round($otherDeductions + $penalties + $advanceRecovery, 2);
        return ['gross_amount' => $gross, 'deductions' => $deductions, 'net_amount' => round($gross - $deductions, 2), 'as_of_date' => $end->toDateString(), 'calculation_breakdown' => ['period_start' => $start->gt($end) ? $end->toDateString() : $start->toDateString(), 'period_end' => $end->toDateString(), 'payroll_coverage_through' => $lastCoveredDate?->toDateString(), 'next_earning_date' => $start->gt($end) ? $start->toDateString() : null, 'working_days' => $workingDays, 'attendance_days' => count($present), 'paid_leave_days' => count($paidLeave), 'unpaid_leave_days' => count($unpaidLeave), 'salary_earned' => round($base, 2), 'approved_overtime_minutes' => $approvedMinutes, 'overtime_multiplier' => $multiplier, 'overtime_amount' => $overtime, 'approved_adjustment_earnings' => $earnings, 'approved_adjustment_deductions' => $otherDeductions, 'approved_penalties' => $penalties, 'outstanding_disbursed_advances' => $advanceRecovery, 'configured_leave_cashout' => $leaveCashout, 'expense_reimbursements' => $reimbursements, 'unpaid_locked_payroll' => round($lockedUnpaid, 2), 'paid_payroll_is_excluded' => true, 'locked_payroll_outstanding_is_included' => true, 'employer_policy' => ['working_days_per_month' => $workingDaysPerMonth, 'standard_daily_hours' => $dailyHours, 'attendance_proration' => $attendanceProration, 'include_paid_leave' => $includePaidLeave]]];
    }

    /** Calculates (or recalculates) the settlement from live data and routes it for approval. */
    public function prepare(EmployeeLifecycleCase $case, int $userId): EmployeeFinalSettlement
    {
        return DB::transaction(function () use ($case, $userId) {
            $case = EmployeeLifecycleCase::with('clearances')->whereKey($case->id)->lockForUpdate()->firstOrFail();
            if ($case->kind !== 'offboarding') throw ValidationException::withMessages(['settlement' => 'Final settlement applies to offboarding cases only.']);
            if ($case->clearances->contains(fn ($item) => $item->status !== 'cleared')) throw ValidationException::withMessages(['settlement' => 'Complete all clearance items before preparing the final settlement.']);
            if (\App\Models\EmployeeAsset::where('employee_id', $case->employee_id)->whereIn('status', ['assigned', 'lost'])->exists()) throw ValidationException::withMessages(['settlement' => 'Assets are still with the employee or lost without a decision. Resolve them before the final settlement.']);
            $pendingFinance = collect([
                'salary adjustments' => \App\Models\SalaryAdjustment::where('employee_id', $case->employee_id)->where('status', 'pending')->count(),
                'penalties' => \App\Models\EmployeePenalty::where('employee_id', $case->employee_id)->where('status', 'pending')->count(),
                'salary advances' => \App\Models\SalaryAdvance::where('employee_id', $case->employee_id)->where('status', 'pending')->count(),
                'expense claims' => \App\Models\ExpenseClaim::where('employee_id', $case->employee_id)->where('status', 'pending')->count(),
                'overtime requests' => app(PeopleOperationsService::class)->overtimeEnabled() ? \App\Models\OvertimeRequest::where('employee_id', $case->employee_id)->where('status', 'pending')->count() : 0,
            ])->filter();
            if ($pendingFinance->isNotEmpty()) throw ValidationException::withMessages(['settlement' => 'Decide the pending '.$pendingFinance->keys()->join(', ', ' and ').' first — they change the amount due.']);
            $settlement = EmployeeFinalSettlement::firstOrNew(['lifecycle_case_id' => $case->id]);
            if (in_array($settlement->status, ['approved', 'partially_paid', 'paid'], true)) throw ValidationException::withMessages(['settlement' => 'An approved or paid settlement cannot be recalculated.']);
            $calculation = $this->calculate($case, $case->effective_on?->toDateString() ?? today()->toDateString());
            $settlement->fill([...$calculation, 'status' => 'pending', 'prepared_by' => $userId])->save();
            $approval = ApprovalRequest::firstOrCreate(['approvable_type' => EmployeeFinalSettlement::class, 'approvable_id' => $settlement->id, 'status' => 'pending'], ['step' => 1]);
            if ($approval->wasRecentlyCreated) {
                foreach (User::permission('payroll.approve')->get() as $approver) $approver->notify(new ApprovalRequested('EmployeeFinalSettlement', $settlement->id, 'A final settlement needs approval.'));
            }
            app(AuditLogger::class)->record('employee.final_settlement_prepared', $settlement, $calculation['calculation_breakdown']);

            return $settlement->fresh();
        });
    }

    public function approve(EmployeeFinalSettlement $settlement, int $userId): EmployeeFinalSettlement
    {
        return DB::transaction(function () use ($settlement, $userId) {
            $settlement = EmployeeFinalSettlement::whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            if ($settlement->status !== 'pending') throw ValidationException::withMessages(['settlement' => 'Only a pending settlement can be approved.']);
            if ($settlement->prepared_by === $userId && ! User::find($userId)?->hasRole('Master Admin')) throw ValidationException::withMessages(['settlement' => 'The person who prepared a settlement cannot approve it.']);
            $settlement->update(['status' => 'approved', 'approved_by' => $userId, 'approved_at' => now()]);
            $this->closeApproval($settlement, $userId, 'approved');
            app(AuditLogger::class)->record('employee.final_settlement_approved', $settlement);
            $settlement->lifecycleCase?->employee?->user?->notify(new ApprovalRequested('EmployeeFinalSettlement', $settlement->id, 'Your final settlement has been approved.'));

            return $settlement->fresh();
        });
    }

    public function reject(EmployeeFinalSettlement $settlement, int $userId, ?string $note = null): EmployeeFinalSettlement
    {
        return DB::transaction(function () use ($settlement, $userId, $note) {
            $settlement = EmployeeFinalSettlement::whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            if ($settlement->status !== 'pending') throw ValidationException::withMessages(['settlement' => 'Only a pending settlement can be rejected.']);
            $settlement->update(['status' => 'rejected']);
            $this->closeApproval($settlement, $userId, 'rejected', $note);
            app(AuditLogger::class)->record('employee.final_settlement_rejected', $settlement, ['note' => $note]);

            return $settlement->fresh();
        });
    }

    public function recordPayment(EmployeeFinalSettlement $settlement, float $amount, ?string $reference, int $userId, ?string $method = null, ?string $paidOn = null): EmployeeFinalSettlementPayment
    {
        return DB::transaction(function () use ($settlement, $amount, $reference, $userId, $method, $paidOn) {
            $settlement = EmployeeFinalSettlement::whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            if (! in_array($settlement->status, ['approved', 'partially_paid'], true)) throw ValidationException::withMessages(['settlementPaymentAmount' => 'The settlement must be approved before payment.']);
            $remaining = round(max(0, (float) $settlement->net_amount - (float) $settlement->payments()->sum('amount')), 2);
            if ($amount <= 0 || $amount > $remaining) throw ValidationException::withMessages(['settlementPaymentAmount' => 'Payment must be positive and cannot exceed the remaining '.number_format($remaining, 2).'.']);
            $payment = $settlement->payments()->create(['amount' => $amount, 'paid_on' => $paidOn ?? today(), 'method' => $method, 'reference' => $reference ?: null, 'recorded_by' => $userId]);
            $settlement->update(['status' => $amount >= $remaining ? 'paid' : 'partially_paid']);
            app(AuditLogger::class)->record('employee.final_settlement_payment_recorded', $payment, ['settlement_id' => $settlement->id]);
            $settlement->lifecycleCase?->employee?->user?->notify(new ApprovalRequested('EmployeeFinalSettlementPayment', $payment->id, 'A final settlement payment of '.number_format($amount, 2).' has been recorded.'));

            return $payment;
        });
    }

    private function closeApproval(EmployeeFinalSettlement $settlement, int $userId, string $status, ?string $note = null): void
    {
        ApprovalRequest::where('approvable_type', EmployeeFinalSettlement::class)->where('approvable_id', $settlement->id)->where('status', 'pending')
            ->update(['approver_id' => $userId, 'status' => $status, 'decided_at' => now(), 'comment' => $note]);
    }

    private function isWorkday(string $date,array $calendar): bool
    {
        return \App\Support\WorkCalendar::isWorkday($date, $calendar);
    }
}
