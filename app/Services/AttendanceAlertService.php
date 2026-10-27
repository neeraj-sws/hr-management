<?php

namespace App\Services;

use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\LeaveRequest;
use App\Support\WorkCalendar;
use Illuminate\Support\Carbon;

/**
 * Late and absence alerts (Settings > Work hours & attendance):
 *  - a late check-in tells the employee and their manager at once;
 *  - by the "absent after" time, everyone due at work who has not checked in
 *    (not on approved leave, not a weekly off/holiday) is told, as is their
 *    manager, and HR gets one summary. Each alert is sent once per person per day.
 */
class AttendanceAlertService
{
    /** break_alerts: manager told when a break starts and ends · long_break_minutes: break this long → alert (0 = off). */
    public const DEFAULTS = ['enabled' => true, 'absent_after' => '11:00', 'break_alerts' => true, 'long_break_minutes' => 60];

    public function __construct(private readonly PolicyConfigurationService $policies, private readonly AttendanceService $attendance) {}

    public function settings(): array
    {
        return [...self::DEFAULTS, ...$this->policies->forEmployee(null, 'attendance.alerts', today()->toDateString(), [])];
    }

    public function lateCheckIn(AttendanceSession $session): void
    {
        if (! ($this->settings()['enabled'] ?? true)) return;
        $session->loadMissing('employee.user', 'employee.manager.user');
        $employee = $session->employee;
        if (! $employee || ! $this->attendance->isLate($session)) return;
        // Late means at least one whole minute after start time + grace. A few seconds over is not late,
        // so no "0 min late" alert (the attendance record itself is not changed here).
        $minutes = $this->attendance->lateMinutes($session);
        if ($minutes < 1) return;
        $late = \App\Support\Duration::words($minutes);
        $at = $session->check_in->format('h:i A');
        Notifier::send($employee->user, 'AttendanceLate', $session->id, "You checked in late at {$at} · {$late} late.", route('attendance.my', [], false));
        Notifier::send($employee->manager?->user, 'AttendanceLate', $session->id, "{$employee->name} checked in late at {$at} · {$late} late.", route('attendance.dashboard', ['show' => 'late'], false), $employee->user_id);
    }

    /** @return int employees alerted as absent */
    public function absences(?Carbon $now = null): int
    {
        $now ??= now();
        $settings = $this->settings();
        if (! ($settings['enabled'] ?? true)) return 0;
        $cutoff = Carbon::parse($now->toDateString().' '.($settings['absent_after'] ?? '11:00'));
        if ($now->lt($cutoff)) return 0;
        $date = $now->toDateString();

        $checkedIn = AttendanceSession::whereDate('work_date', $date)->pluck('employee_id');
        $onLeave = LeaveRequest::where('status', 'approved')->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->pluck('employee_id');
        $absent = AttendanceEmployee::with(['user', 'manager.user'])->where('is_active', true)->whereNotNull('user_id')
            ->whereNotIn('id', $checkedIn->merge($onLeave)->all() ?: [0])
            ->where(fn ($q) => $q->whereNull('joined_at')->orWhereDate('joined_at', '<=', $date))
            ->get()
            ->filter(fn (AttendanceEmployee $e) => WorkCalendar::isWorkday($date, $this->policies->forEmployee($e, 'calendar.working_days', $date, ['days' => [1, 2, 3, 4, 5], 'holidays' => []])))
            ->reject(fn (AttendanceEmployee $e) => ! $e->user || $e->user->notifications()->where('data->kind', 'AttendanceAbsent')->where('data->record_id', $e->id)->whereDate('created_at', $date)->exists());

        foreach ($absent as $employee) {
            Notifier::send($employee->user, 'AttendanceAbsent', $employee->id, 'You have not checked in today ('.$now->format('d M').'). You will be marked absent unless you check in or apply for leave.', route('attendance.my', [], false));
            Notifier::send($employee->manager?->user, 'AttendanceAbsent', $employee->id, "{$employee->name} has not checked in today (".$now->format('d M').') and is not on approved leave.', route('attendance.dashboard', ['show' => 'absent'], false), $employee->user_id);
        }
        if ($absent->isNotEmpty()) {
            $names = $absent->pluck('name')->take(8)->join(', ').($absent->count() > 8 ? ' and '.($absent->count() - 8).' more' : '');
            Notifier::send(Notifier::withPermission('attendance.correct'), 'AttendanceAbsent', 0, $absent->count().' employee(s) have not checked in today ('.$now->format('d M')."): {$names}.", route('attendance.dashboard', ['show' => 'absent'], false));
        }

        return $absent->count();
    }

