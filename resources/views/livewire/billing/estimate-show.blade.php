<div>
    @include('billing._ui')
    <div class="page-head"><div><h1>{{ $estimate->number() }}<span class="bill-badge {{ billing_badge_class($estimate->status) }}">{{ $estimate->status }}</span></h1><p>{{ $estimate->customer?->name }} · {{ \App\Services\BillingService::date($estimate->issue_date) }}</p></div>
        <div class="row-actions">
            <a class="btn" href="{{ route('billing.estimates.print', $estimate) }}" target="_blank"><x-icon name="download" class="icon-sm" />Print / PDF</a>
            @if($estimate->status !== 'Draft')<button type="button" class="btn" x-on:click="navigator.clipboard.writeText(@js(app(\App\Services\InvoiceActions::class)->estimateUrl($estimate))).then(() => window.dispatchEvent(new CustomEvent('toast', { detail: { message: 'Proposal link copied — the customer can open it without signing in.', type: 'success' } })))"><x-icon name="external" class="icon-sm" />Copy link</button>@endif
            @if($estimate->status !== 'Converted')<button type="button" class="btn" wire:click="openEmail"><x-icon name="mail" class="icon-sm" />{{ $estimate->status === 'Draft' ? 'Send proposal' : 'Resend proposal' }}</button>@endif
            <button type="button" class="btn" wire:click="duplicate" wire:confirm="Make a copy of this proposal as a new Draft?"><x-icon name="layers" class="icon-sm" />Duplicate</button>
            <a class="btn" href="{{ route('billing.estimates.edit', $estimate) }}"><x-icon name="edit" class="icon-sm" />Edit</a>
            <button type="button" class="btn btn-danger" wire:click="delete" wire:confirm="Proposal {{ $estimate->number() }} — delete?"><x-icon name="trash" class="icon-sm" />Delete</button>
            @if($estimate->status !== 'Converted')
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
            <div><span>Issue date</span><strong>{{ \App\Services\BillingService::date($estimate->issue_date) }}</strong></div>
            <div><span>Expiry date</span><strong>{{ \App\Services\BillingService::date($estimate->expiry_date) ?? '—' }}</strong></div>
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

    @if($emailOpen)
        <x-modal title="Send proposal">
            <form wire:submit="sendEmail"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="To (customer e-mail)" name="emailTo" full><input type="email" wire:model="emailTo" placeholder="customer@company.com"></x-field>
                <x-field label="Message (optional)" name="emailMessage" full hint="shown at the top of the e-mail"><textarea wire:model="emailMessage" rows="3"></textarea></x-field>
            </div><p class="small muted">The customer gets a link to the proposal — they can view / print it without signing in. Sending a Draft proposal marks it "Sent".</p></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="$set('emailOpen', false)">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled"><x-icon name="mail" class="icon-sm" />Send e-mail</button></div></form>
        </x-modal>
    @endif
</div>
