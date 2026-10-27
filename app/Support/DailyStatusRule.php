<?php

namespace App\Support;

use App\Models\AttendanceEmployee;
use App\Services\PeopleOperationsService;
use App\Services\PolicyConfigurationService;

/**
 * Who must write a daily status, and who gets the reminders (Work > Daily status > Who must write it).
 * A company default plus a per-employee choice, each from the date it was saved (older days keep their rule):
 *   required_reminder: must write it; automatic 6:15 PM reminder; "Remind" button works (default)
 *   required:          must write it; no automatic reminder; the "Remind" button still works
 *   optional:          may write it; never "missing", never reminded, no penalty
 */
class DailyStatusRule
{
    public const KEY = 'work.daily_status';

    public const MODES = [
        'required_reminder' => 'Compulsory + 6:15 PM reminder',
        'required' => 'Compulsory, no automatic reminder',
        'optional' => 'Not compulsory',
    ];

    /** @return array{required: bool, reminder: bool} */
    public static function for(?AttendanceEmployee $employee, ?string $date = null): array
    {
        $config = app(PolicyConfigurationService::class)->forEmployee($employee, self::KEY, $date ?? today()->toDateString(), []);
        $required = (bool) ($config['required'] ?? true);

        return ['required' => $required, 'reminder' => $required && (bool) ($config['reminder'] ?? true)];
    }

    public static function required(AttendanceEmployee $employee, ?string $date = null): bool
    {
        return self::for($employee, $date)['required'];
    }

    public static function reminds(AttendanceEmployee $employee, ?string $date = null): bool
    {
        return self::for($employee, $date)['reminder'];
    }

    public static function mode(?AttendanceEmployee $employee, ?string $date = null): string
    {
        $rule = self::for($employee, $date);

        return ! $rule['required'] ? 'optional' : ($rule['reminder'] ? 'required_reminder' : 'required');
    }

    public static function config(string $mode): array
    {
        return ['required' => $mode !== 'optional', 'reminder' => $mode === 'required_reminder'];
    }

    /** Saves the choice from today: for one employee, or the company default when $employee is null. */
    public static function set(?AttendanceEmployee $employee, string $mode, int $actor): void
    {
        if (! isset(self::MODES[$mode])) throw new \InvalidArgumentException("Unknown daily status mode {$mode}");
        app(PeopleOperationsService::class)->setPolicy(self::KEY, 'work', 'Daily status: who must write it', self::config($mode), $employee?->id, $actor, today()->toDateString());
    }

    /** A person's own choice back to the company default (from today). */
    public static function useDefault(AttendanceEmployee $employee): void
    {
        \App\Models\EmployeePolicyOverride::where('employee_id', $employee->id)->where('rule_key', self::KEY)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', today()))
            ->get()->each(fn ($o) => $o->effective_from && $o->effective_from->gte(today())
                ? $o->delete()
                : $o->update(['effective_until' => today()->subDay()->toDateString()]));
    }

    public static function hasOwnChoice(AttendanceEmployee $employee): bool
    {
        return \App\Models\EmployeePolicyOverride::where('employee_id', $employee->id)->where('rule_key', self::KEY)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', today()))
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', today()))->exists();
    }
}
