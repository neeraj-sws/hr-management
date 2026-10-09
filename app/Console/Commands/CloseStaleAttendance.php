<?php

namespace App\Console\Commands;

use App\Services\AttendanceService;
use Illuminate\Console\Command;

class CloseStaleAttendance extends Command
{
    protected $signature = 'attendance:close-stale';
    protected $description = 'Close sessions nobody checked out of, at their last activity, and notify the employee and manager';

    public function handle(AttendanceService $attendance): int
    {
        $count = $attendance->closeStaleSessions();
        $this->info("Closed {$count} session(s) without check-out.");

        return self::SUCCESS;
    }
}
