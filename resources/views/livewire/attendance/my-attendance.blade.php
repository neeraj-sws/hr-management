<div @if($session && !$session->check_out) wire:poll.30s @endif>
    <div class="page-head"><div><h1>My Attendance</h1><p>{{ today()->format('l, d F Y') }} · @if($rules['shift']){{ $rules['shift'] }} shift, starts @else Scheduled @endif{{ $rules['work_start'] ? substr($rules['work_start'], 0, 5) : 'flexible' }}@if($rules['grace_minutes']) (+{{ $rules['grace_minutes'] }} min grace)@endif</p></div><a class="btn btn-sm" href="{{ route('attendance.corrections') }}"><x-icon name="edit" class="icon-sm" />Corrections</a></div>
    <x-errors />

    <section class="card card-body mb">
        <div class="clock-card">
            <div>
                <p class="eyebrow">Worked today</p>
                <div class="timer-display">{{ sprintf('%02d:%02d', intdiv($worked, 3600), intdiv($worked % 3600, 60)) }}</div>
                <div class="pill-row mt">
                    @if($session)
                        <span class="pill"><b>In</b>{{ $session->check_in->format('h:i A') }}</span>
                        @if($session->check_out)<span class="pill"><b>Out</b>{{ $session->check_out->format('h:i A') }}</span>@endif
                        <span class="pill"><b>Break</b>{{ gmdate('H:i', (int) $session->break_seconds) }}</span>
                        @if((int) $session->idle_seconds)<span class="pill"><b>Idle</b>{{ gmdate('H:i', (int) $session->idle_seconds) }}</span>@endif
                        <x-badge :status="$session->status" />
                        @if($late)<x-badge status="late" />@endif
                    @else
                        <span class="pill">Not checked in yet</span>
                    @endif
                </div>
            </div>
            <div class="actions">
                @if(!$session)
                    <button class="btn btn-primary btn-lg" wire:click="checkIn" wire:loading.attr="disabled"><x-icon name="login" />Check in</button>
                @elseif(!$session->check_out)
                    @if($session->status === 'on_break')
                        <button class="btn btn-success btn-lg" wire:click="resume" wire:loading.attr="disabled"><x-icon name="play" />Resume work</button>
                    @else
                        <button class="btn btn-lg" wire:click="startBreak" wire:loading.attr="disabled"><x-icon name="coffee" />Start break</button>
                    @endif
                    <button class="btn btn-danger btn-lg" wire:click="checkOut" wire:confirm="Check out for today? Any running task timer will be stopped." wire:loading.attr="disabled"><x-icon name="logout" />Check out</button>
                @else
                    <x-badge status="completed">Day completed</x-badge>
                @endif
                <a class="btn btn-lg" href="{{ route('work.daily-status') }}"><x-icon name="edit" />Daily status</a>
            </div>
        </div>
        @if($session && $session->events->isNotEmpty())
            <div class="divider"></div>
            <p class="eyebrow">Today's timeline</p>
            <div class="pill-row">@foreach($session->events->sortBy('event_time') as $event)<span class="pill"><b>{{ $event->event_time->format('h:i A') }}</b>{{ str($event->event_type)->replace('_', ' ')->title() }}@if($event->duration_seconds) ({{ gmdate('H:i', $event->duration_seconds) }})@endif</span>@endforeach</div>
        @endif
    </section>

    <section class="card">
        <div class="card-head">
            <div><h2>{{ $monthStart->format('F Y') }}</h2><p>{{ ($summary['present'] ?? 0) + ($summary['late'] ?? 0) + ($summary['half_day'] ?? 0) }} present · {{ $summary['late'] ?? 0 }} late · {{ $summary['half_day'] ?? 0 }} half-day · {{ $summary['on_leave'] ?? 0 }} leave · {{ $summary['absent'] ?? 0 }} absent</p></div>
            <label class="field"><span class="sr-only">Month</span><input type="month" wire:model.live="month" max="{{ now()->format('Y-m') }}"></label>
        </div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Date</th><th>Check in</th><th>Check out</th><th class="num">Active</th><th class="num">Break</th><th class="num">Idle</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @foreach($days->reverse() as $day)
                <tr><td class="nowrap">{{ \Illuminate\Support\Carbon::parse($day['date'])->format('D, d M') }}</td>
                    <td>{{ $day['session']?->check_in?->format('h:i A') ?? '—' }}</td><td>{{ $day['session']?->check_out?->format('h:i A') ?? '—' }}</td>
                    <td class="num">{{ $day['session'] ? gmdate('H:i', (int) $day['session']->active_seconds) : '—' }}</td>
                    <td class="num">{{ $day['session'] ? gmdate('H:i', (int) $day['session']->break_seconds) : '—' }}</td>
                    <td class="num">{{ $day['session'] ? gmdate('H:i', (int) $day['session']->idle_seconds) : '—' }}</td>
                    <td><x-badge :status="$day['status']" /></td>
                    <td>@if(in_array($day['status'], ['present', 'late', 'half_day', 'absent'], true) && (! $day['session'] || ! $day['session']->check_out || $day['status'] !== 'present'))<a class="btn btn-sm" href="{{ route('attendance.corrections', ['date' => $day['date']]) }}" title="Ask your manager to correct this day">Correct</a>@endif</td></tr>
            @endforeach
            </tbody>
        </table></div>
    </section>
</div>
