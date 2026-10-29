<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payslip {{ $request->monthLabel() }} · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/sws.css') }}">
    <style>
        body{background:#eef1f6;color:var(--ink-2)}
        .slip{max-width:860px;margin:28px auto;background:#fff;border:1px solid var(--line);border-radius:14px;overflow:hidden;box-shadow:0 8px 30px rgba(15,23,42,.06)}
        .slip-top{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;padding:24px 30px;background:var(--navy);color:#fff}
        .slip-top h1{margin:0;font-size:21px;color:#fff}.slip-top p{margin:3px 0 0;opacity:.8;font-size:13px}.slip-top .right{text-align:right}.slip-top .right strong{display:block;font-size:18px}
        .slip-body{padding:22px 30px 26px}
        .slip-meta{display:grid;grid-template-columns:repeat(4,1fr);gap:1px;background:var(--line-2);border:1px solid var(--line-2);border-radius:10px;overflow:hidden;margin-bottom:18px}
        .slip-meta div{background:#fff;padding:10px 12px}.slip-meta span{display:block;font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}.slip-meta strong{display:block;font-size:14px}
        .slip-cols{display:grid;grid-template-columns:1fr 1fr;gap:16px}
        .slip-table{width:100%;border-collapse:collapse;font-size:13px;border:1px solid var(--line-2)}
        .slip-table th,.slip-table td{padding:8px 12px;text-align:left;border-bottom:1px solid var(--line-2)}
        .slip-table thead th{background:var(--canvas);font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted)}
        .slip-table .amt{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
        .slip-net{display:flex;justify-content:space-between;align-items:center;gap:16px;margin:18px 0;padding:16px 20px;border-radius:12px;background:#eaf6ef;border:1px solid #b9e2c8}
        .slip-net span{font-size:12px;color:var(--muted);text-transform:uppercase}.slip-net strong{font-size:24px;color:#15803d}
        .slip-foot{margin-top:20px;padding-top:12px;border-top:1px dashed var(--line);font-size:12px;color:var(--muted)}
        @media (max-width:700px){.slip-meta{grid-template-columns:1fr 1fr}.slip-cols{grid-template-columns:1fr}.slip-top,.slip-body{padding:18px}}
        @media print{body{background:#fff}.no-print{display:none!important}.slip{border:0;margin:0;box-shadow:none;max-width:none}.slip-top{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
    </style>
</head>
<body>
@php
    $r = $record;
    $money = fn ($v) => $v === null ? null : number_format((float) $v, 2);
    $num = fn ($v) => $v === null ? null : rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
    $e = $request->employee;
    $earnings = array_filter(['Basic salary' => $r->basic_salary, 'Salary for days paid' => $r->salary_before_adjustment, 'Saturday / holiday work' => $r->saturday_bonus, 'Increment / bonus' => $r->increment_bonus], fn ($v) => $v !== null && (float) $v != 0);
    $deductions = array_filter(['Late coming' => $r->late_coming_deduction, 'Short leave' => $r->short_leave_deduction, 'Leave without application' => $r->without_application_deduction, 'TDS' => $r->tds], fn ($v) => $v !== null && (float) $v != 0);
@endphp
<div class="no-print" style="max-width:860px;margin:18px auto 0;display:flex;justify-content:flex-end;gap:8px;padding:0 12px"><button class="btn btn-primary" onclick="window.print()">Print / Save as PDF</button></div>
<div class="slip">
    <div class="slip-top">
        <div><h1>{{ config('app.name', 'SWS HR') }}</h1><p>Payslip · from the salary sheet (before this system)</p></div>
        <div class="right"><strong>{{ $request->monthLabel() }}</strong><p>{{ $e?->name }}</p></div>
    </div>
    <div class="slip-body">
        <div class="slip-meta">
            <div><span>Employee</span><strong>{{ $e?->name ?? $r->name }}</strong></div>
            <div><span>Code</span><strong>{{ $e?->employee_code ?? $r->employee_code ?? '—' }}</strong></div>
            <div><span>Department</span><strong>{{ $e?->department?->name ?? '—' }}</strong></div>
            <div><span>Month</span><strong>{{ $request->monthLabel() }}</strong></div>
            <div><span>Working days</span><strong>{{ $num($r->total_working_days) ?? '—' }}</strong></div>
            <div><span>Present days</span><strong>{{ $num($r->present_days) ?? '—' }}</strong></div>
            <div><span>Leave days</span><strong>{{ $num($r->leave_days) ?? '—' }}</strong></div>
            <div><span>Days paid</span><strong>{{ $num($r->total_salary_paid_days) ?? '—' }}</strong></div>
        </div>
        <div class="slip-cols">
            <table class="slip-table"><thead><tr><th>Earnings</th><th class="amt">Rs</th></tr></thead><tbody>
                @forelse($earnings as $label => $v)<tr><td>{{ $label }}</td><td class="amt">{{ $money($v) }}</td></tr>@empty<tr><td colspan="2">—</td></tr>@endforelse
            </tbody></table>
            <table class="slip-table"><thead><tr><th>Deductions</th><th class="amt">Rs</th></tr></thead><tbody>
                @forelse($deductions as $label => $v)<tr><td>{{ $label }}</td><td class="amt">{{ $money($v) }}</td></tr>@empty<tr><td colspan="2">No deductions</td></tr>@endforelse
                @if($r->total_payment_deduction !== null)<tr><th>Total deductions</th><th class="amt">{{ $money($r->total_payment_deduction) }}</th></tr>@endif
            </tbody></table>
        </div>
        <div class="slip-net"><div><span>Net salary</span><br><strong>Rs {{ $money($r->total_salary_with_bonus ?? $r->salary_after_deduction) ?? '—' }}</strong></div>
            <div style="text-align:right"><span>Paid</span><br><strong style="font-size:18px">Rs {{ $money($r->salary_paid) ?? '—' }}</strong>@if((float) $r->salary_remains > 0)<br><small>Still due Rs {{ $money($r->salary_remains) }}</small>@endif</div></div>
        @if($r->payment_date || $r->payment_status)<p class="small">Payment: {{ $r->payment_date?->format('d M Y') ?? '' }} {{ $r->payment_status ? '· '.$r->payment_status : '' }}</p>@endif
        @if($r->deduction_reason)<p class="small">Deduction note: {{ $r->deduction_reason }}</p>@endif
        <div class="slip-foot">Made from the salary sheet kept before SWS HR (imported as Legacy Salary), as written there. Sent on {{ $request->decided_at?->format('d M Y') }}{{ $request->decider ? ' by '.$request->decider->name : '' }}.</div>
    </div>
</div>
</body>
</html>
