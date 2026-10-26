<?php

namespace App\Support;

use App\Models\AttendanceSession;
use Illuminate\Support\Carbon;

/**
 * Today's timeline in plain words. The desktop app records a screen change only when the state changes
 * (working → idle → back, a meeting starts or ends), so a long stretch of work has no rows: the last row
 * ("Now") says what is going on and since when.
 */
class DayTimeline
{
    private const STATES = ['active' => 'Working on the computer', 'idle' => 'Idle: no keyboard or mouse', 'meeting' => 'In a meeting (Teams / Zoom / Meet)'];

    /** @return array<int, array{time: Carbon, kind: string, label: string, detail: ?string}> */
    public static function for(AttendanceSession $session): array
    {
        $events = $session->events->sortBy([['event_time', 'asc'], ['id', 'asc']])->values();
        $rows = [];
        $state = 'active';
        $stateSince = $session->check_in;
        foreach ($events as $i => $e) {
            $type = (string) $e->event_type;
            $meta = (array) ($e->metadata ?? []);
            if ($type === 'screen_state') {
                $new = (string) ($meta['state'] ?? 'active');
                $next = $events->slice($i + 1)->first(fn ($x) => $x->event_type === 'screen_state');
                // Same state again, or a change undone within a minute: nothing happened worth a row.
                if ($new === $state) continue;
                if ($next && ($next->metadata['state'] ?? null) === $state && $e->event_time->diffInSeconds($next->event_time) < 60) continue;
                if ($new === 'active') {
                    $rows[] = ['time' => $e->event_time, 'kind' => 'screen', 'label' => 'Back on the computer', 'detail' => ($state === 'idle' ? 'idle' : 'meeting').' '.Duration::hms((int) $stateSince->diffInSeconds($e->event_time))];
                } else {
                    $rows[] = ['time' => $e->event_time, 'kind' => $new === 'idle' ? 'idle' : 'meeting', 'label' => $new === 'idle' ? 'Idle started (no keyboard or mouse)' : 'Meeting started', 'detail' => null];
                }
                $state = $new;
                $stateSince = $e->event_time;
                continue;
            }
            $label = match ($type) {
                'check_in' => 'Check in',
                'check_out' => 'Check out',
                'auto_check_out' => 'Checked out automatically (left the computer)',
                'break_start' => 'Break started',
                'break_end' => 'Break ended',
                'away_worked' => 'Away from the computer, working',
                'correction' => 'Corrected by HR',
                default => (string) str($type)->replace('_', ' ')->ucfirst(),
            };
            $detail = trim(($e->duration_seconds ? Duration::hms((int) $e->duration_seconds) : '').(! empty($meta['note']) ? ' · '.$meta['note'] : ''), ' ·') ?: null;
            $rows[] = ['time' => $e->event_time, 'kind' => \Illuminate\Support\Str::before($type, '_'), 'label' => $label, 'detail' => $detail];
            if (in_array($type, ['break_end', 'check_in'], true)) { $state = 'active'; $stateSince = $e->event_time; }
        }

        if ($session->check_in && ! $session->check_out) {
            $since = $stateSince ?? $session->check_in;
            $label = $session->status === 'on_break' ? 'On a break' : (self::STATES[$state] ?? self::STATES['active']);
            if ($session->status === 'on_break') $since = $events->where('event_type', 'break_start')->last()?->event_time ?? $since;
            $rows[] = ['time' => now(), 'kind' => 'now', 'label' => 'Now · '.$label, 'detail' => 'since '.$since->format('h:i A').' ('.Duration::hms((int) $since->diffInSeconds(now())).')'];
        }

        return $rows;
    }
}
