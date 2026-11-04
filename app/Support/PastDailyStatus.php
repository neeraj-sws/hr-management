<?php

namespace App\Support;

use App\Models\AttendanceEmployee;
use App\Models\DailyWorkStatus;
use App\Models\EmployeePolicyOverride;
use App\Services\PeopleOperationsService;
use App\Services\PolicyConfigurationService;
use Illuminate\Support\Carbon;

/**
 * "Daily status for earlier days" (Employee profile > Admin): HR lets chosen people write a daily status for days in
 * previous months, not only the last 2 days. Saved as the person's own rule (employee policy override), from today:
 *   ['months' => N]          from the 1st of the month N months back (1 = last month too), or
 *   ['from' => 'YYYY-MM-DD'] from that date;
 * optionally only until a date (the rule's end date). Never before the joining date, never today or later (today has
 * its own form). Everyone without the rule keeps the normal 2-day limit.
 */
class PastDailyStatus
{
    public const KEY = 'work.past_status';

    public const MAX_MONTHS = 12;

    /** The person's rule today: ['months' => int] or ['from' => date], plus 'until' (date or null); null = off. */
    public static function rule(AttendanceEmployee $employee): ?array
    {
        $config = app(PolicyConfigurationService::class)->forEmployee($employee, self::KEY, today()->toDateString(), []);
        if (empty($config['enabled'])) return null;
        $until = EmployeePolicyOverride::where('employee_id', $employee->id)->where('rule_key', self::KEY)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', today()))
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', today()))
            ->orderByDesc('effective_from')->orderByDesc('id')->first()?->effective_until?->toDateString();

        return ['months' => isset($config['months']) ? (int) $config['months'] : null, 'from' => $config['from'] ?? null, 'until' => $until];
    }

    /** The earliest day this person may write a status for (with the rule), or null when the rule is off. */
    public static function earliest(AttendanceEmployee $employee): ?string
    {
        $rule = self::rule($employee);
        if (! $rule) return null;
        $from = $rule['from'] ? Carbon::parse($rule['from']) : today()->startOfMonth()->subMonthsNoOverflow(max(1, min(self::MAX_MONTHS, (int) $rule['months'])));
        if ($employee->joined_at && $employee->joined_at->gt($from)) $from = $employee->joined_at->copy();

        return $from->lt(today()) ? $from->toDateString() : null;
    }

    /** May this person write a (late) status for $date now: rule on, a past day in range, and no status for it yet. */
    public static function allows(AttendanceEmployee $employee, string $date): bool
    {
        if (strtotime($date) === false) return false;
        $date = Carbon::parse($date)->toDateString();
        $earliest = self::earliest($employee);

        return $earliest !== null && $date >= $earliest && $date < today()->toDateString()
            && ! DailyWorkStatus::where('employee_id', $employee->id)->whereDate('work_date', $date)->exists();
    }

    /** Turns it on from today: for the last $months months, or from the date $from; optionally until $until. */
    public static function set(AttendanceEmployee $employee, ?int $months, ?string $from, ?string $until, int $actor): void
    {
        self::stop($employee);
        $config = ['enabled' => true] + ($from ? ['from' => Carbon::parse($from)->toDateString()] : ['months' => max(1, min(self::MAX_MONTHS, (int) $months))]);
        app(PeopleOperationsService::class)->setPolicy(self::KEY, 'work', 'Daily status for earlier days', $config, $employee->id, $actor, today()->toDateString(), $until ? Carbon::parse($until)->toDateString() : null);
    }

    /** Turns it off from today (the normal 2-day limit again). */
    public static function stop(AttendanceEmployee $employee): void
    {
        EmployeePolicyOverride::where('employee_id', $employee->id)->where('rule_key', self::KEY)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', today()))
            ->get()->each(fn ($o) => $o->effective_from && $o->effective_from->gte(today())
                ? $o->delete()
                : $o->update(['effective_until' => today()->subDay()->toDateString()]));
    }
}
