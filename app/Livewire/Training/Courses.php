<?php

namespace App\Livewire\Training;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\Department;
use App\Models\TrainingAssignment;
use App\Models\TrainingCourse;
use App\Services\AuditLogger;
use App\Services\TrainingService;
use Livewire\Component;

/** HR manages the catalogue; managers can assign courses to their own team. */
class Courses extends Component
{
    use InteractsWithHr, \Livewire\WithPagination;

    public bool $showForm = false;
    public ?int $editingId = null;
    public string $title = '';
    public string $description = '';
    public string $category = '';
    public string $provider = '';
    public string $durationHours = '';
    public string $materialUrl = '';
    public bool $isMandatory = false;
    public bool $isActive = true;

    public ?int $assigningId = null;
    public array $employeeIds = [];
    public string $departmentId = '';
    public string $dueOn = '';

    public ?int $progressId = null;

    private function canManage(): bool
    {
        return $this->user()->canAny(['training.manage', 'hr.manage']);
    }

    /** Employees this user may assign to: everyone for HR, direct reports for managers. */
    private function assignable()
    {
        $query = AttendanceEmployee::where('is_active', true)->orderBy('name');
        if (! $this->canManage()) $query->where('manager_id', $this->ownEmployee()?->id ?? 0);

        return $query;
    }

    public function edit(?int $id = null): void
    {
        abort_unless($this->canManage(), 403);
        $c = $id ? TrainingCourse::findOrFail($id) : null;
        $this->fill(['editingId' => $c?->id, 'title' => $c?->title ?? '', 'description' => (string) $c?->description, 'category' => (string) $c?->category, 'provider' => (string) $c?->provider,
            'durationHours' => $c?->duration_hours !== null ? (string) (float) $c?->duration_hours : '', 'materialUrl' => (string) $c?->material_url, 'isMandatory' => (bool) $c?->is_mandatory, 'isActive' => $c?->is_active ?? true]);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(AuditLogger $audit): void
    {
        abort_unless($this->canManage(), 403);
        $this->validate([
            'title' => ['required', 'string', 'max:160'], 'description' => ['nullable', 'string', 'max:5000'],
            'category' => ['nullable', 'string', 'max:60'], 'provider' => ['nullable', 'string', 'max:120'],
            'durationHours' => ['nullable', 'numeric', 'min:0', 'max:1000'], 'materialUrl' => ['nullable', 'url:http,https', 'max:500'],
        ], [], ['durationHours' => 'duration', 'materialUrl' => 'material link']);
        $course = TrainingCourse::updateOrCreate(['id' => $this->editingId], ['title' => $this->title, 'description' => $this->description ?: null, 'category' => $this->category ?: null, 'provider' => $this->provider ?: null,
            'duration_hours' => $this->durationHours === '' ? null : $this->durationHours, 'material_url' => $this->materialUrl ?: null, 'is_mandatory' => $this->isMandatory, 'is_active' => $this->isActive]
            + ($this->editingId ? [] : ['created_by' => auth()->id()]));
        $audit->record($this->editingId ? 'training.course_updated' : 'training.course_created', $course);
        $this->showForm = false;
        $this->toast('Course saved.');
    }

    public function openAssign(int $id): void
    {
        $this->fill(['assigningId' => $id, 'employeeIds' => [], 'departmentId' => '', 'dueOn' => today()->addDays(14)->toDateString()]);
        $this->resetValidation();
    }

    public function updatedDepartmentId(): void
    {
        if ($this->departmentId) $this->employeeIds = $this->assignable()->where('department_id', $this->departmentId)->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    public function assign(TrainingService $training): void
    {
        $this->validate(['employeeIds' => ['required', 'array', 'min:1'], 'employeeIds.*' => ['integer'], 'dueOn' => ['nullable', 'date', 'after_or_equal:today']],
            ['employeeIds.required' => 'Select at least one employee.'], ['dueOn' => 'due date']);
        $allowed = $this->assignable()->whereIn('id', $this->employeeIds)->pluck('id')->all();
        abort_if(count($allowed) !== count(array_unique($this->employeeIds)), 403, 'You can only assign training to your own team.');
        $count = $training->assign(TrainingCourse::findOrFail($this->assigningId), $allowed, $this->dueOn ?: null, auth()->id());
        $this->assigningId = null;
        $this->toast($count ? "Assigned to {$count} ".str('employee')->plural($count).'.' : 'Everyone selected already has this course.');
    }

    public function closeModal(): void
    {
        $this->showForm = false;
        $this->assigningId = null;
        $this->progressId = null;
    }

    public function render()
    {
        $scope = $this->canManage() ? null : $this->assignable()->pluck('id');

        return $this->page('livewire.training.courses', [
            'courses' => TrainingCourse::withCount([
                'assignments' => fn ($q) => $scope ? $q->whereIn('employee_id', $scope) : $q,
                'assignments as completed_count' => fn ($q) => ($scope ? $q->whereIn('employee_id', $scope) : $q)->where('status', 'completed'),
            ])->when(! $this->canManage(), fn ($q) => $q->where('is_active', true))->orderByDesc('is_active')->latest('id')->paginate(15),
            'canManage' => $this->canManage(),
            'assigning' => $this->assigningId ? TrainingCourse::find($this->assigningId) : null,
            'employees' => $this->assigningId ? $this->assignable()->get(['id', 'name', 'employee_code']) : collect(),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'progress' => $this->progressId ? TrainingAssignment::with('employee')->where('training_course_id', $this->progressId)->when($scope, fn ($q) => $q->whereIn('employee_id', $scope))->get() : null,
            'progressCourse' => $this->progressId ? TrainingCourse::find($this->progressId) : null,
        ], 'Training Courses');
    }
}
