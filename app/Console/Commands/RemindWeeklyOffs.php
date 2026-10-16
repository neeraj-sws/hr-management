<?php

namespace App\Console\Commands;

use App\Services\CompanyService;
use Illuminate\Console\Command;

class RemindWeeklyOffs extends Command
{
    protected $signature = 'hr:weekly-off-reminders';
    protected $description = 'Remind employees the evening before a special off day (e.g. 3rd Saturday, moved weekly off)';

    public function handle(CompanyService $company): int
    {
        $this->info('Reminded '.$company->remindTomorrowOff().' employee(s).');

        return self::SUCCESS;
    }
}
