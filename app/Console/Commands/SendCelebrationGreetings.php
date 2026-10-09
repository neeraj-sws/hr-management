<?php

namespace App\Console\Commands;

use App\Services\CompanyService;
use Illuminate\Console\Command;

class SendCelebrationGreetings extends Command
{
    protected $signature = 'hr:celebrations';
    protected $description = "Notify everyone about today's birthdays and work anniversaries (safe to run more than once a day)";

    public function handle(CompanyService $company): int
    {
        $count = $company->notifyTodaysCelebrations();
        $this->info($count ? "Sent greetings for {$count} celebration(s)." : 'No birthdays or anniversaries today.');

        return self::SUCCESS;
    }
}
