@props(['month', 'open' => false])
{{-- One month of salary: what was earned, every deduction, net, paid and still due (see SalarySummaryService). --}}
@php
    $m = $month;
    $source = ['estimate' => 'So far — final at month end', 'payroll' => 'Payroll · '.$m['status'], 'old_sheet' => 'Old salary sheet · '.$m['status']][$m['source']] ?? $m['status'];
    $fmt = fn ($v) => number_format((float) $v, 2);
@endphp
<details class="salary-month" @if($open) open @endif>
    <summary>
        <div><strong>{{ $m['label'] }}</strong><small>{{ $source }}@if(! empty($m['up_to'])) · up to {{ \Illuminate\Support\Carbon::parse($m['up_to'])->format('d M') }}@endif · {{ $m['days']['present'] }} of {{ $m['days']['working'] }} working days present</small></div>
        <div class="salary-month-figures">
            <span><small>Earned</small>{{ $fmt($m['gross']) }}</span>
            <span class="neg"><small>Deductions</small>{{ $fmt($m['deductions_total']) }}</span>
            <span><small>{{ $m['source'] === 'estimate' ? 'Net so far' : 'Net' }}</small><strong>{{ $fmt($m['net']) }}</strong></span>
            @if($m['source'] !== 'estimate')<span @class(['due' => $m['remaining'] > 0])><small>{{ $m['remaining'] > 0 ? 'Still due' : 'Paid' }}</small>{{ $fmt($m['remaining'] > 0 ? $m['remaining'] : $m['paid']) }}</span>@endif
        </div>
    </summary>
    <div class="table-wrap"><table class="table">
        <tbody>
            @if($m['monthly_salary'])<tr style="background:var(--canvas)"><td>Monthly salary</td><td class="num">{{ $fmt($m['monthly_salary']) }}</td></tr>@endif
            @foreach($m['earnings'] as [$label, $amount, $note])<tr><td>{{ $label }}@if($note)<small>{{ $note }}</small>@endif</td><td class="num">+ {{ $fmt($amount) }}</td></tr>@endforeach
            @forelse($m['deductions'] as [$label, $amount, $note])<tr><td>{{ $label }}@if($note)<small>{{ $note }}</small>@endif</td><td class="num" style="color:var(--red)">{{ (float) $amount > 0 ? '− '.$fmt($amount) : '—' }}</td></tr>@empty<tr><td class="muted">No deductions</td><td></td></tr>@endforelse
            <tr style="background:var(--canvas)"><td><strong>{{ $m['source'] === 'estimate' ? 'Net so far' : 'Net salary' }}</strong></td><td class="num"><strong>{{ $fmt($m['net']) }}</strong></td></tr>
            @if($m['source'] !== 'estimate')
                <tr><td>Paid{{ ! empty($m['paid_on']) ? ' on '.\Illuminate\Support\Carbon::parse($m['paid_on'])->format('d M Y') : '' }}</td><td class="num">{{ $fmt($m['paid']) }}</td></tr>
                <tr><td>Still due</td><td class="num">{{ $fmt($m['remaining']) }}</td></tr>
            @endif
        </tbody>
    </table></div>
    @if($m['source'] === 'estimate')<p class="small muted" style="margin:8px 16px 12px">This is what payroll would pay if the month ended today. It changes as you work this month; advances and penalties approved later are included when payroll runs.</p>@endif
    @if(! empty($m['payslip_id']))<p style="margin:8px 16px 12px"><a class="btn btn-sm" href="{{ route('hr.payslips.show', $m['payslip_id']) }}" target="_blank">Open payslip</a></p>@endif
</details>
