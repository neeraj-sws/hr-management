<div>
    <div class="page-head"><div><h1>Revenue</h1><p>Income received — with or without an invoice.</p></div>
        <button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />Add revenue</button></div>
    <x-errors />
    <div class="stats">
        <div class="stat"><span class="stat-icon"><x-icon name="receipt" /></span><div><span class="stat-label">Entries</span><span class="stat-value">{{ $revenues->total() }}</span></div></div>
        <div class="stat"><span class="stat-icon green"><x-icon name="check-circle" /></span><div><span class="stat-label">Total received</span><span class="stat-value">{{ number_format($total, 0) }}</span></div></div>
    </div>
    <section class="card">
        <div class="toolbar"><label class="field grow"><span>Search</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Customer, category or reference"></label></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Date</th><th>Customer</th><th>Category</th><th>Method</th><th>Reference</th><th class="num">Amount</th><th></th></tr></thead>
            <tbody>
            @forelse($revenues as $r)
                <tr wire:key="rev-{{ $r->id }}"><td class="nowrap">{{ $r->date->format('d M Y') }}</td><td>{{ $r->customer?->name ?? '—' }}</td>
                    <td>{{ $r->category ?: '—' }}</td><td>{{ $r->payment_method ?: '—' }}</td><td>{{ $r->reference ?: '—' }}</td>
                    <td class="num"><strong>{{ number_format($r->amount, 2) }}</strong></td>
                    <td><button class="btn btn-sm" wire:click="edit({{ $r->id }})">Edit</button></td></tr>
            @empty<tr><td colspan="7"><x-empty title="No revenue entries" icon="receipt" /></td></tr>@endforelse
            </tbody></table></div>
        {{ $revenues->links() }}
    </section>
    @if($showForm)
        <x-modal :title="$editingId ? 'Edit revenue' : 'Add revenue'">
            <form wire:submit="save"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Date" name="date"><input type="date" wire:model="date"></x-field>
                <x-field label="Amount" name="amount"><input type="number" step="0.01" min="0" wire:model="amount"></x-field>
                <x-field label="Customer" name="customerId" full><select wire:model="customerId"><option value="">— none —</option>@foreach($customers as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></x-field>
                <x-field label="Category" name="category"><input wire:model="category" placeholder="e.g. Development"></x-field>
                <x-field label="Payment method" name="paymentMethod"><select wire:model="paymentMethod"><option value="">—</option><option>Bank</option><option>Cash</option><option>UPI</option><option>Cheque</option><option>Card</option></select></x-field>
                <x-field label="Reference" name="reference"><input wire:model="reference"></x-field>
                <x-field label="Description" name="description" full><textarea wire:model="description" rows="2"></textarea></x-field>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary">Save</button></div></form>
        </x-modal>
    @endif
</div>
