<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payslip {{ $payslip->reference }} · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/sws.css') }}">
    <style>
        body{background:#eef1f6;color:var(--ink-2)}
        .slip{max-width:900px;margin:28px auto;background:#fff;border:1px solid var(--line);border-radius:14px;overflow:hidden;box-shadow:0 8px 30px rgba(15,23,42,.06)}
        .slip-top{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;padding:26px 32px;background:var(--navy);color:#fff}
        .slip-top h1{margin:0;font-size:22px;color:#fff}.slip-top p{margin:3px 0 0;opacity:.8;font-size:13px}
        .slip-top .right{text-align:right}.slip-top .right strong{display:block;font-size:18px}
        .slip-body{padding:24px 32px 28px}
        .slip-meta{display:grid;grid-template-columns:repeat(4,1fr);gap:1px;background:var(--line-2);border:1px solid var(--line-2);border-radius:10px;overflow:hidden;margin-bottom:18px}
        .slip-meta div{background:#fff;padding:10px 12px}.slip-meta span{display:block;font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}.slip-meta strong{display:block;font-size:14px;margin-top:2px}
        .slip-days{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px}.slip-days div{flex:1 1 110px;background:var(--canvas);border-radius:10px;padding:9px 12px;text-align:center}
        .slip-days span{display:block;font-size:11px;color:var(--muted)}.slip-days strong{font-size:17px}
        .slip-cols{display:grid;grid-template-columns:1fr 1fr;gap:16px}
        .slip-table{width:100%;border-collapse:collapse;font-size:13px;border:1px solid var(--line-2);border-radius:10px;overflow:hidden}
        .slip-table th,.slip-table td{padding:8px 12px;text-align:left;border-bottom:1px solid var(--line-2)}
        .slip-table thead th{background:var(--canvas);font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted)}
        .slip-table .amt{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
        .slip-table tfoot th{background:var(--canvas)}
        .slip-net{display:flex;justify-content:space-between;align-items:center;gap:16px;margin:18px 0;padding:16px 20px;border-radius:12px;background:#eaf6ef;border:1px solid #b9e2c8}
        .slip-net span{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}.slip-net strong{font-size:26px;color:#15803d}.slip-net small{display:block;color:var(--ink-2);font-size:12px;margin-top:2px}
        .slip-foot{display:flex;justify-content:space-between;gap:12px;margin-top:22px;padding-top:14px;border-top:1px dashed var(--line);font-size:12px;color:var(--muted)}
        @media (max-width:700px){.slip-meta{grid-template-columns:1fr 1fr}.slip-cols{grid-template-columns:1fr}.slip-top,.slip-body{padding:18px}}
        @media print{body{background:#fff}.no-print{display:none!important}.slip{border:0;margin:0;box-shadow:none;max-width:none}.slip-top{-webkit-print-color-adjust:exact;print-color-adjust:exact}.slip-net{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
    </style>
</head>
<body>
@php($item = $payslip->item)
@php($b = $item->calculation_breakdown ?? [])
@php($paid = (float) $item->payments->sum('amount'))
@php($earnings = collect($b['salary_components'] ?? [])->map(fn ($a, $n) => [$n, (float) $a])->values())
@php($earnings = $earnings->isEmpty() ? collect([['Basic salary', (float) ($b['base_salary'] ?? 0)]]) : $earnings)
@php($deductions = collect())
@php($adjLines = collect($b['salary_adjustment_lines'] ?? []))
@foreach($adjLines as $line)
    @php((float) $line['amount'] >= 0 ? $earnings->push([$line['label'].(! empty($line['reason']) ? ' · '.$line['reason'] : ''), (float) $line['amount']]) : $deductions->push([$line['label'].(! empty($line['reason']) ? ' · '.$line['reason'] : ''), abs((float) $line['amount'])]))
@endforeach
@if($adjLines->isEmpty() && (float) $item->adjustments_amount != 0)
    @php((float) $item->adjustments_amount > 0 ? $earnings->push(['Adjustments', (float) $item->adjustments_amount]) : $deductions->push(['Adjustments', abs((float) $item->adjustments_amount)]))
@endif
@if((float) $item->overtime_amount > 0)@php($earnings->push(['Overtime ('.(float) $item->overtime_hours.' h)', (float) $item->overtime_amount]))@endif
@if((float) ($b['extra_work']['amount'] ?? 0) > 0)@php($earnings->push(['Extra working ('.$b['extra_work']['days'].' off / leave '.str('day')->plural($b['extra_work']['days']).', '.\App\Support\Duration::hms((int) $b['extra_work']['seconds']).($b['extra_work']['multiplier'] != 1 ? ' × '.(float) $b['extra_work']['multiplier'] : '').')', (float) $b['extra_work']['amount']]))@endif
@foreach(($b['statutory_components'] ?? []) as $c)
    @php(($c['type'] ?? '') === 'deduction' ? $deductions->push([$c['name'] ?? $c['key'], (float) ($c['amount'] ?? 0)]) : $earnings->push([$c['name'] ?? $c['key'], (float) ($c['amount'] ?? 0)]))
@endforeach
@foreach(($b['fixed_deductions'] ?? []) as $d)@php($deductions->push([$d['name'], (float) $d['amount']]))@endforeach
@if((float) ($b['short_hours']['amount'] ?? 0) > 0)@php($deductions->push(['Short hours ('.\App\Support\Duration::hms($b['short_hours']['short_seconds']).')', (float) $b['short_hours']['amount']]))@endif
@if((float) $item->penalties_amount > 0)@php($deductions->push(['Penalties', (float) $item->penalties_amount]))@endif
@if((float) $item->recovery_amount > 0)@php($deductions->push(['Advance recovery', (float) $item->recovery_amount]))@endif
@php($rows = max($earnings->count(), $deductions->count(), 1))
<div class="slip">
    <div class="slip-top">
        <div><h1>{{ config('app.name') }}</h1><p>Salary slip for {{ $item->run->period_starts_on->format('F Y') }}</p></div>
        <div class="right"><p>Payslip no.</p><strong>{{ $payslip->reference }}</strong><p>Issued {{ $payslip->issued_at->format('d M Y') }}</p></div>
    </div>
    <div class="slip-body">
        <div class="no-print" style="display:flex;justify-content:flex-end;margin-bottom:14px"><button type="button" class="btn" onclick="window.print()"><x-icon name="printer" class="icon-sm" />Print / save PDF</button></div>
        <div class="slip-meta">
            <div><span>Employee</span><strong>{{ $payslip->employee->name }}</strong></div>
            <div><span>Employee ID</span><strong>{{ $payslip->employee->employee_code }}</strong></div>
            <div><span>Designation</span><strong>{{ $payslip->employee->designation ?: '—' }}</strong></div>
            <div><span>Department</span><strong>{{ $payslip->employee->department?->name ?? '—' }}</strong></div>
            <div><span>Pay period</span><strong>{{ $item->run->period_starts_on->format('d M') }} – {{ $item->run->period_ends_on->format('d M Y') }}</strong></div>
            <div><span>Date of joining</span><strong>{{ $payslip->employee->joined_at?->format('d M Y') ?? '—' }}</strong></div>
            <div><span>Paid on</span><strong>{{ $item->payments->max('paid_on')?->format('d M Y') ?? '—' }}</strong></div>
        </div>
        <div class="slip-days">
            <div><span>Working days</span><strong>{{ $item->working_days }}</strong></div>
            <div><span>Present</span><strong>{{ (float) $item->present_days }}</strong></div>
            <div><span>Paid leave</span><strong>{{ (float) $item->paid_leave_days }}</strong></div>
            <div><span>Unpaid leave</span><strong>{{ (float) $item->unpaid_leave_days }}</strong></div>
            @if(! empty($b['half_days']))<div title="Paid half a day each: {{ collect($b['half_days'])->map(fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('d M'))->join(', ') }}"><span>Half days (½ paid)</span><strong>{{ count($b['half_days']) }}</strong></div>@endif
            @if(! empty($b['hours_paid_days']))<div title="Checked in late: paid by hours at work"><span>Late check-in (paid by hours)</span><strong>{{ collect($b['hours_paid_days'])->sum(fn ($d) => (float) ($d['share'] ?? 0)) == 0 ? 0 : round(collect($b['hours_paid_days'])->sum(fn ($d) => (float) ($d['share'] ?? 0)), 2) }} of {{ count($b['hours_paid_days']) }}</strong></div>@endif
            @if(! empty($b['paid_holiday_days']))<div><span>Paid holidays</span><strong>{{ $b['paid_holiday_days'] }}</strong></div>@endif
            @if(! empty($b['short_hours']['enabled']))<div><span>Hours worked / required</span><strong style="font-size:14px">{{ \App\Support\Duration::hms($b['short_hours']['actual_seconds']) }} / {{ \App\Support\Duration::hms($b['short_hours']['required_seconds']) }}</strong></div>@endif
        </div>
        <x-salary-shortfall :s="app(\App\Services\SalarySummaryService::class)->forItem($item)['shortfall'] ?? null" />
        <div class="slip-cols">
            <table class="slip-table"><thead><tr><th>Earnings</th><th class="amt">Amount (₹)</th></tr></thead><tbody>
                @for($i = 0; $i < $rows; $i++)<tr><td>{{ $earnings[$i][0] ?? '' }}</td><td class="amt">{{ isset($earnings[$i]) ? number_format($earnings[$i][1], 2) : '' }}</td></tr>@endfor
            </tbody><tfoot><tr><th>Gross earnings</th><th class="amt">{{ number_format((float) $item->gross_amount, 2) }}</th></tr></tfoot></table>
            <table class="slip-table"><thead><tr><th>Deductions</th><th class="amt">Amount (₹)</th></tr></thead><tbody>
                @for($i = 0; $i < $rows; $i++)<tr><td>{{ $deductions[$i][0] ?? ($i === 0 ? 'No deductions' : '') }}</td><td class="amt">{{ isset($deductions[$i]) ? number_format($deductions[$i][1], 2) : '' }}</td></tr>@endfor
            </tbody><tfoot><tr><th>Total deductions</th><th class="amt">{{ number_format($deductions->sum(1), 2) }}</th></tr></tfoot></table>
        </div>
        @if((float) $item->reimbursements_amount > 0)<p class="small" style="margin:10px 0 0">Expense reimbursements added: <strong>₹{{ number_format((float) $item->reimbursements_amount, 2) }}</strong></p>@endif
        <div class="slip-net">
            <div><span>Net pay</span><small>{{ \App\Support\AmountInWords::rupees((float) $item->net_amount) }}</small></div>
            <strong>₹{{ number_format((float) $item->net_amount, 2) }}</strong>
        </div>
        <table class="slip-table"><thead><tr><th>Payment date</th><th>Method / reference</th><th class="amt">Amount (₹)</th></tr></thead><tbody>
            @forelse($item->payments as $payment)<tr><td>{{ $payment->paid_on->format('d M Y') }}</td><td>{{ \App\Models\SalaryPayment::METHODS[$payment->method] ?? '' }} {{ $payment->reference ?: '' }}</td><td class="amt">{{ number_format((float) $payment->amount, 2) }}</td></tr>@empty<tr><td colspan="3">No payments recorded.</td></tr>@endforelse
        </tbody><tfoot>
            <tr><th colspan="2">Total paid</th><th class="amt">{{ number_format($paid, 2) }}</th></tr>
            @if((float) $item->net_amount - $paid > 0.005)<tr><th colspan="2">Balance due</th><th class="amt" style="color:var(--red)">{{ number_format((float) $item->net_amount - $paid, 2) }}</th></tr>@endif
        </tfoot></table>
        <div class="slip-foot"><span>This is a system-generated payslip from locked payroll records and does not need a signature.</span><span>{{ config('app.name') }}</span></div>
    </div>
</div>
</body>
</html>
