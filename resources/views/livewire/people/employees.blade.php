<div>
    <div class="page-head">
        <div><h1>Employees</h1><p>{{ $counts['active'] }} active · {{ $counts['inactive'] }} inactive</p></div>
        <div class="actions">
            @if(\App\Support\Navigation::canAccess(auth()->user(), 'reports.show', ['type' => 'employees']))<a class="btn" href="{{ route('reports.show', 'employees') }}"><x-icon name="download" class="icon-sm" />Export</a>@endif
            <button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />Add employee</button>
        </div>
    </div>

    <section class="card">
        <div class="toolbar">
            <label class="field grow"><span>Search</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Name, code, email or designation"></label>
            <label class="field"><span>Department</span><select wire:model.live="department"><option value="">All departments</option>@foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></label>
            <label class="field"><span>Status</span><select wire:model.live="status"><option value="active">Active</option><option value="inactive">Inactive</option><option value="all">All</option></select></label>
            <span wire:loading class="spinner" style="margin-bottom:10px"></span>
        </div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Employee</th><th>Department</th><th>Manager</th><th>Teams</th><th>Roles</th><th>Joined</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($employees as $employee)
                <tr wire:key="emp-{{ $employee->id }}">
                    <td><a href="{{ route('people.employees.show', $employee) }}"><strong>{{ $employee->name }}</strong></a><small>{{ $employee->employee_code }} · {{ $employee->designation ?? '—' }}</small></td>
                    <td>{{ $employee->department?->name ?? '—' }}</td>
                    <td>{{ $employee->manager?->name ?? '—' }}</td>
                    <td>{{ $employee->teams->pluck('name')->join(', ') ?: '—' }}</td>
                    <td>{{ $employee->user?->roles->pluck('name')->join(', ') ?: 'No login' }}</td>
                    <td class="nowrap">{{ $employee->joined_at?->format('d M Y') ?? '—' }}</td>
                    <td><x-badge :status="$employee->is_active ? 'active' : 'inactive'" /></td>
                    <td><div class="row-actions">
                        <button class="btn btn-sm" wire:click="edit({{ $employee->id }})">Edit</button>
                        @if($employee->user_id !== auth()->id())
                            <button class="btn btn-sm {{ $employee->is_active ? 'btn-danger' : '' }}" wire:click="toggleActive({{ $employee->id }})" wire:confirm="{{ $employee->is_active ? 'Deactivate '.$employee->name.'? Their login and device tokens will be revoked.' : 'Reactivate '.$employee->name.'?' }}">{{ $employee->is_active ? 'Deactivate' : 'Reactivate' }}</button>
                        @endif
                    </div></td>
                </tr>
            @empty
                <tr><td colspan="8"><x-empty title="No employees match these filters" icon="users" /></td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $employees->links() }}
    </section>

    @if($showForm)
        <x-modal :title="$editingId ? 'Edit employee' : 'Add employee'" size="lg">
            <form wire:submit="save">
                <div class="modal-body">
                    <x-errors />
                    <div class="form-grid">
                        <x-field label="Employee code" name="employeeCode"><input wire:model="employeeCode" placeholder="EMP-010"></x-field>
                        <x-field label="Full name" name="name"><input wire:model="name"></x-field>
                        <x-field label="Login email" name="email"><input type="email" wire:model="email"></x-field>
                        @unless($editingId)<x-field label="Initial password" name="password" hint="12+ chars, letters & numbers"><input type="password" wire:model="password" autocomplete="new-password"></x-field>@endunless
                        <x-field label="Designation" name="designation"><input wire:model="designation" placeholder="e.g. Software Engineer"></x-field>
                        <x-field label="Phone" name="phone"><input wire:model="phone"></x-field>
                        <x-field label="Department" name="departmentId"><select wire:model="departmentId"><option value="">—</option>@foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></x-field>
                        <x-field label="Manager" name="managerId"><select wire:model="managerId"><option value="">No manager</option>@foreach($managers as $m)@if($m->id !== $editingId)<option value="{{ $m->id }}">{{ $m->name }} ({{ $m->employee_code }})</option>@endif @endforeach</select></x-field>
                        <x-field label="Joining date" name="joinedAt"><input type="date" wire:model="joinedAt"></x-field>
                        <x-field label="Employment type" name="employmentType"><select wire:model="employmentType">@foreach(\App\Services\EmployeeService::EMPLOYMENT_TYPES as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></x-field>
                        <x-field label="Probation ends" name="probationEndsOn" hint="optional"><input type="date" wire:model="probationEndsOn" x-bind:min="$wire.joinedAt"></x-field>
                        <x-field label="Notice period (days)" name="noticePeriodDays" hint="optional"><input type="number" min="0" max="365" wire:model="noticePeriodDays"></x-field>
                        <x-field label="Date of birth" name="dateOfBirth" hint="optional"><input type="date" wire:model="dateOfBirth" max="{{ today()->subDay()->toDateString() }}"></x-field>
                        <x-field label="Daily work hours" name="dailyHours"><input type="number" step="0.25" min="0.25" max="24" wire:model="dailyHours"></x-field>
                        <x-field label="Work start" name="workStart"><input type="time" wire:model="workStart"></x-field>
                        <x-field label="Work end" name="workEnd"><input type="time" wire:model="workEnd"></x-field>
                        @unless($editingId)<x-field label="Monthly salary" name="monthlySalary" hint="effective from joining date"><input type="number" step="0.01" min="0" wire:model="monthlySalary"></x-field>@endunless
                        <div class="field full"><span class="label">Teams</span>
                            <div class="checks">@forelse($teams as $team)<label class="check"><input type="checkbox" value="{{ $team->id }}" wire:model="teamIds"> {{ $team->name }}</label>@empty<span class="small muted">No teams yet.</span>@endforelse</div>
                        </div>
                        <label class="check full"><input type="checkbox" wire:model="isManager"> Grant the Manager role (can approve team leave/overtime and assign tasks)</label>
                    </div>
                    @unless($editingId)<p class="small muted mt">Creating an employee also creates their login, salary profile and this year's leave balances. Salary changes later are effective-dated under Payroll → Salary.</p>@endunless
                </div>
                <div class="modal-foot">
                    <button type="button" class="btn" wire:click="closeModal">Cancel</button>
                    <button class="btn btn-primary" wire:loading.attr="disabled"><span wire:loading wire:target="save" class="spinner"></span>{{ $editingId ? 'Save changes' : 'Create employee' }}</button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
