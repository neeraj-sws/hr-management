<?php

namespace App\Support;

/**
 * One way to show a length of time everywhere in the app: HH:MM:SS.
 * Hours are not wrapped at 24 (a 30-hour total shows as 30:00:00), unlike
 * gmdate('H:i'), which silently drops whole days.
 */
class Duration
{
    public static function hms(int|float|string|null $seconds): string
    {
        $seconds = max(0, (int) round((float) $seconds));

        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    /** For values stored in minutes (overtime, breaks). */
    public static function fromMinutes(int|float|string|null $minutes): string
    {
        return self::hms((float) $minutes * 60);
    }

    /** For values stored as decimal hours (estimates, policy hours). */
    public static function fromHours(int|float|string|null $hours): string
    {
        return self::hms((float) $hours * 3600);
    }
}
