<?php

namespace App\Support;

use App\Services\PolicyConfigurationService;

/**
 * "Personal work / personal reason" is not a valid reason: a leave request or a late fine's reason that says so is
 * rejected automatically, and the employee is told why. Settings > Leave types: on/off for leave and for late fines,
 * and the words that count as a personal reason (whole words, any case).
 */
class PersonalReason
{
    public const KEY = 'reasons.personal_auto_reject';

    public const DEFAULT_PHRASES = ['personal', 'private work', 'own work', 'niji', 'nijee'];

    /** @return array{leave: bool, late: bool, phrases: list<string>} */
    public static function settings(): array
    {
        $saved = app(PolicyConfigurationService::class)->forEmployee(null, self::KEY, today()->toDateString(), []);
        $phrases = array_key_exists('phrases', $saved) ? self::clean((array) $saved['phrases']) : self::DEFAULT_PHRASES;

        return ['leave' => (bool) ($saved['leave'] ?? true), 'late' => (bool) ($saved['late'] ?? true), 'phrases' => $phrases];
    }

    /** @return list<string> lower-case, trimmed, no blanks or repeats */
    public static function clean(array $phrases): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($p) => mb_strtolower(trim((string) $p)), $phrases), fn ($p) => $p !== '')));
    }

    /** The word that made the reason personal, or null ($for: "leave" or "late"; off = never). */
    public static function match(?string $reason, string $for): ?string
    {
        $settings = self::settings();
        if (! ($settings[$for] ?? false)) return null;
        foreach ($settings['phrases'] as $phrase) {
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($phrase, '/').'(?![\p{L}\p{N}])/iu', (string) $reason)) return $phrase;
        }

        return null;
    }

    /** Why it was rejected, kept with the request and sent to the employee. */
    public static function note(string $for): string
    {
        return $for === 'leave'
            ? 'Rejected automatically: a personal reason (personal work / personal reason) is not accepted for leave. Apply again with a valid reason, e.g. illness, a family emergency or an exam.'
            : 'Rejected automatically: a personal reason (personal work / personal reason) is not accepted for coming late. Give a valid reason, e.g. a medical issue, an accident or a traffic block.';
    }
}
