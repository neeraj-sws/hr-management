<div>
    @include('billing._ui')
    @php
        $c = $customer;
        $code = $c->code();
        $hasBillShip = fn ($v) => trim((string) $v) !== '';
    @endphp

    <div class="page-head">
        <div><h1>{{ $c->name }}</h1>
            <p><a href="{{ route('billing.customers') }}">Customers</a> · {{ $code }}</p></div>
        <div class="row-actions">
            <a class="btn btn-primary" href="{{ route('billing.invoices.create', ['customer' => $c->id]) }}">Create Invoice</a>
            <a class="btn btn-primary" href="{{ route('billing.estimates.create', ['customer' => $c->id]) }}">Create Proposal</a>
            <a class="btn" href="{{ route('billing.customers.statement', $c) }}" target="_blank" title="Account statement"><x-icon name="file" class="icon-sm" />Statement</a>
            <button type="button" class="btn" wire:click="openEmail" title="E-mail the account statement"><x-icon name="mail" class="icon-sm" />Email statement</button>
            <a class="btn btn-primary btn-icon" href="{{ route('billing.customers', ['edit' => $c->id]) }}" title="Edit customer" aria-label="Edit customer"><x-icon name="edit" class="icon-sm" /></a>
            <button type="button" class="btn btn-danger btn-icon" wire:click="deleteCustomer" wire:confirm="Customer {{ $c->name }}? This cannot be undone." title="Delete customer" aria-label="Delete customer"><x-icon name="trash" class="icon-sm" /></button>
        </div>
    </div>

    {{-- Info cards --}}
    <div class="cust-cards mb">
        <section class="card">
            <div class="card-head"><h2><x-icon name="user" class="icon-sm" /> Customer info</h2></div>
            <div class="cust-lines">
                <strong>{{ $c->name }}</strong>
                @if($c->email)<div><a href="mailto:{{ $c->email }}">{{ $c->email }}</a></div>@endif
                @if($c->contact)<div>{{ $c->contact }}</div>@endif
                @if($c->tax_number)<div class="muted">GSTIN: {{ \App\Services\BillingService::cleanGstin($c->tax_number) }}</div>@endif
            </div>
        </section>
        <section class="card">
            <div class="card-head"><h2><x-icon name="file" class="icon-sm" /> Billing info</h2></div>
            <div class="cust-lines">
                <strong>{{ $c->billing_name ?: $c->name }}</strong>
                @if($hasBillShip($c->billing_address))<div>{{ $c->billing_address }}</div>@endif
                <div>{{ collect([$c->billing_city, $c->billing_state, $c->billing_zip])->filter()->join(', ') }}</div>
                @if($hasBillShip($c->billing_country))<div>{{ $c->billing_country }}</div>@endif
                @if($hasBillShip($c->billing_phone))<div>{{ $c->billing_phone }}</div>@endif
            </div>
        </section>
        <section class="card">
            <div class="card-head"><h2><x-icon name="home" class="icon-sm" /> Shipping info</h2></div>
            <div class="cust-lines">
                @if($hasBillShip($c->shipping_name) || $hasBillShip($c->shipping_address))
                    <strong>{{ $c->shipping_name ?: '—' }}</strong>
                    @if($hasBillShip($c->shipping_address))<div>{{ $c->shipping_address }}</div>@endif
                    <div>{{ collect([$c->shipping_city, $c->shipping_state, $c->shipping_zip])->filter()->join(', ') }}</div>
                    @if($hasBillShip($c->shipping_country))<div>{{ $c->shipping_country }}</div>@endif
                @else
                    <strong>{{ $c->billing_name ?: $c->name }}</strong>
                    @if($hasBillShip($c->billing_address))<div>{{ $c->billing_address }}</div>@endif
                    <div>{{ collect([$c->billing_city, $c->billing_state, $c->billing_zip])->filter()->join(', ') }}</div>
                    <div class="muted small">Same as billing</div>
                @endif
            </div>
        </section>
    </div>

    {{-- Company stats --}}
    <section class="card mb">
        <div class="card-head"><h2><x-icon name="activity" class="icon-sm" /> Company info</h2></div>
        <div class="cust-stats">
            <div><span>Customer ID</span><strong>{{ $code }}</strong></div>
            <div><span>Date of creation</span><strong>{{ \App\Services\BillingService::date($c->created_at) ?? '—' }}</strong></div>
            <div><span>Balance</span><strong class="{{ $balance > 0 ? 'cust-red' : '' }}">₹{{ number_format($balance, 2) }}</strong></div>
            <div><span>Overdue</span><strong class="{{ $overdue > 0 ? 'cust-red' : '' }}">₹{{ number_format($overdue, 2) }}</strong></div>
            <div><span>Total sum of invoices</span><strong>₹{{ number_format($totalInvoiced, 2) }}</strong></div>
            <div><span>Quantity of invoice</span><strong>{{ $count }}</strong></div>
            <div><span>Average sales</span><strong>₹{{ number_format($average, 2) }}</strong></div>
        </div>
    </section>

    {{-- Proposals (estimates) --}}
    <section class="card mb">
        <div class="card-head"><h2><x-icon name="file" class="icon-sm" /> Proposal</h2><a class="btn btn-sm" href="{{ route('billing.estimates.create', ['customer' => $c->id]) }}"><x-icon name="plus" class="icon-sm" />Create Proposal</a></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Proposal</th><th>Issue date</th><th class="num">Amount</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            @forelse($estimates as $e)
                <tr wire:key="e-{{ $e->id }}">
                    <td><a class="btn btn-sm" style="border-color:var(--blue);color:var(--blue);font-weight:600" href="{{ route('billing.estimates.show', $e) }}">{{ $e->number() }}</a></td>
                    <td class="nowrap">{{ \App\Services\BillingService::date($e->issue_date) ?? '—' }}</td>
                    <td class="num">₹{{ number_format($e->total(), 2) }}</td>
                    <td><span class="bill-badge {{ billing_badge_class($e->status) }}">{{ $e->status }}</span></td>
                    <td class="row-actions">
                        @if($e->status === 'Converted' && $e->converted_invoice_id)<a class="btn btn-sm" href="{{ route('billing.invoices.show', $e->converted_invoice_id) }}" title="Already converted to invoice"><x-icon name="receipt" class="icon-sm" /></a>
                        @else<a class="btn btn-sm" href="{{ route('billing.estimates.show', $e) }}" title="Convert to Invoice"><x-icon name="refresh" class="icon-sm" /></a>@endif
                        <button type="button" class="btn btn-sm" wire:click="duplicateEstimate({{ $e->id }})" wire:confirm="{{ $e->number() }} ki copy banayein?" title="Duplicate Proposal"><x-icon name="layers" class="icon-sm" /></button>
                        <a class="btn btn-sm" href="{{ route('billing.estimates.show', $e) }}" title="Show"><x-icon name="eye" class="icon-sm" /></a>
                        <a class="btn btn-sm" href="{{ route('billing.estimates.edit', $e) }}" title="Edit"><x-icon name="edit" class="icon-sm" /></a>
                        <button type="button" class="btn btn-sm btn-danger" wire:click="deleteEstimate({{ $e->id }})" wire:confirm="Proposal {{ $e->number() }} — delete?" title="Delete"><x-icon name="trash" class="icon-sm" /></button>
                    </td></tr>
            @empty<tr><td colspan="5"><x-empty title="No proposals yet" icon="file" /></td></tr>@endforelse
            </tbody></table></div>
    </section>

    {{-- Invoices --}}
    <section class="card">
        <div class="card-head"><h2><x-icon name="receipt" class="icon-sm" /> Invoice</h2><a class="btn btn-sm" href="{{ route('billing.invoices.create', ['customer' => $c->id]) }}"><x-icon name="plus" class="icon-sm" />Create Invoice</a></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Invoice</th><th>Issue date</th><th>Due date</th><th class="num">Due amount</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            @forelse($invoices as $i)
                @php($due = max(0, $i->due()))
                <tr wire:key="i-{{ $i->id }}">
                    <td><a class="btn btn-sm" style="border-color:var(--blue);color:var(--blue);font-weight:600" href="{{ route('billing.invoices.show', $i) }}">{{ $i->number() }}</a></td>
                    <td class="nowrap">{{ \App\Services\BillingService::date($i->issue_date) ?? '—' }}</td>
                    <td class="nowrap {{ $i->due_date && $i->due_date->isPast() && $due > 0 ? 'ov-red' : '' }}">{{ \App\Services\BillingService::date($i->due_date) ?? '—' }}</td>
                    <td class="num">@if($due > 0)<strong style="color:var(--red,#dc2626)">₹{{ number_format($due, 2) }}</strong>@else<span class="muted">₹0.00</span>@endif</td>
                    <td><span class="bill-badge {{ billing_badge_class($i->status) }}">{{ $i->status }}</span></td>
                    <td class="row-actions">
                        @if($i->status !== 'Draft')<button type="button" class="btn btn-sm" title="Copy link (the customer can open it without signing in)" x-on:click="navigator.clipboard.writeText(@js(app(\App\Services\InvoiceActions::class)->publicUrl($i))).then(() => window.dispatchEvent(new CustomEvent('toast', { detail: { message: 'Invoice link copied.', type: 'success' } })))"><x-icon name="external" class="icon-sm" /></button>@endif
                        <button type="button" class="btn btn-sm" wire:click="duplicateInvoice({{ $i->id }})" wire:confirm="{{ $i->number() }} — make a copy as a new Draft invoice?" title="Duplicate"><x-icon name="layers" class="icon-sm" /></button>
                        <a class="btn btn-sm" href="{{ route('billing.invoices.show', $i) }}" title="View"><x-icon name="eye" class="icon-sm" /></a>
                        <a class="btn btn-sm" href="{{ route('billing.invoices.edit', $i) }}" title="Edit"><x-icon name="edit" class="icon-sm" /></a>
                        <a class="btn btn-sm" href="{{ route('billing.invoices.print', $i) }}" target="_blank" title="Print / PDF"><x-icon name="printer" class="icon-sm" /></a>
                        <button type="button" class="btn btn-sm btn-danger" wire:click="deleteInvoice({{ $i->id }})" wire:confirm="Invoice {{ $i->number() }}? This cannot be undone." title="Delete"><x-icon name="trash" class="icon-sm" /></button>
                    </td></tr>
            @empty<tr><td colspan="6"><x-empty title="No invoices yet" icon="receipt" /></td></tr>@endforelse
            </tbody></table></div>
    </section>


    @if($emailOpen)
        <x-modal title="E-mail account statement">
            <form wire:submit="sendEmail"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="To (customer e-mail)" name="emailTo" full><input type="email" wire:model="emailTo" placeholder="customer@company.com"></x-field>
                <x-field label="From" name="statementFrom"><input type="date" wire:model="statementFrom"></x-field>
                <x-field label="To" name="statementTo"><input type="date" wire:model="statementTo"></x-field>
                <x-field label="Message (optional)" name="emailMessage" full hint="shown at the top of the e-mail"><textarea wire:model="emailMessage" rows="3"></textarea></x-field>
            </div><p class="small muted">The customer gets a link to the statement for this period (invoices, payments, balance) — it opens without signing in.</p></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="$set('emailOpen', false)">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled"><x-icon name="mail" class="icon-sm" />Send e-mail</button></div></form>
        </x-modal>
    @endif
    <style>
        .cust-cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;align-items:stretch}
        .cust-cards .card{display:flex;flex-direction:column;margin:0}
        @media(max-width:860px){.cust-cards{grid-template-columns:1fr}}
        .cust-lines{padding:14px 18px 18px;line-height:1.75;font-size:14px;overflow-wrap:anywhere}
        .cust-lines strong{display:block;font-size:15px;margin-bottom:4px}
        .cust-lines .muted{color:var(--muted)}
        .cust-lines a{color:var(--blue)}
        .cust-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;padding:16px 18px 18px}
        .cust-stats>div{background:var(--canvas);border:1px solid var(--line-2);border-radius:10px;padding:12px 14px;min-width:0}
        .cust-stats span{display:block;font-size:11px;letter-spacing:.04em;text-transform:uppercase;color:var(--muted);margin-bottom:6px}
        .cust-stats strong{display:block;font-size:16px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .cust-stats .cust-red{color:var(--red,#dc2626)}
    </style>
</div>
