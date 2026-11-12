<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Invoice {{ $invoice->number() }} · {{ $company['name'] }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    {{-- The customer's view from the shared link, laid out like the old CMS customer invoice page. --}}
    <style>
        *{box-sizing:border-box}
        body{margin:0;background:#f5f7fb;color:#2a2f3a;font-family:'Open Sans',Arial,sans-serif;font-size:14px}
        .wrap{max-width:1620px;margin:0 auto;padding:20px 24px 40px}
        .bar{display:flex;justify-content:flex-end;margin-bottom:20px}
        .btn{cursor:pointer;background:#0d6efd;color:#fff;border:0;border-radius:8px;padding:12px 26px;font-size:16px;text-decoration:none;display:inline-block}
        .card{background:#fff;border-radius:14px;box-shadow:0 4px 18px rgba(0,0,0,.05);overflow:hidden}
        .pad{padding:26px 30px}
        .head{display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #eee;padding-bottom:18px}
        .head h1{margin:0;font-size:30px;font-weight:600}.head h2{margin:0;font-size:28px;font-weight:700}
        .dates{display:flex;justify-content:flex-end;gap:28px;margin-top:22px;font-size:13px}.dates b{display:block;font-weight:700;margin-bottom:6px}
        .billed{display:flex;justify-content:space-between;gap:20px;margin-top:20px}
        .billed b{display:block;margin-bottom:8px}.billed p{margin:0;line-height:1.95;font-size:13px}
        .qr svg{width:150px;height:150px}
        .status{display:flex;justify-content:space-between;margin-top:22px;max-width:62%}
        .status b{display:block;margin-bottom:6px;font-size:13px}
        .badge{display:inline-block;background:#ffa21d;color:#fff;border-radius:4px;padding:2px 7px;font-size:11px}
        .badge.paid{background:#6fd943}.badge.part{background:#3ec9d6}.badge.draft{background:#999}
        .section{margin-top:40px}.section h3{margin:0;font-size:17px;font-weight:400}.section small{display:block;color:#555;margin-top:6px}
        table{width:100%;border-collapse:collapse}
        .prod th{background:#eef0f4;text-align:left;padding:18px 30px;font-weight:700;font-size:16px}
        .prod th small{display:block;color:#e83e5f;font-size:13px}
        .prod td{padding:16px 30px;border-bottom:1px solid #eee;font-size:16px}
        .prod .r,.prod th.r{text-align:right}
        .prod tr.total td{font-weight:700}
        .sum td{padding:16px 30px;border-bottom:1px solid #eee;font-size:16px}
        .sum td:first-child{text-align:right;font-weight:600}.sum td:last-child{text-align:right;width:220px}
        .receipts{margin-top:30px}.receipts h3{font-size:24px;font-weight:600;margin:0 0 16px}
        .receipts th{text-align:left;padding:18px 30px;font-size:16px;border-bottom:1px solid #eee}
        .receipts td{padding:14px 30px;font-size:15px;border-bottom:1px solid #f1f1f1}
        @media(max-width:800px){.wrap{padding:12px}.pad{padding:18px}.status{max-width:none}.prod th,.prod td,.sum td,.receipts th,.receipts td{padding:10px 12px;font-size:13px}.table-wrap{overflow-x:auto}}
    </style>
</head>
<body>
@php
    $c = $invoice->customer;
    $d = fn ($v) => \App\Services\BillingService::date($v);
    $rs = fn ($v) => 'Rs '.number_format((float) $v, 2);
    $badge = ['Paid' => 'paid', 'Partially Paid' => 'part', 'Draft' => 'draft'][$invoice->status] ?? '';
    $qty = fn ($q) => rtrim(rtrim(number_format((float) $q, 2), '0'), '.');
@endphp
<div class="wrap">
    <div class="bar"><button type="button" class="btn" onclick="swsDownloadInPlace(this, @js($downloadUrl))">Download</button></div>
    <div class="card">
        <div class="pad">
            <div class="head"><h1>Invoice</h1><h2>#{{ ltrim($invoice->number(), '#') }}</h2></div>
            <div class="dates"><div><b>Issue Date :</b>{{ $d($invoice->issue_date) }}</div><div><b>Due Date :</b>{{ $d($invoice->due_date) ?? '—' }}</div></div>
            <div class="billed">
                <div><b>Billed To :</b><p>
                    {{ $c?->billing_name ?: $c?->name }}<br>
                    @if($c?->billing_phone ?: $c?->contact){{ $c->billing_phone ?: $c->contact }}<br>@endif
                    @if($c?->billing_address){{ $c->billing_address }}<br>@endif
                    @if($c?->billing_zip){{ $c->billing_zip }}<br>@endif
                    {{ collect([$c?->billing_city, $c?->billing_state, $c?->billing_country])->filter()->join(' ') }}
                </p></div>
                @if(($company['show_qr'] ?? '') !== 'off')<div class="qr" id="invoice-qr" data-url="{{ $selfUrl }}"></div>@endif
            </div>
            <div class="status">
                <div><b>Status :</b><span class="badge {{ $badge }}">{{ $invoice->status }}</span></div>
                <div><b>Hours :</b>{{ $invoice->hours !== null ? $qty($invoice->hours) : '' }}</div>
            </div>
            <div class="section"><h3>Product Summary</h3><small>All items here cannot be deleted.</small></div>
        </div>
        <div class="table-wrap"><table class="prod">
            <thead><tr><th>#</th><th>Product</th><th>Quantity</th><th>Rate</th><th>Discount</th><th>Tax</th><th>Description</th><th class="r">Price<small>after tax &amp; discount</small></th></tr></thead>
            <tbody>
            @foreach($invoice->items as $it)
                <tr><td>{{ $loop->iteration }}</td><td>{{ $it->name }}</td><td>{{ $qty($it->quantity) }}</td><td>{{ $rs($it->price) }}</td><td>{{ $rs($it->discount) }}</td>
                    <td>{{ $it->tax ? $it->tax->name : '-' }}</td><td>{{ $it->description }}</td><td class="r">{{ $rs($it->lineTotal()) }}</td></tr>
            @endforeach
                <tr class="total"><td></td><td>Total</td><td>{{ $qty($invoice->items->sum('quantity')) }}</td><td>{{ $rs($invoice->items->sum(fn ($i) => (float) $i->quantity * (float) $i->price)) }}</td>
                    <td>{{ $rs($invoice->items->sum('discount')) }}</td><td>{{ $rs($invoice->totalTax()) }}</td><td></td><td></td></tr>
            </tbody>
        </table></div>
        <table class="sum">
            <tr><td>Sub Total</td><td>{{ $rs($invoice->items->sum(fn ($i) => (float) $i->quantity * (float) $i->price)) }}</td></tr>
            <tr><td>Discount</td><td>{{ $rs($invoice->items->sum('discount')) }}</td></tr>
            @if($invoice->totalTax() > 0)<tr><td>Tax</td><td>{{ $rs($invoice->totalTax()) }}</td></tr>@endif
            <tr><td>Total</td><td>{{ $rs($invoice->total()) }}</td></tr>
            <tr><td>Paid</td><td>{{ $rs($invoice->paidAmount()) }}</td></tr>
            <tr><td>Credit Note</td><td>{{ $rs($invoice->creditTotal()) }}</td></tr>
            <tr><td>Due</td><td>{{ $rs(max(0, $invoice->due())) }}</td></tr>
        </table>
        <div style="height:24px"></div>
    </div>

    <div class="receipts">
        <h3>Receipt Summary</h3>
        <div class="card table-wrap"><table>
            <thead><tr><th>Date</th><th>Amount</th><th>Payment Type</th><th>Account</th><th>Reference</th><th>Description</th><th>Receipt</th><th>OrderId</th></tr></thead>
            <tbody>
            @forelse($invoice->payments as $p)
                <tr><td>{{ $d($p->date) }}</td><td>{{ $rs($p->amount) }}</td><td>{{ $p->method ?: 'Manually' }}</td><td>{{ $p->account?->label() ?? '-' }}</td>
                    <td>{{ $p->reference ?: '-' }}</td><td>{{ $p->note ?: '-' }}</td><td>R-{{ str_pad((string) $p->id, 5, '0', STR_PAD_LEFT) }}</td><td>-</td></tr>
            @empty
                <tr><td colspan="8" style="text-align:center;color:#888">No payments yet</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </div>
</div>
@if(($company['show_qr'] ?? '') !== 'off')
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js"></script>
<script>
    (function () {
        var el = document.getElementById('invoice-qr');
        if (!el || typeof qrcode !== 'function') return;
        var qr = qrcode(0, 'M'); qr.addData(el.getAttribute('data-url')); qr.make();
        el.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 0 });
    })();
</script>
@endif
<script>
    function swsDownloadInPlace(btn, url) {
        var old = document.getElementById('sws-pdf-frame');
        if (old) old.remove();
        var label = btn.textContent;
        btn.disabled = true; btn.textContent = 'Downloading…';
        var f = document.createElement('iframe');
        f.id = 'sws-pdf-frame';
        f.style.cssText = 'position:fixed;left:-10000px;top:0;width:900px;height:1300px;border:0';
        f.src = url;
        document.body.appendChild(f);
        setTimeout(function () { btn.disabled = false; btn.textContent = label; }, 6000);
    }
</script>
</body>
</html>
