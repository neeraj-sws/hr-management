@php
    $customer = $invoice->customer;
    $state = $invoice->place_of_supply ?: $customer?->billing_state;
    $gstin = \App\Services\BillingService::cleanGstin($customer?->tax_number);
    $initials = collect(preg_split('/\s+/', trim((string) $customer?->name)))->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->join('') ?: '—';
    $statusTone = ['Paid' => 'green', 'Partially Paid' => 'amber', 'Unpaid' => 'red', 'Sent' => 'blue', 'Draft' => 'gray'][$invoice->status] ?? 'gray';
    $due = max(0, $invoice->due());
    $lastPaid = $invoice->payments->max('date');
    $discount = $invoice->items->sum(fn ($i) => (float) $i->discount);
    $pct = fn (float $r) => rtrim(rtrim(number_format($r, 2), '0'), '.').'%';
    $rupee = fn ($v) => '₹'.number_format((float) $v, 2);
@endphp
<div class="inv-page">
    <div class="page-head inv-head">
        <div>
            <div class="inv-title"><h1>{{ $invoice->number() }}</h1><x-badge :tone="$statusTone" class="inv-status-badge">@if($invoice->status === 'Paid')<x-icon name="check-circle" class="icon-sm" />@endif{{ $invoice->status }}</x-badge></div>
            <p>{{ $customer?->name ?? '—' }} <span class="inv-dot">·</span> Created on {{ $invoice->created_at?->format('d M Y, h:i A') ?? $invoice->issue_date->format('d M Y') }}</p>
        </div>
        <div class="row-actions inv-actions">
            @if($invoice->status === 'Draft')<button class="btn" wire:click="markSent"><x-icon name="mail" class="icon-sm" />Mark as sent</button>@endif
            <a class="btn" href="{{ route('billing.invoices.print', $invoice) }}" target="_blank"><x-icon name="download" class="icon-sm" />Print / PDF</a>
            <a class="btn" href="{{ route('billing.invoices.edit', $invoice) }}"><x-icon name="edit" class="icon-sm" />Edit</a>
            @if($invoice->due() > 0)<button class="btn btn-primary" wire:click="openPayment"><x-icon name="plus" class="icon-sm" />Record payment</button>@endif
            <details class="menu inv-more">
                <summary class="btn btn-icon" aria-label="More actions"><x-icon name="more" class="icon-sm" /></summary>
                <div class="menu-panel inv-more-panel">
                    <a class="menu-item" href="{{ route('billing.invoices.print', $invoice) }}" target="_blank"><x-icon name="printer" class="icon-sm" />Print / PDF</a>
                    <a class="menu-item" href="{{ route('billing.credit-notes') }}"><x-icon name="receipt" class="icon-sm" />Credit notes</a>
                    <a class="menu-item" href="{{ route('billing.customers') }}"><x-icon name="users" class="icon-sm" />Customers</a>
                    <a class="menu-item" href="{{ route('billing.invoices') }}"><x-icon name="arrow-left" class="icon-sm" />All invoices</a>
                </div>
            </details>
        </div>
    </div>
    <x-errors />

    <section class="card inv-strip mb">
        <div class="inv-customer">
            <div class="inv-avatar">{{ $initials }}</div>
            <div class="inv-customer-copy">
                <strong>{{ $customer?->name ?? '—' }}</strong>
                <span class="inv-sub">Customer</span>
                @if($customer?->billing_city)<span class="inv-line"><x-icon name="building" class="icon-sm" />{{ $customer->billing_city }}</span>@endif
                <span class="inv-line"><x-icon name="file" class="icon-sm" />GSTIN: {{ $gstin ?: '—' }}</span>
                @if($invoice->project)<span class="inv-line"><x-icon name="folder" class="icon-sm" />{{ $invoice->project->name }}</span>@endif
            </div>
        </div>
        <div class="inv-meta"><span>Invoice number</span><strong class="inv-plain">{{ $invoice->number() }}</strong>@if($invoice->ref_number)<small>Ref: {{ $invoice->ref_number }}</small>@endif</div>
        <div class="inv-meta"><span>Issue date</span><strong class="inv-plain"><x-icon name="calendar" class="icon-sm" />{{ $invoice->issue_date->format('d M Y') }}</strong></div>
        <div class="inv-meta"><span>Due date</span><strong class="inv-plain"><x-icon name="calendar" class="icon-sm" />{{ $invoice->due_date?->format('d M Y') ?? '—' }}</strong></div>
        <div class="inv-meta"><span>Place of supply</span><strong>{{ $state ? \App\Services\BillingService::stateLabel($state) : '—' }}</strong><small>{{ $interState ? 'IGST' : 'CGST + SGST' }}</small></div>
        <div class="inv-meta"><span>Status</span><strong class="inv-state inv-tone-{{ $statusTone }}"><i></i>{{ $invoice->status }}</strong>
            <small>@if($invoice->status === 'Paid' && $lastPaid)Paid on {{ $lastPaid->format('d M Y') }}@elseif($invoice->send_date)Sent on {{ $invoice->send_date->format('d M Y') }}@elseif($invoice->due_date)Due on {{ $invoice->due_date->format('d M Y') }}@endif</small></div>
    </section>

    <div class="inv-grid">
        <div class="inv-main">
            <section class="card inv-card inv-items">
                <div class="inv-card-head"><h2><x-icon name="receipt" class="icon-sm" />Items</h2><a class="btn btn-sm" href="{{ route('billing.invoices.edit', $invoice) }}"><x-icon name="plus" class="icon-sm" />Add Item</a></div>
                <div class="table-wrap"><table class="table inv-table">
                    <thead><tr><th class="inv-n">#</th><th>Item / Description</th><th>HSN/SAC</th><th class="num">Qty</th><th class="num">Price (₹)</th><th class="num">Discount (₹)</th><th>Tax</th><th class="num">Amount (₹)</th></tr></thead>
                    <tbody>
                    @forelse($invoice->items as $it)
                        <tr><td class="inv-n">{{ $loop->iteration }}</td>
                            <td><strong>{{ $it->name }}</strong>@if($it->description)<small class="inv-desc">{{ $it->description }}</small>@endif</td>
                            <td>{{ $it->hsn_sac ?: '—' }}</td><td class="num">{{ (float) $it->quantity }}</td><td class="num">{{ number_format($it->price, 2) }}</td>
                            <td class="num">{{ number_format($it->discount, 2) }}</td><td>{{ $it->tax?->name ?? '—' }}</td>
                            <td class="num"><strong>{{ number_format($it->lineTotal(), 2) }}</strong></td></tr>
                    @empty<tr><td colspan="8"><x-empty title="No items yet" icon="receipt" /></td></tr>@endforelse
                    </tbody></table></div>
            </section>

            <div class="inv-pair">
                <section class="card inv-card">
                    <div class="inv-card-head"><h2><x-icon name="notes" class="icon-sm" />Notes</h2><a class="btn btn-sm" href="{{ route('billing.invoices.edit', $invoice) }}"><x-icon name="edit" class="icon-sm" />Edit</a></div>
                    <div class="inv-text">{{ $invoice->notes ?: '—' }}</div>
                </section>
                <section class="card inv-card">
                    <div class="inv-card-head"><h2><x-icon name="file" class="icon-sm" />Terms &amp; Conditions</h2><a class="btn btn-sm" href="{{ route('billing.settings') }}" title="Terms are the same on every invoice; change them in Billing settings"><x-icon name="edit" class="icon-sm" />Edit</a></div>
                    <div class="inv-text">{{ $terms ?: '—' }}</div>
                </section>
            </div>
        </div>

        <aside class="inv-side">
            <section class="card inv-card inv-summary">
                <div class="inv-card-head"><h2><x-icon name="list" class="icon-sm" />Invoice Summary</h2></div>
                <dl class="inv-sum">
                    <div><dt>Sub-total</dt><dd>{{ $rupee($invoice->subTotal()) }}</dd></div>
                    <div><dt>Discount</dt><dd>{{ $rupee($discount) }}</dd></div>
                    @if($interState)
                        <div><dt>IGST{{ $taxRate !== null ? ' ('.$pct($taxRate).')' : '' }}</dt><dd>{{ $rupee(collect($taxes)->sum('igst')) }}</dd></div>
                    @else
                        <div><dt>CGST{{ $taxRate !== null ? ' ('.$pct($taxRate / 2).')' : '' }}</dt><dd>{{ $rupee(collect($taxes)->sum('cgst')) }}</dd></div>
                        <div><dt>SGST{{ $taxRate !== null ? ' ('.$pct($taxRate / 2).')' : '' }}</dt><dd>{{ $rupee(collect($taxes)->sum('sgst')) }}</dd></div>
                    @endif
                </dl>
                <dl class="inv-total">
                    <div><dt>Total Amount</dt><dd>{{ $rupee($invoice->total()) }}</dd></div>
                    <div class="inv-paid"><dt>Paid Amount</dt><dd>{{ $rupee($invoice->paidAmount()) }}</dd></div>
                    @if($invoice->creditTotal() > 0)<div class="inv-credit"><dt>Credit notes</dt><dd>{{ $rupee($invoice->creditTotal()) }}</dd></div>@endif
                </dl>
                @if($due <= 0)
                    <div class="inv-balance inv-balance-ok">
                        <div><span><x-icon name="check-circle" class="icon-sm" />Fully Paid</span><strong>₹0.00</strong></div>
                        <i class="inv-tick"><x-icon name="check" /></i>
                    </div>
                @else
                    <div class="inv-balance inv-balance-due">
                        <div><span><x-icon name="alert" class="icon-sm" />Balance Due</span><strong>{{ $rupee($due) }}</strong></div>
                        <button class="btn btn-sm btn-primary" wire:click="openPayment">Record payment</button>
                    </div>
                @endif
            </section>
        </aside>
    </div>

    <section class="card inv-card mb">
        <div class="inv-card-head"><h2><x-icon name="credit-card" class="icon-sm" />Payment History</h2>@if($invoice->due() > 0)<button class="btn btn-sm" wire:click="openPayment"><x-icon name="plus" class="icon-sm" />Record payment</button>@endif</div>
        <div class="table-wrap"><table class="table inv-table">
            <thead><tr><th class="inv-n">#</th><th>Date</th><th>Method</th><th>Reference</th><th class="num">Amount (₹)</th><th>Notes</th><th>Added by</th><th class="inv-act">Action</th></tr></thead>
            <tbody>
            @forelse($invoice->payments as $p)
                <tr><td class="inv-n">{{ $loop->iteration }}</td><td class="nowrap">{{ $p->date->format('d M Y') }}</td><td>{{ $p->method ?: '—' }}</td><td>{{ $p->reference ?: '—' }}</td>
                    <td class="num"><strong>{{ number_format($p->amount, 2) }}</strong></td><td>{{ $p->note ?: '—' }}</td>
                    <td>{{ $p->recorder?->name ?? '—' }}@if($p->created_at)<small class="inv-desc">{{ $p->created_at->format('d M Y, h:i A') }}</small>@endif</td>
                    <td class="inv-act"><button class="btn btn-sm btn-danger btn-icon" wire:click="deletePayment({{ $p->id }})" wire:confirm="Remove this payment of {{ number_format($p->amount, 2) }}? The invoice will show it as due again." title="Remove payment" aria-label="Remove payment of {{ number_format($p->amount, 2) }}"><x-icon name="trash" class="icon-sm" /></button></td></tr>
            @empty<tr><td colspan="8"><x-empty title="No payments yet" icon="receipt" /></td></tr>@endforelse
            </tbody></table></div>
    </section>

    <section class="card inv-card">
        <div class="inv-card-head"><h2><x-icon name="activity" class="icon-sm" />Activity Log</h2></div>
        <div class="table-wrap"><table class="table inv-table">
            <thead><tr><th>Date &amp; Time</th><th>Action</th><th>Details</th><th>By</th></tr></thead>
            <tbody>
            @forelse($activity as $a)
                <tr><td class="nowrap">{{ $a->at?->format('d M Y, h:i A') }}</td><td><x-badge :tone="$a->tone" class="inv-pill">{{ $a->label }}</x-badge></td><td class="inv-detail">{{ $a->detail }}</td><td>{{ $a->by }}</td></tr>
            @empty<tr><td colspan="4"><x-empty title="No activity yet" icon="activity" /></td></tr>@endforelse
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
