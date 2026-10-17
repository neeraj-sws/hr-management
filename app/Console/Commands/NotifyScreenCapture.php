<?php

namespace App\Console\Commands;

use App\Services\ScreenCaptureService;
use Illuminate\Console\Command;

class NotifyScreenCapture extends Command
{
    protected $signature = 'monitoring:notify-capture';
    protected $description = 'Tell employees (in-app and by e-mail) that screen capture is on for them, once per version of the capture terms';

    public function handle(ScreenCaptureService $captures): int
    {
        $this->info('Screen capture notice sent to '.$captures->notifyAll().' employee(s).');

        return self::SUCCESS;
    }
}
