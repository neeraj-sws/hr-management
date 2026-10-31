<div>
    @include('billing._ui')
    <div class="page-head"><div><h1>{{ $invoice->number() }}<span class="bill-badge {{ billing_badge_class($invoice->status) }}">{{ $invoice->status }}</span></h1><p>{{ $invoice->customer?->name }} · {{ $invoice->issue_date->format('d M Y') }}</p></div>
        <div class="row-actions">
            <a class="btn" href="{{ route('billing.invoices.print', $invoice) }}" target="_blank"><x-icon name="download" class="icon-sm" />Print / PDF</a>
            <a class="btn" href="{{ route('billing.invoices.edit', $invoice) }}">Edit</a>
            @if($invoice->status === 'Draft')<button class="btn" wire:click="markSent">Mark as sent</button>@endif
            @if($invoice->due() > 0)<button class="btn btn-primary" wire:click="openPayment">Record payment</button>@endif
        </div></div>
    <x-errors />
    <section class="card mb">
        <div class="slip-meta">
            <div><span>Customer</span><strong>{{ $invoice->customer?->name ?? '—' }}</strong></div>
            <div><span>Status</span><strong>{{ $invoice->status }}</strong></div>
            <div><span>Issue date</span><strong>{{ $invoice->issue_date->format('d M Y') }}</strong></div>
            <div><span>Due date</span><strong>{{ $invoice->due_date?->format('d M Y') ?? '—' }}</strong></div>
            <div><span>Place of supply</span><strong>{{ ($invoice->place_of_supply ?: $invoice->customer?->billing_state) ? \App\Services\BillingService::stateLabel($invoice->place_of_supply ?: $invoice->customer?->billing_state) : '—' }}</strong><small>{{ $interState ? 'IGST' : 'CGST + SGST' }}</small></div>
            <div><span>Customer GSTIN</span><strong>{{ \App\Services\BillingService::cleanGstin($invoice->customer?->tax_number) ?: '—' }}</strong></div>
            @if($invoice->project)<div><span>Project</span><strong>{{ $invoice->project->name }}</strong></div>@endif
        </div>
        <div class="table-wrap"><table class="table bill-items">
            <thead><tr><th>Item</th><th>HSN/SAC</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Discount</th><th>Tax</th><th class="num">Amount</th></tr></thead>
            <tbody>
            @foreach($invoice->items as $it)
                <tr><td><strong>{{ $it->name }}</strong>@if($it->description)<small style="white-space:normal">{{ $it->description }}</small>@endif</td>
                    <td>{{ $it->hsn_sac ?: '—' }}</td><td class="num">{{ (float) $it->quantity }}</td><td class="num">{{ number_format($it->price, 2) }}</td>
                    <td class="num">{{ number_format($it->discount, 2) }}</td><td>{{ $it->tax?->name ?? '—' }}</td>
                    <td class="num">{{ number_format($it->lineTotal(), 2) }}</td></tr>
            @endforeach
            </tbody></table></div>
        <div class="inv-totals">
            <div class="r"><span>Sub-total</span><strong>{{ number_format($invoice->subTotal(), 2) }}</strong></div>
            @if($interState)<div class="r"><span>IGST</span><strong>{{ number_format(collect($taxes)->sum('igst'), 2) }}</strong></div>@else<div class="r"><span>CGST</span><strong>{{ number_format(collect($taxes)->sum('cgst'), 2) }}</strong></div><div class="r"><span>SGST</span><strong>{{ number_format(collect($taxes)->sum('sgst'), 2) }}</strong></div>@endif
            <div class="r total"><span>Total</span><strong>₹{{ number_format($invoice->total(), 2) }}</strong></div>
            <div class="r"><span>Paid</span><strong>{{ number_format($invoice->paidAmount(), 2) }}</strong></div>
            @if($invoice->creditTotal() > 0)<div class="r"><span>Credit notes</span><strong>{{ number_format($invoice->creditTotal(), 2) }}</strong></div>@endif
            <div class="inv-due {{ max(0, $invoice->due()) <= 0 ? 'clear' : '' }}"><span class="lbl">{{ max(0, $invoice->due()) <= 0 ? 'Fully paid' : 'Amount due' }}</span><b>₹{{ number_format(max(0, $invoice->due()), 2) }}</b></div>
        </div>
    </section>
    <section class="card">
        <div class="card-head"><h2>Payments</h2></div>
        <div class="table-wrap"><table class="table"><thead><tr><th>Date</th><th>Method</th><th>Reference</th><th class="num">Amount</th><th></th></tr></thead><tbody>
            @forelse($invoice->payments as $p)
                <tr><td>{{ $p->date->format('d M Y') }}</td><td>{{ $p->method ?: '—' }}</td><td>{{ $p->reference ?: '—' }}</td><td class="num">{{ number_format($p->amount, 2) }}</td><td><button class="btn btn-sm btn-danger" wire:click="deletePayment({{ $p->id }})" wire:confirm="Remove this payment of {{ number_format($p->amount, 2) }}? The invoice will show it as due again.">Remove</button></td></tr>
            @empty<tr><td colspan="5"><x-empty title="No payments yet" icon="receipt" /></td></tr>@endforelse
        </tbody></table></div>
    </section>
    @if($showPayment)
        <x-modal title="Record payment">
            <form wire:submit="recordPayment"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Amount" name="payAmount"><input type="number" step="0.01" wire:model="payAmount"></x-field>
                <x-field label="Date" name="payDate"><input type="date" wire:model="payDate"></x-field>
                <x-field label="Method" name="payMethod"><select wire:model="payMethod"><option>Bank</option><option>Cash</option><option>UPI</option><option>Cheque</option><option>Card</option></select></x-field>
                <x-field label="Reference" name="payReference"><input wire:model="payReference"></x-field>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="$set('showPayment', false)">Cancel</button><button class="btn btn-primary">Save payment</button></div></form>
        </x-modal>
    @endif
</div>
