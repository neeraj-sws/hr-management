<div>
    @include('billing._ui')
    <div class="page-head"><div><h1>Bills</h1><p>Purchase bills from suppliers — payments made and what is still to pay.</p></div>
        <a class="btn btn-primary" href="{{ route('billing.bills.create') }}"><x-icon name="plus" class="icon-sm" />New bill</a></div>
    <div class="stats">
        <div class="stat"><span class="stat-icon"><x-icon name="receipt" /></span><div><span class="stat-label">Bills</span><span class="stat-value">{{ $totals['count'] }}</span></div></div>
        <div class="stat"><span class="stat-icon"><x-icon name="layers" /></span><div><span class="stat-label">Billed</span><span class="stat-value">₹{{ number_format($totals['billed'], 0) }}</span></div></div>
        <div class="stat"><span class="stat-icon green"><x-icon name="check-circle" /></span><div><span class="stat-label">Paid</span><span class="stat-value">₹{{ number_format($totals['paid'], 0) }}</span></div></div>
        <div class="stat"><span class="stat-icon amber"><x-icon name="clock" /></span><div><span class="stat-label">To pay</span><span class="stat-value">₹{{ number_format($totals['due'], 0) }}</span></div></div>
    </div>
    <section class="card">
        <div class="toolbar">
            <label class="field"><span>Entries per page</span><select wire:model.live="perPage"><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option></select></label>
            <label class="field grow"><span>Search</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Supplier, bill no. or supplier's bill no."></label>
            <label class="field"><span>Supplier</span><select wire:model.live="vendor"><option value="">All suppliers</option>@foreach($vendors as $v)<option value="{{ $v->id }}">{{ $v->name }}</option>@endforeach</select></label>
            <label class="field"><span>Bill date from</span><input type="date" wire:model.live="from"></label>
            <label class="field"><span>to</span><input type="date" wire:model.live="to"></label>
            <label class="field"><span>Status</span><select wire:model.live="status"><option value="">Any</option>@foreach(\App\Models\BillingBill::STATUSES as $s)<option value="{{ $s }}">{{ $s === 'Sent' ? 'Received' : $s }}</option>@endforeach</select></label>
            <div class="field"><span>&nbsp;</span><button type="button" class="btn" wire:click="resetFilters"><x-icon name="x" class="icon-sm" />Reset</button></div>
        </div>
        <div class="table-wrap"><table class="table fx"><colgroup><col style="width:10%"><col style="width:16%"><col style="width:10%"><col style="width:10%"><col style="width:10%"><col style="width:9%"><col style="width:9%"><col style="width:10%"><col style="width:16%"></colgroup>
            <thead><tr><th>Bill</th><th>Supplier</th><th>Bill date</th><th>Due date</th><th class="num">Total</th><th class="num">Paid</th><th class="num">Due</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            @forelse($bills as $b)
                <tr wire:key="b-{{ $b->id }}">
                    <td><a class="btn btn-sm" style="border-color:var(--blue);color:var(--blue);font-weight:600" href="{{ route('billing.bills.show', $b) }}">{{ $b->number() }}</a>@if($b->ref_number)<small>{{ $b->ref_number }}</small>@endif</td>
                    <td>{{ $b->vendor?->name ?? '—' }}</td>
                    <td class="nowrap">{{ \App\Services\BillingService::date($b->bill_date) }}</td>
                    <td class="nowrap {{ $b->due_date && $b->due_date->isPast() && $b->due() > 0 && $b->status !== 'Draft' ? 'text-red' : '' }}">{{ \App\Services\BillingService::date($b->due_date) ?? '—' }}</td>
                    <td class="num">{{ number_format($b->total(), 2) }}</td><td class="num">{{ number_format($b->paidAmount(), 2) }}</td>
                    <td class="num"><strong>{{ number_format(max(0, $b->due()), 2) }}</strong></td>
                    <td><span class="bill-badge {{ billing_badge_class($b->status) }}">{{ $b->status === 'Sent' ? 'Received' : $b->status }}</span></td>
                    <td><div class="row-actions nowrap">
                        <button type="button" class="btn btn-sm btn-icon" wire:click="duplicate({{ $b->id }})" wire:confirm="{{ $b->number() }} ki copy banayein?" title="Duplicate" aria-label="Duplicate bill"><x-icon name="layers" class="icon-sm" /></button>
                        <a class="btn btn-sm btn-icon" href="{{ route('billing.bills.show', $b) }}" title="View" aria-label="View bill"><x-icon name="eye" class="icon-sm" /></a>
                        <a class="btn btn-sm btn-icon" href="{{ route('billing.bills.edit', $b) }}" title="Edit" aria-label="Edit bill"><x-icon name="edit" class="icon-sm" /></a>
                        <button type="button" class="btn btn-sm btn-icon btn-danger" wire:click="delete({{ $b->id }})" wire:confirm="Bill {{ $b->number() }} — delete?" title="Delete" aria-label="Delete bill"><x-icon name="trash" class="icon-sm" /></button>
                    </div></td></tr>
            @empty<tr><td colspan="9"><x-empty title="No bills" icon="receipt" /></td></tr>@endforelse
            </tbody></table></div>
        {{ $bills->links() }}
    </section>
</div>
