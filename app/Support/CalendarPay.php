<?php

namespace App\Support;

use App\Models\AttendanceEmployee;
use App\Models\EmployeePenalty;
use App\Models\PayrollItem;
use App\Services\HrWorkflowService;
use Illuminate\Support\Carbon;

/**
 * What the attendance calendar shows about pay for each day of a month, by the same rules payroll uses:
 * short time cut that day (minutes under the working day x per-minute rate), the day's fines, and days not
 * paid because of "Pay only for project days". A month whose payroll is approved or locked shows what that
 * payroll stored; any other month is worked out as payroll would now. Trainees get no salary: nothing.
 */
class CalendarPay
{
    /** @return array{short: array<string, array{seconds: int, amount: float}>, fines: array<string, list<array{amount: float, pending: bool, reason: string}>>, not_paid: array<string, true>} */
    public static function month(?AttendanceEmployee $employee, Carbon $month): array
    {
        $empty = ['short' => [], 'fines' => [], 'not_paid' => []];
        if (! $employee || $employee->isTrainee()) return $empty;
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        return rescue(function () use ($employee, $start, $end, $empty) {
            $out = $empty;
            $item = PayrollItem::with('run')->where('employee_id', $employee->id)
                ->whereHas('run', fn ($q) => $q->whereDate('period_starts_on', '<=', $end)->whereDate('period_ends_on', '>=', $start))->latest('id')->first();
            if ($item && $item->run && $item->run->status !== 'draft') {
                $b = (array) $item->calculation_breakdown; // as approved / paid
            } else {
                $to = $end->copy()->min(today());
                $b = $to->lt($start) ? [] : (array) (app(HrWorkflowService::class)->payEstimate($employee, $start->toDateString(), $to->toDateString())['breakdown'] ?? []);
            }
            foreach ((array) ($b['short_hours']['days'] ?? []) as $date => $d) $out['short'][$date] = ['seconds' => (int) $d['short'], 'amount' => round((float) $d['amount'], 2)];
            foreach ((array) ($b['project_days']['not_paid'] ?? []) as $date) $out['not_paid'][$date] = true;
            foreach (EmployeePenalty::where('employee_id', $employee->id)->whereIn('status', ['approved', 'pending'])->whereBetween('effective_on', [$start->toDateString(), $end->toDateString()])->orderBy('id')->get() as $p) {
                if (method_exists($p, 'isPaidBack') && $p->isPaidBack()) continue;
                $out['fines'][$p->effective_on->toDateString()][] = ['amount' => round((float) $p->amount, 2), 'pending' => $p->status === 'pending', 'reason' => (string) $p->reason];
            }

            return $out;
        }, $empty, false);
    }
}
