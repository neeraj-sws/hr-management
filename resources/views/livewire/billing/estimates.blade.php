<div>
    @include('billing._ui')
    <div class="page-head"><div><h1>Manage Proposals</h1><p>Proposals (estimates) you send before billing — convert an accepted one to an invoice.</p></div>
        <div class="row-actions"><a class="btn" href="{{ route('billing.estimates.export') }}"><x-icon name="download" class="icon-sm" />Export</a><a class="btn btn-primary" href="{{ route('billing.estimates.create') }}"><x-icon name="plus" class="icon-sm" />Create Proposal</a></div></div>
    <section class="card">
        <div class="toolbar">
            <label class="field"><span>Entries per page</span><select wire:model.live="perPage"><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option></select></label>
            <label class="field grow"><span>Search</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Customer name or estimate no."></label>
            <label class="field"><span>Customer</span><select wire:model.live="customer"><option value="">All customers</option>@foreach($customers as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></label>
            <label class="field"><span>Issue date from</span><input type="date" wire:model.live="from"></label>
            <label class="field"><span>to</span><input type="date" wire:model.live="to"></label>
            <label class="field"><span>Status</span><select wire:model.live="status"><option value="">Any</option>@foreach(\App\Models\BillingEstimate::STATUSES as $s)<option value="{{ $s }}">{{ $s }}</option>@endforeach</select></label>
            <label class="field"><span>Financial year</span><select wire:model.live="fy"><option value="">All</option>@foreach($years as $y)<option value="{{ $y }}">{{ $y }}</option>@endforeach</select></label>
            <div class="field"><span>&nbsp;</span><button type="button" class="btn" wire:click="resetFilters"><x-icon name="x" class="icon-sm" />Reset</button></div>
        </div>
        <div class="table-wrap"><table class="table fx"><colgroup><col style="width:15%"><col style="width:17%"><col style="width:10%"><col style="width:11%"><col style="width:11%"><col style="width:12%"><col style="width:24%"></colgroup>
            <thead><tr><th>Proposal</th><th>Customer</th><th>Category</th><th>Issue Date</th><th>Amount</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            @forelse($estimates as $e)
                <tr wire:key="e-{{ $e->id }}">
                    <td><a class="cms-no" href="{{ route('billing.estimates.show', $e) }}">#{{ ltrim($e->number(), '#') }}</a></td>
                    <td>@if($e->customer)<a href="{{ route('billing.customers.show', $e->customer) }}">{{ $e->customer->name }}</a>@else—@endif</td>
                    <td>{{ $e->category?->name ?? '—' }}</td>
                    <td class="nowrap">{{ \App\Services\BillingService::date($e->issue_date) }}</td>
                    <td class="nowrap">Rs {{ number_format($e->total(), 2) }}</td>
                    <td><span class="cms-pill {{ billing_badge_class($e->status) }}">{{ $e->status }}</span></td>
                    <td><div class="cms-acts">
                        @if($e->status === 'Converted' && $e->converted_invoice_id)<a class="cms-act conv" href="{{ route('billing.invoices.show', $e->converted_invoice_id) }}" title="Already convert to Invoice" aria-label="Open invoice"><x-icon name="receipt" /></a>
                        @else<a class="cms-act conv" href="{{ route('billing.estimates.show', $e) }}" title="Convert to Invoice" aria-label="Convert to invoice"><x-icon name="refresh" /></a>@endif
                        @if($e->status === 'Draft')<button type="button" class="cms-act link" disabled title="Draft ka link nahi — pehle Send karo" aria-label="Copy proposal link"><x-icon name="link" /></button>
                        @else<button type="button" class="cms-act link" title="Copy Proposal" aria-label="Copy proposal link"
                            x-on:click="navigator.clipboard.writeText(@js(app(\App\Services\InvoiceActions::class)->estimateUrl($e))).then(() => window.dispatchEvent(new CustomEvent('toast', { detail: { message: 'Link copied on clipboard.', type: 'success' } })))"><x-icon name="link" /></button>@endif
                        <button type="button" class="cms-act copy" wire:click="duplicate({{ $e->id }})" wire:confirm="{{ $e->number() }} ki copy banake naya Draft proposal kholein?" title="Duplicate" aria-label="Duplicate proposal"><x-icon name="copy" /></button>
                        <a class="cms-act view" href="{{ route('billing.estimates.show', $e) }}" title="Show" aria-label="View proposal"><x-icon name="eye" /></a>
                        <a class="cms-act edit" href="{{ route('billing.estimates.edit', $e) }}" title="Edit" aria-label="Edit proposal"><x-icon name="edit" /></a>
                        <button type="button" class="cms-act del" wire:click="delete({{ $e->id }})" wire:confirm="Proposal {{ $e->number() }} delete karein?{{ $e->status === 'Converted' ? ' (Bani hui invoice rahegi.)' : '' }}" title="Delete" aria-label="Delete proposal"><x-icon name="trash" /></button>
                    </div></td></tr>
            @empty<tr><td colspan="7"><x-empty title="No proposals" icon="receipt" /></td></tr>@endforelse
            </tbody></table></div>
        {{ $estimates->links() }}
    </section>
</div>
