<?php

namespace App\Livewire\People;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\Department;
use App\Services\AuditLogger;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Departments extends Component
{
    use InteractsWithHr, \Livewire\WithPagination;

    public bool $showForm = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $code = '';
    public ?int $headId = null;
    public bool $isActive = true;

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
        ], 'Departments');
    }
}
