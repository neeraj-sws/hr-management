<?php

namespace App\Console\Commands;

use App\Services\ScreenCaptureService;
use Illuminate\Console\Command;

class PurgeScreenshots extends Command
{
    protected $signature = 'monitoring:purge-screenshots';
    protected $description = 'Delete screen captures older than the configured retention period';

    public function handle(ScreenCaptureService $captures): int
    {
        $this->info('Deleted '.$captures->purgeExpired().' expired screenshot(s).');

        return self::SUCCESS;
    }
}
