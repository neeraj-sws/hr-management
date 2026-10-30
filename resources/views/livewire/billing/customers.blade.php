<div>
    <div class="page-head"><div><h1>Customers</h1><p>People and companies you invoice.</p></div>
        <button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />Add customer</button></div>
    <x-errors />
    <section class="card">
        <div class="toolbar"><label class="field grow"><span>Search</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Name, email or contact"></label></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Name</th><th>Email</th><th>Contact</th><th class="num">Invoices</th><th></th></tr></thead>
            <tbody>
            @forelse($customers as $c)
                <tr wire:key="c-{{ $c->id }}"><td><strong>{{ $c->name }}</strong>@if($c->billing_city)<small>{{ $c->billing_city }}{{ $c->billing_state ? ', '.$c->billing_state : '' }}</small>@endif</td>
                    <td>{{ $c->email ?: '—' }}</td><td>{{ $c->contact ?: '—' }}</td><td class="num">{{ $c->invoices_count }}</td>
                    <td><button class="btn btn-sm" wire:click="edit({{ $c->id }})">Edit</button></td></tr>
            @empty<tr><td colspan="5"><x-empty title="No customers" icon="users" /></td></tr>@endforelse
            </tbody></table></div>
        {{ $customers->links() }}
    </section>
    @if($showForm)
        <x-modal :title="$editingId ? 'Edit customer' : 'Add customer'">
            <form wire:submit="save"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Name" name="name" full><input wire:model="name"></x-field>
                <x-field label="Email" name="email"><input type="email" wire:model="email"></x-field>
                <x-field label="Contact" name="contact"><input wire:model="contact"></x-field>
                <x-field label="Tax / GST number" name="taxNumber"><input wire:model="taxNumber"></x-field>
                <x-field label="City" name="billingCity"><input wire:model="billingCity"></x-field>
                <x-field label="State" name="billingState"><input wire:model="billingState"></x-field>
                <x-field label="Billing address" name="billingAddress" full><textarea wire:model="billingAddress" rows="2"></textarea></x-field>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary">Save</button></div></form>
        </x-modal>
    @endif
</div>
