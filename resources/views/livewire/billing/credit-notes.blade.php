<div>
    <div class="page-head"><div><h1>Credit notes</h1><p>Amounts credited back to customers against invoices.</p></div>
        <button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />Add credit note</button></div>
    <x-errors />
    <div class="stats">
        <div class="stat"><span class="stat-icon"><x-icon name="receipt" /></span><div><span class="stat-label">Credit notes</span><span class="stat-value">{{ $notes->total() }}</span></div></div>
        <div class="stat"><span class="stat-icon amber"><x-icon name="layers" /></span><div><span class="stat-label">Total credited</span><span class="stat-value">{{ number_format($total, 0) }}</span></div></div>
    </div>
    <section class="card">
        <div class="toolbar"><label class="field grow"><span>Search</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Customer name"></label></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Date</th><th>Customer</th><th>Invoice</th><th>Note</th><th class="num">Amount</th><th></th></tr></thead>
            <tbody>
            @forelse($notes as $n)
                <tr wire:key="cn-{{ $n->id }}"><td class="nowrap">{{ $n->date->format('d M Y') }}</td><td>{{ $n->customer?->name ?? '—' }}</td>
                    <td>@if($n->invoice)<a href="{{ route('billing.invoices.show', $n->invoice) }}">{{ $n->invoice->number() }}</a>@else—@endif</td>
                    <td>{{ $n->description ?: '—' }}</td><td class="num"><strong>{{ number_format($n->amount, 2) }}</strong></td>
                    <td><button class="btn btn-sm" wire:click="edit({{ $n->id }})">Edit</button></td></tr>
            @empty<tr><td colspan="6"><x-empty title="No credit notes" icon="receipt" /></td></tr>@endforelse
            </tbody></table></div>
        {{ $notes->links() }}
    </section>
    @if($showForm)
        <x-modal :title="$editingId ? 'Edit credit note' : 'Add credit note'">
            <form wire:submit="save"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Invoice" name="invoiceId" full><select wire:model.live="invoiceId"><option value="">— none —</option>@foreach($invoices as $inv)<option value="{{ $inv->id }}">{{ $inv->number() }} · {{ $inv->customer?->name }}</option>@endforeach</select></x-field>
                <x-field label="Customer" name="customerId"><select wire:model="customerId"><option value="">— none —</option>@foreach($customers as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></x-field>
                <x-field label="Amount" name="amount"><input type="number" step="0.01" min="0" wire:model="amount"></x-field>
                <x-field label="Date" name="date"><input type="date" wire:model="date"></x-field>
                <x-field label="Description" name="description" full><textarea wire:model="description" rows="2"></textarea></x-field>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary">Save</button></div></form>
        </x-modal>
    @endif
</div>
