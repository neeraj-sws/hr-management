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
        $this->info("Reminders sent: {$before} before shift, {$checkout} still checked in, {$breaks} long breaks.");

        return self::SUCCESS;
    }
}
