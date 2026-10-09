<?php

namespace App\Console\Commands;

use App\Services\WorkService;
use Illuminate\Console\Command;

class NotifyOverdueTasks extends Command
{
    protected $signature = 'work:notify-overdue-tasks';
    protected $description = 'Tell the assignee and their manager once when an open task passes its due date';

    public function handle(WorkService $work): int
    {
        $this->info('Notified about '.$work->notifyOverdueTasks().' overdue task(s).');

        return self::SUCCESS;
    }
}
