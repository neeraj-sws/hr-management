{{-- Screen time from the desktop app, the same figures as the employee page, reports and the apps
     (AttendanceService::liveFigures). Inside an x-data="swsLive(...)" row the numbers count on each second. --}}
@php($f = $f ?? ($s ? app(\App\Services\AttendanceService::class)->liveFigures($s) : null))
@php($t = $f['screen'] ?? null)
@if($t && ($t['active'] + $t['idle'] + $t['meeting']) > 0)
    @php($total = $t['active'] + $t['idle'] + $t['meeting'] + $t['corrected'])
    <span title="Computer in use, in meetings, and untouched while checked in (desktop app)"><span x-text="hms(v.active)">@hms($t['active'])</span> in use</span>
    <small>@if($t['meeting'] || $f['state'] === 'meeting')<span title="Online meetings, plus away time the employee confirmed as working (e.g. a meeting away from the computer)"><span x-text="hms(v.meeting)">@hms($t['meeting'])</span> in meetings</span> · @endif @if($t['corrected'])<span title="The desktop app did not record this time, but an approved attendance correction confirmed it as working time">@hms($t['corrected']) added by approved correction</span> · @endif <span x-text="hms(v.sIdle)">@hms($t['idle'])</span> idle · @if($t['untracked'] >= 60)<span title="Working time the desktop app did not see: it was closed, being updated, or opened after check-in. Counted as worked.">@hms($t['untracked']) not tracked</span> · @endif<span x-text="pct">{{ (int) round(($t['active'] + $t['meeting'] + $t['corrected']) * 100 / $total) }}</span>% working</small>
@else
    <span class="muted small" title="Idle and screen time come from the SWS HR desktop app; it has not reported for this day">no desktop app</span>
@endif
