<div>
    <div class="page-head"><div><h1>Departments</h1><p>{{ $departments->total() }} departments · {{ $totals['active'] }} active · {{ $totals['employees'] }} employees assigned @if($totals['unassigned'])· <a href="{{ route('people.employees') }}">{{ $totals['unassigned'] }} without a department</a>@endif</p></div><button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />Add department</button></div>
    @unless($showForm)<x-errors />@endunless
    <section class="card"><div class="table-wrap"><table class="table">
        <thead><tr><th>Department</th><th>Code</th><th>Head</th><th class="num">Active employees</th><th class="num">Teams</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @forelse($departments as $department)
            <tr wire:key="dep-{{ $department->id }}"><td><strong>{{ $department->name }}</strong></td><td>{{ $department->code }}</td><td>{{ $department->head?->name ?? '—' }}</td><td class="num"><a href="{{ route('people.employees', ['department' => $department->id]) }}">{{ $department->employees_count }}</a>@if($department->all_employees_count > $department->employees_count)<small>+{{ $department->all_employees_count - $department->employees_count }} inactive</small>@endif</td><td class="num">{{ $department->teams_count }}</td><td><x-badge :status="$department->is_active ? 'active' : 'inactive'" /></td><td><div class="row-actions"><a class="btn btn-sm" href="{{ route('people.employees', ['department' => $department->id]) }}">Employees</a>@if($department->changeable())<button class="btn btn-sm" wire:click="edit({{ $department->id }})">Edit</button><button class="btn btn-sm btn-danger" wire:click="remove({{ $department->id }})" wire:confirm="Remove the department {{ $department->name }}? Only possible when no employees or teams are in it.">Remove</button>@endif</div></td></tr>
        @empty
            <tr><td colspan="7"><x-empty title="No departments yet" icon="building">Create your first department to organise employees.</x-empty></td></tr>
        @endforelse
        </tbody>
    </table></div></section>
    {{ $departments->links() }}

    {{-- Companies: each employee belongs to one; payroll is calculated, approved and locked per company. --}}
    <section class="card mt">
        <div class="card-head"><div><h2>Companies</h2><p>Payroll is calculated, approved and locked separately for each company. Choose the company on each employee (Employees › Edit › Company); an employee with none is in the main company.</p></div></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Company</th><th class="num">Active employees</th><th></th></tr></thead>
            <tbody>
            @foreach($companies as $c)
                <tr wire:key="co-{{ $c->id }}"><td><strong>{{ $c->name }}</strong></td><td class="num">{{ $c->active_count }}</td><td>@if($editCompanies)<div class="row-actions"><button class="btn btn-sm" wire:click="editCompany({{ $c->id }})">Rename</button></div>@endif</td></tr>
            @endforeach
            </tbody>
        </table></div>
        @if($editCompanies)
        <form class="toolbar" wire:submit="saveCompany" style="border-top:1px solid var(--line-2)">
            <x-field :label="$companyEditingId ? 'New name' : 'Add a company'" name="companyName"><input wire:model="companyName" maxlength="120" placeholder="e.g. ITWeb"></x-field>
            <button class="btn btn-primary" wire:loading.attr="disabled">{{ $companyEditingId ? 'Save name' : 'Add company' }}</button>
            @if($companyEditingId)<button type="button" class="btn" wire:click="cancelCompany">Cancel</button>@endif
        </form>
        @endif
    </section>

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
