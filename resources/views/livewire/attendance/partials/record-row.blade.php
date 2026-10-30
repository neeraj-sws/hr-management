{{-- One attendance session row of Attendance records (compact). Needs $s and $attendance. Full details: View details drawer. --}}
@php($f = $attendance->liveFigures($s))
@php($t = $f['screen'] ?? null)
@php($late = $attendance->lateMinutes($s))
@php($state = $attendance->isHalfDay($s) ? 'half_day' : ($attendance->isLate($s) ? 'late' : $s->status))
<tr wire:key="rec-{{ $s->id }}-{{ now()->timestamp }}" x-data="swsLive(@js($f))">
    <td class="nowrap ar-date" data-label="Date"><strong>{{ $s->work_date->format('d M Y') }}</strong><small>{{ $s->work_date->format('D') }}</small></td>
    <td data-label="Employee"><div class="ar-person"><x-avatar :employee="$s->employee" /><div><strong>{{ $s->employee?->name }}</strong><small>{{ collect([$s->employee?->department?->name, $s->employee?->employee_code])->filter()->join(' · ') }}</small>@if($s->employee)<x-person-badges :employee="$s->employee" />@endif</div></div></td>
    <td data-col="in" data-label="In" class="nowrap">{{ $s->check_in?->format('h:i A') ?? '—' }}
        @if($s->check_in_photo_path || $s->check_in_latitude !== null || $s->check_in_ip)
            <span class="ar-marks">@if($s->check_in_photo_path)<a href="{{ route('attendance.photo', [$s, 'in']) }}" target="_blank" rel="noopener" title="Check-in selfie">📸</a>@endif @if($s->check_in_latitude !== null)<span title="{{ $s->checkInOffice ? 'At '.$s->checkInOffice->name : ($s->check_in_distance_meters !== null ? number_format($s->check_in_distance_meters).' m from office' : 'Location shared') }} ({{ $s->check_in_latitude }}, {{ $s->check_in_longitude }})">📍</span>@endif @if($s->check_in_ip)<span class="ar-ip" title="IP {{ $s->check_in_ip }}">IP</span>@endif</span>
        @endif</td>
    <td data-col="out" data-label="Out" class="nowrap">{{ $s->check_out?->format('h:i A') ?? '—' }}
        @if($s->autoCheckedOut() || $s->check_out_photo_path || $s->check_out_latitude !== null || $s->check_out_ip)
            <span class="ar-marks">@if($s->autoCheckedOut())<span class="ar-auto" title="auto check-out (left the computer): the desktop app checked them out at the last use of the computer, no keyboard/mouse after the shift">auto</span>@endif @if($s->check_out_photo_path)<a href="{{ route('attendance.photo', [$s, 'out']) }}" target="_blank" rel="noopener" title="Check-out selfie">📸</a>@endif @if($s->check_out_latitude !== null)<span title="{{ $s->checkOutOffice ? 'At '.$s->checkOutOffice->name : ($s->check_out_distance_meters !== null ? number_format($s->check_out_distance_meters).' m from office' : 'Location shared') }} ({{ $s->check_out_latitude }}, {{ $s->check_out_longitude }})">📍</span>@endif @if($s->check_out_ip)<span class="ar-ip" title="IP {{ $s->check_out_ip }}">IP</span>@endif</span>
        @endif</td>
    <td data-col="worked" data-label="Worked" class="num"><span x-text="hms(v.worked)">@hms($f['worked'])</span>@if($s->check_out)<small class="att-atwork" title="At work = check-in to check-out less breaks (idle and meetings are not taken off). Under the half-day limit it is a half day.">at work @hms($attendance->officeSeconds($s))</small>@endif</td>
    <td data-col="idle" data-label="Idle" class="num"><span x-text="hms(v.idle)">@hms($f['idle'])</span></td>
    <td data-col="break" data-label="Break" class="num"><span x-text="hms(v.brk)">@hms($f['break'])</span></td>
    <td data-col="screen" data-label="Screen time" class="ar-c ar-screen">
        @if($t && ($t['active'] + $t['idle'] + $t['meeting']) > 0)
            @php($total = $t['active'] + $t['idle'] + $t['meeting'] + $t['corrected'])
            <strong title="Computer in use plus meetings"><span x-text="hms(v.active + v.meeting)">@hms($t['active'] + $t['meeting'])</span> in use</strong>
            <small>@if($t['meeting'])incl. <span x-text="hms(v.meeting)">@hms($t['meeting'])</span> meet · @endif<span x-text="hms(v.sIdle)">@hms($t['idle'])</span> idle · @if($t['untracked'] >= 60)@hms($t['untracked']) not tracked @else<span x-text="pct">{{ (int) round(($t['active'] + $t['meeting'] + $t['corrected']) * 100 / $total) }}</span>% working @endif</small>
            @if(! ($f['idle_deducted'] ?? true))<small class="ar-field">Field / mobile · idle not taken off</small>@endif
        @else
            <span class="muted small" title="Idle and screen time come from the SWS HR desktop app; it has not reported for this day">no desktop app</span>
        @endif
        <button type="button" class="ar-activity" wire:click="openDetail({{ $s->id }}, 'timeline')">@php($activityCount = $s->activity_count ?? $s->events()->whereIn('event_type', ['screen_state', 'break_start', 'away_worked'])->count())View activity{{ $activityCount ? ' ('.$activityCount.')' : '' }}</button>
    </td>
    <td data-col="late" data-label="Late by" @class(['num', 'ar-red' => $late])>{{ $late ? \App\Support\Duration::fromMinutes($late) : '—' }}</td>
    <td data-col="source" data-label="Source" class="small">{{ $s->device?->computer_name ? 'Desktop' : 'Web / mobile' }}@if($s->device?->computer_name)<small class="muted">{{ $s->device->computer_name }}</small>@endif</td>
    <td data-col="status" data-label="Status"><x-badge :status="$state" /></td>
    <td class="ar-act"><button type="button" class="ar-more" wire:click="openDetail({{ $s->id }})" title="View details" aria-label="View details of {{ $s->employee?->name }} on {{ $s->work_date->format('d M') }}"><x-icon name="more" class="icon-sm" /></button></td>
</tr>
