<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment receipt · {{ $invoice->number() }} · {{ $company['name'] }}</title>
    <link rel="stylesheet" href="{{ asset('css/sws.css') }}">
    <style>
        :root{--acc:{{ \App\Services\BillingService::color('invoice') }}}
        .logo{max-height:56px;max-width:200px;display:block;margin-bottom:8px}
        body{background:#eef1f6;color:#1f2937;font-size:13px}
        .doc{max-width:720px;margin:24px auto;background:#fff;border:1px solid #d6dce6;padding:28px 32px}
        .head{display:flex;justify-content:space-between;gap:20px;border-bottom:2px solid var(--acc);padding-bottom:12px;margin-bottom:16px}
        .head h1{margin:0;font-size:20px;color:var(--acc)}.head p{margin:2px 0;color:#4b5563}
        .title{text-align:right}.title h2{margin:0;font-size:18px;letter-spacing:.05em;text-transform:uppercase}
        table{width:100%;border-collapse:collapse;margin-top:8px}
        th,td{border:1px solid #d6dce6;padding:7px 10px;text-align:left}
        th{background:#f3f6fa;width:38%;font-size:12px}
        .amount{font-size:22px;font-weight:700;color:#15803d;margin:14px 0}
        .sign{text-align:right;margin-top:48px}
        @media print{body{background:#fff}.no-print{display:none!important}.doc{border:0;margin:0;max-width:none;padding:0}}
    </style>
</head>
<body>
@php($customer = $invoice->customer)
<div class="doc">
    <div class="no-print" style="display:flex;justify-content:flex-end;gap:8px;margin-bottom:12px"><button class="btn" onclick="window.print()">Print / Save as PDF</button></div>
    <div class="head">
        <div>
            @if(! empty($company['logo']))<img class="logo" src="{{ $company['logo'] }}" alt="{{ $company['name'] }}">@endif
            <h1>{{ $company['name'] }}</h1>
            @if($company['address'])<p>{{ $company['address'] }}</p>@endif
            <p>{{ collect([$company['city'], $company['state'], $company['pin']])->filter()->join(', ') }}</p>
            @if($company['gstin'])<p><strong>GSTIN:</strong> {{ $company['gstin'] }}</p>@endif
        </div>
        <div class="title"><h2>Payment Receipt</h2><p>Receipt no. R-{{ str_pad((string) $payment->id, 5, '0', STR_PAD_LEFT) }}</p><p>{{ \App\Services\BillingService::date($payment->date) }}</p></div>
    </div>

    <p>Received with thanks from <strong>{{ $customer?->billing_name ?: $customer?->name }}</strong></p>
    <div class="amount">₹{{ number_format((float) $payment->amount, 2) }}</div>

    <table>
        <tr><th>Against invoice</th><td>{{ $invoice->number() }} · dated {{ \App\Services\BillingService::date($invoice->issue_date) }}</td></tr>
        <tr><th>Payment date</th><td>{{ \App\Services\BillingService::date($payment->date) }}</td></tr>
        <tr><th>Payment method</th><td>{{ $payment->method ?: '—' }}</td></tr>
        <tr><th>Reference</th><td>{{ $payment->reference ?: '—' }}</td></tr>
        @if($payment->note)<tr><th>Note</th><td>{{ $payment->note }}</td></tr>@endif
        <tr><th>Invoice total</th><td>₹{{ number_format($invoice->total(), 2) }}</td></tr>
        <tr><th>Total received so far</th><td>₹{{ number_format($invoice->paidAmount(), 2) }}</td></tr>
        @if($invoice->creditTotal() > 0)<tr><th>Credit notes</th><td>₹{{ number_format($invoice->creditTotal(), 2) }}</td></tr>@endif
        <tr><th>Balance due</th><td><strong>₹{{ number_format(max(0, $invoice->due()), 2) }}</strong></td></tr>
    </table>

    <div class="sign">For {{ $company['name'] }}<br><br><br>Authorised signatory</div>
</div>
</body>
</html>
