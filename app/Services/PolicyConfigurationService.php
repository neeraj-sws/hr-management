<?php

namespace App\Services;

use App\Models\AttendanceEmployee;
use App\Models\EmployeePolicyOverride;
use App\Models\PolicyRule;
use App\Models\TeamPolicyOverride;

/**
 * Effective policy for an employee on a date: the rule for all companies, then the rule of the employee's
 * company (e.g. ITWeb's own payroll rules, set by its owner), then the employee's team override (lowest team id
 * if they are in several), then the employee's own override — each layer only replaces the keys it sets.
 * With no employee: the rule for all companies, then the rule of the company being worked on
 * (CompanyAccess::rulesCompanyId(): the company owner's own, or the one the Super Admin picked in Settings).
 */
class PolicyConfigurationService
{
    /**
     * Answers kept while one page is drawn (see remember()); null = off, every call asks the database.
     * A long list asks the same "rule for this person on this day" hundreds of times.
     */
    private static ?array $memo = null;

    /** Turns the short-lived memory on until stop() — for pages that only read rules (e.g. Attendance Records). */
    public static function start(): void
    {
        self::$memo ??= [];
    }

    public static function stop(): void
    {
        self::$memo = null;
    }

    public static function on(): bool
    {
        return self::$memo !== null;
    }

    /** While the memory is on: work out $value once per $key; otherwise just work it out. */
    public static function memo(string $key, callable $value): mixed
    {
        if (self::$memo === null) return $value();

        return array_key_exists($key, self::$memo) ? self::$memo[$key] : (self::$memo[$key] = $value());
    }

    /**
     * Rules a company can set for itself (Settings, Compliance, letterhead). Everything else (e-mail, Telegram,
     * Google Sheets, security, website access, leave year …) is one rule for all companies, set by the Super Admin.
     */
    public const COMPANY_KEYS = [
        'calendar.working_days', 'work.hours', 'attendance.rules', 'attendance.idle', 'attendance.auto_checkout', 'attendance.sources',
        'attendance.selfie', 'attendance.location', 'attendance.late_penalty', 'attendance.early_checkout', 'attendance.unauthorized_absence',
        'overtime.multiplier', 'payroll.calculation', 'payroll.statutory', 'payroll.salary_structure', 'leave.advance_limit',
        'leave.final_settlement', 'leave.late_application', 'task.late_penalty', 'task.missing_entry', 'task.late_entry', 'daily_status.missing', 'monitoring.screenshots', 'monitoring.app_usage',
        'monitoring.window_titles', 'billing.company', 'letters.settings',
    ];

    public static function isCompanyKey(string $key): bool
    {
        return in_array($key, self::COMPANY_KEYS, true);
    }

    /** The rule as one company has it (null = the rule for all companies). */
    public function forCompany(?int $companyId, string $key, string $asOf, array $fallback = []): array
    {
        return \App\Support\CompanyAccess::withRulesCompany($companyId, fn () => $this->forEmployee(null, $key, $asOf, $fallback));
    }

    public function forEmployee(?AttendanceEmployee $employee, string $key, string $asOf, array $fallback = []): array
    {
        $context = $employee ? '' : '|c'.(\App\Support\CompanyAccess::rulesCompanyId() ?? 0);

        return self::memo('policy|'.($employee?->id ?? 0).$context.'|'.$key.'|'.$asOf.'|'.md5(serialize($fallback)), function () use ($employee, $key, $asOf, $fallback) {
            $config = $this->resolve($employee, $key, $asOf, $fallback);
            // Offs that count only when asked for: the days HR approved are off (WorkCalendar::awaitsOffRequest).
            if ($key === 'calendar.working_days' && $employee && ! empty($config['offs_need_request'])) {
                $config['confirmed_offs'] = self::memo('rows|confirmed-offs|'.$employee->id, fn () => \App\Models\OffDayRequest::where('employee_id', $employee->id)->where('status', 'approved')
                    ->pluck('off_date')->map(fn ($d) => \Illuminate\Support\Carbon::parse($d)->toDateString())->unique()->values()->all());
            }

            return $config;
        });
    }

