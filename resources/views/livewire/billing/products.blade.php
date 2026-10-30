<div>
    <div class="page-head"><div><h1>Products &amp; services</h1><p>Items you add to invoices and estimates.</p></div>
        <button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />Add item</button></div>
    <x-errors />
    <section class="card">
        <div class="toolbar"><label class="field grow"><span>Search</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Name or SKU"></label></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Name</th><th>Type</th><th>SKU</th><th class="num">Price</th><th>Tax</th><th>Active</th><th></th></tr></thead>
            <tbody>
            @forelse($products as $p)
                <tr wire:key="p-{{ $p->id }}"><td><strong>{{ $p->name }}</strong>@if($p->description)<small style="white-space:normal">{{ \Illuminate\Support\Str::limit($p->description, 60) }}</small>@endif</td>
                    <td><span class="badge">{{ ucfirst($p->type) }}</span></td><td>{{ $p->sku ?: '—' }}</td>
                    <td class="num">{{ number_format($p->sale_price, 2) }}</td>
                    <td>{{ $p->tax ? $p->tax->name.' ('.(float) $p->tax->rate.'%)' : '—' }}</td>
                    <td>{{ $p->is_active ? 'Yes' : 'No' }}</td>
                    <td><button class="btn btn-sm" wire:click="edit({{ $p->id }})">Edit</button></td></tr>
            @empty<tr><td colspan="7"><x-empty title="No products yet" icon="layers" /></td></tr>@endforelse
            </tbody></table></div>
        {{ $products->links() }}
    </section>
    @if($showForm)
        <x-modal :title="$editingId ? 'Edit item' : 'Add item'">
            <form wire:submit="save"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Name" name="name" full><input wire:model="name"></x-field>
                <x-field label="Type" name="type"><select wire:model="type"><option value="service">Service</option><option value="product">Product</option></select></x-field>
                <x-field label="SKU" name="sku"><input wire:model="sku"></x-field>
                <x-field label="Sale price" name="salePrice"><input type="number" step="0.01" min="0" wire:model="salePrice"></x-field>
                <x-field label="Purchase price" name="purchasePrice"><input type="number" step="0.01" min="0" wire:model="purchasePrice"></x-field>
                <x-field label="Default tax" name="taxId"><select wire:model="taxId"><option value="">None</option>@foreach($taxes as $t)<option value="{{ $t->id }}">{{ $t->name }} ({{ (float) $t->rate }}%)</option>@endforeach</select></x-field>
                <x-field label="Active" name="isActive"><select wire:model="isActive"><option value="1">Yes</option><option value="0">No</option></select></x-field>
                <x-field label="Description" name="description" full><textarea wire:model="description" rows="2"></textarea></x-field>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary">Save</button></div></form>
        </x-modal>
    @endif
</div>
