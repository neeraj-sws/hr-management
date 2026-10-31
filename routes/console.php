<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('leave:accrue')->monthlyOn(1, '00:15')->withoutOverlapping();
Schedule::command('hr:celebrations')->dailyAt('09:00')->withoutOverlapping();
Schedule::command('attendance:close-stale')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('monitoring:purge-screenshots')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('monitoring:notify-capture')->hourly()->withoutOverlapping();
Schedule::command('monitoring:expire-capture')->dailyAt('00:10')->withoutOverlapping();
Schedule::command('compliance:assess')->dailyAt('03:00')->withoutOverlapping();
Schedule::command('work:notify-overdue-tasks')->dailyAt('09:45')->withoutOverlapping();
Schedule::command('sanctum:prune-expired --hours=24')->dailyAt('04:00');
// Every day at the time set in Work > Daily status > Who must write it (default 6:00 PM): the command checks
// every 5 minutes and sends once the time has come. Only people who checked in that day, have not written it
// and are "Compulsory + reminder" are reminded, once a day, so a working Saturday is covered and a day off is not.
Schedule::command('work:remind-daily-status')->everyFiveMinutes()->withoutOverlapping();
// End of the day: each daily status written today goes by e-mail (task status and timing) to the person and their manager.
Schedule::command('work:daily-status-summary')->dailyAt('20:30')->withoutOverlapping();
// Each person's daily status deadline (shift end + grace): missing ones written from tasks, late-written ones mailed.
Schedule::command('work:daily-status-deadline')->everyTenMinutes()->withoutOverlapping();
// Before-shift and forgot-to-check-out reminders (each sent once per person per day).
Schedule::command('attendance:reminders')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('attendance:absence-alerts')->everyFifteenMinutes()->between('09:00', '20:00')->withoutOverlapping();
Schedule::command('work:reconcile-task-hours --fix')->dailyAt('01:30')->withoutOverlapping();
Schedule::command('sheets:sync')->everyThirtyMinutes()->withoutOverlapping();
// Telegram messages wait in the "telegram" queue: sent every minute by a short worker run (no background process
// needed, so it works on shared hosting with the normal scheduler cron).
if (config('services.telegram.queue_connection', 'database') !== 'sync') {
    Schedule::command('queue:work '.config('services.telegram.queue_connection', 'database').' --queue=telegram --stop-when-empty --max-time=50 --tries=4')->everyMinute()->withoutOverlapping(5);
}
// Undecided leave: daily reminder to the approver (from 10:00), HR / admins after 24 hours, urgent on the leave day.
Schedule::command('leave:pending-reminders')->hourly()->between('09:00', '21:00')->withoutOverlapping();
// Other requests still waiting: Telegram list to the Super Admin and to each manager.
Schedule::command('approvals:pending-reminders')->hourly()->between('09:00', '21:00')->withoutOverlapping();
Schedule::command('hr:weekly-off-reminders')->dailyAt('17:00')->withoutOverlapping();
