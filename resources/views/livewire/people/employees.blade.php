<div class="emp-page">
    <div class="page-head">
        <div><h1>{{ $traineesOnly ? 'Trainees' : 'Employees' }}</h1><p>{{ $counts['active'] }} active · {{ $counts['inactive'] }} inactive @if($counts['other']) · <a href="{{ route($traineesOnly ? 'people.employees' : 'people.trainees') }}">{{ $counts['other'] }} {{ $traineesOnly ? str('employee')->plural($counts['other']) : str('trainee')->plural($counts['other']) }} {{ $counts['other'] === 1 ? 'is' : 'are' }} listed under {{ $traineesOnly ? 'Employees' : 'Trainees' }}</a>@endif</p></div>
        <div class="actions">
            @if(\App\Support\Navigation::canAccess(auth()->user(), 'reports.show', ['type' => 'employees']))<a class="btn" href="{{ route('reports.show', 'employees') }}"><x-icon name="download" class="icon-sm" />Export</a>@endif
            @if($canImport)<button type="button" class="btn" wire:click="openImport"><x-icon name="upload" class="icon-sm" />Import</button>@endif
            <button class="btn btn-primary" wire:click="create"><x-icon name="plus" class="icon-sm" />{{ $traineesOnly ? 'Add trainee' : 'Add employee' }}</button>
        </div>
    </div>
    @unless($showForm || $pwEmployee || $showImport)<x-errors />@endunless
    @if($reportsToManager)<div class="alert alert-info">Showing people who report to <strong>{{ $reportsToManager->name }}</strong> ({{ $employees->total() }}). <button type="button" class="btn btn-sm" wire:click="$set('reportsTo', null)">Show everyone</button></div>@endif

    <section class="emp-card">
        <div class="emp-filters">
            <label class="field emp-search"><span>Search</span><span class="emp-input-icon"><x-icon name="search" class="icon-sm" /><input type="search" wire:model.live.debounce.300ms="search" placeholder="Name, code, email or designation"></span></label>
            <label class="field"><span>Department</span><select wire:model.live="department"><option value="">All departments</option>@foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></label>
            <label class="field"><span>Joined from</span><input type="date" wire:model.live="joinedFrom"></label>
            <label class="field"><span>Joined to</span><input type="date" wire:model.live="joinedTo" x-bind:min="$wire.joinedFrom" max="{{ today()->toDateString() }}"></label>
            <label class="field"><span>Status</span><select wire:model.live="status"><option value="active">Active</option><option value="inactive">Inactive</option><option value="left">Ex-employees (left)</option><option value="all">All status</option></select></label>
            <button type="button" class="btn emp-reset" wire:click="resetFilters"><x-icon name="refresh" class="icon-sm" />Reset</button>
        </div>

        @php($sortHead = function (string $key, string $label) use ($sort, $dir) {
            $on = $sort === $key;
            $aria = $on ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none';
            $arrow = $on ? ($dir === 'asc' ? '↑' : '↓') : '⇅';
            return '<th aria-sort="'.$aria.'"><button type="button" class="emp-sort'.($on ? ' is-on' : '').'" wire:click="sortBy(\''.$key.'\')">'.e($label).' <span aria-hidden="true">'.$arrow.'</span></button></th>';
        })
        <div class="table-wrap emp-table-wrap" wire:loading.class="is-loading">
            <table class="table emp-table" data-no-sort>
                <thead><tr>
                    {!! $sortHead('name', 'Employee') !!}
                    {!! $sortHead('email', 'Email') !!}
                    {!! $sortHead('department', 'Department') !!}
                    <th>Manager</th><th>Teams</th><th>Roles</th>
                    {!! $sortHead('hours', 'Work hours') !!}
                    {!! $sortHead('joined', 'Joined') !!}
                    {!! $sortHead('status', 'Status') !!}
                    <th class="emp-actions-col">Actions</th>
                </tr></thead>
                <tbody>
                @forelse($employees as $employee)
                    @php($left = ! $employee->is_active && $employee->exit_date)
                    @php($pill = $left ? ['Left', 'red'] : ($employee->employment_type === \App\Services\EmployeeService::TRAINEE && $employee->is_active ? ['Trainee', 'blue'] : ($employee->is_active ? ['Active', 'green'] : ['Inactive', 'amber'])))
                    <tr wire:key="emp-{{ $employee->id }}">
                        <td>
                            <div class="emp-person">
                                <x-avatar :employee="$employee" />
                                <div class="emp-person-text"><a href="{{ route('people.employees.show', $employee) }}"><strong>{{ $employee->name }}</strong></a><small>{{ $employee->employee_code }}@if($employee->designation) · {{ $employee->designation }}@endif</small></div>
                            </div>
                        </td>
                        <td class="emp-email">@if($mail = ($employee->email ?? $employee->user?->email))<a href="mailto:{{ $mail }}">{{ $mail }}</a><button type="button" class="emp-copy" title="Copy email" aria-label="Copy email {{ $mail }}" x-data="{ copied: false }" x-on:click="navigator.clipboard.writeText(@js($mail)); copied = true; setTimeout(() => copied = false, 1500)"><x-icon name="clipboard" class="icon-sm" x-show="! copied" /><x-icon name="check" class="icon-sm" x-show="copied" x-cloak /></button>@else — @endif</td>
                        <td>{{ $employee->department?->name ?? '—' }}</td>
                        <td>{{ $employee->manager?->name ?? '—' }}</td>
                        <td>@if($employee->teams->isNotEmpty())<div class="emp-chips">@foreach($employee->teams as $team)<span class="emp-chip">{{ $team->name }}</span>@endforeach</div>@else — @endif</td>
                        <td><x-person-badges :user="$employee->user" :employee="$employee" /></td>
                        <td class="nowrap">{{ \App\Support\Duration::fromHours($employee->daily_work_hours) }}<small>{{ $employee->work_start ? substr($employee->work_start, 0, 5).' – '.substr((string) $employee->work_end, 0, 5) : '' }}</small></td>
                        <td class="nowrap">{{ $employee->joined_at?->format('d M Y') ?? '—' }}</td>
                        <td><span class="emp-status emp-status-{{ $pill[1] }}"><i aria-hidden="true"></i>{{ $pill[0] }}</span>@if($left)<small>{{ $employee->exit_date->format('d M Y') }}</small>@endif</td>
                        <td class="emp-actions-col">
                            <details class="menu emp-menu">
                                <summary class="btn btn-sm btn-icon" aria-label="Actions for {{ $employee->name }}"><x-icon name="more" class="icon-sm" /></summary>
                                <div class="menu-panel">
                                    <a class="menu-item" href="{{ route('people.employees.show', $employee) }}"><x-icon name="user" class="icon-sm" />View profile</a>
                                    <button type="button" class="menu-item" wire:click="edit({{ $employee->id }})"><x-icon name="edit" class="icon-sm" />Edit</button>
                                    @if($employee->user_id && $isAdmin)<button type="button" class="menu-item" wire:click="openPassword({{ $employee->id }})"><x-icon name="key" class="icon-sm" />Reset password</button>@endif
                                    @if(! app(\App\Services\ImpersonationService::class)->refusal(auth()->user(), $employee))<form method="POST" action="{{ route('people.view-as', $employee) }}">@csrf<button type="submit" class="menu-item"><x-icon name="eye" class="icon-sm" />View as employee</button></form>@endif
                                    @if($employee->user_id !== auth()->id())
                                        <button type="button" class="menu-item {{ $employee->is_active ? 'danger' : '' }}" wire:click="toggleActive({{ $employee->id }})" wire:confirm="{{ $employee->is_active ? 'Deactivate '.$employee->name.'? Their login and device tokens will be revoked.' : 'Reactivate '.$employee->name.'?' }}"><x-icon name="{{ $employee->is_active ? 'user-minus' : 'user-check' }}" class="icon-sm" />{{ $employee->is_active ? 'Deactivate' : 'Reactivate' }}</button>
                                    @endif
                                    @if($isAdmin && $employee->user_id !== auth()->id())<button type="button" class="menu-item danger" wire:click="remove({{ $employee->id }})" wire:confirm="Permanently remove {{ $employee->name }} ({{ $employee->employee_code }}) and their login? Only possible if they have no attendance, payroll, leave or other records."><x-icon name="trash" class="icon-sm" />Remove</button>@endif
                                </div>
                            </details>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10"><x-empty title="No employees match these filters" icon="users" /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="emp-foot">
            <span class="muted small">@if($employees->total())Showing {{ $employees->firstItem() }} to {{ $employees->lastItem() }} of {{ $employees->total() }} {{ $traineesOnly ? str('trainee')->plural($employees->total()) : str('employee')->plural($employees->total()) }}@else No results @endif</span>
            <div class="emp-foot-right">
                {{ $employees->onEachSide(1)->links('components.pager') }}
                <label class="emp-per-page"><span class="sr-only">Rows per page</span><select wire:model.live="perPage">@foreach([10, 15, 25, 50] as $n)<option value="{{ $n }}">{{ $n }} per page</option>@endforeach</select></label>
            </div>
        </div>
    </section>

    @if($showImport)
        <x-modal title="Import employees">
            <form wire:submit="importEmployees">
                <div class="modal-body">
                    <p class="small muted" style="margin-top:0">One row per person. A row whose <strong>employee_code</strong> already exists updates that person (only the cells you fill in); a new code adds the person with a login. New logins get a random password: use <em>Reset password</em> or <em>Forgot password</em>.</p>
                    <p class="small"><a href="{{ route('imports.template', ['type' => 'employees', 'format' => 'csv']) }}"><x-icon name="download" class="icon-sm" /> Download the CSV template</a> · <a href="{{ route('imports.template', ['type' => 'employees']) }}">Excel template</a></p>
                    <p class="small muted">Columns: {{ implode(', ', \App\Services\EmployeeImport::COLUMNS) }}. Required: employee_code, name (and email for a new person). Department must already exist; manager_code is the manager's employee code; dates like 2026-10-24.</p>
                    <x-field label="File" name="importFile" hint="CSV or Excel (.xlsx), max 5 MB" full><input type="file" wire:model="importFile" accept=".csv,.xlsx"><span wire:loading wire:target="importFile" class="small muted">Uploading…</span></x-field>
                    @if($importResult)
                        @if($importResult['fatal'])<div class="alert alert-danger">{{ $importResult['fatal'] }}</div>
                        @else<div class="alert alert-info">{{ $importResult['created'] }} added · {{ $importResult['updated'] }} updated @if($importResult['errors']) · {{ count($importResult['errors']) }} row(s) skipped @endif</div>@endif
                        @if($importResult['errors'])<ul class="small emp-import-errors">@foreach($importResult['errors'] as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
                    @endif
                </div>
                <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Close</button><button class="btn btn-primary" wire:loading.attr="disabled" wire:target="importEmployees,importFile"><span wire:loading wire:target="importEmployees" class="spinner"></span>Import</button></div>
            </form>
        </x-modal>
    @endif
    @if($showForm)
        <x-modal :title="$editingId ? ($traineesOnly ? 'Edit trainee' : 'Edit employee') : ($traineesOnly ? 'Add trainee' : 'Add employee')" size="lg">
            <form wire:submit="save">
                <div class="modal-body">
                    <x-errors />
                    <div class="form-grid">
                        <x-field label="Employee code" name="employeeCode"><input wire:model="employeeCode" placeholder="EMP-010"></x-field>
                        <x-field label="Full name" name="name"><input wire:model="name"></x-field>
                        <x-field label="Login email" name="email"><input type="email" wire:model="email"></x-field>
                        @unless($editingId || $employmentType === \App\Services\EmployeeService::EX_EMPLOYEE)<x-field label="Initial password" name="password" hint="12+ chars, letters & numbers"><input type="password" wire:model="password" autocomplete="new-password"></x-field>@endunless
                        <x-field label="Designation" name="designation"><input wire:model="designation" placeholder="e.g. Software Engineer"></x-field>
                        <x-field label="Phone" name="phone"><input wire:model="phone"></x-field>
                        <x-field label="Department" name="departmentId"><select wire:model="departmentId"><option value="">—</option>@foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></x-field>
                        <x-field label="Manager" name="managerId"><select wire:model="managerId"><option value="">No manager</option>@foreach($managers as $m)@if($m->id !== $editingId)<option value="{{ $m->id }}">{{ $m->name }} ({{ $m->employee_code }})</option>@endif @endforeach</select></x-field>
                        <x-field label="Joining date" name="joinedAt"><input type="date" wire:model="joinedAt"></x-field>
                        <x-field label="Employment type" name="employmentType"><select wire:model.live="employmentType">@foreach(\App\Services\EmployeeService::EMPLOYMENT_TYPES as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></x-field>
                        @if($employmentType === \App\Services\EmployeeService::EX_EMPLOYEE)
                            <div class="alert alert-info full" style="margin:0">For someone who has already left — e.g. to import their old attendance and salary. They are saved as inactive with no working login, and nobody is notified.</div>
                            <x-field label="Last working day" name="exitDate"><input type="date" wire:model="exitDate" x-bind:min="$wire.joinedAt" max="{{ today()->toDateString() }}"></x-field>
                            <x-field label="How they left" name="exitType"><select wire:model="exitType">@foreach(\App\Services\EmployeeService::EXIT_TYPES as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></x-field>
                            <x-field label="Exit note" name="exitReason" hint="optional" full><input wire:model="exitReason" placeholder="e.g. Resigned for higher studies"></x-field>
                        @endif
                        <x-field label="Probation ends" name="probationEndsOn" hint="optional"><input type="date" wire:model="probationEndsOn" x-bind:min="$wire.joinedAt"></x-field>
                        <x-field label="Notice period (days)" name="noticePeriodDays" hint="optional"><input type="number" min="0" max="365" wire:model="noticePeriodDays"></x-field>
                        <x-field label="Date of birth" name="dateOfBirth" hint="optional"><input type="date" wire:model="dateOfBirth" max="{{ today()->subDay()->toDateString() }}"></x-field>
                        <x-field label="Daily work hours" name="dailyHours" hint="used by attendance and payroll"><input type="number" step="0.25" min="0.25" max="24" wire:model="dailyHours"></x-field>
                        <x-field label="Work start" name="workStart"><input type="time" wire:model="workStart"></x-field>
                        <x-field label="Work end" name="workEnd"><input type="time" wire:model="workEnd"></x-field>
                        @unless($editingId)
                            <x-field label="Salary type" name="payBasis"><select wire:model.live="payBasis">@foreach(\App\Models\SalaryProfile::PAY_BASES as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></x-field>
                            @if($payBasis === 'daily')
                                <x-field label="Salary per day" name="dailyRate" hint="paid for each working day attended, e.g. 130"><input type="number" step="0.01" min="0" wire:model="dailyRate" placeholder="130"></x-field>
                            @else
                                <x-field label="Monthly salary" name="monthlySalary" hint="effective from joining date{{ in_array($employmentType, [\App\Services\EmployeeService::EX_EMPLOYEE, \App\Services\EmployeeService::TRAINEE], true) ? ' · optional (trainees are not paid)' : '' }}"><input type="number" step="0.01" min="0" wire:model="monthlySalary"></x-field>
                            @endif
                        @endunless
                        <div class="field full"><span class="label">Teams</span>
                            <div class="checks">@forelse($teams as $team)<label class="check"><input type="checkbox" value="{{ $team->id }}" wire:model="teamIds"> {{ $team->name }}</label>@empty<span class="small muted">No teams yet.</span>@endforelse</div>
                        </div>
                        <label class="check full"><input type="checkbox" wire:model="isManager"> Grant the Manager role (can approve team leave{{ app(\App\Services\PeopleOperationsService::class)->overtimeEnabled() ? '/overtime' : '' }} and assign tasks)</label>
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

    @if($pwEmployee)
        <x-modal title="Reset password · {{ $pwEmployee->name }}">
            <form wire:submit="resetPassword">
                <div class="modal-body stack">
                    <x-errors />
                    <p class="small muted">Set a new login password for {{ $pwEmployee->name }}. At least 12 characters with letters and numbers. Their existing API/device tokens will be revoked.</p>
                    <x-field label="New password" name="pwPassword"><input type="text" wire:model="pwPassword" autocomplete="new-password"></x-field>
                </div>
                <div class="modal-foot">
                    <button type="button" class="btn" wire:click="closeModal">Cancel</button>
                    <button class="btn btn-primary" wire:loading.attr="disabled"><span wire:loading wire:target="resetPassword" class="spinner"></span>Reset password</button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
