<?php

namespace App\Console\Commands;

use App\Services\WorkService;
use Illuminate\Console\Command;

class RemindDailyStatus extends Command
{
    protected $signature = 'work:remind-daily-status {--date=}';
    protected $description = 'Remind employees who checked in today but have not submitted their daily status';

    public function handle(WorkService $work): int
    {
        $this->info('Reminded '.$work->remindDailyStatus($this->option('date') ?: today()->toDateString()).' employee(s).');

        return self::SUCCESS;
    }
}
