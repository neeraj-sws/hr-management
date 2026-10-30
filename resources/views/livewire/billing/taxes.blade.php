<div>
    <div class="page-head"><div><h1>Taxes</h1><p>Tax rates used on invoice and estimate items.</p></div>
        <button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />Add tax</button></div>
    <x-errors />
    <section class="card">
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Name</th><th class="num">Rate (%)</th><th></th></tr></thead>
            <tbody>
            @forelse($taxes as $t)
                <tr wire:key="t-{{ $t->id }}"><td><strong>{{ $t->name }}</strong></td><td class="num">{{ (float) $t->rate }}</td>
                    <td><button class="btn btn-sm" wire:click="edit({{ $t->id }})">Edit</button></td></tr>
            @empty<tr><td colspan="3"><x-empty title="No taxes" icon="layers" /></td></tr>@endforelse
            </tbody></table></div>
    </section>
    @if($showForm)
        <x-modal :title="$editingId ? 'Edit tax' : 'Add tax'">
            <form wire:submit="save"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Name" name="name"><input wire:model="name" placeholder="e.g. CGST"></x-field>
                <x-field label="Rate (%)" name="rate"><input type="number" step="0.01" min="0" max="100" wire:model="rate"></x-field>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary">Save</button></div></form>
        </x-modal>
    @endif
</div>
