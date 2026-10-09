<?php

namespace App\Services;

use App\Models\AttendanceEmployee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveAccrualService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function accrueThrough(int $year, int $month, int $actor): int
    {
        if ($month < 1 || $month > 12) throw ValidationException::withMessages(['month' => 'Month must be between 1 and 12.']);
        $updated = 0;
        DB::transaction(function () use ($year, $month, $actor, &$updated) {
            foreach (AttendanceEmployee::where('is_active', true)->get() as $employee) {
                foreach (LeaveType::where('is_active', true)->get() as $type) {
                    $balance = LeaveBalance::firstOrCreate(
                        ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $year],
                        ['entitled_days' => (float) $type->monthly_accrual > 0 ? 0 : $type->annual_allowance]
                    );
                    $balance = LeaveBalance::whereKey($balance->id)->lockForUpdate()->firstOrFail();
                    if ($balance->carried_expires_on && $balance->carried_expires_on->lt(Carbon::create($year, $month, 1))) {
                        $balance->carried_days = 0;
                        $balance->save();
                    }
                    $last = (int) ($balance->last_accrual_month ?? 0);
                    if ($last >= $month) continue;
                    $policy = app(PolicyConfigurationService::class)->forEmployee($employee, 'leave.accrual.' . $type->id, Carbon::create($year, $month, 1)->toDateString(), []);
                    $policy = array_replace($policy, app(PolicyConfigurationService::class)->forEmployee($employee, 'leave.accrual', Carbon::create($year, $month, 1)->toDateString(), []));
                    $monthly = max(0, (float) ($policy['days_per_month'] ?? $type->monthly_accrual));
                    if ($last === 0 && $type->carry_forward) {
                        $prior = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('year', $year - 1)->first();
                        if ($prior) {
                            $priorCarried = (float) $prior->carried_days;
                            if ($prior->carried_expires_on && $prior->carried_expires_on->lt(Carbon::create($year, 1, 1))) $priorCarried = 0;
                            $unused = max(0, (float) $prior->entitled_days + (float) $prior->accrued_days + (float) $prior->adjusted_days + $priorCarried - (float) $prior->used_days);
                            $limit = max(0, (float) ($policy['carry_forward_limit'] ?? $type->carry_forward_limit));
                            $balance->carried_days = min($unused, $limit);
                            $expiryMonths = $policy['carry_forward_expiry_months'] ?? $type->carry_forward_expiry_months;
                            $balance->carried_expires_on = $expiryMonths ? Carbon::create($year, 1, 1)->addMonthsNoOverflow((int) $expiryMonths)->endOfMonth()->toDateString() : null;
                        }
                    }
                    $balance->accrued_days = (float) $balance->accrued_days + $monthly * ($month - $last);
                    $balance->last_accrual_month = $month;
                    $balance->save();
                    $updated++;
                }
            }
            $this->audit->record('leave.monthly_accrual', null, ['year' => $year, 'month' => $month, 'balances_updated' => $updated, 'actor_id' => $actor]);
        });
        return $updated;
    }

    public function balance(AttendanceEmployee $employee, LeaveType $type, int $year, ?string $asOf = null): array
    {
        $record = LeaveBalance::firstOrNew(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $year], ['entitled_days' => (float) $type->monthly_accrual > 0 ? 0 : $type->annual_allowance]);
        $pending = (float) LeaveRequest::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('status', 'pending')->whereYear('starts_on', $year)->sum('days');
        $carried = (float) $record->carried_days;
        if ($record->carried_expires_on && $record->carried_expires_on->lt(Carbon::parse($asOf ?? today()->toDateString()))) $carried = 0;
        $total = (float) $record->entitled_days + $carried + (float) $record->accrued_days + (float) $record->adjusted_days;
        return ['entitled' => (float) $record->entitled_days, 'accrued' => (float) $record->accrued_days, 'adjusted' => (float) $record->adjusted_days, 'booked' => $pending, 'used' => (float) $record->used_days, 'carried' => (float) $carried, 'carried_expires_on' => $record->carried_expires_on?->toDateString(), 'remaining' => $total - (float) $record->used_days - $pending];
    }

}
