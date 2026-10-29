@props(['days', 'month'])
{{-- One month of attendance as a calendar: every day coloured by its status, with in–out and worked time. --}}
@php
    $att = app(\App\Services\AttendanceService::class);
    $byDate = collect($days)->keyBy('date');
    $start = \Illuminate\Support\Carbon::parse($month)->startOfMonth();
    $gridStart = $start->copy()->startOfWeek(\Carbon\CarbonInterface::MONDAY);
    $gridEnd = $start->copy()->endOfMonth()->endOfWeek(\Carbon\CarbonInterface::SUNDAY);
    $labels = ['present' => 'Present', 'late' => 'Late', 'half_day' => 'Half-day', 'on_leave' => 'On leave', 'weekend' => 'Weekly off', 'holiday' => 'Holiday',
        'absent' => 'Absent', 'extra_work' => 'Extra work', 'leave_conflict' => 'Worked on leave', 'upcoming' => '', 'not_joined' => 'Before joining', 'left' => 'Left'];
@endphp
<div class="att-cal">
    <div class="att-cal-head">@foreach(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d)<span>{{ $d }}</span>@endforeach</div>
    <div class="att-cal-grid">
        @for($day = $gridStart->copy(); $day->lte($gridEnd); $day->addDay())
            @php($info = $day->month === $start->month ? $byDate->get($day->toDateString()) : null)
            @php($s = $info['session'] ?? null)
            <div @class(['att-cal-day', 'att-cal-out' => $day->month !== $start->month, 'att-cal-today' => $day->isToday(), 'att-'.($info['status'] ?? 'none')])
                 @if($info) title="{{ $day->format('D d M Y') }} · {{ $labels[$info['status']] ?? $info['status'] }}{{ $info['label'] ? ' · '.$info['label'] : '' }}" @endif>
                <span class="att-cal-num">{{ $day->day }}</span>
                @if($info && $info['status'] !== 'upcoming')
                    <span class="att-cal-status">{{ ! empty($info['leave_pending']) ? 'Leave pending' : ($labels[$info['status']] ?? str($info['status'])->replace('_', ' ')->ucfirst()) }}</span>
                    @if($s)
                        <span class="att-cal-time">{{ $s->check_in?->format('h:i A') ?? '—' }} – {{ $s->check_out?->format('h:i A') ?? ($day->isToday() ? 'now' : '—') }}</span>
                        <span class="att-cal-worked" title="Worked: in–out less break and idle">@hms($att->workedSeconds($s))</span>
                        @if($s->check_out && ($latePay = app(\App\Services\HrWorkflowService::class)->lateCheckInPay($s->employee, $s)))@if($latePay['seconds'] >= $latePay['standard'])<span class="att-cal-paid" title="Came after the late check-in time: paid by the hours worked, at most one full working day ({{ \App\Support\Duration::hm($latePay['standard']) }}) on a working day. Time above that is shown but not paid.">paid {{ \App\Support\Duration::hm($latePay['standard']) }} (max){{ $latePay['seconds'] - $latePay['standard'] >= 60 ? ' · +'.\App\Support\Duration::hm($latePay['seconds'] - $latePay['standard']).' extra, not paid' : '' }}</span>@else<span class="att-cal-paid" title="Came after the late check-in time: paid only for the hours worked">paid for {{ \App\Support\Duration::hm($latePay['seconds']) }} of {{ \App\Support\Duration::hm($latePay['standard']) }}</span>@endif@endif
                        @if($s->check_out)<span class="att-cal-atwork" title="At work = check-in to check-out less breaks (idle and meetings are not taken off). Under the half-day limit it is a half day.">at work @hms($att->officeSeconds($s))</span>@endif
                    @elseif($info['label'])
                        <span class="att-cal-time">{{ \Illuminate\Support\Str::limit($info['label'], 28) }}</span>
                    @endif
                @endif
            </div>
        @endfor
    </div>
    <div class="att-cal-legend small">
        @foreach(['present' => 'Present', 'late' => 'Late', 'half_day' => 'Half-day', 'on_leave' => 'Leave', 'holiday' => 'Holiday', 'weekend' => 'Weekly off', 'extra_work' => 'Extra work', 'absent' => 'Absent'] as $k => $l)<span class="att-key att-{{ $k }}">{{ $l }}</span>@endforeach
    </div>
</div>
