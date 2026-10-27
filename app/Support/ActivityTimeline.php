<?php

namespace App\Support;

use App\Models\AttendanceSession;
use Illuminate\Support\Carbon;

/**
 * One day's screen activity as a timeline, from what is recorded: check-in/out, the desktop app's state
 * changes (in use / idle / meeting), breaks, and "I was working" answers (with the employee's note).
 * Each segment: start, end, seconds, kind (work | meeting | idle | break | away_work | untracked), label.
 * Apps are recorded per day, not per minute, so a work segment says "Working on the computer"; the
 * Apps & Websites tab shows which apps the day was spent in.
 */
class ActivityTimeline
{
    public const KINDS = [
        'work' => 'Working on the computer',
        'meeting' => 'In an online meeting',
        'idle' => 'Idle / no activity',
        'break' => 'Break',
        'away_work' => 'Away from the computer, working',
        'untracked' => 'Checked in (no desktop app data)',
    ];

    /** @return list<array{start: Carbon, end: Carbon, seconds: int, kind: string, label: string, note: ?string}> */
    public static function for(AttendanceSession $session): array
    {
        if (! $session->check_in) return [];
        $start = $session->check_in->copy();
        $end = $session->check_out?->copy() ?? Carbon::now()->min($session->work_date->copy()->endOfDay());
        if ($end->lte($start)) return [];
        $events = $session->relationLoaded('events') ? $session->events->sortBy('event_time')->values() : $session->events()->orderBy('event_time')->get();

        // 1. The computer's state over the day (no state reported at all: one "no desktop data" stretch).
        $states = $events->where('event_type', 'screen_state');
        $base = [];
        $state = $states->isEmpty() ? 'untracked' : 'work';
        $at = $start->copy();
        foreach ($states as $e) {
            $time = $e->event_time->copy()->max($start)->min($end);
            $base[] = [$at->copy(), $time->copy(), $state];
            $state = ['active' => 'work', 'idle' => 'idle', 'meeting' => 'meeting'][$e->metadata['state'] ?? 'active'] ?? 'work';
            $at = $time;
        }
        $base[] = [$at->copy(), $end->copy(), $state];

        // 2. Breaks and "I was working" answers cover what was under them.
        $overlays = [];
        $breakFrom = null;
        foreach ($events as $e) {
            if ($e->event_type === 'break_start') $breakFrom = $e->event_time->copy();
            if ($e->event_type === 'break_end' && $breakFrom) {
                $overlays[] = [$breakFrom, $e->event_time->copy(), 'break', ($e->metadata['reason'] ?? null) === 'asleep' ? 'Computer off or asleep' : null];
                $breakFrom = null;
            }
            if ($e->event_type === 'away_worked') {
                $from = Carbon::parse($e->metadata['from'] ?? $e->event_time->copy()->subSeconds((int) $e->duration_seconds));
                $overlays[] = [$from, $e->event_time->copy(), 'away_work', $e->metadata['note'] ?? null];
            }
        }
        if ($breakFrom) $overlays[] = [$breakFrom, $end->copy(), 'break', 'still on break'];

        $segments = array_map(fn ($s) => [$s[0], $s[1], $s[2], null], $base);
        foreach ($overlays as [$from, $to, $kind, $note]) {
            $from = $from->max($start);
            $to = $to->min($end);
            if ($to->lte($from)) continue;
            $next = [];
            foreach ($segments as [$a, $b, $k, $n]) {
                if ($b->lte($from) || $a->gte($to)) { $next[] = [$a, $b, $k, $n]; continue; }
                if ($a->lt($from)) $next[] = [$a, $from->copy(), $k, $n];
                if ($b->gt($to)) $next[] = [$to->copy(), $b, $k, $n];
            }
            $next[] = [$from->copy(), $to->copy(), $kind, $note];
            usort($next, fn ($x, $y) => $x[0] <=> $y[0]);
            $segments = $next;
        }

        // 3. Join neighbours of the same kind; leave out blips under 20 seconds.
        $out = [];
        foreach ($segments as [$a, $b, $k, $n]) {
            if ($b->diffInSeconds($a, true) < 20) continue;
            $last = count($out) - 1;
            if ($last >= 0 && $out[$last]['kind'] === $k && $out[$last]['note'] === $n && $a->diffInSeconds($out[$last]['end'], true) <= 60) {
                $out[$last]['end'] = $b;
                $out[$last]['seconds'] = (int) $out[$last]['start']->diffInSeconds($b, true);
                continue;
            }
            $out[] = ['start' => $a, 'end' => $b, 'seconds' => (int) $a->diffInSeconds($b, true), 'kind' => $k, 'label' => self::KINDS[$k], 'note' => $n];
        }

        return $out;
    }
}