    private function resolve(?AttendanceEmployee $employee, string $key, string $asOf, array $fallback): array
    {
        if (self::$memo !== null) return $this->resolveFromMemory($employee, $key, $asOf, $fallback);

        $global = PolicyRule::query()
            ->where('rule_key', $key)
            ->whereNull('company_id')
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $asOf))
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $asOf))
            ->orderByRaw('effective_from IS NULL')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first()?->configuration ?? $fallback;
        $global = array_replace($global, $this->companyRule($employee, $key, $asOf));

        if (! $employee) return $global;

        $override = EmployeePolicyOverride::query()
            ->where('employee_id', $employee->id)
            ->where('rule_key', $key)
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $asOf))
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $asOf))
            ->orderByRaw('effective_from IS NULL')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first()?->configuration ?? [];

        // The teams the employee belonged to on that date; current teams when no history was recorded.
        $teamIds = \App\Models\EmployeeTeamHistory::where('employee_id', $employee->id)->exists()
            ? \App\Models\EmployeeTeamHistory::where('employee_id', $employee->id)->on(\Illuminate\Support\Carbon::parse($asOf)->toDateString())->pluck('team_id')->unique()->sort()->values()->all()
            : $employee->teams()->pluck('teams.id')->sort()->values()->all();
        $team = $teamIds ? TeamPolicyOverride::query()
            ->whereIn('team_id', $teamIds)
            ->where('rule_key', $key)
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $asOf))
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $asOf))
            ->orderBy('team_id')
            ->first()?->configuration ?? [] : [];

        return array_replace($global, $team, $override);
    }

    /** The newest active rule of the employee's company (or, with no employee, of the company being worked on). */
    private function companyRule(?AttendanceEmployee $employee, string $key, string $asOf): array
    {
        if (! self::isCompanyKey($key)) return [];
        $query = PolicyRule::query()->where('rule_key', $key)->where('is_active', true);
        if ($employee) {
            // No company on the employee = the main company.
            $employee->company_id ? $query->where('company_id', $employee->company_id)
                : $query->whereIn('company_id', \App\Models\Company::withoutGlobalScopes()->where('is_main', true)->select('id'));
        } else {
            $companyId = \App\Support\CompanyAccess::rulesCompanyId();
            if (! $companyId) return [];
            $query->where('company_id', $companyId);
        }

        return $query
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $asOf))
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $asOf))
            ->orderByRaw('effective_from IS NULL')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first()?->configuration ?? [];
    }

    /**
     * Same answer as the queries in resolve(), but each table is read once per page (all dates) and the
     * row for the date is picked here, in the same order: dated rows before undated, newest date, then newest id.
     */
    private function resolveFromMemory(?AttendanceEmployee $employee, string $key, string $asOf, array $fallback): array
    {
        $day = \Illuminate\Support\Carbon::parse($asOf)->toDateString();
        $covers = fn ($row) => (! $row->effective_from || $row->effective_from->toDateString() <= $day)
            && (! $row->effective_until || $row->effective_until->toDateString() >= $day);
        $newest = fn ($rows) => $rows->filter($covers)->sort(fn ($a, $b) => [$a->effective_from === null, $b->effective_from?->toDateString(), $b->id]
            <=> [$b->effective_from === null, $a->effective_from?->toDateString(), $a->id])->first();

        $global = $newest(self::memo('rows|global|'.$key, fn () => PolicyRule::where('rule_key', $key)->whereNull('company_id')->where('is_active', true)->get()))?->configuration ?? $fallback;
        if (self::isCompanyKey($key)) {
            $companyId = $employee ? ($employee->company_id ?: self::memo('rows|main-company', fn () => \App\Models\Company::withoutGlobalScopes()->where('is_main', true)->value('id')))
                : \App\Support\CompanyAccess::rulesCompanyId();
            if ($companyId) $global = array_replace($global, $newest(self::memo('rows|company|'.$companyId.'|'.$key, fn () => PolicyRule::where('rule_key', $key)->where('company_id', $companyId)->where('is_active', true)->get()))?->configuration ?? []);
        }
        if (! $employee) return $global;

        $override = $newest(self::memo('rows|employee|'.$employee->id.'|'.$key, fn () => EmployeePolicyOverride::where('employee_id', $employee->id)->where('rule_key', $key)->get()))?->configuration ?? [];

        $history = self::memo('rows|team-history|'.$employee->id, fn () => \App\Models\EmployeeTeamHistory::where('employee_id', $employee->id)->get());
        $teamIds = $history->isNotEmpty()
            ? $history->filter(fn ($h) => $h->starts_on && $h->starts_on->toDateString() <= $day && (! $h->ends_on || $h->ends_on->toDateString() >= $day))->pluck('team_id')->unique()->sort()->values()->all()
            : self::memo('rows|teams|'.$employee->id, fn () => $employee->teams()->pluck('teams.id')->sort()->values()->all());
        $team = $teamIds ? self::memo('rows|team|'.$key, fn () => TeamPolicyOverride::where('rule_key', $key)->get())
            ->filter(fn ($t) => in_array($t->team_id, $teamIds) && $covers($t))->sortBy([['team_id', 'asc'], ['id', 'asc']])->first()?->configuration ?? [] : [];

        return array_replace($global, $team, $override);
    }
}
