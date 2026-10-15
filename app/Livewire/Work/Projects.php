<?php

namespace App\Livewire\Work;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\Project;
use App\Models\Task;
use App\Services\WorkService;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** "Projects" (manage) and "My Projects" (an employee's own projects, read-only) share this page. */
class Projects extends Component
{
    use InteractsWithHr, WithPagination;

    #[Locked] public bool $mine = false;
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

    public function mount(): void
    {
        $this->mine = request()->routeIs('work.my-projects');
    }

    public function updated($property): void
    {
        if (in_array($property, ['search', 'status'], true)) $this->resetPage();
    }

    private function scopedToOwn(): bool
    {
        return ! $this->user()->canAny(['hr.manage', 'employees.manage']);
    }

    private function canManage(): bool
    {
        return ! $this->mine && $this->user()->canAny(['projects.manage', 'hr.manage']);
    }

    public function create(): void
    {
        abort_unless($this->canManage(), 403);
        $this->resetForm();
        $this->managerId = $this->ownEmployee()?->id;
        $this->startsOn = today()->toDateString();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        abort_unless($this->canManage(), 403);
        $project = $this->findProject($id);
        $this->resetForm();
        $this->fill(['editingId' => $project->id, 'name' => $project->name, 'description' => (string) $project->description, 'managerId' => $project->manager_id, 'startsOn' => (string) $project->starts_on?->toDateString(), 'endsOn' => (string) $project->ends_on?->toDateString(), 'projectStatus' => $project->status, 'showForm' => true]);
    }

    public function save(WorkService $work): void
    {
        abort_unless($this->canManage(), 403);
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
        abort_unless($this->canManage(), 403);
        $project = $this->findProject($id);
        $this->membersOf = $project->id;
        $this->memberIds = $project->members()->pluck('attendance_employees.id')->map(fn ($id) => (string) $id)->all();
    }

    public function saveMembers(WorkService $work): void
    {
        abort_unless($this->canManage(), 403);
        $project = $this->findProject((int) $this->membersOf);
        $this->validate(['memberIds' => ['array'], 'memberIds.*' => ['integer', 'exists:attendance_employees,id']]);
        $work->setProjectMembers($project, array_map('intval', $this->memberIds), $this->user());
        $this->membersOf = null;
        $this->toast('Project members saved. People added or removed were notified.');
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

    /** An employee's projects: ones they are a member of, manage, or have tasks in. */
    private function myProjectsQuery()
    {
        $own = $this->ownEmployee()?->id ?? 0;

        return Project::query()->where(fn ($q) => $q->where('manager_id', $own)
            ->orWhereHas('members', fn ($m) => $m->where('attendance_employees.id', $own))
            ->orWhereHas('tasks', fn ($t) => $t->where('assignee_id', $own)));
    }

    public function render()
    {
        $own = $this->ownEmployee();
        $base = $this->mine ? $this->myProjectsQuery() : Project::query()->when($this->scopedToOwn(), fn ($q) => $q->where('manager_id', $own?->id ?? 0));
        $counts = app(\App\Services\WorkService::class)->projectCounts($base);
        $projects = (clone $base)
            ->with(['manager', 'members:attendance_employees.id,attendance_employees.name'])
            ->withCount(['tasks', 'tasks as completed_tasks_count' => fn ($q) => $q->where('status', 'completed'), 'tasks as overdue_tasks_count' => fn ($q) => $q->where('status', '!=', 'completed')->whereDate('due_on', '<', today())])
            ->when($this->mine, fn ($q) => $q->withCount(['tasks as my_open_tasks_count' => fn ($t) => $t->where('assignee_id', $own?->id ?? 0)->where('status', '!=', 'completed')]))
            ->withSum('tasks', 'estimated_hours')
            ->when(trim($this->search) !== '', fn ($q) => $q->where('name', 'like', '%'.trim($this->search).'%'))
            ->when($this->status, fn ($q, $status) => $q->where('status', $status))
            ->latest()->paginate(15);

        return $this->page('livewire.work.projects', [
            'projects' => $projects,
            'counts' => $counts,
            'mine' => $this->mine,
            'canManage' => $this->canManage(),
            'managers' => $this->canManage() ? AttendanceEmployee::where('is_active', true)->orderBy('name')->get(['id', 'name']) : collect(),
            'scoped' => $this->scopedToOwn(),
            'membersProject' => $this->membersOf ? Project::find($this->membersOf) : null,
        ], $this->mine ? 'My Projects' : 'Projects');
    }
}
