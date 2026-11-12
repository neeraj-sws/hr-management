<div>
    @include('billing._ui')
    <div class="page-head"><div><h1>Invoices</h1><p>Bills to customers — status, payments and dues.</p></div>
        <a class="btn btn-primary" href="{{ route('billing.invoices.create') }}"><x-icon name="plus" class="icon-sm" />New invoice</a></div>
    <div class="stats">
        <div class="stat"><span class="stat-icon"><x-icon name="receipt" /></span><div><span class="stat-label">Invoices</span><span class="stat-value">{{ $totals['count'] }}</span></div></div>
        <div class="stat"><span class="stat-icon"><x-icon name="layers" /></span><div><span class="stat-label">Billed</span><span class="stat-value">{{ number_format($totals['billed'], 0) }}</span></div></div>
        <div class="stat"><span class="stat-icon green"><x-icon name="check-circle" /></span><div><span class="stat-label">Received</span><span class="stat-value">{{ number_format($totals['received'], 0) }}</span></div></div>
        <div class="stat"><span class="stat-icon amber"><x-icon name="clock" /></span><div><span class="stat-label">Outstanding</span><span class="stat-value">{{ number_format($totals['due'], 0) }}</span></div></div>
    </div>
    <section class="card">
        <div class="toolbar">
            <label class="field"><span>Entries per page</span><select wire:model.live="perPage"><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option></select></label>
            <label class="field grow"><span>Search</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Customer name or invoice no."></label>
            <label class="field"><span>Customer</span><select wire:model.live="customer"><option value="">All customers</option>@foreach($customers as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></label>
            <label class="field"><span>Issue date from</span><input type="date" wire:model.live="from"></label>
            <label class="field"><span>to</span><input type="date" wire:model.live="to"></label>
            <label class="field"><span>Status</span><select wire:model.live="status"><option value="">Any</option>@foreach(\App\Models\BillingInvoice::STATUSES as $s)<option value="{{ $s }}">{{ $s }}</option>@endforeach</select></label>
            <label class="field"><span>Financial year</span><select wire:model.live="fy"><option value="">All</option>@foreach($years as $y)<option value="{{ $y }}">{{ $y }}</option>@endforeach</select></label>
            <div class="field"><span>&nbsp;</span><div class="row-actions">
                <button type="button" class="btn" wire:click="resetFilters" title="Clear all filters"><x-icon name="x" class="icon-sm" />Reset</button>
                <a class="btn" href="{{ route('billing.invoices.export', $filters) }}"><x-icon name="download" class="icon-sm" />Export</a>
            </div></div>
        </div>
        <div class="table-wrap"><table class="table fx"><colgroup><col style="width:17%"><col style="width:17%"><col style="width:11%"><col style="width:11%"><col style="width:12%"><col style="width:10%"><col style="width:22%"></colgroup>
            <thead><tr><th>Invoice</th><th>Customer</th><th>Issue Date</th><th>Due Date</th><th>Due Amount</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            @forelse($invoices as $i)
                @php($due = max(0, $i->due()))
                <tr wire:key="i-{{ $i->id }}">
                    <td><a class="cms-no" href="{{ route('billing.invoices.show', $i) }}" title="{{ $i->customer?->name }}">#{{ ltrim($i->number(), '#') }}</a></td>
                    <td>@if($i->customer)<a href="{{ route('billing.customers.show', $i->customer) }}" style="color:inherit">{{ $i->customer->name }}</a>@else—@endif</td>
                    <td class="nowrap">{{ \App\Services\BillingService::date($i->issue_date) }}</td>
                    <td class="nowrap {{ $i->due_date && $i->due_date->isPast() && $due > 0 ? 'cms-late' : '' }}">{{ \App\Services\BillingService::date($i->due_date) ?? '—' }}</td>
                    <td class="nowrap">Rs {{ number_format($due, 2) }}</td>
                    <td><span class="cms-pill {{ billing_badge_class($i->status) }}">{{ $i->status }}</span></td>
                    <td><div class="cms-acts">
                        @if($i->status === 'Draft')<button type="button" class="cms-act link" disabled title="Draft ka link nahi — pehle Send karo" aria-label="Copy invoice link"><x-icon name="link" /></button>
                        @else<button type="button" class="cms-act link" title="Copy Invoice" aria-label="Copy invoice link"
                            x-on:click="navigator.clipboard.writeText(@js(app(\App\Services\InvoiceActions::class)->publicUrl($i))).then(() => window.dispatchEvent(new CustomEvent('toast', { detail: { message: 'Link copied on clipboard.', type: 'success' } })))"><x-icon name="link" /></button>@endif
                        <button type="button" class="cms-act copy" wire:click="duplicate({{ $i->id }})" wire:confirm="{{ $i->number() }} ki copy banake nayi Draft invoice kholein?" title="Duplicate" aria-label="Duplicate invoice"><x-icon name="copy" /></button>
                        <a class="cms-act view" href="{{ route('billing.invoices.show', $i) }}" title="Show" aria-label="View invoice"><x-icon name="eye" /></a>
                        <a class="cms-act edit" href="{{ route('billing.invoices.edit', $i) }}" title="Edit" aria-label="Edit invoice"><x-icon name="edit" /></a>
                        <button type="button" class="cms-act del" wire:click="delete({{ $i->id }})" wire:confirm="Invoice {{ $i->number() }} delete karein? Ye wapas nahi aayegi." title="Delete" aria-label="Delete invoice"><x-icon name="trash" /></button>
                    </div></td></tr>
            @empty<tr><td colspan="7"><x-empty title="No invoices" icon="receipt" /></td></tr>@endforelse
            </tbody></table></div>
        {{ $invoices->links() }}
    </section>
</div>
