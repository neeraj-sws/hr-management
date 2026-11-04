<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} {{ $number }} · {{ $company['name'] }}</title>
    <link rel="stylesheet" href="{{ asset('css/sws.css') }}">
    <style>
        body{background:#eef1f6;color:#1f2937;font-size:13px}
        .doc{max-width:900px;margin:24px auto;background:#fff;border:1px solid #d6dce6;padding:28px 32px}
        .doc table{width:100%;border-collapse:collapse}
        .doc th,.doc td{border:1px solid #d6dce6;padding:6px 8px;vertical-align:top;text-align:left}
        .doc th{background:#f3f6fa;font-size:11px;text-transform:uppercase;letter-spacing:.03em}
        .doc .r{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
        .doc .c{text-align:center}
        .head{display:flex;justify-content:space-between;gap:20px;border-bottom:2px solid #1f3a5f;padding-bottom:12px;margin-bottom:14px}
        .head h1{margin:0;font-size:20px;color:#1f3a5f}.head p{margin:2px 0;color:#4b5563}
        .title{text-align:right}.title h2{margin:0;font-size:18px;letter-spacing:.05em;text-transform:uppercase}
        .parties{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}
        .box{border:1px solid #d6dce6;padding:10px 12px}.box span{display:block;font-size:11px;color:#6b7280;text-transform:uppercase}
        .totals td{border:none;padding:3px 8px}.totals tr.grand td{border-top:2px solid #1f3a5f;font-weight:700;font-size:15px}
        .foot{display:grid;grid-template-columns:1.3fr 1fr;gap:14px;margin-top:16px}
        .sign{text-align:right;margin-top:40px}
        .muted{color:#6b7280}
        @media print{body{background:#fff}.no-print{display:none!important}.doc{border:0;margin:0;max-width:none;padding:0}}
    </style>
</head>
<body>
@php($customer = $doc->customer)
@php($pos = $doc->place_of_supply ?: $customer?->billing_state)
<div class="doc">
    <div class="no-print" style="display:flex;justify-content:flex-end;gap:8px;margin-bottom:12px"><button class="btn" onclick="window.print()">Print / Save as PDF</button></div>
    <div class="head">
        <div>
            <h1>{{ $company['name'] }}</h1>
            @if($company['address'])<p>{{ $company['address'] }}</p>@endif
            <p>{{ collect([$company['city'], $company['state'], $company['pin']])->filter()->join(', ') }}</p>
            @if($company['gstin'])<p><strong>GSTIN:</strong> {{ $company['gstin'] }}@if($company['pan']) · <strong>PAN:</strong> {{ $company['pan'] }}@endif</p>@endif
            <p>{{ collect([$company['phone'], $company['email'], $company['website']])->filter()->join(' · ') }}</p>
        </div>
        <div class="title">
            <h2>{{ $title }}</h2>
            <p><strong>{{ $number }}</strong></p>
            <p>Date: {{ $doc->issue_date->format('d M Y') }}</p>
            @if($kind === 'invoice' && $doc->due_date)<p>Due: {{ $doc->due_date->format('d M Y') }}</p>@endif
            @if($kind === 'estimate' && $doc->expiry_date)<p>Valid till: {{ $doc->expiry_date->format('d M Y') }}</p>@endif
            @if($kind === 'invoice' && $doc->ref_number)<p>Ref: {{ $doc->ref_number }}</p>@endif
        </div>
    </div>
    <div class="parties">
        <div class="box"><span>Bill to</span>
            <strong>{{ $customer?->billing_name ?: $customer?->name }}</strong><br>
            {{ $customer?->billing_address }}@if($customer?->billing_address)<br>@endif
            {{ collect([$customer?->billing_city, $customer?->billing_state, $customer?->billing_zip])->filter()->join(', ') }}
            @if($customer?->tax_number)<br><strong>GSTIN:</strong> {{ \App\Services\BillingService::cleanGstin($customer->tax_number) }}@endif
            @if($customer?->contact || $customer?->email)<br><span style="display:inline;text-transform:none">{{ collect([$customer?->contact, $customer?->email])->filter()->join(' · ') }}</span>@endif
        </div>
        <div class="box"><span>Place of supply</span>
            <strong>{{ $pos ? \App\Services\BillingService::stateLabel($pos) : '—' }}</strong><br>
            {{ $interState ? 'Inter-state supply: IGST' : 'Intra-state supply: CGST + SGST' }}
            @if($kind === 'invoice' && $doc->project)<br><span>Project</span>{{ $doc->project->name }}@endif
        </div>
    </div>
    <table>
        <thead><tr><th class="c">#</th><th>Item / service</th><th class="c">HSN/SAC</th><th class="r">Qty</th><th class="r">Rate</th><th class="r">Discount</th><th class="r">Taxable value</th><th class="c">GST %</th><th class="r">Amount</th></tr></thead>
        <tbody>
        @foreach($doc->items as $i => $it)
            <tr><td class="c">{{ $i + 1 }}</td><td><strong>{{ $it->name }}</strong>@if($it->description)<br><span class="muted" style="white-space:pre-line">{{ $it->description }}</span>@endif</td>
                <td class="c">{{ $it->hsn_sac ?: '—' }}</td><td class="r">{{ rtrim(rtrim(number_format((float) $it->quantity, 2), '0'), '.') }}</td><td class="r">{{ number_format((float) $it->price, 2) }}</td>
                <td class="r">{{ (float) $it->discount ? number_format((float) $it->discount, 2) : '—' }}</td><td class="r">{{ number_format($it->lineSubtotal(), 2) }}</td>
                <td class="c">{{ $it->tax ? rtrim(rtrim(number_format((float) $it->tax->rate, 2), '0'), '.').'%' : '—' }}</td><td class="r">{{ number_format($it->lineSubtotal() + $it->lineTax(), 2) }}</td></tr>
        @endforeach
        </tbody>
    </table>
    <div class="foot">
        <div>
            @if(collect($taxes)->sum('total_tax') > 0)
                <table style="margin-bottom:10px">
                    <thead><tr><th class="r">GST %</th><th class="r">Taxable</th>@if($interState)<th class="r">IGST</th>@else<th class="r">CGST</th><th class="r">SGST</th>@endif</tr></thead>
                    <tbody>@foreach($taxes as $t)@continue($t['total_tax'] <= 0)<tr><td class="r">{{ (float) $t['rate'] }}%</td><td class="r">{{ number_format($t['taxable'], 2) }}</td>@if($interState)<td class="r">{{ number_format($t['igst'], 2) }}</td>@else<td class="r">{{ number_format($t['cgst'], 2) }} <span class="muted">({{ (float) $t['rate'] / 2 }}%)</span></td><td class="r">{{ number_format($t['sgst'], 2) }} <span class="muted">({{ (float) $t['rate'] / 2 }}%)</span></td>@endif</tr>@endforeach</tbody>
                </table>
            @endif
            <p><strong>Amount in words:</strong> {{ \App\Support\AmountInWords::rupees($doc->total()) }}</p>
            @if($company['bank_name'] || $company['account_number'] || $company['upi'])
                <div class="box" style="margin-top:8px"><span>Bank details</span>
                    @if($company['account_name'])Account name: {{ $company['account_name'] }}<br>@endif
                    @if($company['bank_name'])Bank: {{ $company['bank_name'] }}<br>@endif
                    @if($company['account_number'])A/c no.: {{ $company['account_number'] }}@if($company['ifsc']) · IFSC: {{ $company['ifsc'] }}@endif<br>@endif
                    @if($company['upi'])UPI: {{ $company['upi'] }}@endif
                </div>
            @endif
            @if($doc->notes)<p style="white-space:pre-line;margin-top:8px"><strong>Notes:</strong> {{ $doc->notes }}</p>@endif
            @if($company['terms'])<p class="muted" style="white-space:pre-line;margin-top:8px"><strong>Terms:</strong> {{ $company['terms'] }}</p>@endif
        </div>
        <div>
            <table class="totals">
                <tr><td>Taxable value</td><td class="r">{{ number_format($doc->subTotal(), 2) }}</td></tr>
                @if($interState)
                    <tr><td>IGST</td><td class="r">{{ number_format(collect($taxes)->sum('igst'), 2) }}</td></tr>
                @else
                    <tr><td>CGST</td><td class="r">{{ number_format(collect($taxes)->sum('cgst'), 2) }}</td></tr>
                    <tr><td>SGST</td><td class="r">{{ number_format(collect($taxes)->sum('sgst'), 2) }}</td></tr>
                @endif
                <tr class="grand"><td>Total</td><td class="r">₹{{ number_format($doc->total(), 2) }}</td></tr>
                @if($kind === 'invoice')
                    @if($doc->paidAmount() > 0)<tr><td>Paid</td><td class="r">{{ number_format($doc->paidAmount(), 2) }}</td></tr>@endif
                    @if($doc->creditTotal() > 0)<tr><td>Credit notes</td><td class="r">{{ number_format($doc->creditTotal(), 2) }}</td></tr>@endif
                    <tr><td><strong>Balance due</strong></td><td class="r"><strong>₹{{ number_format(max(0, $doc->due()), 2) }}</strong></td></tr>
                @endif
            </table>
            <div class="sign">For <strong>{{ $company['name'] }}</strong><br><br><br>{{ $company['signatory'] ?: 'Authorised signatory' }}</div>
        </div>
    </div>
    <p class="muted c" style="margin-top:18px;font-size:11px">This is a computer-generated {{ strtolower($title) }}.</p>
</div>
</body>
</html>
