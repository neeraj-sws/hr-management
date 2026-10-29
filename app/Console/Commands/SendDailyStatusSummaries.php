<?php

namespace App\Console\Commands;

use App\Models\AttendanceSession;
use App\Models\DailyWorkStatus;
use App\Models\TaskTimeEntry;
use App\Notifications\DailyStatusSummary;
use App\Services\AttendanceService;
use Illuminate\Console\Command;

/** End of the day: each daily status written today is e-mailed (with the day's timing) to the person and their manager. */
class SendDailyStatusSummaries extends Command
{
    protected $signature = 'work:daily-status-summary {--date= : day to send (default today)}';
    protected $description = 'E-mail today\'s daily statuses with task status and overall timing to each person and their manager';

    public function handle(AttendanceService $attendance): int
    {
        $date = $this->option('date') ?: today()->toDateString();
        $sent = 0;
        DailyWorkStatus::with(['employee.user', 'employee.manager.user'])->whereDate('work_date', $date)->get()->each(function (DailyWorkStatus $status) use (&$sent) {
            $sent += self::send($status);
        });
        $this->info("{$sent} summary e-mail(s) sent for {$date}.");

        return self::SUCCESS;
    }

    /**
     * E-mails one daily status (with that day's timing) to the person, their manager and the extra mailbox, as set in
     * Settings > E-mail. Also used at once for a missed day written later (the 8:30 PM run for that day is past).
     */
    public static function send(DailyWorkStatus $status): int
    {
        $attendance = app(AttendanceService::class);
        $date = $status->work_date->toDateString();
        $sent = 0;
        // Settings > E-mail: a mailbox that also gets every summary (e.g. swstask@gmail.com).
        $mailPolicy = app(\App\Services\PolicyConfigurationService::class)->forEmployee(null, 'notifications.email', $date, []);
        $extra = collect(preg_split('/[\s,;]+/', (string) ($mailPolicy['daily_summary_to'] ?? '')))
            ->filter(fn ($a) => filter_var($a, FILTER_VALIDATE_EMAIL))->unique()->values();
        // Settings > E-mail: whether the person and their manager get it too (both on unless turned off).
        $toPerson = (bool) ($mailPolicy['daily_summary_person'] ?? true);
        $toManager = (bool) ($mailPolicy['daily_summary_manager'] ?? true);
        $employee = $status->employee;
        if (! $employee) return 0;
        $sessions = AttendanceSession::where('employee_id', $employee->id)->whereDate('work_date', $date)->orderBy('check_in')->get();
        $timing = ['worked' => 0, 'break' => 0, 'idle' => 0, 'check_in' => $sessions->first()?->check_in?->format('h:i A'), 'check_out' => $sessions->isNotEmpty() && $sessions->every(fn ($s) => $s->check_out) ? $sessions->last()->check_out->format('h:i A') : null];
        foreach ($sessions as $session) {
            $f = rescue(fn () => $attendance->liveFigures($session), null, false);
            if (! $f) continue;
            $timing['worked'] += (int) $f['worked'];
            $timing['break'] += (int) $f['break'];
            $timing['idle'] += (int) $f['idle'];
        }
        $timing['task_seconds'] = (int) TaskTimeEntry::where('employee_id', $employee->id)->whereDate('started_at', $date)->get()
            ->sum(fn ($e) => $e->status === 'running' ? (int) $e->started_at->diffInSeconds(now()) : (int) $e->duration_seconds);
        foreach (collect([$toPerson ? $employee->user : null, $toManager ? $employee->manager?->user : null])->filter()->unique('id') as $user) {
            if (! ($user->is_active ?? true)) continue;
            rescue(fn () => $user->notify(new DailyStatusSummary($status, $timing)), null, true);
            $sent++;
        }
        foreach ($extra as $address) {
            if (collect([$toPerson ? $employee->user?->email : null, $toManager ? $employee->manager?->user?->email : null])->contains(fn ($e) => $e && strcasecmp($e, $address) === 0)) continue;
            rescue(fn () => (new \App\Support\MailRecipient($address))->notify(new DailyStatusSummary($status, $timing)), null, true);
            $sent++;
        }

        return $sent;
    }
}
