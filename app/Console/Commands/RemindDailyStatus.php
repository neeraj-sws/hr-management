<?php

namespace App\Console\Commands;

use App\Services\WorkService;
use Illuminate\Console\Command;

class RemindDailyStatus extends Command
{
    protected $signature = 'work:remind-daily-status {--date=} {--now : send even before the set reminder time}';
    protected $description = 'Remind employees who checked in today but have not submitted their daily status';

    public function handle(WorkService $work): int
    {
        // Runs every 5 minutes; nothing happens before the reminder time set by the admin (default 6:00 PM).
        if (! $this->option('date') && ! $this->option('now') && now()->format('H:i') < \App\Support\DailyStatusRule::reminderTime()) {
            $this->info('Before the reminder time ('.\App\Support\DailyStatusRule::reminderLabel().'): nothing sent.');

            return self::SUCCESS;
        }
        $this->info('Reminded '.$work->remindDailyStatus($this->option('date') ?: today()->toDateString()).' employee(s).');

        return self::SUCCESS;
    }
}
