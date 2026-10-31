<?php

namespace App\Services;

use App\Models\AttendanceEmployee;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\SalaryAdjustment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A leave, attendance correction or overtime approved after its month's payroll
 * was approved or locked. That payroll (and anything already paid) stays as it
 * is; instead the period is priced again with today's data and the difference
 * is added to the employee's next payroll as an approved arrear (or recovered
 * as a deduction), linked to the payroll item it corrects.
 *
 * Only the attendance-driven part of pay is compared: days paid, overtime,
 * extra working and the short-hours deduction. Adjustments already carried for the same item are
 * subtracted, so approving several changes for one month carries each
 * difference once.
 */
class PayrollCarryOver
{
    public function __construct(private readonly HrWorkflowService $payroll, private readonly AuditLogger $audit) {}

    /** @return Collection<int, SalaryAdjustment> the arrears/recoveries created */
    public function settle(int $employeeId, string $from, string $to, string $what, ?int $actorId = null): Collection
    {
        $employee = AttendanceEmployee::findOrFail($employeeId);
        $items = PayrollItem::with('run')->where('employee_id', $employeeId)
            ->whereHas('run', fn ($q) => $q->whereIn('status', ['approved', 'locked'])->whereDate('period_starts_on', '<=', $to)->whereDate('period_ends_on', '>=', $from))
            ->get();
        $created = collect();
        foreach ($items as $item) {
            $run = $item->run;
            $now = $this->payroll->attendancePay($employee, $run->period_starts_on->toDateString(), $run->period_ends_on->toDateString());
            if ($now === null) continue;
            $breakdown = $item->calculation_breakdown ?? [];
            $paid = round((float) ($breakdown['base_salary'] ?? 0) + (float) $item->overtime_amount + (float) ($breakdown['extra_work']['amount'] ?? 0) + (float) ($breakdown['night_work']['amount'] ?? 0) - (float) ($breakdown['short_hours']['amount'] ?? 0), 2);
            $carried = (float) SalaryAdjustment::where('carried_from_payroll_item_id', $item->id)->where('status', 'approved')->get()->sum(fn (SalaryAdjustment $a) => $a->signedAmount());
            $difference = round($now['total'] - $paid - $carried, 2);
            if (abs($difference) < 0.01) continue;

            $period = $run->period_starts_on->format('d M').' – '.$run->period_ends_on->format('d M Y');
            $effectiveOn = $this->nextOpenDay($employeeId, $run);
            $adjustment = SalaryAdjustment::create([
                'employee_id' => $employeeId, 'effective_on' => $effectiveOn, 'amount' => abs($difference),
                'kind' => $difference > 0 ? 'arrear' : 'arrear_recovery', 'is_recurring' => false, 'status' => 'approved',
                'reason' => mb_substr("{$what} — approved after the payroll for {$period} was {$run->status}", 0, 255),
                'requested_by' => $actorId, 'decided_by' => $actorId, 'decided_at' => now(), 'carried_from_payroll_item_id' => $item->id,
            ]);
            // The payroll that will carry it (if already drafted) must be recalculated.
            PayrollRun::where('status', 'draft')->whereDate('period_starts_on', '<=', $effectiveOn)->whereDate('period_ends_on', '>=', $effectiveOn)->update(['needs_recalculation' => true]);
            $this->audit->record('payroll.carried_over', $adjustment, ['payroll_item_id' => $item->id, 'period' => $period, 'difference' => $difference, 'what' => $what]);
            $amount = number_format(abs($difference), 2);
            Notifier::send($employee->user, 'SalaryAdjustment', $adjustment->id, ($difference > 0 ? "₹{$amount} will be added to" : "₹{$amount} will be recovered from")." your next salary: {$what} (salary for {$period} was already processed).", route('payroll.salary', [], false));
            Notifier::send(Notifier::withPermission('payroll.manage'), 'SalaryAdjustment', $adjustment->id, "{$employee->name}: {$what} after the payroll for {$period} was {$run->status}. ".($difference > 0 ? 'Arrear' : 'Recovery')." of ₹{$amount} goes into the payroll from {$effectiveOn}.", route('payroll.salary', [], false), $actorId);
            $created->push($adjustment);
        }

        return $created;
    }

    /** The first day after every approved/locked payroll that covers this employee (at least the day after $run). */
    private function nextOpenDay(int $employeeId, PayrollRun $run): string
    {
        $lastClosed = PayrollRun::whereIn('status', ['approved', 'locked'])
            ->where(fn ($q) => $q->whereHas('items', fn ($i) => $i->where('employee_id', $employeeId))->orWhere('status', 'locked'))
            ->max('period_ends_on');
        $end = Carbon::parse($lastClosed ?? $run->period_ends_on)->max($run->period_ends_on);

        return $end->copy()->addDay()->toDateString();
    }
}
