<div>
    <div class="page-head"><div><h1>Credit notes</h1><p>Amounts credited back to customers against invoices.</p></div>
        <button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />Add credit note</button></div>
    <x-errors />
    <div class="stats">
        <div class="stat"><span class="stat-icon"><x-icon name="receipt" /></span><div><span class="stat-label">Credit notes</span><span class="stat-value">{{ $notes->total() }}</span></div></div>
        <div class="stat"><span class="stat-icon amber"><x-icon name="layers" /></span>            <div><span class="stat-label">Total credited</span><span class="stat-value">₹{{ number_format($total, 2) }}</span></div></div>
    </div>
    <section class="card">
        <div class="toolbar">
            <label class="field"><span>Customer</span><select wire:model.live="customer"><option value="">All customers</option>@foreach($customers as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></label>
            <label class="field"><span>Date from</span><input type="date" wire:model.live="from"></label>
            <label class="field"><span>to</span><input type="date" wire:model.live="to"></label>
            <div class="field"><span>&nbsp;</span><button type="button" class="btn" wire:click="resetFilters"><x-icon name="x" class="icon-sm" />Reset</button></div>
        </div>
        <div class="toolbar">
            <label class="field"><span>Entries per page</span><select wire:model.live="perPage"><option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option></select></label>
            <label class="field grow"><span>Search</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Customer, invoice no. or description"></label>
        </div>
        <div class="table-wrap"><table class="table fx"><colgroup><col style="width:14%"><col style="width:20%"><col style="width:12%"><col style="width:12%"><col style="width:30%"><col style="width:12%"></colgroup>
            <thead><tr><th>Invoice</th><th>Customer</th><th>Date</th><th class="num">Amount</th><th>Description</th><th>Action</th></tr></thead>
            <tbody>
            @forelse($notes as $n)
                <tr wire:key="cn-{{ $n->id }}">
                    <td>@if($n->invoice)<a class="btn btn-sm" href="{{ route('billing.invoices.show', $n->invoice) }}">{{ $n->invoice->number() }}</a>@else—@endif</td>
                    <td>{{ $n->customer?->name ?? '—' }}</td><td class="nowrap">{{ \App\Services\BillingService::date($n->date) }}</td>
                    <td class="num"><strong>₹{{ number_format($n->amount, 2) }}</strong></td><td style="white-space:normal">{{ $n->description ?: '—' }}</td>
                    <td><div class="row-actions">
                        <button class="btn btn-sm btn-icon" wire:click="edit({{ $n->id }})" title="Edit" aria-label="Edit credit note"><x-icon name="edit" class="icon-sm" /></button>
                        <button class="btn btn-sm btn-icon btn-danger" wire:click="delete({{ $n->id }})" wire:confirm="Credit note of {{ number_format($n->amount, 2) }} delete karein?" title="Delete" aria-label="Delete credit note"><x-icon name="trash" class="icon-sm" /></button>
                    </div></td></tr>
            @empty<tr><td colspan="6"><x-empty title="No credit notes" icon="receipt" /></td></tr>@endforelse
            </tbody></table></div>
        {{ $notes->links() }}
    </section>
    @if($showForm)
        <x-modal :title="$editingId ? 'Edit Credit Note' : 'Create New Credit Note'">
            <form wire:submit="save"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Invoice" name="invoiceId" full><select wire:model.live="invoiceId"><option value="">Select Invoice</option>@foreach($invoices as $inv)<option value="{{ $inv->id }}">{{ $inv->number() }} · {{ $inv->customer?->name }}</option>@endforeach</select></x-field>
                <x-field label="Amount" name="amount"><input type="number" step="0.01" min="0" wire:model="amount" placeholder="Enter Amount"></x-field>
                <x-field label="Date" name="date"><input type="date" wire:model="date"></x-field>
                <x-field label="Description" name="description" full><textarea wire:model="description" rows="3" placeholder="Enter Description"></textarea></x-field>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary">{{ $editingId ? 'Update' : 'Create' }}</button></div></form>
        </x-modal>
    @endif
</div>
