{{-- Same figures as the employee page, reports and the apps (AttendanceSession::screenTime). --}}
@php($t = $s ? $s->screenTime() : null)
@if($t && ($t['active'] + $t['idle'] + $t['meeting']) > 0)
    {{-- Time the app missed (e.g. it stopped) but an approved correction confirmed counts as working, shown apart. --}}
    @php($total = $t['active'] + $t['idle'] + $t['meeting'] + $t['corrected'])
    <span title="Computer in use, in meetings, and untouched while checked in (desktop app)">@hms($t['active']) active</span>
    <small>@if($t['meeting'])<span title="Online meetings, plus away time the employee confirmed as working (e.g. a meeting away from the computer)">@hms($t['meeting']) in meetings</span> · @endif @if($t['corrected'])<span title="The desktop app did not record this time, but an approved attendance correction confirmed it as working time">@hms($t['corrected']) added by approved correction</span> · @endif @hms($t['idle']) idle · {{ (int) round(($t['active'] + $t['meeting'] + $t['corrected']) * 100 / $total) }}% working</small>
@else
    <span class="muted small">no desktop app</span>
@endif
