<?php

namespace App\Support;

use App\Models\AttendanceEmployee;
use App\Models\LeaveType;
use App\Services\AttendanceService;
use App\Services\PolicyConfigurationService;
use Illuminate\Support\Carbon;

/**
 * The rules every employee must know, in plain words. Employees use the mobile app and the desktop app (not the
 * website), so every step names where it is in those apps. Shown on the website (Company > Instructions) and the
 * mobile app (More > Instructions). Times and limits come from the current settings, so the page always
 * says what the system really does.
 */
class Instructions
{
    /** @return array<int, array{key: string, title: string, icon: string, items: array<int, array{0: string, 1: string}>}> */
    public static function sections(?AttendanceEmployee $employee = null): array
    {
        $today = today()->toDateString();
        $policies = app(PolicyConfigurationService::class);
        $attendance = app(AttendanceService::class);
        $hours = $policies->forEmployee($employee, 'work.hours', $today, []);
        $rules = $employee ? $attendance->rules($employee, $today) : [
            'work_start' => $hours['start_time'] ?? null,
            'grace_minutes' => (int) ($policies->forEmployee(null, 'attendance.rules', $today, [])['grace_minutes'] ?? 0),
            'half_day_seconds' => (int) round(((float) ($policies->forEmployee(null, 'attendance.rules', $today, [])['half_day_hours'] ?? 4)) * 3600),
        ];
        $start = $rules['work_start'] ? Carbon::parse(substr((string) $rules['work_start'], 0, 5))->format('g:i A') : 'your start time';
        $end = $employee && ($e = $attendance->expectedEnd($employee, $today)) ? $e->format('g:i A') : (isset($hours['end_time']) ? Carbon::parse(substr((string) $hours['end_time'], 0, 5))->format('g:i A') : 'your end time');
        $dailyHours = $employee ? $attendance->dailyHours($employee, $today) : (float) ($hours['standard_daily_hours'] ?? 8.5);
        $grace = (int) ($rules['grace_minutes'] ?? 0);
        $halfSeconds = (int) ($rules['half_day_seconds'] ?? 4 * 3600);
        $half = intdiv($halfSeconds, 3600).' h'.(($halfSeconds % 3600) ? ' '.intdiv($halfSeconds % 3600, 60).' min' : '');
        $payroll = $policies->forEmployee($employee, 'payroll.calculation', $today, []);
        $lateOn = (bool) ($payroll['late_checkin_hours_pay'] ?? true);
        $halfByHours = (bool) ($payroll['half_day_hours_only'] ?? true);
        $lateAfter = Carbon::parse((string) ($payroll['late_checkin_after'] ?? '12:00'))->format('g:i A');
        $idle = $policies->forEmployee($employee, 'attendance.idle', $today, []);
        $idleAfter = (int) ($idle['idle_after_minutes'] ?? 5);
        $askAfter = (int) ($idle['away_prompt_minutes'] ?? 10);
        $alerts = $policies->forEmployee(null, 'attendance.alerts', $today, []);
        $absentAfter = Carbon::parse((string) ($alerts['absent_after'] ?? '11:00'))->format('g:i A');
        $calendar = $policies->forEmployee($employee, 'calendar.working_days', $today, ['days' => [1, 2, 3, 4, 5]]);
        $week = WorkCalendar::describe($calendar);
        $advance = (float) ($policies->forEmployee($employee, 'leave.advance_limit', $today, ['max_days' => 5])['max_days'] ?? 5);
        $types = LeaveType::where('is_active', true)->orderByDesc('paid')->orderBy('name')->get()
            ->map(fn ($t) => $t->name.' ('.($t->paid ? 'paid' : 'unpaid').((float) $t->annual_allowance > 0 ? ', '.(float) $t->annual_allowance.' days a year' : '').')')->join(', ');
        $hoursText = rtrim(rtrim(number_format($dailyHours, 2), '0'), '.');
        $mailPolicy = $policies->forEmployee(null, 'notifications.email', $today, []);
        $summaryTo = (string) ($mailPolicy['daily_summary_to'] ?? '');
        $summaryWho = collect([($mailPolicy['daily_summary_person'] ?? true) ? 'you' : null, ($mailPolicy['daily_summary_manager'] ?? true) ? 'your manager' : null, $summaryTo ?: null])->filter()->values();
        $summaryWhoText = $summaryWho->count() > 1 ? $summaryWho->slice(0, -1)->join(', ').' and '.$summaryWho->last() : (string) $summaryWho->first();

        return [
            ['key' => 'attendance', 'title' => 'Daily attendance', 'icon' => 'clock', 'items' => [
                ['Working days', "{$week}. Holidays: mobile app > More > Holidays."],
                ['Working hours', "{$start} to {$end} — a working day is {$hoursText} hours."],
                ['Check in', "Check in as soon as you start work: on the SWS HR desktop app (office computer), or in the mobile app (Attendance tab). Late after {$start}".($grace ? " + {$grace} min grace" : '').'.'],
                ['Not checked in', "If you have not checked in by {$absentAfter}, your manager and HR get an alert (and you get a reminder). Check in, or apply for leave."],
                ['Check out', 'Check out when you finish (desktop app, or mobile app > Attendance). If you forget, the desktop app asks at the end of the shift and checks you out at the time the computer was last used.'],
                ['Every day counts', 'A working day with no check-in and no approved leave is Absent and is not paid.'],
            ]],
            ['key' => 'breaks', 'title' => 'Breaks, idle time and meetings', 'icon' => 'coffee', 'items' => [
                ['Breaks', 'Press Start break (desktop app, or mobile app > Attendance) when you leave your desk for lunch, tea or personal work, and Resume when you are back. Break time is not working time.'],
                ['Idle time', "No keyboard or mouse for {$idleAfter} minutes counts as idle. Idle time is only shown in reports: it does NOT reduce your salary and does NOT make a day a half day."],
                ['"Are you sitting idle?"', "After {$askAfter} minutes away the desktop app asks. If you were working away from the computer (phone call, meeting, discussion), choose \"No, I was working\" and write what you did."],
                ['Meetings', 'Teams, Zoom and Google Meet calls are counted as meeting time (working), automatically.'],
            ]],
            ['key' => 'half-day', 'title' => 'Half day, late check-in and pay for the day', 'icon' => 'alert', 'items' => [
                ['Time at work', 'Check-out time − check-in time − breaks taken. Idle time and meetings are not taken off.'],
                ['Half day', $halfByHours
                    ? "Less than {$half} at work in a day = Half day: you are paid ONLY for the time you were at work (e.g. 3 h at work = 3 hours' pay). {$half} or more is a full day."
                    : "Less than {$half} at work in a day = Half day: ½ day's salary. {$half} or more is a full day."],
                ['Late check-in', $lateOn ? "Checking in after {$lateAfter}: that day is paid only for the hours at work (hours at work ÷ {$hoursText}, at most one day). Example: in at 1:30 PM, 6 h at work = ".rtrim(rtrim(number_format(6 / max(1, $dailyHours), 2), '0'), '.').' day.' : 'Coming late is marked as Late on your attendance.'],
                ['Planned half day', 'If you know you will work only half a day, apply for a half-day leave in advance (mobile app > Leave), so the day is not marked Absent or Late. Even when the leave is approved, only the hours you actually work that day are paid.'],
                ['Festival half day', 'On a half-day holiday the office closes early: coming in = full day; not coming = ½ day (when the holiday is paid).'],
            ]],
            ['key' => 'leave', 'title' => 'Leave', 'icon' => 'calendar', 'items' => [
                ['Leave types', $types ?: 'Ask HR which leave types apply to you.'],
                ['Apply in advance', 'Apply in the mobile app (Leave tab) before you take leave. Your manager approves it; you get a notification.'],
                ['Emergency', 'If you cannot apply in advance (illness, emergency), tell your manager the same day and apply within 1–2 days of coming back.'],
                ['Half day / short leave', 'Choose Half day or Short leave when applying. They are for one day.'],
                ['Balance', 'Your balance is in the mobile app > Leave tab. '.($advance > 0 ? "Up to {$advance} days of paid leave can be taken in advance." : 'Paid leave cannot be taken beyond your balance.')],
                ['No leave applied', 'Absent with no leave = not paid. HR can record the day as leave only if you informed them.'],
            ]],
            ['key' => 'salary', 'title' => 'Salary and payslips', 'icon' => 'wallet', 'items' => [
                ['How it is worked out', "Monthly salary ÷ the month's working days = one day's salary. You are paid for: days present + approved paid leave + paid holidays. ".($halfByHours ? 'Half days and late check-ins are paid by the hours at work.' : 'Half days are paid ½, late check-ins by hours.')],
                ['Why less than full', "Mobile app > More > My salary: open a month to see \"Why the salary is less than full\" — every day not paid, with its date and amount."],
                ['This month so far', 'My salary also shows this month so far. It is final only after the month ends and payroll is approved.'],
                ['Payslip', 'After salary is paid, your payslip is in the mobile app > More > Payslips (or My salary > open the month > Download payslip).'],
                ['Advances and claims', 'Salary advances are recovered in monthly instalments. Approved expense claims (with bills) are paid with the salary.'],
                ['Questions', 'If something looks wrong, ask HR within 5 days of the payslip. A correction for a paid month is made in the next month as an arrear.'],
            ]],
            ['key' => 'work', 'title' => 'Daily status and tasks', 'icon' => 'edit', 'items' => [
                ['Daily status', 'Fill in your daily status every working day before you leave (plan, done, blockers): mobile app > More > Daily status. At '.\App\Support\DailyStatusRule::reminderLabel().' a reminder comes on your phone if it is not filled in.'],
                ['End-of-day e-mail', 'At 8:30 PM a summary of your day (what you planned, finished, task times, check-in, check-out, breaks) is e-mailed to '.($summaryWhoText ?: 'HR').'.'],
                ['Tasks', 'Mobile app > Tasks tab: start the timer on the task you work on, and update its status. Overdue tasks are reminded at 9:45 AM.'],
            ]],
            ['key' => 'emails', 'title' => 'E-mails and notifications', 'icon' => 'mail', 'items' => [
                ['Your e-mail', 'Your e-mail address must be correct (ask HR to change it): sign-in codes, password reset, leave and salary messages go there.'],
                ['Sign-in code', 'If 2-step sign-in is on, a 6-digit code is e-mailed at every sign-in. Never share it with anyone, including HR.'],
                ['What you get', 'Leave approved / rejected, attendance corrections, salary paid, daily status summary, birthday and holiday announcements.'],
                ['Not received?', 'Check Spam / Promotions and mark SWS HR mails as "Not spam".'],
                ['Phone notifications', 'Allow notifications for the SWS HR app on your phone (phone Settings > Apps > SWS HR > Notifications), so reminders and approvals reach you.'],
                ['All notifications', 'Mobile app > More > Notifications (or the bell on the Home screen) lists everything sent to you.'],
            ]],
            ['key' => 'apps', 'title' => 'Mobile app and desktop app', 'icon' => 'monitor', 'items' => [
                ['Desktop app', 'Office computer: keep the SWS HR desktop app running while you work. Do not close it; it shows when screen capture is on.'],
                ['Mobile app', 'For check-in on the move, breaks, leave, tasks, daily status, salary, payslips, documents and notifications. Install updates when HR shares a new version (install over the old app; your sign-in stays).'],
                ['Profile photo', 'Change your photo in the mobile app: More > Change photo (up to 5 MB).'],
            ]],
            ['key' => 'corrections', 'title' => 'Mistakes and corrections', 'icon' => 'search', 'items' => [
                ['Wrong attendance', 'Forgot to check in/out or wrong time: mobile app > More > Correction, for that date with the reason. Your manager approves it.'],
                ['When', 'Raise corrections in the same week, and before the 2nd of next month — after that the month is closed for payroll.'],
                ['Proof', 'Every day is in the mobile app > Attendance tab: check-in, check-out, breaks and the day\'s status.'],
            ]],
            ['key' => 'security', 'title' => 'Privacy, monitoring and security', 'icon' => 'lock', 'items' => [
                ['Your login', 'Do not share your password or sign-in code. Lock your phone and your office computer when you leave them.'],
                ['Monitoring', 'The desktop app records screen time and, if turned on, screenshots (it shows a red "Screen capture ON" sign then). Only your manager and HR can see them. Keystrokes, passwords, webcam and microphone are never recorded.'],
                ['Company data', 'Do not copy company data, client data or passwords to personal accounts or devices.'],
            ]],
        ];
    }
}
