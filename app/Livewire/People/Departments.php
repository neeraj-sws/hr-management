<?php

namespace App\Livewire\People;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\Company;
use App\Models\Department;
use App\Services\AuditLogger;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Departments extends Component
{
    use InteractsWithHr, \App\Livewire\Concerns\WithPaginationNoUrl;

    public bool $showForm = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $code = '';
    public ?int $headId = null;
    public bool $isActive = true;
    /** Companies (e.g. SWS, ITWeb): payroll is made per company. */
    public ?int $companyEditingId = null;
    public string $companyName = '';

    public function editCompany(int $id): void
    {
        // Adding and renaming companies is for logins of all companies (the Super Admin), not a company owner.
        abort_if(\App\Support\CompanyAccess::isLimited($this->user()), 403);
        $this->fill(['companyEditingId' => $id, 'companyName' => Company::findOrFail($id)->name]);
        $this->resetValidation();
    }

    public function saveCompany(AuditLogger $audit): void
    {
        abort_unless($this->user()->canAny(['employees.manage', 'hr.manage']) && ! \App\Support\CompanyAccess::isLimited($this->user()), 403);
        $this->companyName = trim($this->companyName);
        $this->validate(['companyName' => ['required', 'string', 'max:120', Rule::unique('companies', 'name')->ignore($this->companyEditingId)]], [], ['companyName' => 'company name']);
        $company = Company::updateOrCreate(['id' => $this->companyEditingId], ['name' => $this->companyName]);
        $audit->record($this->companyEditingId ? 'company.updated' : 'company.created', $company);
        $this->toast($this->companyEditingId ? 'Company renamed.' : "Company {$company->name} added. Choose it on each of its employees (Employees > Edit > Company).");
        $this->fill(['companyEditingId' => null, 'companyName' => '']);
    }

    public function cancelCompany(): void
    {
        $this->fill(['companyEditingId' => null, 'companyName' => '']);
        $this->resetValidation();
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $department = Department::findOrFail($id);
        $this->resetForm();
        $this->fill(['editingId' => $department->id, 'name' => $department->name, 'code' => $department->code, 'headId' => $department->manager_id, 'isActive' => $department->is_active, 'showForm' => true]);
    }

    public function save(AuditLogger $audit): void
    {
        abort_unless($this->user()->canAny(['employees.manage', 'hr.manage']), 403);
        $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('departments', 'name')->ignore($this->editingId)],
            'code' => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('departments', 'code')->ignore($this->editingId)],
            'headId' => ['nullable', 'exists:attendance_employees,id'],
        ]);
        $department = Department::updateOrCreate(['id' => $this->editingId], ['name' => $this->name, 'code' => strtoupper($this->code), 'manager_id' => $this->headId ?: null, 'is_active' => $this->isActive]);
        $audit->record($this->editingId ? 'department.updated' : 'department.created', $department);
        $this->toast($this->editingId ? 'Department updated.' : 'Department created.');
        $this->closeModal();
    }

    /** Removes a department that has no employees or teams left (move them first). */
    public function remove(int $id, AuditLogger $audit): void
    {
        abort_unless($this->user()->canAny(['employees.manage', 'hr.manage']), 403);
        $department = Department::withCount(['employees', 'teams'])->findOrFail($id);
        if ($department->employees_count || $department->teams_count) {
            throw \Illuminate\Validation\ValidationException::withMessages(['department' => "{$department->name} still has {$department->employees_count} employee(s) and {$department->teams_count} team(s). Move them to another department first, or mark the department inactive."]);
        }
        $audit->record('department.removed', null, ['id' => $department->id, 'name' => $department->name, 'code' => $department->code]);
        $department->delete();
        $this->toast("Department {$department->name} removed.");
    }

    public function closeModal(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'code', 'headId', 'isActive');
        $this->resetValidation();
    }

    public function render()
    {
        return $this->page('livewire.people.departments', [
            'departments' => Department::with('head')->withCount(['employees' => fn ($q) => $q->where('is_active', true), 'employees as all_employees_count', 'teams'])->orderBy('name')->paginate(15),
            'totals' => ['active' => Department::where('is_active', true)->count(), 'employees' => AttendanceEmployee::where('is_active', true)->whereNotNull('department_id')->count(), 'unassigned' => AttendanceEmployee::where('is_active', true)->whereNull('department_id')->count()],
            'employees' => AttendanceEmployee::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'companies' => Company::orderByDesc('is_main')->orderBy('name')->get()->each(fn (Company $c) => $c->setAttribute('active_count', AttendanceEmployee::where('is_active', true)->inPayrollCompany($c->is_main ? null : $c->id)->count())),
            // Only logins of all companies add or rename companies.
            'editCompanies' => ! \App\Support\CompanyAccess::isLimited($this->user()),
        ], 'Departments');
    }
}
