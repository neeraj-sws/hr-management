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
    use InteractsWithHr;

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
            'departments' => Department::with('head')->withCount(['employees' => fn ($q) => $q->where('is_active', true), 'teams'])->orderBy('name')->get(),
            'employees' => AttendanceEmployee::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ], 'Departments');
    }
}
