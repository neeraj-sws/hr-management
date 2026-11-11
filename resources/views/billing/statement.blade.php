<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Statement · {{ $customer->name }} · {{ $company['name'] }}</title>
    <link rel="stylesheet" href="{{ asset('css/sws.css') }}">
    <style>
        :root{--acc:{{ \App\Services\BillingService::color('invoice') }}}
        body{background:#eef1f6;color:#1f2937;font-size:13px}
        .doc{max-width:900px;margin:24px auto;background:#fff;border:1px solid #d6dce6;padding:28px 32px}
        .head{display:flex;justify-content:space-between;gap:20px;border-bottom:2px solid var(--acc);padding-bottom:12px;margin-bottom:14px}
        .head h1{margin:0;font-size:20px;color:var(--acc)}.head p{margin:2px 0;color:#4b5563}
        .title{text-align:right}.title h2{margin:0;font-size:18px;letter-spacing:.05em;text-transform:uppercase}
        .logo{max-height:56px;max-width:200px;display:block;margin-bottom:8px}
        table{width:100%;border-collapse:collapse;margin-top:10px}
        th,td{border:1px solid #d6dce6;padding:6px 8px;text-align:left;vertical-align:top}
        th{background:#f3f6fa;background:color-mix(in srgb,var(--acc) 10%,#fff);font-size:11px;text-transform:uppercase;letter-spacing:.03em}
        .r{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
        .box{border:1px solid #d6dce6;padding:10px 12px}.box span{display:block;font-size:11px;color:#6b7280;text-transform:uppercase}
        .sum{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:14px 0}
        .sum div{border:1px solid #d6dce6;padding:8px 10px}.sum span{display:block;font-size:11px;color:#6b7280;text-transform:uppercase}.sum b{font-size:15px}
        .filter{display:flex;gap:8px;align-items:end;justify-content:flex-end;margin-bottom:12px}
        @media print{body{background:#fff}.no-print{display:none!important}.doc{border:0;margin:0;max-width:none;padding:0}}
    </style>
</head>
<body>
@php($d = fn ($v) => \App\Services\BillingService::date($v))
{{-- Like the CMS: a balance the customer owes is shown negative. --}}
@php($bal = fn ($v) => number_format(round($v, 2) == 0 ? 0 : -$v, 2))
<div class="doc">
    @if($public ?? false)<div class="filter no-print"><button class="btn" type="button" onclick="window.print()">Print / Save as PDF</button></div>@else
    <form class="filter no-print" method="get">
        <label>From <input type="date" name="from" value="{{ $from }}"></label>
        <label>To <input type="date" name="to" value="{{ $to }}"></label>
        <button class="btn">Show</button>
        <button class="btn" type="button" onclick="window.print()">Print / Save as PDF</button>
    </form>@endif
    <div class="head">
        <div>
            @if(! empty($company['logo']))<img class="logo" src="{{ $company['logo'] }}" alt="{{ $company['name'] }}">@endif
            <h1>{{ $company['name'] }}</h1>
            @if($company['address'])<p>{{ $company['address'] }}</p>@endif
            <p>{{ collect([$company['city'], $company['state'], $company['pin']])->filter()->join(', ') }}</p>
            @if($company['gstin'])<p><strong>GSTIN:</strong> {{ $company['gstin'] }}</p>@endif
        </div>
        <div class="title"><h2>Account Statement</h2><p>{{ $d($from) }} – {{ $d($to) }}</p></div>
    </div>
    <div class="box"><span>Customer</span><strong>{{ $customer->billing_name ?: $customer->name }}</strong> ({{ $customer->code() }})<br>
        {{ collect([$customer->billing_address, $customer->billing_city, $customer->billing_state, $customer->billing_zip])->filter()->join(', ') }}
        @if($customer->tax_number)<br>GSTIN: {{ \App\Services\BillingService::cleanGstin($customer->tax_number) }}@endif</div>

    <div class="sum">
        <div><span>Opening balance</span><b>₹{{ $bal($opening) }}</b></div>
        <div><span>Invoiced</span><b>₹{{ number_format($invoiced, 2) }}</b></div>
        <div><span>Received / credited</span><b>₹{{ number_format($received, 2) }}</b></div>
        <div><span>Closing balance</span><b>₹{{ $bal($closing) }}</b></div>
    </div>

    <table>
        <thead><tr><th>Date</th><th>Particulars</th><th>Reference</th><th class="r">Debit (₹)</th><th class="r">Credit (₹)</th><th class="r">Balance (₹)</th></tr></thead>
        <tbody>
            <tr><td>{{ $d($from) }}</td><td colspan="4"><strong>Opening balance</strong></td><td class="r">{{ $bal($opening) }}</td></tr>
            @php($balance = $opening)
            @forelse($lines as $l)
                @php($balance = round($balance + $l['debit'] - $l['credit'], 2))
                <tr><td class="nowrap">{{ $d($l['date']) }}</td><td>{{ $l['particulars'] }}</td><td>{{ $l['reference'] }}</td>
                    <td class="r">{{ $l['debit'] ? number_format($l['debit'], 2) : '' }}</td><td class="r">{{ $l['credit'] ? number_format($l['credit'], 2) : '' }}</td><td class="r">{{ $bal($balance) }}</td></tr>
            @empty
                <tr><td colspan="6" style="text-align:center;color:#6b7280">No entry found during this period</td></tr>
            @endforelse
            <tr><td></td><td colspan="4"><strong>Closing balance</strong></td><td class="r"><strong>{{ $bal($closing) }}</strong></td></tr>
        </tbody>
    </table>
    <p style="margin-top:18px;font-size:11px;color:#6b7280;text-align:center">This is a computer-generated statement. Negative balance = amount due from the customer.</p>
</div>
</body>
</html>
