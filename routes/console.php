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
Schedule::command('work:remind-daily-status')->weekdays()->dailyAt('18:15')->withoutOverlapping();
// Before-shift and forgot-to-check-out reminders (each sent once per person per day).
Schedule::command('attendance:reminders')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('attendance:absence-alerts')->everyFifteenMinutes()->between('09:00', '20:00')->withoutOverlapping();
Schedule::command('work:reconcile-task-hours --fix')->dailyAt('01:30')->withoutOverlapping();
Schedule::command('sheets:sync')->everyThirtyMinutes()->withoutOverlapping();
Schedule::command('hr:weekly-off-reminders')->dailyAt('17:00')->withoutOverlapping();
