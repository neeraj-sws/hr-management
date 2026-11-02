<div>
    @include('billing._ui')
    <div class="page-head"><div><h1>{{ $estimate->number() }}<span class="bill-badge {{ billing_badge_class($estimate->status) }}">{{ $estimate->status }}</span></h1><p>{{ $estimate->customer?->name }} · {{ $estimate->issue_date->format('d M Y') }}</p></div>
        <div class="row-actions">
            <a class="btn" href="{{ route('billing.estimates.print', $estimate) }}" target="_blank"><x-icon name="download" class="icon-sm" />Print / PDF</a>
            @if($estimate->status !== 'Converted')
                <a class="btn" href="{{ route('billing.estimates.edit', $estimate) }}">Edit</a>
                @if($estimate->status === 'Draft')<button class="btn" wire:click="setStatus('Sent')">Mark as sent</button>@endif
                @if(in_array($estimate->status, ['Sent','Draft']))<button class="btn" wire:click="setStatus('Accepted')">Accepted</button><button class="btn" wire:click="setStatus('Declined')">Declined</button>@endif
                <button class="btn btn-primary" wire:click="convert" wire:confirm="Create an invoice from this estimate?">Convert to invoice</button>
            @else
                <a class="btn btn-primary" href="{{ route('billing.invoices.show', $estimate->converted_invoice_id) }}">View invoice</a>
            @endif
        </div></div>
    <x-errors />
    <section class="card mb">
        <div class="slip-meta">
            <div><span>Customer</span><strong>{{ $estimate->customer?->name ?? '—' }}</strong></div>
            <div><span>Status</span><strong>{{ $estimate->status }}</strong></div>
            <div><span>Issue date</span><strong>{{ $estimate->issue_date->format('d M Y') }}</strong></div>
            <div><span>Expiry date</span><strong>{{ $estimate->expiry_date?->format('d M Y') ?? '—' }}</strong></div>
        </div>
        <div class="table-wrap"><table class="table bill-items">
            <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Discount</th><th>Tax</th><th class="num">Amount</th></tr></thead>
            <tbody>
            @foreach($estimate->items as $it)
                <tr><td><strong>{{ $it->name }}</strong>@if($it->description)<small style="white-space:normal">{{ $it->description }}</small>@endif</td>
                    <td class="num">{{ (float) $it->quantity }}</td><td class="num">{{ number_format($it->price, 2) }}</td>
                    <td class="num">{{ number_format($it->discount, 2) }}</td><td>{{ $it->tax?->name ?? '—' }}</td>
                    <td class="num">{{ number_format($it->lineSubtotal() + $it->lineTax(), 2) }}</td></tr>
            @endforeach
            </tbody></table></div>
        <div class="inv-totals">
            <div class="r"><span>Sub-total</span><strong>{{ number_format($estimate->subTotal(), 2) }}</strong></div>
            <div class="r"><span>Tax</span><strong>{{ number_format($estimate->totalTax(), 2) }}</strong></div>
            <div class="r total"><span>Total</span><strong>₹{{ number_format($estimate->total(), 2) }}</strong></div>
        </div>
    </section>
</div>
