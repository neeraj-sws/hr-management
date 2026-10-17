<?php

namespace App\Services;

use App\Models\PayrollItem;
use App\Models\PayrollRun;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Anything that changes pay for a day (salary, overtime, penalty, leave,
 * attendance correction, adjustment) goes through here:
 *  - a day inside an approved or locked payroll run cannot change (HTTP 409);
 *  - a day inside a draft run marks that run "needs recalculation", and the
 *    run cannot be approved or locked until it is recalculated.
 * Leave, attendance corrections and overtime use changedOrCarry() instead: the
 * employee's request is still honoured after the payroll closed, and the pay
 * difference goes into the next payroll (PayrollCarryOver).
 */
class PayrollLockGuard
{
    public function assertOpen(?int $employeeId, string $from, string $what, ?string $to = null): void
    {
        $run = $this->runs($from, $to ?? $from)->whereIn('status', ['approved', 'locked'])
            ->when($employeeId, fn ($q) => $q->where(fn ($q) => $q->whereHas('items', fn ($q) => $q->where('employee_id', $employeeId))->orWhere('status', 'locked')))
            ->first();
        if ($run) {
            throw ValidationException::withMessages(['payroll' => "Payroll for {$run->period_starts_on->format('d M')}–{$run->period_ends_on->format('d M Y')} is {$run->status}; this {$what} change is not allowed. If the payroll was ".($run->status === 'locked' ? 'locked' : 'approved').' by mistake, an administrator can reopen it under Payroll (when no salary payment is recorded yet).'])->status(409);
        }
    }

    /** Asserts the period is open and flags draft runs covering it for recalculation. */
    public function changed(?int $employeeId, string $from, string $what, ?string $to = null): void
    {
        $this->assertOpen($employeeId, $from, $what, $to);
        $this->runs($from, $to ?? $from)->where('status', 'draft')->update(['needs_recalculation' => true]);
    }

    /**
     * Marks draft payroll runs covering [from, to] as needing recalculation, without
     * blocking. Used when attendance for a day changes (check-in/out, import), so a
     * draft run never shows stale present-days until it is recalculated.
     */
    public function flagDrafts(string $from, ?string $to = null): void
    {
        $this->runs($from, $to ?? $from)->where('status', 'draft')->update(['needs_recalculation' => true]);
    }

    /**
     * Like changed(), but a day inside an approved or locked payroll does not block:
     * returns true when the caller must, after applying the change, call
     * PayrollCarryOver::settle() so the difference reaches the next payroll.
     * Items calculated before pay breakdowns were stored cannot be re-priced, so
     * those periods still block (an administrator can reopen them).
     */
    public function changedOrCarry(int $employeeId, string $from, string $what, ?string $to = null): bool
    {
        $to ??= $from;
        $closedRuns = $this->runs($from, $to)->whereIn('status', ['approved', 'locked'])->pluck('id');
        $items = $closedRuns->isEmpty() ? collect() : PayrollItem::whereIn('payroll_run_id', $closedRuns)->where('employee_id', $employeeId)->get();
        if ($items->contains(fn (PayrollItem $item) => ! isset($item->calculation_breakdown['base_salary']))) $this->assertOpen($employeeId, $from, $what, $to);
        $this->runs($from, $to)->where('status', 'draft')->update(['needs_recalculation' => true]);

        return $items->isNotEmpty();
    }

    /** Shown to the employee before they ask: "salary for this period is already processed…", or null. */
    public function closedNotice(int $employeeId, string $from, ?string $to = null): ?string
    {
        $run = $this->runs($from, $to ?? $from)->whereIn('status', ['approved', 'locked'])->whereHas('items', fn ($q) => $q->where('employee_id', $employeeId))->orderBy('period_starts_on')->first();

        return $run ? 'Salary for '.$run->period_starts_on->format('d M').' – '.$run->period_ends_on->format('d M Y').' is already processed. If this is approved, the difference is added to (or recovered from) your next salary.' : null;
    }

    private function runs(string $from, string $to)
    {
        return PayrollRun::whereDate('period_starts_on', '<=', Carbon::parse($to)->toDateString())->whereDate('period_ends_on', '>=', Carbon::parse($from)->toDateString());
    }
}
