<?php

namespace App\Services;

use App\Models\PayrollRun;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Anything that changes pay for a day (salary, overtime, penalty, leave,
 * attendance correction, adjustment) goes through here:
 *  - a day inside an approved or locked payroll run cannot change (HTTP 409);
 *  - a day inside a draft run marks that run "needs recalculation", and the
 *    run cannot be approved or locked until it is recalculated.
 */
class PayrollLockGuard
{
    public function assertOpen(?int $employeeId, string $from, string $what, ?string $to = null): void
    {
        $run = $this->runs($from, $to ?? $from)->whereIn('status', ['approved', 'locked'])
            ->when($employeeId, fn ($q) => $q->where(fn ($q) => $q->whereHas('items', fn ($q) => $q->where('employee_id', $employeeId))->orWhere('status', 'locked')))
            ->first();
        if ($run) {
            throw ValidationException::withMessages(['payroll' => "Payroll for {$run->period_starts_on->format('d M')}–{$run->period_ends_on->format('d M Y')} is {$run->status}; this {$what} change is not allowed."])->status(409);
        }
    }

    /** Asserts the period is open and flags draft runs covering it for recalculation. */
    public function changed(?int $employeeId, string $from, string $what, ?string $to = null): void
    {
        $this->assertOpen($employeeId, $from, $what, $to);
        $this->runs($from, $to ?? $from)->where('status', 'draft')->update(['needs_recalculation' => true]);
    }

    private function runs(string $from, string $to)
    {
        return PayrollRun::whereDate('period_starts_on', '<=', Carbon::parse($to)->toDateString())->whereDate('period_ends_on', '>=', Carbon::parse($from)->toDateString());
    }
}
