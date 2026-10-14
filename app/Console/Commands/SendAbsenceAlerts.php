<?php

namespace App\Console\Commands;

use App\Services\AttendanceAlertService;
use Illuminate\Console\Command;

class SendAbsenceAlerts extends Command
{
    protected $signature = 'attendance:absence-alerts';
    protected $description = 'After the configured time, alert employees (and their managers and HR) who have not checked in and are not on leave';

    public function handle(AttendanceAlertService $alerts): int
    {
        $this->info('Absence alerts sent for '.$alerts->absences().' employee(s).');

        return self::SUCCESS;
    }
}
