<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payslip {{ $payslip->reference }} · SWS HR</title>
    <link rel="stylesheet" href="{{ asset('css/sws.css') }}">
    <style>body{background:#eef1f6}.slip{max-width:860px;margin:28px auto;background:#fff;border:1px solid var(--line);border-radius:14px;padding:32px}.slip h1{font-size:22px}.slip .table td,.slip .table th{font-size:13px}@media print{body{background:#fff}.no-print{display:none!important}.slip{border:0;margin:0;padding:0}}</style>
</head>
<body>
@php($item = $payslip->item)
@php($paid = (float) $item->payments->sum('amount'))
<div class="slip">
    <div class="page-head" style="align-items:flex-start">
        <div class="profile-chip"><span class="brand-mark" style="background:var(--navy);color:#fff">SW</span><div><h1>Salary payslip</h1><p class="muted" style="margin:2px 0 0">SWS HR · {{ $payslip->reference }}</p></div></div>
        <button type="button" class="btn no-print" onclick="window.print()"><x-icon name="printer" class="icon-sm" />Print / save PDF</button>
    </div>
    <div class="grid grid-2 mb">
        <dl class="kv"><dt>Employee</dt><dd>{{ $payslip->employee->name }}</dd><dt>Employee code</dt><dd>{{ $payslip->employee->employee_code }}</dd><dt>Designation</dt><dd>{{ $payslip->employee->designation ?? '—' }}</dd></dl>
        <dl class="kv"><dt>Pay period</dt><dd>{{ $item->run->period_starts_on->format('d M Y') }} – {{ $item->run->period_ends_on->format('d M Y') }}</dd><dt>Issued</dt><dd>{{ $payslip->issued_at->format('d M Y') }}</dd><dt>Payroll status</dt><dd>{{ ucfirst($item->run->status) }}</dd></dl>
    </div>
    <div class="grid grid-2">
        <table class="table"><thead><tr><th>Attendance</th><th class="num">Days</th></tr></thead><tbody>
            <tr><td>Working days</td><td class="num">{{ $item->working_days }}</td></tr>
            <tr><td>Present</td><td class="num">{{ (float) $item->present_days }}</td></tr>
            <tr><td>Paid leave</td><td class="num">{{ (float) $item->paid_leave_days }}</td></tr>
            <tr><td>Unpaid leave</td><td class="num">{{ (float) $item->unpaid_leave_days }}</td></tr>
            <tr><td>Overtime hours</td><td class="num">{{ (float) $item->overtime_hours }}</td></tr>
        </tbody></table>
        <table class="table"><thead><tr><th>Earnings &amp; deductions</th><th class="num">Amount</th></tr></thead><tbody>
            @forelse(($item->calculation_breakdown['salary_components'] ?? []) as $name => $amount)
                <tr><td>{{ $name }} <span class="muted small">(prorated)</span></td><td class="num">{{ number_format((float) $amount, 2) }}</td></tr>
            @empty
                <tr><td>Base salary (prorated)</td><td class="num">{{ number_format((float) ($item->calculation_breakdown['base_salary'] ?? 0), 2) }}</td></tr>
            @endforelse
            <tr><td>Overtime</td><td class="num">{{ number_format((float) $item->overtime_amount, 2) }}</td></tr>
            <tr><td>Adjustments (net)</td><td class="num">{{ number_format((float) $item->adjustments_amount, 2) }}</td></tr>
            @foreach(($item->calculation_breakdown['statutory_components'] ?? []) as $c)
                <tr><td>{{ $c['name'] ?? $c['key'] }} <span class="muted small">({{ $c['type'] ?? '' }})</span></td><td class="num">{{ ($c['type'] ?? '') === 'deduction' ? '−' : '' }}{{ number_format((float) ($c['amount'] ?? 0), 2) }}</td></tr>
            @endforeach
            @foreach(($item->calculation_breakdown['fixed_deductions'] ?? []) as $d)<tr><td>{{ $d['name'] }}</td><td class="num">−{{ number_format((float) $d['amount'], 2) }}</td></tr>@endforeach
            <tr><td>Penalties</td><td class="num">−{{ number_format((float) $item->penalties_amount, 2) }}</td></tr>
            <tr><td>Advance recovery</td><td class="num">−{{ number_format((float) $item->recovery_amount, 2) }}</td></tr>
            <tr><th>Gross</th><th class="num">{{ number_format((float) $item->gross_amount, 2) }}</th></tr>
            @if((float) $item->reimbursements_amount > 0)<tr><td>Expense reimbursements</td><td class="num">+{{ number_format((float) $item->reimbursements_amount, 2) }}</td></tr>@endif
            <tr><th>Net payable</th><th class="num">{{ number_format((float) $item->net_amount, 2) }}</th></tr>
        </tbody></table>
    </div>
    <h2 class="mt" style="font-size:15px">Payments</h2>
    <table class="table"><thead><tr><th>Date</th><th>Reference</th><th class="num">Amount</th></tr></thead><tbody>
        @forelse($item->payments as $payment)<tr><td>{{ $payment->paid_on->format('d M Y') }}</td><td>{{ \App\Models\SalaryPayment::METHODS[$payment->method] ?? '' }} {{ $payment->reference ?: '—' }}</td><td class="num">{{ number_format((float) $payment->amount, 2) }}</td></tr>@empty<tr><td colspan="3">No payments recorded.</td></tr>@endforelse
        <tr><th colspan="2">Total paid</th><th class="num">{{ number_format($paid, 2) }}</th></tr>
        <tr><th colspan="2">Remaining</th><th class="num">{{ number_format(max(0, (float) $item->net_amount - $paid), 2) }}</th></tr>
    </tbody></table>
    <p class="small muted mt">This payslip is generated from locked payroll records and is valid without a signature.</p>
</div>
</body>
</html>
