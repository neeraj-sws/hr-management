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
    public const DEFAULTS = ['enabled' => true, 'absent_after' => '11:00'];

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
        $minutes = $this->attendance->lateMinutes($session);
        $late = \App\Support\Duration::fromMinutes($minutes);
        $at = $session->check_in->format('h:i A');
        Notifier::send($employee->user, 'AttendanceLate', $session->id, "You checked in late today at {$at} ({$late} after your start time).", route('attendance.my', [], false));
        Notifier::send($employee->manager?->user, 'AttendanceLate', $session->id, "{$employee->name} checked in late today at {$at} ({$late} after the start time).", route('attendance.dashboard', ['show' => 'late'], false), $employee->user_id);
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
}
