<?php

namespace App\Livewire\Work;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\Project;
use App\Services\WorkService;
use Livewire\Attributes\Url;
use Livewire\Component;

class Projects extends Component
{
    use InteractsWithHr;

    #[Url] public string $search = '';
    #[Url] public string $status = '';

    public bool $showForm = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $description = '';
    public ?int $managerId = null;
    public string $startsOn = '';
    public string $endsOn = '';
    public string $projectStatus = 'active';
    public ?int $membersOf = null;
    public array $memberIds = [];

    private function scopedToOwn(): bool
    {
        return ! $this->user()->canAny(['hr.manage', 'employees.manage']);
    }

    public function create(): void
    {
        $this->resetForm();
        $this->managerId = $this->ownEmployee()?->id;
        $this->startsOn = today()->toDateString();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $project = $this->findProject($id);
        $this->resetForm();
        $this->fill(['editingId' => $project->id, 'name' => $project->name, 'description' => (string) $project->description, 'managerId' => $project->manager_id, 'startsOn' => (string) $project->starts_on?->toDateString(), 'endsOn' => (string) $project->ends_on?->toDateString(), 'projectStatus' => $project->status, 'showForm' => true]);
    }

    public function save(WorkService $work): void
    {
        abort_unless($this->user()->canAny(['projects.manage', 'hr.manage']), 403);
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'managerId' => ['nullable', 'exists:attendance_employees,id'],
            'startsOn' => ['nullable', 'date'],
            'endsOn' => ['nullable', 'date', 'after_or_equal:startsOn'],
            'projectStatus' => ['required', 'in:planned,active,on_hold,completed'],
        ]);
        $managerId = $this->scopedToOwn() ? $this->ownEmployee()?->id : ($this->managerId ?: null);
        $work->saveProject($this->editingId ? $this->findProject($this->editingId) : null, ['name' => $this->name, 'description' => $this->description ?: null, 'manager_id' => $managerId, 'starts_on' => $this->startsOn ?: null, 'ends_on' => $this->endsOn ?: null, 'status' => $this->projectStatus], $this->user());
        $this->toast($this->editingId ? 'Project updated.' : 'Project created.');
        $this->closeModal();
    }

    public function openMembers(int $id): void
    {
        $project = $this->findProject($id);
        $this->membersOf = $project->id;
        $this->memberIds = $project->members()->pluck('attendance_employees.id')->map(fn ($id) => (string) $id)->all();
    }

    public function saveMembers(WorkService $work): void
    {
        $project = $this->findProject((int) $this->membersOf);
        $this->validate(['memberIds' => ['array'], 'memberIds.*' => ['integer', 'exists:attendance_employees,id']]);
        $work->setProjectMembers($project, array_map('intval', $this->memberIds), $this->user());
        $this->membersOf = null;
        $this->toast('Project members saved. Only members can now be given tasks in this project.');
    }

    public function closeModal(): void
    {
        $this->membersOf = null;
        $this->showForm = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'description', 'managerId', 'startsOn', 'endsOn', 'projectStatus');
        $this->resetValidation();
    }

    private function findProject(int $id): Project
    {
        return Project::when($this->scopedToOwn(), fn ($q) => $q->where('manager_id', $this->ownEmployee()?->id ?? 0))->findOrFail($id);
    }

    public function render()
    {
        $projects = Project::with('manager')
            ->withCount(['tasks', 'tasks as completed_tasks_count' => fn ($q) => $q->where('status', 'completed'), 'tasks as overdue_tasks_count' => fn ($q) => $q->where('status', '!=', 'completed')->whereDate('due_on', '<', today())])
            ->withSum('tasks', 'estimated_hours')
            ->when($this->scopedToOwn(), fn ($q) => $q->where('manager_id', $this->ownEmployee()?->id ?? 0))
            ->when(trim($this->search) !== '', fn ($q) => $q->where('name', 'like', '%'.trim($this->search).'%'))
            ->when($this->status, fn ($q, $status) => $q->where('status', $status))
            ->latest()->get();

        return $this->page('livewire.work.projects', [
            'projects' => $projects,
            'managers' => AttendanceEmployee::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'scoped' => $this->scopedToOwn(),
            'membersProject' => $this->membersOf ? Project::find($this->membersOf) : null,
        ], 'Projects');
    }
}
