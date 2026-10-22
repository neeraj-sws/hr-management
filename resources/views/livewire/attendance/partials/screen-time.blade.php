@php($meeting = (int) ($s->screen_meeting_seconds ?? 0))
@if($s && ((int) $s->screen_active_seconds + (int) $s->screen_idle_seconds + $meeting) > 0)
    {{-- Time the app missed (e.g. it stopped) but an approved correction confirmed counts as working, shown apart. --}}
    @php($corrected = $s->correctedScreenSeconds())
    @php($total = (int) $s->screen_active_seconds + (int) $s->screen_idle_seconds + $meeting + $corrected)
    <span title="Computer in use, in online meetings, and untouched while checked in (desktop app)">@hms((int) $s->screen_active_seconds) active</span>
    <small>@if($meeting)@hms($meeting) in meetings · @endif @if($corrected)<span title="The desktop app did not record this time, but an approved attendance correction confirmed it as working time">@hms($corrected) added by approved correction</span> · @endif @hms((int) $s->screen_idle_seconds) idle · {{ (int) round(((int) $s->screen_active_seconds + $meeting + $corrected) * 100 / $total) }}% working</small>
@else
    <span class="muted small">no desktop app</span>
@endif
