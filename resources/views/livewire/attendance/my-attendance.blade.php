<div class="ma-page" @if($session && !$session->check_out) wire:poll.30s @endif>
    <div class="page-head"><div><h1>My Attendance</h1><p>{{ today()->format('l, d F Y') }} · @if($rules['shift']){{ $rules['shift'] }} shift, starts @else Scheduled @endif{{ $rules['work_start'] ? substr($rules['work_start'], 0, 5) : 'flexible' }}@if($rules['grace_minutes']) (+{{ $rules['grace_minutes'] }} min grace)@endif</p></div><a class="btn btn-sm" href="{{ route('attendance.corrections') }}"><x-icon name="edit" class="icon-sm" />Corrections</a></div>
    <x-errors />
    @if($session && $session->wasForcedOut())
        <div class="alert alert-info">{{ $session->forcedBy?->name ?? 'An administrator' }} checked you out at {{ $session->check_out->format('h:i A') }}. Reason: {{ $session->forcedReasonLabel() }}. You cannot check out again today — <a href="{{ route('attendance.corrections', ['date' => $session->work_date->toDateString()]) }}">ask for a correction</a> if this is wrong.</div>
    @endif

    <section class="card card-body mb" wire:key="today-{{ now()->timestamp }}" @if($live) x-data="swsLive(@js($live))" @endif>
        <div class="clock-card">
            <div>
                <p class="eyebrow">Worked today
                    @if($session && ! $session->check_out)<span class="ta-pill {{ $session->status === 'on_break' ? 'ta-pill-break' : 'ta-pill-work' }}"><i></i>{{ $session->status === 'on_break' ? 'On break' : 'Working' }}</span>
                    @elseif($session)<span class="ta-pill ta-pill-done"><i></i>Day done</span>@endif</p>
                <div class="timer-display"><span x-text="hms(v.worked)">@hms($live['worked'] ?? 0)</span></div>
                <div class="pill-row mt">
                    @if($session)
                        <span class="pill"><b>In</b>{{ $session->check_in->format('h:i A') }}@if($session->check_in_photo_path) <a href="{{ route('attendance.photo', [$session, 'in']) }}" target="_blank" title="Check-in selfie">📸</a>@endif @if($session->check_in_latitude) <span title="Location recorded">📍</span>@endif</span>
                        @if($session->check_out)<span class="pill"><b>Out</b>{{ $session->check_out->format('h:i A') }}</span>@endif
                        <span class="pill" title="Check-in to now (or check-out)"><b>In–out</b><span x-text="hms(v.inOut)">@hms($live['in_out'])</span></span>
                        <span class="pill"><b>Break</b><span x-text="hms(v.brk)">@hms($live['break'])</span></span>
                        <span class="pill"><b>Idle</b><span x-text="hms(v.idle)">@hms($live['idle'])</span></span>
                        @if($live['screen']['meeting'] || $live['state'] === 'meeting')<span class="pill" title="Online meetings (Teams, Zoom, Google Meet) and away time you confirmed as working — counted as working time, not idle"><b>Meeting</b><span x-text="hms(v.meeting)">@hms($live['screen']['meeting'])</span></span>@endif
                        <x-badge :status="$session->status" />
                        @if($late)<x-badge status="late" />@endif
                    </div>
                    <div class="small mt" title="From the SWS HR desktop app">
                        <b>Screen time</b> @include('livewire.attendance.partials.screen-time', ['s' => $session, 'f' => $live])
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
    </section>

    {{-- Timeline in plain words; screen changes are recorded only when the state changes, so "Now" closes it. --}}
    @php($evs = $session ? collect(\App\Support\DayTimeline::for($session)) : collect())
    <div class="tabs ma-tabs" role="tablist">
        <button type="button" role="tab" @class(['tab', 'active' => $tab !== 'today']) wire:click="$set('tab', 'month')">{{ $monthStart->format('F Y') }}</button>
        <button type="button" role="tab" @class(['tab', 'active' => $tab === 'today']) wire:click="$set('tab', 'today')">Today's timeline @if($evs->count())<span class="ma-tab-count">{{ $evs->count() }}</span>@endif</button>
    </div>

    @if($tab === 'today')
    <section class="card card-body">
        @if($evs->isNotEmpty())
            <p class="eyebrow">Today's timeline · {{ today()->format('d M Y') }}</p>
            <ol class="ma-timeline">@foreach($evs as $row)<li class="ma-ev ma-ev-{{ $row['kind'] }}"><b>{{ $row['kind'] === 'now' ? 'Now' : $row['time']->format('h:i A') }}</b><span>{{ $row['kind'] === 'now' ? \Illuminate\Support\Str::after($row['label'], 'Now · ') : $row['label'] }}</span>@if($row['detail'])<small>{{ $row['detail'] }}</small>@endif</li>@endforeach</ol>
            <p class="small muted ma-tl-note">The desktop app adds a row only when something changes (break, idle, meeting, back on the computer). A long stretch of steady work has no rows in between.</p>
        @else
            <x-empty title="{{ $session ? 'Nothing recorded yet today' : 'Not checked in yet today' }}" icon="clock" />
        @endif
    </section>
    @else
    <section class="card">
        <div class="card-head">
            <div><h2>{{ $monthStart->format('F Y') }}</h2><p>{{ ($summary['present'] ?? 0) + ($summary['late'] ?? 0) + ($summary['half_day'] ?? 0) }} present · {{ $summary['late'] ?? 0 }} late · {{ $summary['half_day'] ?? 0 }} half-day · {{ $summary['on_leave'] ?? 0 }} leave @if($summary['extra_work'] ?? 0)· {{ $summary['extra_work'] }} extra work @endif · {{ $summary['absent'] ?? 0 }} absent</p>
                @if($time['days'])<p class="small">Worked <strong>@hms($time['worked'])</strong> of @hms($time['required']) required on {{ $time['days'] }} {{ str('day')->plural($time['days']) }}@if($time['worked'] < $time['required']) · <span style="color:var(--amber)">@hms($time['required'] - $time['worked']) short</span>@endif · In–out time @hms($time['span']) (break @hms($time['break']), idle @hms($time['idle']))</p>@endif</div>
            <div class="toolbar" style="border:0;padding:0">
                <div class="seg" role="group" aria-label="View"><button type="button" @class(['btn btn-sm', 'btn-primary' => $view !== 'calendar']) wire:click="$set('view', 'list')">List</button><button type="button" @class(['btn btn-sm', 'btn-primary' => $view === 'calendar']) wire:click="$set('view', 'calendar')">Calendar</button></div>
                <label class="field"><span class="sr-only">Month</span><input type="month" wire:model.live="month" max="{{ now()->format('Y-m') }}"></label>
            </div>
        </div>
        @php($presentDays = ($summary['present'] ?? 0) + ($summary['late'] ?? 0) + ($summary['half_day'] ?? 0))
        <div class="ma-tiles">
            @foreach([['Present', $presentDays, 'ta-green'], ['Late', $summary['late'] ?? 0, 'ta-amber'], ['Half-day', $summary['half_day'] ?? 0, 'ta-yellow'], ['On leave', $summary['on_leave'] ?? 0, 'ta-violet'], ['Absent', $summary['absent'] ?? 0, 'ta-red']] as [$label, $count, $tone])
                <div class="ma-tile {{ $tone }}"><span>{{ $label }}</span><strong>{{ $count }}</strong></div>
            @endforeach
            @if($time['days'])
                <div class="ma-tile ma-tile-wide"><span>Worked this month</span><strong>@hms($time['worked'])</strong><small>of @hms($time['required']) required</small>
                    <div class="docs-progress"><span style="width:{{ min(100, round($time['worked'] * 100 / max(1, $time['required']))) }}%;{{ $time['worked'] < $time['required'] ? 'background:#EF9F27' : '' }}"></span></div></div>
            @endif
        </div>
        @if($view === 'calendar')
            <x-attendance-calendar :days="$days" :month="$monthStart" />
        @else
        <div x-data="{ hideOff: false }">
        <div class="ma-listbar"><span class="small muted">Click a day for in–out, break, idle and screen time</span><button type="button" class="docs-chip" :class="hideOff && 'is-active'" x-on:click="hideOff = ! hideOff" :aria-pressed="hideOff ? 'true' : 'false'">Hide off days</button></div>
        <div class="table-wrap"><table class="table ma-table ma-compact" data-no-sort>
            <thead><tr><th>Date</th><th>Check in</th><th>Check out</th><th class="num" title="In–out minus break minus idle">Worked</th><th>Status</th><th></th><th></th></tr></thead>
            @foreach($days->reverse() as $day)
                @php($dayShift = app(\App\Services\ShiftService::class)->shiftFor($employee, $day['date']))
                @php($required = (int) round(app(\App\Services\AttendanceService::class)->dailyHours($employee, $day['date']) * 3600))
                {{-- The same figures as the admin list, reports and the apps; today's open day counts on each second. --}}
                @php($f = $day['session'] ? app(\App\Services\AttendanceService::class)->liveFigures($day['session']) : null)
                @php($worked = $f['worked'] ?? null)
                @php($idle = $f['idle'] ?? null)
                @php($span = $day['session']?->check_in ? $f['in_out'] : null)
                @php($isOff = ! $day['session'] && in_array($day['status'], ['weekend', 'holiday'], true))
                @php($statusCell = view('livewire.attendance.partials.my-day-status', ['day' => $day])->render())
                @if($isOff)
                <tbody class="ma-off" wire:key="day-{{ $day['date'] }}-{{ now()->timestamp }}" x-show="! hideOff">
                    <tr><td class="nowrap">{{ \Illuminate\Support\Carbon::parse($day['date'])->format('D, d M') }}</td><td colspan="6">{!! $statusCell !!}</td></tr>
                </tbody>
                @else
                <tbody class="ma-day" wire:key="day-{{ $day['date'] }}-{{ now()->timestamp }}" x-data="{ open: false }" :class="open && 'is-open'">
                    <tr class="ma-main" x-on:click="if (! $event.target.closest('a,button')) open = ! open" @if($f) x-data="swsLive(@js($f))" @endif>
                        <td class="nowrap">{{ \Illuminate\Support\Carbon::parse($day['date'])->format('D, d M') }}@if($dayShift)<small>{{ $dayShift->name }} · {{ $dayShift->hours() }}</small>@endif</td>
                        <td>{{ $day['session']?->check_in?->format('h:i A') ?? '—' }}</td>
                        <td>{{ $day['session']?->check_out?->format('h:i A') ?? '—' }}@if($day['session']?->autoCheckedOut())<small>auto check-out (left the computer)</small>@endif</td>
                        <td class="num nowrap">@if($worked !== null)<strong style="color:{{ $day['session']->check_out && $worked < $required ? 'var(--amber)' : 'var(--green)' }}"><span x-text="hms(v.worked)">@hms($worked)</span></strong>@if($day['session']->check_out && $worked < $required)<small class="ma-short">@hms($required - $worked) short</small>@elseif($day['session']->check_out && $worked > $required)<small class="ma-extra">+@hms($worked - $required)</small>@endif @if($day['session']->check_out)<small class="att-atwork" title="At work = check-in to check-out less breaks (idle and meetings are not taken off). Under the half-day limit it is a half day.">at work @hms(app(\App\Services\AttendanceService::class)->officeSeconds($day['session']))</small>@endif @else — @endif</td>
                        <td>{!! $statusCell !!}</td>
                        <td class="ma-act">@if($overtimeOn && empty($day['extra']) && $worked !== null && $day['session']->check_out && $worked > $required + 1800)<a class="btn btn-sm" href="{{ route('leave.overtime', ['date' => $day['date'], 'minutes' => intdiv($worked - $required, 60)]) }}" title="Extra time on a working day is paid only after your overtime request is approved">Request overtime</a>@endif @if(in_array($day['status'], ['present', 'late', 'half_day', 'absent'], true) && (! $day['session'] || ! $day['session']->check_out || $day['status'] !== 'present'))<a class="btn btn-sm" href="{{ route('attendance.corrections', ['date' => $day['date']]) }}" title="Ask your manager to correct this day">Correct</a>@endif</td>
                        <td class="ma-chev">@if($day['session'])<x-icon name="chevron-down" class="icon-sm" />@endif</td>
                    </tr>
                    @if($day['session'])
                    <tr class="ma-detail" x-show="open" x-cloak @if($f) x-data="swsLive(@js($f))" @endif>
                        <td colspan="7">
                            <div class="ma-detail-grid">
                                <div><span>In–out</span><b>@if($span !== null)<span x-text="hms(v.inOut)">@hms($span)</span>@else — @endif</b></div>
                                <div><span>Break</span><b><span x-text="'− ' + hms(v.brk)">− @hms($f['break'])</span></b></div>
                                <div><span>Idle</span><b><span x-text="'− ' + hms(v.idle)">− @hms($idle)</span></b></div>
                                <div><span>Worked</span><b><span x-text="hms(v.worked)">@hms($worked)</span> <small>of @hms($required)</small></b></div>
                                @if($day['session']->check_out)<div title="At work = check-in to check-out less breaks (idle and meetings are not taken off). Under the half-day limit it is a half day."><span>At work (half-day check)</span><b>@hms(app(\App\Services\AttendanceService::class)->officeSeconds($day['session']))</b></div>@endif
                                <div><span>Shift</span><b>{{ $dayShift ? $dayShift->name.' · '.$dayShift->hours() : 'default' }}</b></div>
                            </div>
                            <div class="small ma-screen"><b>Screen time</b> @include('livewire.attendance.partials.screen-time', ['s' => $day['session'], 'f' => $f])</div>
                        </td>
                    </tr>
                    @endif
                </tbody>
                @endif
            @endforeach
        </table></div>
        </div>
        @endif
    </section>
    @endif
</div>
