@php($meeting = (int) ($s->screen_meeting_seconds ?? 0))
@if($s && ((int) $s->screen_active_seconds + (int) $s->screen_idle_seconds + $meeting) > 0)
    @php($total = (int) $s->screen_active_seconds + (int) $s->screen_idle_seconds + $meeting)
    <span title="Computer in use, in online meetings, and untouched while checked in (desktop app)">@hms((int) $s->screen_active_seconds) active</span>
    <small>@if($meeting)@hms($meeting) in meetings · @endif @hms((int) $s->screen_idle_seconds) idle · {{ (int) round(((int) $s->screen_active_seconds + $meeting) * 100 / $total) }}% working</small>
@else
    <span class="muted small">no desktop app</span>
@endif
