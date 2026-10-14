<div>
    <div class="page-head"><div><h1>Departments</h1><p>{{ $departments->total() }} departments</p></div><button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />Add department</button></div>
    <section class="card"><div class="table-wrap"><table class="table">
        <thead><tr><th>Department</th><th>Code</th><th>Head</th><th class="num">Active employees</th><th class="num">Teams</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @forelse($departments as $department)
            <tr wire:key="dep-{{ $department->id }}"><td><strong>{{ $department->name }}</strong></td><td>{{ $department->code }}</td><td>{{ $department->head?->name ?? '—' }}</td><td class="num">{{ $department->employees_count }}</td><td class="num">{{ $department->teams_count }}</td><td><x-badge :status="$department->is_active ? 'active' : 'inactive'" /></td><td><div class="row-actions"><a class="btn btn-sm" href="{{ route('people.employees', ['department' => $department->id]) }}">Employees</a><button class="btn btn-sm" wire:click="edit({{ $department->id }})">Edit</button></div></td></tr>
        @empty
            <tr><td colspan="7"><x-empty title="No departments yet" icon="building">Create your first department to organise employees.</x-empty></td></tr>
        @endforelse
        </tbody>
    </table></div></section>
    {{ $departments->links() }}

    @if($showForm)
        <x-modal :title="$editingId ? 'Edit department' : 'Add department'">
            <form wire:submit="save"><div class="modal-body"><div class="form-grid">
                <x-field label="Name" name="name"><input wire:model="name"></x-field>
                <x-field label="Code" name="code"><input wire:model="code" placeholder="ENG"></x-field>
                <x-field label="Department head" name="headId" full><select wire:model="headId"><option value="">—</option>@foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach</select></x-field>
                <label class="check full"><input type="checkbox" wire:model="isActive"> Active</label>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Save</button></div></form>
        </x-modal>
    @endif
</div>
