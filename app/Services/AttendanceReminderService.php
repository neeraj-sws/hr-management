<?php

namespace App\Services;

use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\LeaveRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Phone / in-app reminders around the working day:
 *  - shortly before the shift starts, for people due in who have not checked in yet;
 *  - after the shift has ended, for people who are still checked in (forgot to check out).
 * Each reminder goes out once per person per day.
 */
class AttendanceReminderService
{
    /** Minutes before the shift start the reminder goes out. */
    public const BEFORE_SHIFT_MINUTES = 15;
    /** Minutes after the shift end before "still checked in?" is sent. */
    public const AFTER_SHIFT_MINUTES = 30;

    public function __construct(private readonly AttendanceService $attendance) {}

    /** Reminds people whose shift starts within the next 15 minutes. Returns how many were reminded. */
    public function beforeShift(?Carbon $now = null): int
    {
        $now ??= now();
        $date = $now->toDateString();
        $checkedIn = AttendanceSession::whereDate('work_date', $date)->whereNotNull('check_in')->pluck('employee_id')->all();
        $onLeave = LeaveRequest::where('status', 'approved')->where('duration', 'full')->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->pluck('employee_id')->all();
        $sent = 0;
        foreach (AttendanceEmployee::with('user')->where('is_active', true)->whereNotNull('user_id')->whereNotIn('id', [...$checkedIn, ...$onLeave])->get() as $employee) {
            if (! $this->attendance->isScheduledDay($employee, $date)) continue;
            $start = $this->attendance->rules($employee, $date)['work_start'] ?? null;
            if (! $start) continue;
            $startsAt = Carbon::parse($date.' '.$start);
            $minutes = $now->diffInMinutes($startsAt, false);
            if ($minutes < 0 || $minutes > self::BEFORE_SHIFT_MINUTES) continue;
            if (! Cache::add("reminder:shift:{$employee->id}:{$date}", true, now()->addDay())) continue;
            $sent += Notifier::send($employee->user, 'ShiftReminder', $employee->id,
                'Your shift starts at '.$startsAt->format('h:i A').'. Remember to check in.', route('attendance.my', [], false));
        }

        return $sent;
    }

    /** Reminds people still checked in 30 minutes (or more) after their shift ended. Returns how many were reminded. */
    public function forgotCheckout(?Carbon $now = null): int
    {
        $now ??= now();
        $sent = 0;
        $open = AttendanceSession::with('employee.user')->whereNotNull('check_in')->whereNull('check_out')
            ->whereIn('status', ['working', 'on_break'])->whereDate('work_date', '>=', $now->copy()->subDay()->toDateString())->get();
        foreach ($open as $session) {
            $employee = $session->employee;
            if (! $employee?->user) continue;
            $end = $this->attendance->expectedEnd($employee, $session->work_date->toDateString());
            if (! $end || $now->lt($end->copy()->addMinutes(self::AFTER_SHIFT_MINUTES))) continue;
            if (! Cache::add("reminder:checkout:{$session->id}", true, now()->addDays(2))) continue;
            $sent += Notifier::send($employee->user, 'CheckoutReminder', $session->id,
                'You are still checked in (since '.$session->check_in->format('h:i A').'). Your day ended at '.$end->format('h:i A').' — check out if you have finished.',
                route('attendance.my', [], false));
        }

        return $sent;
    }
}
