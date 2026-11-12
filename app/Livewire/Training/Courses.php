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
    use InteractsWithHr, \App\Livewire\Concerns\EditsTrainingCourse, \App\Livewire\Concerns\WithPaginationNoUrl;

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
        $query = AttendanceEmployee::where('is_active', true)->inCodeOrder();
        if (! $this->canManage()) $query->where('manager_id', $this->ownEmployee()?->id ?? 0);

        return $query;
    }

    /** New course / edit course: the shared course form. */
    public function edit(?int $id = null): void
    {
        $this->editCourse($id);
    }

    public function save(AuditLogger $audit, \App\Services\TrainingMentorService $mentors): void
    {
        $this->saveCourse($audit, $mentors);
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
        $this->showCourseForm = false;
        $this->assigningId = null;
        $this->progressId = null;
    }

    public function render()
    {
        $scope = $this->canManage() ? null : $this->assignable()->pluck('id');

        return $this->page('livewire.training.courses', [
            'courses' => TrainingCourse::with('topics.mentors:id,name')->withCount('enrollments')->withCount([
                'assignments' => fn ($q) => $scope ? $q->whereIn('employee_id', $scope) : $q,
                'assignments as completed_count' => fn ($q) => ($scope ? $q->whereIn('employee_id', $scope) : $q)->where('status', 'completed'),
            ])->when(! $this->canManage(), fn ($q) => $q->where('is_active', true))->orderByDesc('is_active')->latest('id')->paginate(15),
            'canManage' => $this->canManage(),
            'assigning' => $this->assigningId ? TrainingCourse::find($this->assigningId) : null,
            'employees' => $this->assigningId ? $this->assignable()->get(['id', 'name', 'employee_code']) : collect(),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'progress' => $this->progressId ? TrainingAssignment::with('employee')->where('training_course_id', $this->progressId)->when($scope, fn ($q) => $q->whereIn('employee_id', $scope))->get() : null,
            'progressCourse' => $this->progressId ? TrainingCourse::find($this->progressId) : null,
            'mentorOptions' => $this->showCourseForm ? $this->mentorOptions() : collect(),
        ], 'Training Courses');
    }
}
