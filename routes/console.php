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
Schedule::command('compliance:assess')->dailyAt('03:00')->withoutOverlapping();
Schedule::command('work:notify-overdue-tasks')->dailyAt('09:45')->withoutOverlapping();
Schedule::command('sanctum:prune-expired --hours=24')->dailyAt('04:00');
Schedule::command('work:remind-daily-status')->weekdays()->dailyAt('18:15')->withoutOverlapping();
