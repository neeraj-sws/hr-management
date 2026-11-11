<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} {{ $number }} · {{ $company['name'] }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;700;900&display=swap" rel="stylesheet">
    {{-- Same look as the old CMS invoice template: big title, logo, Bill To / From, navy item table. --}}
    <style>
        :root{--acc:{{ \App\Services\BillingService::color($kind) }}}
        *{box-sizing:border-box}
        body{margin:0;background:#eef1f6;color:#111;font-family:Lato,Arial,sans-serif;font-size:15px}
        .doc{max-width:860px;margin:24px auto;background:#fff;padding:36px 44px;box-shadow:0 2px 12px rgba(0,0,0,.06)}
        .bar{display:flex;justify-content:flex-end;gap:8px;max-width:860px;margin:16px auto 0}
        .bar button{background:#0d6efd;color:#fff;border:0;border-radius:6px;padding:10px 22px;font-size:15px;cursor:pointer}
        .top{display:flex;justify-content:space-between;align-items:flex-start;gap:20px}
        .top h1{margin:0 0 10px;font-size:34px;font-weight:900;letter-spacing:.01em}
        .logo{max-height:70px;max-width:230px}
        .company-name{font-size:20px;font-weight:900;color:var(--acc)}
        .meta{display:grid;grid-template-columns:1fr 1fr;gap:4px 30px;font-size:18px;margin-bottom:14px}
        .meta b{font-weight:700}
        hr{border:0;border-top:1px solid #999;margin:6px 0 14px}
        .parties{display:grid;grid-template-columns:1fr 1fr;gap:30px;margin-bottom:22px}
        .parties h3{margin:0 0 8px;font-size:19px}
        .parties p{margin:0;line-height:1.6;font-size:16px}
        table.items{width:100%;border-collapse:collapse;margin-top:10px}
        .items th{background:var(--acc);color:#fff;text-align:left;padding:14px 16px;font-size:15px}
        .items td{padding:14px 16px;font-size:14px;font-weight:700;vertical-align:top;border-bottom:1px solid var(--acc)}
        .items td.desc{font-weight:700}
        .num{white-space:nowrap}
        table.totals{margin-left:auto;border-collapse:collapse;min-width:300px}
        .totals td{padding:14px 16px;font-weight:700;font-size:14px;border-bottom:1px solid var(--acc)}
        .totals td:last-child{white-space:nowrap}
        .extra{margin-top:26px;font-size:13px;line-height:1.6;color:#333}
        .extra .bank{border:1px solid #ccc;padding:10px 14px;display:inline-block;margin-top:6px}
        .sign{text-align:right;margin-top:40px;font-size:14px}
        .foot-notes{font-size:15px;font-weight:700;line-height:1.7;color:#111;margin-top:34px}
        .foot-note{margin-top:20px;font-size:11px;color:#666;text-align:center}
        @media print{body{background:#fff}.bar{display:none!important}.doc{box-shadow:none;margin:0;max-width:none;padding:10px 6px}}
    </style>
</head>
<body>
@php
    $customer = $doc->customer;
    $pos = $doc->place_of_supply ?: $customer?->billing_state;
    $d = fn ($v) => \App\Services\BillingService::date($v);
    $rs = fn ($v) => 'Rs '.number_format((float) $v, 2);
    $isInvoice = $kind === 'invoice';
    $hasTax = collect($taxes)->sum('total_tax') > 0;
    $location = $company['state'] ? \App\Services\BillingService::stateLabel($company['state']) : '';
@endphp
<div class="bar"><button type="button" onclick="swsDownloadPdf()">Download PDF</button><button type="button" onclick="window.print()" style="background:#6c757d">Print</button></div>
<div class="doc" id="sws-doc">
    <div class="top">
        <h1>{{ $isInvoice ? 'INVOICE' : 'PROPOSAL' }}</h1>
        @if(! empty($company['logo']))<img class="logo" src="{{ $company['logo'] }}" alt="{{ $company['name'] }}">@else<span class="company-name">{{ $company['name'] }}</span>@endif
    </div>
    <div class="meta">
        <div>
            <div><b>{{ $isInvoice ? 'Invoice No:' : 'Proposal No:' }}</b> #{{ ltrim($number, '#') }}</div>
            <div><b>{{ $isInvoice ? 'Invoice Date:' : 'Date:' }}</b> {{ $d($doc->issue_date) }}</div>
            @if($isInvoice)<div><b>Due Date:</b> {{ $d($doc->due_date) ?? '—' }}</div>
            @elseif($doc->expiry_date)<div><b>Valid till:</b> {{ $d($doc->expiry_date) }}</div>@endif
            @if($isInvoice)<div><b>Hours :</b> {{ $doc->hours !== null ? rtrim(rtrim(number_format((float) $doc->hours, 2), '0'), '.') : '' }}</div>@endif
            @if($isInvoice && $doc->ref_number)<div><b>Ref:</b> {{ $doc->ref_number }}</div>@endif
        </div>
        <div>
            @if($company['pan'])<div><b>PAN No.:</b> {{ $company['pan'] }}</div>@endif
            @if($location)<div><b>Location:</b> {{ $location }}</div>@endif
            @if($company['gstin'])<div><b>GSTIN:</b> {{ $company['gstin'] }}</div>@endif
        </div>
    </div>
    <hr>
    <div class="parties">
        <div><h3>Bill To:</h3><p>
            {{ $customer?->billing_name ?: $customer?->name }}<br>
            @if($customer?->billing_phone ?: $customer?->contact){{ $customer->billing_phone ?: $customer->contact }}<br>@endif
            @if($customer?->email){{ $customer->email }}<br>@endif
            @if($customer?->billing_address){{ $customer->billing_address }}<br>@endif
            @if($customer?->billing_city || $customer?->billing_state){{ collect([$customer?->billing_city, $customer?->billing_state, $customer?->billing_country])->filter()->join(', ') }}<br>@endif
            @if($customer?->billing_zip)Pincode: {{ $customer->billing_zip }}<br>@endif
            @if($customer?->tax_number)GSTIN: {{ \App\Services\BillingService::cleanGstin($customer->tax_number) }}@endif
        </p></div>
        <div><h3>From:</h3><p>
            {{ $company['name'] }}<br>
            @if($company['phone']){{ $company['phone'] }}<br>@endif
            @if($company['address']){{ $company['address'] }}<br>@endif
            @if($company['city'] || $company['state']){{ collect([$company['city'], $company['state'], 'India'])->filter()->join(', ') }}<br>@endif
            @if($company['pin'])Pincode: {{ $company['pin'] }}@endif
        </p></div>
    </div>

    <table class="items">
        <thead><tr><th>Service</th><th>Description</th><th>Rate</th><th>Qty</th>@if($hasTax)<th>Tax</th>@endif<th>Price</th></tr></thead>
        <tbody>
        @foreach($doc->items as $it)
            <tr><td>{{ $it->name }}@if($it->hsn_sac)<br><small style="font-weight:400">HSN/SAC {{ $it->hsn_sac }}</small>@endif</td><td class="desc" style="white-space:pre-line">{{ $it->description }}</td>
                <td class="num">{{ $rs($it->price) }}@if((float) $it->discount)<br><small style="font-weight:400">− {{ $rs($it->discount) }} discount</small>@endif</td>
                <td>{{ rtrim(rtrim(number_format((float) $it->quantity, 2), '0'), '.') }}</td>
                @if($hasTax)<td>{{ $it->tax ? rtrim(rtrim(number_format((float) $it->tax->rate, 2), '0'), '.').'%' : '—' }}</td>@endif
                <td class="num">{{ $rs($it->lineSubtotal() + $it->lineTax()) }}</td></tr>
        @endforeach
        </tbody>
    </table>
    <table class="totals">
        <tr><td>Subtotal</td><td>{{ $rs($doc->subTotal()) }}</td></tr>
        @if($hasTax)
            @if($interState)<tr><td>IGST</td><td>{{ $rs(collect($taxes)->sum('igst')) }}</td></tr>
            @else<tr><td>CGST</td><td>{{ $rs(collect($taxes)->sum('cgst')) }}</td></tr><tr><td>SGST</td><td>{{ $rs(collect($taxes)->sum('sgst')) }}</td></tr>@endif
        @endif
        <tr><td>Total:</td><td>{{ $rs($doc->total()) }}</td></tr>
        @if($isInvoice)
            <tr><td>Paid</td><td>{{ $rs($doc->paidAmount()) }}</td></tr>
            @if($doc->creditTotal() > 0)<tr><td>Credit Note</td><td>{{ $rs($doc->creditTotal()) }}</td></tr>@endif
            <tr><td>Due Amount</td><td>{{ $rs(max(0, $doc->due())) }}</td></tr>
        @endif
    </table>

    <div class="extra">
        @if($hasTax && $pos)<div>Place of supply: {{ \App\Services\BillingService::stateLabel($pos) }} ({{ $interState ? 'IGST' : 'CGST + SGST' }})</div>@endif
        <div>Amount in words: {{ \App\Support\AmountInWords::rupees($doc->total()) }}</div>
        @if(! empty($company['footer_notes']))
            <div class="foot-notes">@if(! empty($company['footer_title']))<b>{{ $company['footer_title'] }}</b><br>@endif{!! \App\Services\BillingService::safeHtml($company['footer_notes']) !!}</div>
        @elseif($company['bank_name'] || $company['account_number'] || $company['upi'])
            <div class="bank"><b>Bank Details:</b><br>
                @if($company['account_name'])Account name: {{ $company['account_name'] }}<br>@endif
                @if($company['bank_name'])Bank: {{ $company['bank_name'] }}<br>@endif
                @if($company['account_number'])A/c no.: {{ $company['account_number'] }}@if($company['ifsc']) · IFSC: {{ $company['ifsc'] }}@endif<br>@endif
                @if($company['upi'])UPI: {{ $company['upi'] }}@endif
            </div>
        @endif
        @if($doc->notes)<p style="white-space:pre-line"><b>Notes:</b> {{ $doc->notes }}</p>@endif
        @if($company['terms'])<p style="white-space:pre-line"><b>Terms:</b> {{ $company['terms'] }}</p>@endif
    </div>
    @if($company['signatory'] || ! empty($company['signature']))<div class="sign">@if(! empty($company['signature']))<img src="{{ $company['signature'] }}" alt="Signature" style="width:150px;display:block;margin-left:auto">@else For <b>{{ $company['name'] }}</b><br><br><br>@endif<b>{{ $company['signatory'] }}</b>@if($company['signatory'])<br><b>Authorised Signatory</b>@endif</div>@endif
    <div class="foot-note">This is a computer-generated {{ $isInvoice ? 'invoice' : 'proposal' }}.</div>
</div>
{{-- "Download" saves a real PDF file (like the CMS); if the PDF library can't load, the print / save-as-PDF dialog opens instead. --}}
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
    function swsDownloadPdf() {
        var el = document.getElementById('sws-doc');
        if (typeof html2pdf !== 'function') { if (window.self === window.top) window.print(); return; }
        html2pdf().set({ margin: [8, 8, 8, 8], filename: @js(preg_replace('/[^A-Za-z0-9-]+/', '-', trim($number, '#/')).'.pdf'),
            image: { type: 'jpeg', quality: 0.98 }, html2canvas: { scale: 2, useCORS: true }, jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' } }).from(el).save();
    }
    @if(($autoPrint ?? false) || request()->boolean('download'))
    window.addEventListener('load', function () { setTimeout(swsDownloadPdf, 300); });
    @endif
</script>
</body>
</html>
