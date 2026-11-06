<?php

namespace App\Support;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Logins limited to one company (users.company_id, e.g. the ITWeb owner and the HR / Accounts / Manager logins he
 * makes) see that company's employees and their records only; everyone else (company empty, e.g. the Super Admin)
 * sees every company, as before. The limit is applied by global scopes on the employee and on each record that
 * belongs to an employee, so every page, report, export and API answer follows it without its own filter.
 */
class CompanyAccess
{
    /** Company the signed-in login is limited to; null = all companies (or nobody signed in: console, queue, login). */
    public static function limitedCompanyId(): ?int
    {
        // Only a login already resolved: resolving one here would query employees again (and loop).
        if (! Auth::hasUser()) return null;
        $user = Auth::user();

        return $user instanceof User && $user->company_id ? (int) $user->company_id : null;
    }

    /** The company the Super Admin picked to work on in Settings (null = the rules for all companies). */
    private const RULES_COMPANY = 'company-access.rules-company';

    /** Kept in the container, so it lasts one request (and one test) only. */
    private static function pickedCompany(): ?int
    {
        return app()->bound(self::RULES_COMPANY) ? app(self::RULES_COMPANY) : null;
    }

    /** Whose rules are read and saved with no employee in hand: the limited login's company, or the picked one. */
    public static function rulesCompanyId(): ?int
    {
        return self::limitedCompanyId() ?? self::pickedCompany();
    }

    public static function chooseRulesCompany(?int $companyId): void
    {
        app()->instance(self::RULES_COMPANY, $companyId);
    }

    /** Runs $work with the rules of this company (e.g. an ITWeb employee's letter, made by the Super Admin). */
    public static function withRulesCompany(?int $companyId, callable $work): mixed
    {
        $before = self::pickedCompany();
        app()->instance(self::RULES_COMPANY, $companyId);
        try {
            return $work();
        } finally {
            app()->instance(self::RULES_COMPANY, $before);
        }
    }

    public static function isLimited(?User $user): bool
    {
        return $user !== null && $user->company_id !== null;
    }

    /** Notification kinds whose record is not the model of the same name. */
    private const NOTIFICATION_MODELS = [
        'NightWork' => \App\Models\NightWorkRequest::class, 'LateArrival' => \App\Models\LateArrivalRequest::class, 'OffDay' => \App\Models\OffDayRequest::class,
        'AttendanceLate' => \App\Models\AttendanceSession::class, 'AttendanceBreak' => \App\Models\AttendanceSession::class,
        'AttendanceLongBreak' => \App\Models\AttendanceSession::class, 'AutoCheckout' => \App\Models\AttendanceSession::class,
        'DailyStatus' => \App\Models\DailyWorkStatus::class, 'DailyStatusSubmitted' => \App\Models\DailyWorkStatus::class,
        'TrainingFeeReminder' => \App\Models\TrainingEnrollment::class,
        'TaskAssignment' => \App\Models\Task::class, 'TaskOverdue' => \App\Models\Task::class, 'TaskCompleted' => \App\Models\Task::class, 'TaskUpdated' => \App\Models\Task::class,
        // Sent with the employee's own id.
        'AttendanceAbsent' => \App\Models\AttendanceEmployee::class, 'Celebration' => \App\Models\AttendanceEmployee::class, 'ManagerChange' => \App\Models\AttendanceEmployee::class,
        'Employee' => \App\Models\AttendanceEmployee::class, 'ScreenCapture' => \App\Models\AttendanceEmployee::class, 'DailyStatusReminder' => \App\Models\AttendanceEmployee::class,
    ];

    /**
     * The company a notification is about (through the employee its record belongs to), or null when it is not
     * about one company (e.g. a company-wide announcement) or cannot be told.
     */
    public static function companyOfNotification(string $kind, int $recordId): ?int
    {
        if ($recordId <= 0) return null;
        $class = self::NOTIFICATION_MODELS[$kind] ?? 'App\\Models\\'.$kind;
        if (! class_exists($class) || ! is_subclass_of($class, \Illuminate\Database\Eloquent\Model::class)) return null;
        $record = rescue(fn () => $class::withoutGlobalScopes()->find($recordId), null, false);
        if (! $record) return null;
        if ($record instanceof \App\Models\PayrollRun) return $record->company_id ?? Company::main()?->id;
        if ($record instanceof \App\Models\Announcement) return $record->company_id;
        if ($record instanceof \App\Models\EmployeeFinalSettlement) $record = $record->lifecycleCase()->withoutGlobalScopes()->first();
        $employeeId = match (true) {
            $record instanceof \App\Models\AttendanceEmployee => $record->id,
            $record instanceof \App\Models\Task => $record->assignee_id,
            default => $record?->getAttribute('employee_id'),
        };
        if (! $employeeId) return null;
        $employee = \App\Models\AttendanceEmployee::withoutGlobalScopes()->find($employeeId);

        return $employee ? ($employee->company_id ?? Company::main()?->id) : null;
    }

    /** False when the login is limited to one company and the company given is another one. */
    public static function userSeesCompany(?User $user, ?int $companyId): bool
    {
        if (! $user?->company_id || ! $companyId) return true;

        return Company::payrollKey((int) $user->company_id) === Company::payrollKey($companyId);
    }

    /** Keeps rows of this company: the main company also owns rows with no company (everyone before companies). */
    public static function whereCompany(Builder $query, int $companyId, string $column): Builder
    {
        $isMain = (bool) Company::withoutGlobalScopes()->whereKey($companyId)->value('is_main');

        return $isMain
            ? $query->where(fn ($q) => $q->whereNull($column)->orWhere($column, $companyId))
            : $query->where($column, $companyId);
    }
}
