<div>
    <div class="page-head"><div><h1>Categories</h1><p>Income and expense categories used on revenue.</p></div>
        <button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />Add category</button></div>
    <x-errors />
    <section class="card">
        <div class="toolbar">
            <label class="field"><span>Type</span><select wire:model.live="type"><option value="">All</option>@foreach(\App\Models\BillingCategory::TYPES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></label>
        </div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Category</th><th>Type</th><th class="num">Revenue entries</th><th></th></tr></thead>
            <tbody>
            @forelse($categories as $c)
                <tr wire:key="cat-{{ $c->id }}"><td><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:{{ $c->color ?: '#cbd5e1' }};margin-right:6px"></span><strong>{{ $c->name }}</strong></td>
                    <td>{{ \App\Models\BillingCategory::TYPES[$c->type] ?? $c->type }}</td><td class="num">{{ $used[$c->id] ?? 0 }}</td>
                    <td><div class="row-actions">
                        <button class="btn btn-sm btn-icon" wire:click="edit({{ $c->id }})" title="Edit" aria-label="Edit category"><x-icon name="edit" class="icon-sm" /></button>
                        <button class="btn btn-sm btn-icon btn-danger" wire:click="delete({{ $c->id }})" wire:confirm="Category {{ $c->name }} delete karein?" title="Delete" aria-label="Delete category"><x-icon name="trash" class="icon-sm" /></button>
                    </div></td></tr>
            @empty<tr><td colspan="4"><x-empty title="No categories" icon="layers" /></td></tr>@endforelse
            </tbody></table></div>
    </section>
    @if($showForm)
        <x-modal :title="$editingId ? 'Edit category' : 'Add category'">
            <form wire:submit="save"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Name" name="name" full><input wire:model="name" placeholder="e.g. Web Development Service"></x-field>
                <x-field label="Type" name="categoryType"><select wire:model="categoryType">@foreach(\App\Models\BillingCategory::TYPES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></x-field>
                <x-field label="Colour" name="color"><input type="color" wire:model="color"></x-field>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary">Save</button></div></form>
        </x-modal>
    @endif
</div>
