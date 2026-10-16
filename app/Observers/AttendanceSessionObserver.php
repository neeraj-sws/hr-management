<?php

namespace App\Observers;

use App\Models\AttendanceSession;
use App\Services\PayrollLockGuard;

/**
 * Keeps draft payroll in step with attendance: when a day's attendance is added,
 * removed, or its times change (check-in/out, import, auto-close), any draft
 * payroll run covering that day is marked "needs recalculation" so it never shows
 * stale present-days. Frequent desktop syncs (screen time, active seconds) do not
 * trigger it — only the fields that affect pay.
 */
class AttendanceSessionObserver
{
    public function created(AttendanceSession $session): void
    {
        $this->flag($session);
    }

    public function updated(AttendanceSession $session): void
    {
        if ($session->wasChanged(['check_in', 'check_out', 'work_date', 'status'])) $this->flag($session);
    }

    public function deleted(AttendanceSession $session): void
    {
        $this->flag($session);
    }

    private function flag(AttendanceSession $session): void
    {
        if (! $session->work_date) return;
        rescue(fn () => app(PayrollLockGuard::class)->flagDrafts($session->work_date->toDateString()), null, false);
    }
}
