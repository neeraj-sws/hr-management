<div>
    <div class="page-head"><div><h1>Debit notes</h1><p>Amounts reduced on supplier bills (returns, discounts). Add one from the bill page.</p></div></div>
    <div class="stats"><div class="stat"><span class="stat-icon amber"><x-icon name="layers" /></span><div><span class="stat-label">Total debited</span><span class="stat-value">₹{{ number_format($total, 2) }}</span></div></div></div>
    <section class="card">
        <div class="toolbar">
            <label class="field"><span>Entries per page</span><select wire:model.live="perPage"><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option></select></label>
            <label class="field"><span>Supplier</span><select wire:model.live="vendor"><option value="">All suppliers</option>@foreach($vendors as $v)<option value="{{ $v->id }}">{{ $v->name }}</option>@endforeach</select></label>
            <label class="field"><span>Date from</span><input type="date" wire:model.live="from"></label>
            <label class="field"><span>to</span><input type="date" wire:model.live="to"></label>
            <div class="field"><span>&nbsp;</span><button type="button" class="btn" wire:click="resetFilters"><x-icon name="x" class="icon-sm" />Reset</button></div>
        </div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Bill</th><th>Supplier</th><th>Date</th><th class="num">Amount</th><th>Description</th><th>Action</th></tr></thead>
            <tbody>
            @forelse($notes as $n)
                <tr wire:key="dn-{{ $n->id }}">
                    <td>@if($n->bill)<a class="btn btn-sm" href="{{ route('billing.bills.show', $n->bill) }}">{{ $n->bill->number() }}</a>@else—@endif</td>
                    <td>{{ $n->vendor?->name ?? '—' }}</td><td class="nowrap">{{ \App\Services\BillingService::date($n->date) }}</td>
                    <td class="num"><strong>₹{{ number_format($n->amount, 2) }}</strong></td><td style="white-space:normal">{{ $n->description ?: '—' }}</td>
                    <td><div class="row-actions">
                        @if($n->bill)<a class="btn btn-sm btn-icon" href="{{ route('billing.bills.show', $n->bill) }}" title="Open bill" aria-label="Open bill"><x-icon name="eye" class="icon-sm" /></a>@endif
                        <button class="btn btn-sm btn-icon btn-danger" wire:click="delete({{ $n->id }})" wire:confirm="Debit note delete karein?" title="Delete" aria-label="Delete debit note"><x-icon name="trash" class="icon-sm" /></button>
                    </div></td></tr>
            @empty<tr><td colspan="6"><x-empty title="No debit notes" icon="receipt" /></td></tr>@endforelse
            </tbody></table></div>
        {{ $notes->links() }}
    </section>
</div>
