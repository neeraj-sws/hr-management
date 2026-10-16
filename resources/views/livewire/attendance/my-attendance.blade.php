<div @if($session && !$session->check_out) wire:poll.30s @endif>
    <div class="page-head"><div><h1>My Attendance</h1><p>{{ today()->format('l, d F Y') }} · @if($rules['shift']){{ $rules['shift'] }} shift, starts @else Scheduled @endif{{ $rules['work_start'] ? substr($rules['work_start'], 0, 5) : 'flexible' }}@if($rules['grace_minutes']) (+{{ $rules['grace_minutes'] }} min grace)@endif</p></div><a class="btn btn-sm" href="{{ route('attendance.corrections') }}"><x-icon name="edit" class="icon-sm" />Corrections</a></div>
    <x-errors />
    @if($session && $session->wasForcedOut())
        <div class="alert alert-info">{{ $session->forcedBy?->name ?? 'An administrator' }} checked you out at {{ $session->check_out->format('h:i A') }}. Reason: {{ $session->forcedReasonLabel() }}. You cannot check out again today — <a href="{{ route('attendance.corrections', ['date' => $session->work_date->toDateString()]) }}">ask for a correction</a> if this is wrong.</div>
    @endif

    <section class="card card-body mb">
        <div class="clock-card">
            <div>
                <p class="eyebrow">Worked today</p>
                <div class="timer-display">@hms($worked)</div>
                <div class="pill-row mt">
                    @if($session)
                        <span class="pill"><b>In</b>{{ $session->check_in->format('h:i A') }}@if($session->check_in_photo_path) <a href="{{ route('attendance.photo', [$session, 'in']) }}" target="_blank" title="Check-in selfie">📸</a>@endif @if($session->check_in_latitude) <span title="Location recorded">📍</span>@endif</span>
                        @if($session->check_out)<span class="pill"><b>Out</b>{{ $session->check_out->format('h:i A') }}</span>@endif
                        <span class="pill"><b>Break</b>@hms((int) $session->break_seconds)</span>
                        @if($session->idleSeconds())<span class="pill"><b>Idle</b>@hms($session->idleSeconds())</span>@endif
                        <x-badge :status="$session->status" />
                        @if($late)<x-badge status="late" />@endif
                    @else
                        <span class="pill">Not checked in yet</span>
                    @endif
                </div>
            </div>
            {{-- The browser's position is sent with check-in/out when the employee allows it (required when Settings say so). --}}
            <div class="actions" x-data="{
                    locating: false, geoError: '',
                    go(action) {
                        if (! navigator.geolocation) { if (@js($locationRequired)) { this.geoError = 'This browser cannot share your location; use the mobile app.'; return; } return $wire[action](); }
                        this.locating = true; this.geoError = '';
                        navigator.geolocation.getCurrentPosition(
                            (p) => { this.locating = false; $wire[action](p.coords.latitude, p.coords.longitude, p.coords.accuracy); },
                            () => { this.locating = false; if (@js($locationRequired)) { this.geoError = 'Allow location for this site: your company requires check-in at an office.'; } else { $wire[action](); } },
                            { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 });
                    } }">
                @if($selfieRequired && (! $session || ! $session->check_out))
                    <label class="field" style="min-width:220px"><span>Selfie {{ ! $session ? 'for check-in' : 'for check-out' }}</span>
                        <input type="file" accept="image/*" capture="user" wire:model="photo">
                        <span wire:loading wire:target="photo" class="small muted">Uploading photo…</span>
                        @if($photo && ! $errors->has('photo') && method_exists($photo, 'temporaryUrl'))<img src="{{ $photo->temporaryUrl() }}" alt="Your selfie" style="width:72px;height:72px;object-fit:cover;border-radius:10px;margin-top:6px">@endif
                        @error('photo')<span class="error">{{ $message }}</span>@enderror
                    </label>
                @endif
                <span x-show="locating" class="small muted">Getting your location…</span>
                <span x-show="geoError" x-text="geoError" class="error"></span>
                @if(!$session)
                    <button class="btn btn-primary btn-lg" x-on:click="go('checkIn')" x-bind:disabled="locating" wire:loading.attr="disabled" @disabled($selfieRequired && ! $photo)><x-icon name="login" />Check in</button>
                @elseif(!$session->check_out)
                    @if($session->status === 'on_break')
                        <button class="btn btn-success btn-lg" wire:click="resume" wire:loading.attr="disabled"><x-icon name="play" />Resume work</button>
                    @else
                        <button class="btn btn-lg" wire:click="startBreak" wire:loading.attr="disabled"><x-icon name="coffee" />Start break</button>
                    @endif
                    <button class="btn btn-danger btn-lg" x-on:click="if (confirm('Check out for today? Any running task timer will be stopped.')) go('checkOut')" x-bind:disabled="locating" wire:loading.attr="disabled" @disabled($selfieRequired && ! $photo)><x-icon name="logout" />Check out</button>
                @else
                    <x-badge status="completed">Day completed</x-badge>
                @endif
                <a class="btn btn-lg" href="{{ route('work.daily-status') }}"><x-icon name="edit" />Daily status</a>
            </div>
        </div>
        @if($session && $session->events->isNotEmpty())
            <div class="divider"></div>
            <p class="eyebrow">Today's timeline</p>
            <div class="pill-row">@foreach($session->events->sortBy('event_time') as $event)<span class="pill"><b>{{ $event->event_time->format('h:i A') }}</b>{{ str($event->event_type)->replace('_', ' ')->title() }}@if($event->duration_seconds) (@hms($event->duration_seconds))@endif</span>@endforeach</div>
        @endif
    </section>

    <section class="card">
        <div class="card-head">
            <div><h2>{{ $monthStart->format('F Y') }}</h2><p>{{ ($summary['present'] ?? 0) + ($summary['late'] ?? 0) + ($summary['half_day'] ?? 0) }} present · {{ $summary['late'] ?? 0 }} late · {{ $summary['half_day'] ?? 0 }} half-day · {{ $summary['on_leave'] ?? 0 }} leave · {{ $summary['absent'] ?? 0 }} absent</p>
                @if($time['days'])<p class="small">Actual working time <strong>@hms($time['worked'])</strong> of @hms($time['required']) required on {{ $time['days'] }} {{ str('day')->plural($time['days']) }}@if($time['worked'] < $time['required']) · <span style="color:var(--amber)">@hms($time['required'] - $time['worked']) short</span>@endif · In–out time @hms($time['span']) (break @hms($time['break']), idle @hms($time['idle']))</p>@endif</div>
            <label class="field"><span class="sr-only">Month</span><input type="month" wire:model.live="month" max="{{ now()->format('Y-m') }}"></label>
        </div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Date</th><th>Shift</th><th>Check in</th><th>Check out</th><th class="num" title="Check-out minus check-in">In–out</th><th class="num">Break</th><th class="num">Idle</th><th class="num" title="In–out minus break minus idle">Actual working time</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @foreach($days->reverse() as $day)
                @php($dayShift = app(\App\Services\ShiftService::class)->shiftFor($employee, $day['date']))
                @php($required = (int) round(app(\App\Services\AttendanceService::class)->dailyHours($employee, $day['date']) * 3600))
                <tr><td class="nowrap">{{ \Illuminate\Support\Carbon::parse($day['date'])->format('D, d M') }}</td>
                    <td class="nowrap">@if($dayShift){{ $dayShift->name }}<small>{{ $dayShift->hours() }}</small>@else<span class="muted small">default</span>@endif</td>
                    <td>{{ $day['session']?->check_in?->format('h:i A') ?? '—' }}</td><td>{{ $day['session']?->check_out?->format('h:i A') ?? '—' }}</td>
                    @php($worked = $day['session'] ? app(\App\Services\AttendanceService::class)->workedSeconds($day['session']) : null)
                    @php($idle = $day['session'] ? $day['session']->idleSeconds() : null)
                    @php($span = $day['session']?->check_in ? ($day['session']->check_out ? (int) $day['session']->check_in->diffInSeconds($day['session']->check_out) : $worked + (int) $day['session']->break_seconds + $idle) : null)
                    <td class="num">{{ $span !== null ? \App\Support\Duration::hms($span) : '—' }}</td>
                    <td class="num">{{ $day['session'] ? '− '.\App\Support\Duration::hms((int) $day['session']->break_seconds) : '—' }}</td>
                    <td class="num">{{ $day['session'] ? '− '.\App\Support\Duration::hms($idle) : '—' }}</td>
                    <td class="num nowrap">@if($worked !== null)<strong style="color:{{ $day['session']->check_out && $worked < $required ? 'var(--amber)' : 'var(--green)' }}">@hms($worked)</strong><small>of @hms($required)@if($day['session']->check_out && $worked < $required) · @hms($required - $worked) short @elseif($day['session']->check_out && $worked > $required) · +@hms($worked - $required) @endif</small>@else — @endif</td>
                    <td>@if($day['status'] === 'weekend' && ($offReason = $day['label'] ?? null))<x-badge tone="violet">Weekly off</x-badge><small>{{ $offReason }}</small>@elseif($day['status'] === 'weekend')<x-badge status="weekend">Weekly off</x-badge>@else<x-badge :status="$day['status']" />@if(! empty($day['label']))<small>{{ $day['label'] }}</small>@endif @endif</td>
                    <td>@if(in_array($day['status'], ['present', 'late', 'half_day', 'absent'], true) && (! $day['session'] || ! $day['session']->check_out || $day['status'] !== 'present'))<a class="btn btn-sm" href="{{ route('attendance.corrections', ['date' => $day['date']]) }}" title="Ask your manager to correct this day">Correct</a>@endif</td></tr>
            @endforeach
            </tbody>
        </table></div>
    </section>
</div>
