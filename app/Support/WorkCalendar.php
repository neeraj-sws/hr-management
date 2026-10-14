<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Is a date a working day, given an employee's resolved `calendar.working_days`
 * policy? Every module (attendance, leave, payroll, overtime, compliance, final
 * settlement) asks this one function, so weekly offs behave the same everywhere.
 *
 * The policy is layered company -> team -> employee (each layer replaces the
 * keys it sets), so a single employee can have their own weekly-off pattern.
 *
 * Keys:
 *   days      ISO weekdays that are working days, e.g. [1,2,3,4,5,6] (Mon–Sat)
 *   holidays  ['Y-m-d', ...] dates that are off
 *   off_weeks {"6": [3]} – the 3rd Saturday of each month is off (weekday => occurrences;
 *             -1 = the last one). A team/employee layer can add the 2nd Saturday: {"6": [2, 3]}.
 *   shifts    [{"from": "2026-10-17", "to": "2026-10-19", "note": "..."}] – a weekly off
 *             moved for one week: "from" becomes a working day, "to" becomes the off day.
 */
class WorkCalendar
{
    public const WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
    public const OCCURRENCES = [1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th', 5 => '5th', -1 => 'Last'];

    public static function isWorkday(string $date, array $calendar): bool
    {
        $day = Carbon::parse($date);
        $ymd = $day->toDateString();

        // A temporary adjustment wins over the regular pattern.
        foreach ((array) ($calendar['shifts'] ?? []) as $shift) {
            if (($shift['to'] ?? null) === $ymd) return false;
        }
        foreach ((array) ($calendar['shifts'] ?? []) as $shift) {
            if (($shift['from'] ?? null) === $ymd) return ! in_array($ymd, $calendar['holidays'] ?? [], true);
        }

        $weekday = (int) $day->isoWeekday();
        if (! in_array($weekday, array_map('intval', $calendar['days'] ?? [1, 2, 3, 4, 5]), true)) return false;
        if (in_array($ymd, $calendar['holidays'] ?? [], true)) return false;

        return ! self::isNthWeekdayOff($day, (array) ($calendar['off_weeks'] ?? []));
    }

    /** Which occurrence of its weekday in the month a date is (1..5), and whether it is the last. */
    public static function occurrence(Carbon $day): array
    {
        return [(int) ceil($day->day / 7), $day->copy()->addWeek()->month !== $day->month];
    }

    private static function isNthWeekdayOff(Carbon $day, array $offWeeks): bool
    {
        $weeks = array_map('intval', (array) ($offWeeks[(string) $day->isoWeekday()] ?? $offWeeks[$day->isoWeekday()] ?? []));
        if (! $weeks) return false;
        [$nth, $isLast] = self::occurrence($day);

        return in_array($nth, $weeks, true) || ($isLast && in_array(-1, $weeks, true));
    }

    /** Human description, e.g. "Mon–Fri, plus Saturday except the 3rd Saturday". */
    public static function describe(array $calendar): string
    {
        $days = array_map('intval', $calendar['days'] ?? [1, 2, 3, 4, 5]);
        sort($days);
        $parts = [implode(', ', array_map(fn ($d) => substr(self::WEEKDAYS[$d] ?? '', 0, 3), $days))];
        foreach ((array) ($calendar['off_weeks'] ?? []) as $weekday => $weeks) {
            if (! $weeks || ! in_array((int) $weekday, $days, true)) continue;
            $parts[] = 'off on the '.implode(' & ', array_map(fn ($w) => self::OCCURRENCES[(int) $w] ?? $w, (array) $weeks)).' '.(self::WEEKDAYS[(int) $weekday] ?? '');
        }

        return implode('; ', $parts);
    }

    /** Validates and normalises off_weeks / shifts entered in Settings or an employee override. */
    public static function normalise(array $calendar): array
    {
        $offWeeks = [];
        foreach ((array) ($calendar['off_weeks'] ?? []) as $weekday => $weeks) {
            $weekday = (int) $weekday;
            $weeks = array_values(array_unique(array_filter(array_map('intval', (array) $weeks), fn ($w) => isset(self::OCCURRENCES[$w]))));
            if ($weekday >= 1 && $weekday <= 7 && $weeks) { sort($weeks); $offWeeks[(string) $weekday] = $weeks; }
        }
        $shifts = collect((array) ($calendar['shifts'] ?? []))
            ->filter(fn ($s) => is_array($s) && strtotime((string) ($s['from'] ?? '')) && strtotime((string) ($s['to'] ?? '')) && ($s['from'] ?? null) !== ($s['to'] ?? null))
            ->map(fn ($s) => ['from' => Carbon::parse($s['from'])->toDateString(), 'to' => Carbon::parse($s['to'])->toDateString(), 'note' => mb_substr(trim((string) ($s['note'] ?? '')), 0, 120)])
            ->unique(fn ($s) => $s['from'].'>'.$s['to'])->sortBy('from')->values()->all();

        return [...$calendar, 'off_weeks' => $offWeeks, 'shifts' => $shifts];
    }
}
