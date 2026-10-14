@if($s && ((int) $s->screen_active_seconds + (int) $s->screen_idle_seconds) > 0)
    @php($total = (int) $s->screen_active_seconds + (int) $s->screen_idle_seconds)
    <span title="Computer in use vs. untouched while checked in (desktop app)">@hms((int) $s->screen_active_seconds) active</span>
    <small>@hms((int) $s->screen_idle_seconds) idle · {{ (int) round($s->screen_active_seconds * 100 / $total) }}% in use</small>
@else
    <span class="muted small">no desktop app</span>
@endif
