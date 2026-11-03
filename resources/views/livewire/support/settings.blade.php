<div>
    <div class="page-head">
        <div><h1>Support settings</h1><p>The categories employees choose from, and how long a resolved request can be reopened. Who handles requests is set in System &gt; Roles &amp; Permissions (support.manage, support.handle, support.payroll).</p></div>
        <div class="actions"><button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />New category</button></div>
    </div>

    <section class="card mb">
        <div class="card-head"><div><h2>Categories</h2><p>Restricted: only people with the <strong>support.payroll</strong> permission see those requests (e.g. Salary &amp; Payroll).</p></div></div>
        <div class="list">
            @foreach($categories as $c)
                <div class="list-row" wire:key="sc-{{ $c->id }}">
                    <div><strong>{{ $c->name }}</strong><small>{{ $c->description ?: 'No description' }} · {{ $c->tickets_count }} request(s) · order {{ $c->sort_order }}</small></div>
                    <div class="row-actions">
                        @if($c->is_restricted)<x-badge tone="violet">Restricted</x-badge>@endif
                        <x-badge :tone="$c->is_active ? 'green' : 'gray'">{{ $c->is_active ? 'On' : 'Off' }}</x-badge>
                        <button class="btn btn-sm" wire:click="edit({{ $c->id }})">Edit</button>
                        <button class="btn btn-sm" wire:click="toggle({{ $c->id }})">{{ $c->is_active ? 'Turn off' : 'Turn on' }}</button>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    <section class="card">
        <div class="card-head"><div><h2>Reopening</h2><p>An employee can reopen their own request for this many days after it is marked Resolved. 0 = they cannot reopen (they raise a new request). Closed requests are never reopened by the employee.</p></div></div>
        <form class="card-body" wire:submit="saveReopen">
            <div class="form-grid">
                <x-field label="Days to reopen after Resolved" name="reopenDays"><input type="number" min="0" max="365" wire:model="reopenDays"></x-field>
            </div>
            <div class="form-actions"><button class="btn btn-primary">Save</button></div>
        </form>
    </section>

    @if($showForm)
        <x-modal :title="$editingId ? 'Edit category' : 'New category'">
            <form wire:submit="save">
                <div class="modal-body stack">
                    <div class="form-grid">
                        <x-field label="Name" name="name"><input type="text" wire:model="name" maxlength="80"></x-field>
                        <x-field label="Order in the list" name="sort_order" hint="smaller first"><input type="number" min="0" max="9999" wire:model="sort_order"></x-field>
                        <x-field label="Description (optional)" name="description" full><input type="text" wire:model="description" maxlength="255"></x-field>
                    </div>
                    <label class="check"><input type="checkbox" wire:model="is_restricted"> Restricted – only people with support.payroll see these requests</label>
                    <label class="check"><input type="checkbox" wire:model="is_active"> On – employees can choose it</label>
                </div>
                <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Save</button></div>
            </form>
        </x-modal>
    @endif
</div>
