@props(['month', 'open' => false])
{{-- One month of salary: what was earned, every deduction, net, paid and still due (see SalarySummaryService). --}}
@php
    $m = $month;
    $source = ['estimate' => 'So far — final at month end', 'payroll' => 'Payroll · '.$m['status'], 'old_sheet' => 'Old salary sheet · '.$m['status']][$m['source']] ?? $m['status'];
    $fmt = fn ($v) => number_format((float) $v, 2);
    $estimate = $m['source'] === 'estimate';
    $due = ! $estimate && $m['remaining'] > 0;
    $tone = $estimate ? 'sm-blue' : ($due ? 'sm-amber' : 'sm-green');
    $start = \Illuminate\Support\Carbon::parse($m['month'].'-01');
    $working = max(1, (float) $m['days']['working']);
    $daysPaid = (float) ($m['days_paid'] ?? ($m['days']['present'] + $m['days']['paid_leave']));
@endphp
<details class="salary-month sm-card" data-year="{{ $start->year }}" data-due="{{ $due ? 1 : 0 }}" @if($open) open @endif>
    <summary>
        <span class="sm-badge {{ $tone }}"><b>{{ strtoupper($start->format('M')) }}</b><small>{{ $start->year }}</small></span>
        <div class="sm-head"><strong>{{ $m['label'] }}</strong><small>{{ $source }}@if(! empty($m['up_to'])) · up to {{ \Illuminate\Support\Carbon::parse($m['up_to'])->format('d M') }}@endif · {{ $m['days']['present'] }} of {{ $m['days']['working'] }} working days present</small></div>
        <div class="salary-month-figures">
            <span><small>Earned</small>{{ $fmt($m['gross']) }}</span>
            <span class="neg"><small>Deductions</small>{{ $fmt($m['deductions_total']) }}</span>
            <span><small>{{ $estimate ? 'Net so far' : 'Net' }}</small><strong>{{ $fmt($m['net']) }}</strong></span>
        </div>
        @if($estimate)<span class="sm-pill sm-pill-blue">So far</span>
        @elseif($due)<span class="sm-pill sm-pill-amber" title="Still due">Due {{ $fmt($m['remaining']) }}</span>
        @else<span class="sm-pill sm-pill-green">Paid</span>@endif
        <x-icon name="chevron-down" class="icon-sm sm-chev" />
    </summary>
    <div class="sm-body">
        <div class="sm-att">
            <x-salary-shortfall :s="$m['shortfall'] ?? null" />
            <div class="sm-att-head"><span>Attendance</span><span>{{ rtrim(rtrim(number_format($daysPaid, 2), '0'), '.') }}/{{ rtrim(rtrim(number_format((float) $m['days']['working'], 1), '0'), '.') }} days paid</span></div>
            <div class="sm-att-bar"><span class="sm-att-present" style="width:{{ min(100, $m['days']['present'] * 100 / $working) }}%"></span>@if($m['days']['paid_leave'])<span class="sm-att-leave" style="width:{{ min(100, $m['days']['paid_leave'] * 100 / $working) }}%"></span>@endif @if($m['days']['unpaid_leave'])<span class="sm-att-unpaid" style="width:{{ min(100, $m['days']['unpaid_leave'] * 100 / $working) }}%"></span>@endif</div>
            <div class="sm-legend"><span><i class="sm-att-present"></i>Present {{ (float) $m['days']['present'] }}</span>@if($m['days']['paid_leave'])<span><i class="sm-att-leave"></i>Paid leave {{ (float) $m['days']['paid_leave'] }}</span>@endif @if($m['days']['unpaid_leave'])<span><i class="sm-att-unpaid"></i>Unpaid leave {{ (float) $m['days']['unpaid_leave'] }}</span>@endif</div>
        </div>
        <div class="table-wrap"><table class="table sm-lines" data-no-sort>
            <tbody>
                @if($m['monthly_salary'])<tr class="sm-sub"><td>Monthly salary</td><td class="num">{{ $fmt($m['monthly_salary']) }}</td></tr>@endif
                @foreach($m['earnings'] as $i => [$label, $amount, $note])<tr><td>{{ $i === 0 && ! empty($m['per_day_rate']) ? 'Days paid '.rtrim(rtrim(number_format($daysPaid, 4), '0'), '.').' × '.$fmt($m['per_day_rate']) : $label }}@if($note)<small>{{ $note }}</small>@endif</td><td class="num">+ {{ $fmt($amount) }}</td></tr>@endforeach
                @forelse($m['deductions'] as [$label, $amount, $note])<tr><td>{{ $label }}@if($note)<small>{{ $note }}</small>@endif</td><td class="num sm-neg">{{ (float) $amount > 0 ? '− '.$fmt($amount) : '—' }}</td></tr>@empty<tr><td class="muted">No deductions</td><td></td></tr>@endforelse
                <tr class="sm-total"><td><strong>{{ $estimate ? 'Net so far' : 'Net salary' }}</strong></td><td class="num"><strong>{{ $fmt($m['net']) }}</strong></td></tr>
                @if(! $estimate)
                    <tr><td>Paid{{ ! empty($m['paid_on']) ? ' on '.\Illuminate\Support\Carbon::parse($m['paid_on'])->format('d M Y') : '' }}</td><td class="num">{{ $fmt($m['paid']) }}</td></tr>
                    <tr><td>Still due</td><td class="num {{ $due ? 'sm-due' : '' }}">{{ $fmt($m['remaining']) }}</td></tr>
                @endif
            </tbody>
        </table></div>
        @if($estimate)<p class="small muted sm-note">This is what payroll would pay if the month ended today. It changes as you work this month; advances and penalties approved later are included when payroll runs.</p>@endif
        @if(! empty($m['payslip_id']))<div class="sm-actions"><a class="btn btn-sm" href="{{ route('hr.payslips.show', $m['payslip_id']) }}" target="_blank" rel="noopener"><x-icon name="download" class="icon-sm" />Download payslip</a></div>@endif
    </div>
</details>
