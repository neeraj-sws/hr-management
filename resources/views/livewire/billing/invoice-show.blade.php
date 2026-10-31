<div>
    @include('billing._ui')
    @php
        $cust = $invoice->customer;
        $initials = \Illuminate\Support\Str::of(trim($cust?->name ?? '?'))->explode(' ')->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->join('') ?: '?';
        $gstin = \App\Services\BillingService::cleanGstin($cust?->tax_number);
        $pos = $invoice->place_of_supply ?: $cust?->billing_state;
        $posLabel = $pos ? \App\Services\BillingService::stateLabel($pos) : '—';
        $due = max(0, $invoice->due());
        $overdue = $invoice->due_date && $invoice->due_date->isPast() && $due > 0;
        $creator = $invoice->created_by ? \App\Models\User::find($invoice->created_by)?->name : null;
    @endphp

    <div class="page-head">
        <div><h1>{{ $invoice->number() }}<span class="bill-badge dot {{ billing_badge_class($invoice->status) }}">{{ $invoice->status }}</span></h1>
            <p>{{ $cust?->name }} · Created on {{ $invoice->created_at?->format('d M Y') ?? $invoice->issue_date->format('d M Y') }}</p></div>
        <div class="row-actions">
            @if($invoice->status === 'Draft')<button class="btn" wire:click="markSent"><x-icon name="mail" class="icon-sm" />Send invoice</button>@endif
            <a class="btn" href="{{ route('billing.invoices.print', $invoice) }}" target="_blank"><x-icon name="printer" class="icon-sm" />Print / PDF</a>
            <a class="btn" href="{{ route('billing.invoices.edit', $invoice) }}"><x-icon name="edit" class="icon-sm" />Edit</a>
            @if($due > 0)<button class="btn btn-primary" wire:click="openPayment"><x-icon name="plus" class="icon-sm" />Record payment</button>@endif
        </div>
    </div>
    <x-errors />

    {{-- Customer + meta --}}
    <section class="card mb">
        <div class="inv-cust">
            <div class="inv-cust-main">
                <div class="inv-avatar">{{ $initials }}</div>
                <div>
                    <div class="nm">{{ $cust?->name ?? '—' }}</div>
                    <div class="sub">Customer{!! $cust?->billing_city ? '<br>'.e($cust->billing_city) : '' !!}{!! $gstin ? '<br>GSTIN: '.e($gstin) : '' !!}</div>
                </div>
            </div>
            <div class="inv-cmeta">
                <div><span>Invoice number</span><strong>{{ $invoice->number() }}</strong></div>
                <div><span>Issue date</span><strong>{{ $invoice->issue_date->format('d M Y') }}</strong></div>
                <div><span>Due date</span><strong class="{{ $overdue ? 'ov-red' : '' }}">{{ $invoice->due_date?->format('d M Y') ?? '—' }}</strong></div>
                <div><span>Place of supply</span><strong>{{ $posLabel }}</strong><small>{{ $interState ? 'IGST' : 'CGST + SGST' }}</small></div>
                <div><span>Customer GSTIN</span><strong>{{ $gstin ?: '—' }}</strong></div>
            </div>
        </div>
    </section>

    {{-- Items + summary --}}
    <div class="inv-grid mb">
        <section class="card">
            <div class="card-head"><h2><x-icon name="receipt" class="icon-sm" /> Items</h2><a class="btn btn-sm" href="{{ route('billing.invoices.edit', $invoice) }}"><x-icon name="plus" class="icon-sm" />Add item</a></div>
            <div class="table-wrap"><table class="table bill-items">
                <thead><tr><th>#</th><th>Item / description</th><th>HSN/SAC</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Discount</th><th class="num">Tax</th><th class="num">Amount</th></tr></thead>
                <tbody>
                @foreach($invoice->items as $i => $it)
                    <tr><td>{{ $i + 1 }}</td>
                        <td><strong>{{ $it->name }}</strong>@if($it->description)<small>{{ $it->description }}</small>@endif</td>
                        <td>{{ $it->hsn_sac ?: '—' }}</td><td class="num">{{ (float) $it->quantity }}</td><td class="num">{{ number_format($it->price, 2) }}</td>
                        <td class="num">{{ number_format($it->discount, 2) }}</td><td class="num">{{ $it->tax?->name ?? '—' }}</td>
                        <td class="num">{{ number_format($it->lineTotal(), 2) }}</td></tr>
                @endforeach
                </tbody>
            </table></div>
        </section>
        <section class="card">
            <div class="card-head"><h2><x-icon name="list" class="icon-sm" /> Invoice summary</h2></div>
            <div class="inv-sum">
                <div class="r"><span>Sub-total</span><strong>₹{{ number_format($invoice->subTotal(), 2) }}</strong></div>
                @if($interState)<div class="r"><span>IGST</span><strong>₹{{ number_format(collect($taxes)->sum('igst'), 2) }}</strong></div>
                @else<div class="r"><span>CGST</span><strong>₹{{ number_format(collect($taxes)->sum('cgst'), 2) }}</strong></div><div class="r"><span>SGST</span><strong>₹{{ number_format(collect($taxes)->sum('sgst'), 2) }}</strong></div>@endif
                @if($invoice->creditTotal() > 0)<div class="r"><span>Credit notes</span><strong>− ₹{{ number_format($invoice->creditTotal(), 2) }}</strong></div>@endif
                <div class="r tot"><span>Total amount</span><strong>₹{{ number_format($invoice->total(), 2) }}</strong></div>
                <div class="r"><span>Paid amount</span><strong class="pos">₹{{ number_format($invoice->paidAmount(), 2) }}</strong></div>
                <div class="inv-bal {{ $due <= 0 ? 'clear' : '' }}"><span class="lbl">{{ $due <= 0 ? 'Fully paid' : 'Balance due' }}</span><b>₹{{ number_format($due, 2) }}</b></div>
            </div>
        </section>
    </div>

    {{-- Notes + terms --}}
    <div class="two-col mb">
        <section class="card"><div class="card-head"><h2><x-icon name="note" class="icon-sm" /> Notes</h2><a class="btn btn-sm" href="{{ route('billing.invoices.edit', $invoice) }}"><x-icon name="edit" class="icon-sm" />Edit</a></div>
            <p style="white-space:pre-wrap;margin:4px 2px;{{ $invoice->notes ? '' : 'color:var(--muted)' }}">{{ $invoice->notes ?: '—' }}</p></section>
        <section class="card"><div class="card-head"><h2><x-icon name="file" class="icon-sm" /> Terms &amp; conditions</h2></div>
            <p style="white-space:pre-wrap;margin:4px 2px;color:var(--muted)">{{ config('billing.terms') ?: '—' }}</p></section>
    </div>

    {{-- Payment history --}}
    <section class="card mb">
        <div class="card-head"><h2><x-icon name="credit-card" class="icon-sm" /> Payment history</h2>@if($due > 0)<button class="btn btn-sm btn-primary" wire:click="openPayment"><x-icon name="plus" class="icon-sm" />Add payment</button>@endif</div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>#</th><th>Date</th><th>Method</th><th>Reference</th><th class="num">Amount</th><th>Notes</th><th>Added by</th><th>Action</th></tr></thead>
            <tbody>
            @forelse($invoice->payments as $i => $p)
                <tr><td>{{ $i + 1 }}</td><td class="nowrap">{{ $p->date->format('d M Y') }}</td><td>{{ $p->method ?: '—' }}</td><td>{{ $p->reference ?: '—' }}</td>
                    <td class="num"><strong>{{ number_format($p->amount, 2) }}</strong></td><td>{{ $p->note ?: '—' }}</td>
                    <td>{{ $p->recorder?->name ?? '—' }}<small class="muted-dash">{{ $p->created_at?->format('d M Y, h:i A') }}</small></td>
                    <td><button class="btn btn-sm btn-danger" wire:click="deletePayment({{ $p->id }})" wire:confirm="Remove this payment of {{ number_format($p->amount, 2) }}? The invoice will show it as due again."><x-icon name="trash" class="icon-sm" /></button></td></tr>
            @empty<tr><td colspan="8"><x-empty title="No payments yet" icon="credit-card" /></td></tr>@endforelse
            </tbody></table></div>
    </section>

    {{-- Activity log --}}
    <section class="card">
        <div class="card-head"><h2><x-icon name="activity" class="icon-sm" /> Activity log</h2></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Date &amp; time</th><th>Action</th><th>Details</th><th>By</th></tr></thead>
            <tbody>
            @foreach($invoice->payments->sortByDesc('created_at') as $p)
                <tr><td class="nowrap">{{ $p->created_at?->format('d M Y, h:i A') }}</td><td><span class="act-pill green"><x-icon name="check-circle" class="icon-sm" />Payment added</span></td>
                    <td>Payment of ₹{{ number_format($p->amount, 2) }} recorded ({{ $p->method ?: 'Manual' }}).</td><td>{{ $p->recorder?->name ?? '—' }}</td></tr>
            @endforeach
                <tr><td class="nowrap">{{ $invoice->created_at?->format('d M Y, h:i A') ?? $invoice->issue_date->format('d M Y') }}</td><td><span class="act-pill blue"><x-icon name="receipt" class="icon-sm" />Invoice created</span></td>
                    <td>Invoice created with total amount ₹{{ number_format($invoice->total(), 2) }}.</td><td>{{ $creator ?? '—' }}</td></tr>
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
