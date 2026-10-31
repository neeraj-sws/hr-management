<?php

namespace App\Console\Commands;

use App\Models\AttendanceSession;
use App\Models\DailyWorkStatus;
use App\Services\PeopleOperationsService;
use App\Services\WorkService;
use App\Support\DailyStatusRule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * When a day's daily status is due (shift end + grace hours, e.g. 10:30 PM): someone who worked but wrote none gets one
 * written from the day's tasks, and every status of that day not mailed yet (written after the 8:30 PM summary) is
 * e-mailed now. Runs every 10 minutes; each status is mailed once.
 */
class DailyStatusDeadline extends Command
{
    protected $signature = 'work:daily-status-deadline';
    protected $description = 'After the daily status deadline: write missing statuses from the day\'s tasks and mail the ones not sent yet';

    public function handle(WorkService $work, PeopleOperationsService $people): int
    {
        $written = 0;
        $mailed = 0;
        foreach ([today()->subDay()->toDateString(), today()->toDateString()] as $date) {
            $employees = AttendanceSession::with('employee.user', 'employee.manager.user')->whereDate('work_date', $date)->whereNotNull('check_in')->get()->pluck('employee')->filter()->unique('id');
            foreach ($employees as $employee) {
                if (! $employee->is_active || $people->statusDeadline($employee, $date)->gt(now())) continue;
                if (DailyStatusRule::required($employee, $date) && rescue(fn () => $work->autoStatusFromTasks($employee, $date), null, true)) $written++;
                $status = DailyWorkStatus::with(['employee.user', 'employee.manager.user'])->where('employee_id', $employee->id)->whereDate('work_date', $date)->first();
                if ($status && ! Cache::has(SendDailyStatusSummaries::mailedKey($status))) $mailed += SendDailyStatusSummaries::send($status) > 0 ? 1 : 0;
            }
        }
        $this->info("{$written} daily status(es) written from tasks; {$mailed} status(es) mailed.");

        return self::SUCCESS;
    }
}
