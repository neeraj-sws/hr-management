@props(['s'])
{{-- "Why the salary is less than full": full salary, then late check-ins (hours worked and rupees), then other cuts, then what is paid. --}}
@if($s)
@php($rs = fn ($v) => '₹'.number_format((float) $v, 2))
<div class="why-less">
    <div class="why-less-head"><strong>{{ ($s['lines'] || $s['late']) ? 'Why the salary is less than full' : 'Full salary — nothing cut' }}</strong>@if($s['estimate'])<small>this month so far · final after month end</small>@endif</div>
    <div class="wl-row wl-full"><span>{{ $s['full_label'] }}<small>{{ $rs($s['rate']) }} a day{{ $s['hourly'] ? ' · '.$s['day_hours'].' a day = '.$rs($s['hourly']).' an hour' : '' }}</small></span><b>{{ $rs($s['full']) }}</b></div>

    @if($s['late'])
        <div class="wl-block">
            @php($leaveRows = collect($s['late'])->whereNotNull('leave')->count())
            <div class="wl-row wl-title"><span>{{ $s['late_title'] ?? ($leaveRows === count($s['late']) ? 'Half-day leave — paid only for the hours worked' : ($leaveRows ? 'Paid only for the hours worked (late check-in or half-day leave)' : 'Came after the late check-in time — paid only for the hours worked')) }}<small>{{ count($s['late']) }} {{ count($s['late']) === 1 ? 'day' : 'days' }}</small></span><b class="wl-neg">−{{ $rs($s['late_cut']) }}</b></div>
            <div class="table-wrap"><table class="wl-table">
                <thead><tr><th>Date</th><th>Came in</th><th>Worked</th><th class="num">Paid</th><th class="num">Not paid</th></tr></thead>
                <tbody>@foreach($s['late'] as $l)<tr><td class="nowrap">{{ $l['day'] }}, {{ $l['date'] }}</td><td class="nowrap">{{ $l['in'] }}@if(! empty($l['leave']))<small>{{ $l['leave'] }}</small>@endif</td><td class="nowrap">{{ $l['worked'] }} <span class="muted">of {{ $l['of'] }}</span></td><td class="num">{{ $rs($l['paid']) }}</td><td class="num wl-neg">−{{ $rs($l['cut']) }}<small>{{ $l['short'] }} short</small></td></tr>@endforeach</tbody>
            </table></div>
        </div>
    @endif

    @foreach($s['lines'] as $l)
        <div class="wl-row"><span>{{ $l['label'] }}@if($l['detail'])<small>{{ $l['detail'] }}</small>@endif<small>@if(abs($l['days'] - round($l['days'])) < 0.001){{ (int) round($l['days']) }} {{ round($l['days']) == 1 ? 'day' : 'days' }} × {{ $rs($s['rate']) }}@elseif(abs($l['days'] * 2 - round($l['days'] * 2)) < 0.001){{ (int) round($l['days'] * 2) }} × half day ({{ $rs($s['rate'] / 2) }} each)@else{{ $rs($l['amount']) }}@endif</small></span><b class="wl-neg">−{{ $rs($l['amount']) }}</b></div>
    @endforeach

    <div class="wl-row wl-total"><span>Salary for days worked</span><b>{{ $rs($s['earned']) }}</b></div>
    @if(! empty($s['extra']))
        <div class="wl-row"><span>+ Extra working on off days (paid by the hours worked)<small>{{ $s['extra']['days'] }} {{ $s['extra']['days'] === 1 ? 'day' : 'days' }} · {{ $s['extra']['hours'] }} · {{ $s['extra']['dates'] }}</small></span><b class="wl-pos">+{{ $rs($s['extra']['amount']) }}</b></div>
        <div class="wl-row wl-total"><span>Total (before additions and deductions)</span><b>{{ $rs($s['earned'] + $s['extra']['amount']) }}</b></div>
    @endif
    @if(isset($s['net']))
        {{-- Every cut after the salary for days worked, one line each, then what is added, then the net salary. --}}
        @foreach($s['after'] ?? [] as $d)
            <div class="wl-row"><span>− {{ $d['label'] }}@if($d['detail'])<small>{{ $d['detail'] }}</small>@endif</span><b class="wl-neg">−{{ $rs($d['amount']) }}</b></div>
        @endforeach
        @foreach($s['added'] ?? [] as $a)
            <div class="wl-row"><span>+ {{ $a['label'] }}@if($a['detail'])<small>{{ $a['detail'] }}</small>@endif</span><b class="wl-pos">+{{ $rs($a['amount']) }}</b></div>
        @endforeach
        <div class="wl-row wl-total"><span>{{ ($s['estimate'] ?? false) ? 'Net salary so far' : 'Net salary' }}</span><b>{{ $rs($s['net']) }}</b></div>
        @if(! empty($s['pending_fines']))
            <div class="wl-pending"><strong>Fines waiting for approval (not cut yet)</strong>
                @foreach($s['pending_fines'] as $p)<div class="wl-row"><span>{{ $p['label'] }}<small>{{ $p['detail'] }}</small></span><b class="muted">{{ $rs($p['amount']) }}</b></div>@endforeach
            </div>
        @endif
    @else
        <p class="why-note">Bonus and reimbursements are added, and advance recovery, penalties and TDS taken off, after this.</p>
    @endif
</div>
@endif
