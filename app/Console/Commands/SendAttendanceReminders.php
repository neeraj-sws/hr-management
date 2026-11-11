<?php

namespace App\Console\Commands;

use App\Services\AttendanceReminderService;
use Illuminate\Console\Command;

class SendAttendanceReminders extends Command
{
    protected $signature = 'attendance:reminders';
    protected $description = 'Phone reminders: shortly before the shift starts (not checked in yet) and after it ends (still checked in)';

    public function handle(AttendanceReminderService $reminders): int
    {
        $before = $reminders->beforeShift();
        $checkout = $reminders->forgotCheckout();
        $breaks = app(\App\Services\AttendanceAlertService::class)->longBreaks();
        // Forgot to check out: desktop (computer idle) and web (browser inactive).
        $attendanceSvc = app(\App\Services\AttendanceService::class);
        $autoOut = $attendanceSvc->autoCheckOutIdleSessions();
        $autoOutWeb = $attendanceSvc->autoCheckOutWebIdleSessions();
        $this->info("Reminders sent: {$before} before shift, {$checkout} still checked in, {$breaks} long breaks; {$autoOut} desktop + {$autoOutWeb} web checked out automatically.");

        return self::SUCCESS;
    }
}
