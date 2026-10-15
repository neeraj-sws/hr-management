<?php

namespace App\Support;

use App\Services\PolicyConfigurationService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The leave session (e.g. April–March). A leave year is identified by the
 * calendar year it starts in: with an April start, leave year 2026 runs from
 * 1 Apr 2026 to 31 Mar 2027 and is shown as "2026-27". The start month is a
 * company setting (Settings > Leave types, policy `leave.year`); with a January
 * start the leave year is simply the calendar year.
 */
class LeaveYear
{
    public const DEFAULT_START_MONTH = 4;

    public static function startMonth(): int
    {
        $month = (int) (app(PolicyConfigurationService::class)->forEmployee(null, 'leave.year', today()->toDateString(), [])['start_month'] ?? self::DEFAULT_START_MONTH);

        return $month >= 1 && $month <= 12 ? $month : self::DEFAULT_START_MONTH;
    }

    /** The leave year a date falls in. */
    public static function of(CarbonInterface|string $date, ?int $startMonth = null): int
    {
        $date = Carbon::parse($date);
        $startMonth ??= self::startMonth();

        return $date->month >= $startMonth ? $date->year : $date->year - 1;
    }

    public static function current(): int
    {
        return self::of(today());
    }

    public static function start(int $year, ?int $startMonth = null): Carbon
    {
        return Carbon::create($year, $startMonth ?? self::startMonth(), 1)->startOfDay();
    }

    public static function end(int $year, ?int $startMonth = null): Carbon
    {
        return self::start($year, $startMonth)->addYear()->subDay();
    }

    /** Position of a calendar month inside its leave year: the start month is 1, the month before it is 12. */
    public static function monthIndex(int $calendarMonth, ?int $startMonth = null): int
    {
        $startMonth ??= self::startMonth();

        return (($calendarMonth - $startMonth + 12) % 12) + 1;
    }

    /** "2026-27" for an April start, "2026" for a January start. */
    public static function label(int $year, ?int $startMonth = null): string
    {
        return ($startMonth ?? self::startMonth()) === 1 ? (string) $year : $year.'-'.substr((string) ($year + 1), -2);
    }

    /** Recent and next leave years for a picker, newest first. */
    public static function options(int $back = 4): array
    {
        $startMonth = self::startMonth();
        $current = self::of(today(), $startMonth);
        $years = [];
        for ($year = $current + 1; $year >= $current - $back; $year--) $years[$year] = self::label($year, $startMonth);

        return $years;
    }
}