    /** Break started: the manager gets a bell notification and a phone push (no e-mail). */
    public function breakStarted(AttendanceEmployee $employee, AttendanceSession $session, Carbon|\Carbon\CarbonInterface $at): void
    {
        if (! ($this->settings()['break_alerts'] ?? true)) return;
        $this->toManager($employee, $at, 'AttendanceBreak', $session->id, "{$employee->name} started a break at {$at->format('h:i A')}.");
    }

    /** Back from break: the manager is told when, and how long the break was. */
    public function breakEnded(AttendanceEmployee $employee, AttendanceSession $session, Carbon|\Carbon\CarbonInterface $at, int $seconds): void
    {
        if (! ($this->settings()['break_alerts'] ?? true)) return;
        $this->toManager($employee, $at, 'AttendanceBreak', $session->id, "{$employee->name} is back from break at {$at->format('h:i A')} · break of ".\App\Support\Duration::words(intdiv($seconds, 60)).'.');
    }

    /**
     * Breaks running longer than the set minutes: the employee (press Resume) and their manager are told, once per break.
     * Runs every 5 minutes with the attendance reminders. @return int breaks alerted
     */
    public function longBreaks(?Carbon $now = null): int
    {
        $now ??= now();
        $minutes = (int) ($this->settings()['long_break_minutes'] ?? 60);
        if ($minutes <= 0) return 0;
        $sent = 0;
        $sessions = AttendanceSession::with(['employee.user', 'employee.manager.user'])->where('status', 'on_break')->whereNull('check_out')
            ->whereDate('work_date', '>=', $now->copy()->subDay()->toDateString())->get();
        foreach ($sessions as $session) {
            $employee = $session->employee;
            $start = $session->events()->where('event_type', 'break_start')->latest('event_time')->first();
            if (! $employee || ! $start || $start->event_time->gt($now->copy()->subMinutes($minutes))) continue;
            // One alert per break: the break_start event's id is the record id.
            if (\Illuminate\Notifications\DatabaseNotification::where('data->kind', 'AttendanceLongBreak')->where('data->record_id', $start->id)->exists()) continue;
            $long = \App\Support\Duration::words((int) $start->event_time->diffInMinutes($now));
            $since = $start->event_time->format('h:i A');
            if ($employee->user) rescue(fn () => $employee->user->notify(new \App\Notifications\ApprovalRequested('AttendanceLongBreak', $start->id, "Your break started at {$since} and has run {$long}. Press Resume when you are back at work.", route('attendance.my', [], false), false)), null, true);
            $this->toManager($employee, $now, 'AttendanceLongBreak', $start->id, "{$employee->name} has been on break for {$long} (since {$since}).", false);
            $sent++;
        }

        return $sent;
    }

    /** Bell + push to the person's manager, never e-mail; a break sent in late by an offline phone (over 15 min ago) is not announced. */
    private function toManager(AttendanceEmployee $employee, \Carbon\CarbonInterface $at, string $kind, int $recordId, string $message, bool $checkAge = true): void
    {
        $manager = $employee->manager?->user;
        if (! $manager || ! ($manager->is_active ?? true) || $manager->id === $employee->user_id) return;
        if ($checkAge && $at->lt(now()->subMinutes(15))) return;
        rescue(fn () => $manager->notify(new \App\Notifications\ApprovalRequested($kind, $recordId, $message, route('attendance.dashboard', [], false), false)), null, true);
    }
}
