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
 *   holiday_types {"Y-m-d": {"category": "national", "paid": true}} – the kind of holiday
 *             (public, national, festival, company, optional) and whether it is paid.
 *             An optional holiday is not a day off for everyone: it stays a working
 *             day and an employee who wants it applies for leave.
 *   off_weeks {"6": [3]} – the 3rd Saturday of each month is off (weekday => occurrences;
 *             -1 = the last one). A team/employee layer can add the 2nd Saturday: {"6": [2, 3]}.
 *   shifts    [{"from": "2026-10-17", "to": "2026-10-19", "note": "..."}] – a weekly off
 *             moved for one week: "from" becomes a working day, "to" becomes the off day.
 */
class WorkCalendar
{
    public const WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
    public const OCCURRENCES = [1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th', 5 => '5th', -1 => 'Last'];
    public const HOLIDAY_CATEGORIES = ['public' => 'Public holiday', 'national' => 'National holiday', 'festival' => 'Festival', 'company' => 'Company holiday', 'optional' => 'Optional holiday'];

    /**
     * Category and paid flag of a listed holiday; a holiday without a type is a paid public holiday.
     * closes_at (HH:MM) makes it a half-day holiday: the office is open until that time.
     */
    public static function holidayTypeOf(array $calendar, string $date): array
    {
        $type = (array) ($calendar['holiday_types'][$date] ?? []);
        $category = isset(self::HOLIDAY_CATEGORIES[$type['category'] ?? '']) ? $type['category'] : 'public';
        $closesAt = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', substr((string) ($type['closes_at'] ?? ''), 0, 5)) ? substr((string) $type['closes_at'], 0, 5) : null;

        return ['category' => $category, 'paid' => (bool) ($type['paid'] ?? true), ...($closesAt && $category !== 'optional' ? ['closes_at' => $closesAt] : [])];
    }

    /** Holidays that close the office for the whole day (every listed holiday except optional and half-day ones). */
    public static function offHolidays(array $calendar): array
    {
        return array_values(array_filter((array) ($calendar['holidays'] ?? []), function ($date) use ($calendar) {
            $type = self::holidayTypeOf($calendar, (string) $date);

            return $type['category'] !== 'optional' && empty($type['closes_at']);
        }));
    }

    /**
     * A half-day holiday on a working day: the office closes at closes_at. Whoever comes in is paid a full
     * day (never a "half day"); whoever does not is paid half a day when the holiday is paid. Null otherwise.
     *
     * @return array{name: string, closes_at: string, paid: bool}|null
     */
    public static function halfDayHoliday(array $calendar, string $date): ?array
    {
        $ymd = Carbon::parse($date)->toDateString();
        if (! in_array($ymd, (array) ($calendar['holidays'] ?? []), true)) return null;
        $type = self::holidayTypeOf($calendar, $ymd);
        if (empty($type['closes_at']) || ! self::isWorkday($ymd, $calendar)) return null;

        return ['name' => (string) ($calendar['holiday_names'][$ymd] ?? 'Holiday'), 'closes_at' => $type['closes_at'], 'paid' => $type['paid']];
    }

    /** True when the date is a listed, non-optional holiday that is paid, and would otherwise be a working day. */
    public static function isPaidHoliday(string $date, array $calendar): bool
    {
        $ymd = Carbon::parse($date)->toDateString();
        if (! in_array($ymd, self::offHolidays($calendar), true) || ! self::holidayTypeOf($calendar, $ymd)['paid']) return false;

        return self::isWorkday($ymd, [...$calendar, 'holidays' => []]);
    }

    public static function isWorkday(string $date, array $calendar): bool
    {
        $day = Carbon::parse($date);
        $ymd = $day->toDateString();

        // A temporary adjustment wins over the regular pattern.
        foreach ((array) ($calendar['shifts'] ?? []) as $shift) {
            if (($shift['to'] ?? null) === $ymd) return false;
        }
        foreach ((array) ($calendar['shifts'] ?? []) as $shift) {
            if (($shift['from'] ?? null) === $ymd) return ! in_array($ymd, self::offHolidays($calendar), true);
        }

        $weekday = (int) $day->isoWeekday();
        if (! in_array($weekday, array_map('intval', $calendar['days'] ?? [1, 2, 3, 4, 5]), true)) return false;
        if (in_array($ymd, self::offHolidays($calendar), true)) return false;

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

    /**
     * off_weeks for the Settings checkboxes: every weekday present as a list
     * (an absent weekday made a checkbox group act as one on/off switch, so
     * ticking one week ticked them all).
     *
     * @return array<string, array<int, string>>
     */
    public static function offWeeksForForm(array $offWeeks): array
    {
        $form = [];
        foreach (array_keys(self::WEEKDAYS) as $weekday) {
            $form[(string) $weekday] = array_map('strval', (array) ($offWeeks[(string) $weekday] ?? $offWeeks[$weekday] ?? []));
        }

        return $form;
    }

    /**
     * Why a date is off although it is normally a working day: a week-of-month
     * off ("3rd Saturday off") or a moved weekly off. Null for normal days,
     * regular weekly offs (e.g. every Sunday) and listed holidays.
     */
    public static function specialOffReason(string $date, array $calendar): ?string
    {
        $day = Carbon::parse($date);
        $ymd = $day->toDateString();
        foreach ((array) ($calendar['shifts'] ?? []) as $shift) {
            if (($shift['to'] ?? null) === $ymd) return 'Weekly off moved here from '.Carbon::parse($shift['from'])->format('D d M').(! empty($shift['note']) ? ' — '.$shift['note'] : '');
        }
        if (! in_array((int) $day->isoWeekday(), array_map('intval', $calendar['days'] ?? [1, 2, 3, 4, 5]), true)) return null;
        if (in_array($ymd, self::offHolidays($calendar), true)) return null;
        if (! self::isNthWeekdayOff($day, (array) ($calendar['off_weeks'] ?? []))) return null;
        [$nth, $isLast] = self::occurrence($day);
        $weeks = array_map('intval', (array) ($calendar['off_weeks'][(string) $day->isoWeekday()] ?? []));
        $label = in_array($nth, $weeks, true) ? self::OCCURRENCES[$nth] : 'Last';

        return "{$label} ".self::WEEKDAYS[(int) $day->isoWeekday()].' — weekly off';
    }

    /** Why a non-working day is off, in words: "3rd Saturday — weekly off", "holiday" or "Sunday — weekly off". */
    public static function offDayReason(string $date, array $calendar): string
    {
        if ($special = self::specialOffReason($date, $calendar)) return $special;
        if (in_array(Carbon::parse($date)->toDateString(), self::offHolidays($calendar), true)) return 'holiday';

        return self::WEEKDAYS[(int) Carbon::parse($date)->isoWeekday()].' — weekly off';
    }

    /**
     * The special offs (week-of-month offs and moved weekly offs) from a date on.
     *
     * @return array<int, array{date: Carbon, reason: string}>
     */
    public static function upcomingSpecialOffs(array $calendar, Carbon $from, int $days = 90): array
    {
        $out = [];
        for ($day = $from->copy()->startOfDay(), $end = $from->copy()->addDays($days); $day->lte($end); $day->addDay()) {
            if ($reason = self::specialOffReason($day->toDateString(), $calendar)) $out[] = ['date' => $day->copy(), 'reason' => $reason];
        }

        return $out;
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
