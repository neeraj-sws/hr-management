<?php

namespace App\Console\Commands;

use App\Services\TeamMonitoringService;
use Illuminate\Console\Command;

class ExpireScreenCapture extends Command
{
    protected $signature = 'monitoring:expire-capture';
    protected $description = 'Turn screen capture off for people whose end date has passed (they are told)';

    public function handle(TeamMonitoringService $monitoring): int
    {
        $this->info($monitoring->expireCaptures().' capture(s) ended.');

        return self::SUCCESS;
    }
}
