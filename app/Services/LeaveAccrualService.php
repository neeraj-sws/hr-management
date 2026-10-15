<?php

namespace App\Services;

use App\Models\AttendanceEmployee;
use App\Models\LeaveBalance;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Support\LeaveYear;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveAccrualService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Applies monthly accruals up to and including a calendar month. The month is
     * placed in its leave year (e.g. April–March), so October is month 7 of an
     * April leave year. Carry-forward from the previous leave year is applied on
     * the first accrual of a leave year. Every change is written to the leave ledger.
     */
    public function accrueThrough(int $year, int $month, int $actor): int
    {
        if ($month < 1 || $month > 12) throw ValidationException::withMessages(['month' => 'Month must be between 1 and 12.']);
        $startMonth = LeaveYear::startMonth();
        $leaveYear = LeaveYear::of(Carbon::create($year, $month, 1), $startMonth);
        $index = LeaveYear::monthIndex($month, $startMonth);
        $yearStart = LeaveYear::start($leaveYear, $startMonth);
        $updated = 0;
        DB::transaction(function () use ($year, $month, $actor, $leaveYear, $index, $yearStart, &$updated) {
            foreach (AttendanceEmployee::where('is_active', true)->get() as $employee) {
                foreach (LeaveType::where('is_active', true)->get() as $type) {
                    $balance = LeaveBalance::open($employee->id, $type, $leaveYear);
                    $balance = LeaveBalance::whereKey($balance->id)->lockForUpdate()->firstOrFail();
                    if ((float) $balance->carried_days > 0 && $balance->carried_expires_on && $balance->carried_expires_on->lt(Carbon::create($year, $month, 1))) {
                        LeaveLedgerEntry::record($balance, 'expiry', -(float) $balance->carried_days, 'Carried-forward leave expired on '.$balance->carried_expires_on->format('d M Y'), null, $actor ?: null);
                        $balance->carried_days = 0;
                        $balance->save();
                    }
                    $last = (int) ($balance->last_accrual_month ?? 0);
                    if ($last >= $index) continue;
                    $asOf = Carbon::create($year, $month, 1)->toDateString();
                    $policy = app(PolicyConfigurationService::class)->forEmployee($employee, 'leave.accrual.' . $type->id, $asOf, []);
                    $policy = array_replace($policy, app(PolicyConfigurationService::class)->forEmployee($employee, 'leave.accrual', $asOf, []));
                    $monthly = max(0, (float) ($policy['days_per_month'] ?? $type->monthly_accrual));
                    if ($last === 0 && $type->carry_forward) {
                        $prior = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('year', $leaveYear - 1)->first();
                        if ($prior) {
                            $priorCarried = (float) $prior->carried_days;
                            if ($prior->carried_expires_on && $prior->carried_expires_on->lt($yearStart)) $priorCarried = 0;
                            $unused = max(0, (float) $prior->entitled_days + (float) $prior->accrued_days + (float) $prior->adjusted_days + $priorCarried - (float) $prior->used_days);
                            $limit = max(0, (float) ($policy['carry_forward_limit'] ?? $type->carry_forward_limit));
                            $balance->carried_days = min($unused, $limit);
                            $expiryMonths = $policy['carry_forward_expiry_months'] ?? $type->carry_forward_expiry_months;
                            $balance->carried_expires_on = $expiryMonths ? $yearStart->copy()->addMonthsNoOverflow((int) $expiryMonths)->endOfMonth()->toDateString() : null;
                            LeaveLedgerEntry::record($balance, 'carry_forward', (float) $balance->carried_days, 'Unused '.LeaveYear::label($leaveYear - 1).' leave carried forward (limit '.rtrim(rtrim(number_format($limit, 2), '0'), '.').')', null, $actor ?: null);
                        }
                    }
                    $accrued = $monthly * ($index - $last);
                    $balance->accrued_days = (float) $balance->accrued_days + $accrued;
                    $balance->last_accrual_month = $index;
                    $balance->save();
                    LeaveLedgerEntry::record($balance, 'accrual', $accrued, 'Accrual through '.Carbon::create($year, $month, 1)->format('M Y'), null, $actor ?: null);
                    $updated++;
                }
            }
            $this->audit->record('leave.monthly_accrual', null, ['year' => $year, 'month' => $month, 'leave_year' => $leaveYear, 'balances_updated' => $updated, 'actor_id' => $actor]);
        });
        return $updated;
    }

    public function balance(AttendanceEmployee $employee, LeaveType $type, int $year, ?string $asOf = null): array
    {
        $record = LeaveBalance::firstOrNew(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $year], ['entitled_days' => (float) $type->monthly_accrual > 0 ? 0 : $type->annual_allowance]);
        $pending = (float) LeaveRequest::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('status', 'pending')->whereBetween('starts_on', [LeaveYear::start($year, $startMonth = LeaveYear::startMonth())->toDateString(), LeaveYear::end($year, $startMonth)->toDateString()])->sum('days');
        $carried = (float) $record->carried_days;
        if ($record->carried_expires_on && $record->carried_expires_on->lt(Carbon::parse($asOf ?? today()->toDateString()))) $carried = 0;
        $total = (float) $record->entitled_days + $carried + (float) $record->accrued_days + (float) $record->adjusted_days;
        return ['year' => $year, 'year_label' => LeaveYear::label($year, $startMonth), 'entitled' => (float) $record->entitled_days, 'accrued' => (float) $record->accrued_days, 'adjusted' => (float) $record->adjusted_days, 'booked' => $pending, 'used' => (float) $record->used_days, 'carried' => (float) $carried, 'carried_expires_on' => $record->carried_expires_on?->toDateString(), 'remaining' => $total - (float) $record->used_days - $pending];
    }

}
