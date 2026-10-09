<?php

namespace App\Services;

use App\Models\AttendanceEmployee;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Application-usage tracking: how long each application was in the foreground
 * while the employee was checked in and working. Only the application name and
 * a duration are stored — never window titles, typed text, keystrokes or
 * screenshots. Off by default; an administrator (monitoring.manage) turns it on
 * per employee, exactly like screen capture.
 */
class AppUsageService
{
    public function __construct(private readonly PolicyConfigurationService $policies, private readonly AuditLogger $audit) {}

    public function enabledFor(AttendanceEmployee $employee): bool
    {
        return (bool) ($this->policies->forEmployee($employee, 'monitoring.app_usage', today()->toDateString(), [])['enabled'] ?? false);
    }

    public function titlesEnabledFor(AttendanceEmployee $employee): bool
    {
        return (bool) ($this->policies->forEmployee($employee, 'monitoring.window_titles', today()->toDateString(), [])['enabled'] ?? false);
    }

    public function setForEmployee(AttendanceEmployee $employee, bool $enabled, User $actor): void
    {
        abort_unless($actor->can('monitoring.manage'), 403, 'Only an administrator can change application tracking.');
        app(PeopleOperationsService::class)->setPolicy('monitoring.app_usage', 'monitoring', 'Application usage', ['enabled' => $enabled], $employee->id, $actor->id, today()->toDateString());
        $this->audit->record($enabled ? 'app_usage.enabled_for_employee' : 'app_usage.disabled_for_employee', $employee, ['by' => $actor->id]);
    }

    public function setTitlesForEmployee(AttendanceEmployee $employee, bool $enabled, User $actor): void
    {
        abort_unless($actor->can('monitoring.manage'), 403, 'Only an administrator can change window-title tracking.');
        app(PeopleOperationsService::class)->setPolicy('monitoring.window_titles', 'monitoring', 'Window titles', ['enabled' => $enabled], $employee->id, $actor->id, today()->toDateString());
        $this->audit->record($enabled ? 'window_titles.enabled_for_employee' : 'window_titles.disabled_for_employee', $employee, ['by' => $actor->id]);
    }

    /**
     * Adds seconds per (application, active-window title) to today's totals.
     * Stored only when window-title tracking is on for the employee.
     *
     * @param array<int, array{app: string, title: string, seconds: int}> $titles
     */
    public function recordTitles(AttendanceEmployee $employee, array $titles, ?string $date = null): int
    {
        if (! $this->titlesEnabledFor($employee)) return 0;
        $date = $date ? \Illuminate\Support\Carbon::parse($date)->toDateString() : today()->toDateString();
        $saved = 0;
        foreach ($titles as $row) {
            $app = trim(mb_substr((string) ($row['app'] ?? ''), 0, 120));
            $title = trim(mb_substr((string) ($row['title'] ?? ''), 0, 255));
            $seconds = (int) ($row['seconds'] ?? 0);
            if ($app === '' || $title === '' || $seconds <= 0 || $seconds > 86400) continue;
            DB::table('activity_titles_daily')->upsert(
                [['employee_id' => $employee->id, 'work_date' => $date, 'app_name' => $app, 'title' => $title, 'seconds' => $seconds, 'created_at' => now(), 'updated_at' => now()]],
                ['employee_id', 'work_date', 'app_name', 'title'],
                ['seconds' => DB::raw('seconds + '.$seconds), 'updated_at' => now()]
            );
            $saved++;
        }

        return $saved;
    }

    /**
     * Adds the reported seconds per application to today's totals.
     *
     * @param array<string, int> $apps  app name => seconds since the last report
     */
    public function record(AttendanceEmployee $employee, array $apps, ?string $date = null): int
    {
        if (! $this->enabledFor($employee)) return 0;
        $date = $date ? Carbon::parse($date)->toDateString() : today()->toDateString();
        $saved = 0;
        foreach ($apps as $name => $seconds) {
            $name = trim(mb_substr((string) $name, 0, 120));
            $seconds = (int) $seconds;
            if ($name === '' || $seconds <= 0 || $seconds > 86400) continue;
            DB::table('app_usage_daily')->upsert(
                [['employee_id' => $employee->id, 'work_date' => $date, 'app_name' => $name, 'seconds' => $seconds, 'created_at' => now(), 'updated_at' => now()]],
                ['employee_id', 'work_date', 'app_name'],
                ['seconds' => DB::raw('seconds + '.$seconds), 'updated_at' => now()]
            );
            $saved++;
        }

        return $saved;
    }
}
