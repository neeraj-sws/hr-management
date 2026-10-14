<?php

namespace App\Livewire\Work;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Services\HrWorkflowService;
use App\Services\WorkService;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** "Tasks" (assign/manage team work) and "My Tasks" (own work + timer) share this page. */
class Tasks extends Component
{
    use InteractsWithHr, WithPagination;

    #[Locked] public bool $mine = false;
    #[Url] public string $search = '';
    /** '' = all tasks: completed work stays in the list with a Completed badge (open work first). */
    #[Url] public string $status = '';
    #[Url] public string $priority = '';
    #[Url] public ?int $project = null;
    #[Url] public ?int $assignee = null;
    #[Url] public string $due = '';

    public bool $showForm = false;
    public ?int $editingId = null;
    public string $title = '';
    public string $description = '';
    public ?int $projectId = null;
    public ?int $assigneeId = null;
    public string $taskPriority = 'normal';
    public string $taskStatus = 'todo';
    public string $estimatedHours = '';
    public string $taskProgress = '';
    public string $dueOn = '';
    public string $workDate = '';
    public ?int $parentId = null;
    #[Url(as: 'task')] public ?int $openId = null;
    public string $comment = '';

    public function mount(): void
    {
        $this->mine = request()->routeIs('work.my-tasks');
    }

    public function updated($property): void
    {
        if (in_array($property, ['search', 'status', 'priority', 'project', 'assignee', 'due'], true)) $this->resetPage();
    }

    private function canAssign(): bool
    {
        // Team leads (anyone with direct reports) can assign to their team, even without the global permission.
        return $this->user()->canAny(['tasks.manage', 'hr.manage']) || $this->isManager();
    }

    /** True when the signed-in user's employee has at least one direct report. */
    private function isManager(): bool
    {
        $own = $this->ownEmployee();

        return $own !== null && $own->directReports()->exists();
    }

    /** Employees can add their own tasks from My Tasks (the assignee is always themselves). */
    private function canAddOwn(): bool
    {
        return $this->mine && $this->ownEmployee() !== null;
    }

    public function create(?int $parentId = null): void
    {
        abort_unless($this->canAssign() || $this->canAddOwn(), 403);
        $this->resetForm();
        $this->projectId = $this->project;
        if ($this->mine) { $this->assigneeId = $this->ownEmployee()?->id; $this->workDate = today()->toDateString(); }
        if ($parentId) { $parent = $this->visibleTasks()->findOrFail($parentId); $this->parentId = $parent->id; $this->projectId = $parent->project_id; $this->assigneeId ??= $parent->assignee_id; }
        $this->showForm = true;
    }

    public function open(int $id): void
    {
        $this->openId = $this->visibleTasks()->findOrFail($id)->id;
        $this->comment = '';
        $this->resetValidation();
    }

    public function addComment(WorkService $work): void
    {
        $this->validate(['comment' => ['required', 'string', 'max:4000']], ['comment.required' => 'Write a comment first.']);
        $work->addComment(Task::findOrFail($this->openId), $this->user(), $this->comment);
        $this->comment = '';
        $this->toast('Comment added.');
    }

    public function edit(int $id): void
    {
        $task = $this->visibleTasks()->findOrFail($id);
        abort_unless($this->canAssign() || ($this->canAddOwn() && $task->created_by === auth()->id()), 403);
        $this->resetForm();
        $this->fill(['editingId' => $task->id, 'title' => $task->title, 'description' => (string) $task->description, 'projectId' => $task->project_id, 'assigneeId' => $task->assignee_id, 'taskPriority' => $task->priority, 'taskStatus' => $task->status, 'estimatedHours' => $task->estimated_hours !== null ? (string) (float) $task->estimated_hours : '', 'taskProgress' => $task->progress !== null ? (string) $task->progress : '', 'dueOn' => (string) $task->due_on?->toDateString(), 'workDate' => (string) $task->work_date?->toDateString(), 'parentId' => $task->parent_id, 'showForm' => true]);
    }

    public function save(WorkService $work): void
    {
        abort_unless($this->canAssign() || $this->canAddOwn(), 403);
        if (! $this->canAssign()) $this->assigneeId = $this->ownEmployee()?->id; // own tasks only
        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'projectId' => ['nullable', 'exists:projects,id'],
            'assigneeId' => ['required', 'exists:attendance_employees,id'],
            'taskPriority' => ['required', 'in:'.implode(',', WorkService::PRIORITIES)],
            'taskStatus' => ['required', 'in:'.implode(',', WorkService::STATUSES)],
            'estimatedHours' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'taskProgress' => ['nullable', 'integer', 'min:0', 'max:100'],
            'dueOn' => ['nullable', 'date'],
            'workDate' => ['nullable', 'date', 'before_or_equal:today'],
            'parentId' => ['nullable', 'exists:tasks,id'],
        ], [], ['workDate' => 'work date', 'parentId' => 'parent task', 'assigneeId' => 'assignee', 'projectId' => 'project', 'estimatedHours' => 'estimated hours', 'dueOn' => 'due date']);
        $task = $this->editingId ? $this->visibleTasks()->findOrFail($this->editingId) : null;
        if ($task && ! $this->canAssign() && $task->created_by !== auth()->id()) abort(403);
        $work->saveTask($task, ['work_date' => $this->workDate ?: null, 'parent_id' => $this->parentId ?: null, 'title' => $this->title, 'description' => $this->description ?: null, 'project_id' => $this->projectId ?: null, 'assignee_id' => $this->assigneeId, 'priority' => $this->taskPriority, 'status' => $this->taskStatus, 'estimated_hours' => $this->estimatedHours === '' ? null : $this->estimatedHours, 'progress' => $this->taskProgress === '' ? null : (int) $this->taskProgress, 'due_on' => $this->dueOn ?: null], $this->user());
        $this->toast($this->editingId ? 'Task updated.' : 'Task assigned.');
        $this->closeModal();
    }

    /** The assignee (or whoever can manage the task) updates its progress %; kept in the task history. */
    public function updateProgress(int $taskId, int $percent): void
    {
        $task = $this->visibleTasks()->findOrFail($taskId);
        $own = $this->ownEmployee();
        abort_unless(($own && $task->assignee_id === $own->id) || $this->canAssign(), 403);
        if ($task->status === 'completed') return;
        $percent = max(0, min(100, $percent));
        $before = $task->progress;
        $task->update(['progress' => $percent, 'status' => $task->status === 'todo' && $percent > 0 ? 'in_progress' : $task->status]);
        $task->increment('server_version');
        app(\App\Services\AuditLogger::class)->record('task.progress_updated', $task, ['from' => $before, 'to' => $percent]);
        $this->toast("Progress set to {$percent}%.");
    }

    public function start(int $taskId, HrWorkflowService $workflow): void
    {
        $task = Task::findOrFail($taskId);
        $workflow->startTask($this->requireEmployee(), $task);
        $this->toast('Timer started for "'.$task->title.'".');
    }

    public function pause(int $taskId, HrWorkflowService $workflow): void
    {
        $employee = $this->requireEmployee();
        $entry = TaskTimeEntry::where('task_id', $taskId)->where('employee_id', $employee->id)->where('status', 'running')->firstOrFail();
        $workflow->stopTaskTimer($employee, $entry);
        $this->toast('Timer paused.');
    }

    public function complete(int $taskId, HrWorkflowService $workflow): void
    {
        $workflow->completeTask($this->requireEmployee(), Task::findOrFail($taskId));
        $this->toast('Task completed. Nice work!');
    }

    public function closeModal(): void
    {
        if ($this->showForm) { $this->showForm = false; $this->resetForm(); return; }
        $this->openId = null;
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'title', 'description', 'projectId', 'assigneeId', 'taskPriority', 'taskStatus', 'estimatedHours', 'taskProgress', 'dueOn', 'workDate', 'parentId');
        $this->resetValidation();
    }

    private function visibleTasks()
    {
        $own = $this->ownEmployee();
        if ($this->mine) return Task::query()->where('assignee_id', $own?->id ?? 0);
        if ($this->user()->canAny(['hr.manage', 'employees.manage'])) return Task::query();

        return Task::query()->whereIn('assignee_id', $this->teamEmployeeIds()->all() ?: [0]);
    }

    public function render(WorkService $work)
    {
        $term = trim($this->search);
        $tasks = $this->visibleTasks()->with(['assignee', 'project', 'timeEntries', 'parent', 'creator'])->withCount(['subtasks', 'subtasks as subtasks_done_count' => fn ($q) => $q->where('status', 'completed'), 'comments'])
            ->when($term !== '', fn ($q) => $q->where('title', 'like', "%{$term}%"))
            ->when($this->status === 'open', fn ($q) => $q->where('status', '!=', 'completed'))
            ->when($this->status && $this->status !== 'open', fn ($q) => $q->where('status', $this->status))
            ->when($this->priority, fn ($q, $p) => $q->where('priority', $p))
            ->when($this->project, fn ($q, $id) => $q->where('project_id', $id))
            ->when(! $this->mine && $this->assignee, fn ($q) => $q->where('assignee_id', $this->assignee))
            ->when($this->due === 'overdue', fn ($q) => $q->where('status', '!=', 'completed')->whereDate('due_on', '<', today()))
            ->when($this->due === 'today', fn ($q) => $q->whereDate('due_on', today()))
            ->when($this->due === 'week', fn ($q) => $q->whereBetween('due_on', [today()->toDateString(), today()->addDays(7)->toDateString()]))
            ->when($this->due === 'late', fn ($q) => $q->where('status', 'completed')->whereNotNull('due_on')->whereRaw('DATE(completed_at) > due_on'))
            ->orderByRaw("CASE WHEN status = 'completed' THEN 1 ELSE 0 END")
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
            ->orderByRaw('due_on IS NULL')->orderBy('due_on')->orderByDesc('completed_at')->latest('id')
            ->paginate(20);

        $own = $this->ownEmployee();
        $base = $this->visibleTasks();

        $opened = $this->openId ? $this->visibleTasks()->with(['assignee', 'project', 'parent', 'subtasks.assignee', 'comments.author', 'timeEntries', 'creator'])->find($this->openId) : null;
        // Completion history: who created, assigned, started, completed or re-opened the task, and when.
        $history = $opened ? \App\Models\AuditLog::with('actor')->where('subject_type', Task::class)->where('subject_id', $opened->id)->where('action', 'like', 'task.%')->where('action', '!=', 'task.commented')->latest('id')->limit(50)->get() : collect();
        $parentChoices = $this->showForm ? $this->visibleTasks()->whereNull('parent_id')->where('status', '!=', 'completed')->when($this->editingId, fn ($q) => $q->whereKeyNot($this->editingId))->orderBy('title')->limit(200)->get(['id', 'title']) : collect();
        return $this->page('livewire.work.tasks', [
            'tasks' => $tasks,
            'work' => $work,
            'own' => $own,
            'runningTaskId' => $own ? TaskTimeEntry::where('employee_id', $own->id)->where('status', 'running')->value('task_id') : null,
            'counts' => ['open' => (clone $base)->where('status', '!=', 'completed')->count(), 'overdue' => (clone $base)->where('status', '!=', 'completed')->whereDate('due_on', '<', today())->count(), 'in_progress' => (clone $base)->where('status', 'in_progress')->count(), 'done_week' => (clone $base)->where('status', 'completed')->where('completed_at', '>=', now()->startOfWeek())->count(), 'completed' => (clone $base)->where('status', 'completed')->count()],
            'history' => $history,
            'projects' => Project::when(! $this->mine && ! $this->user()->canAny(['hr.manage', 'employees.manage']), fn ($q) => $q->where('manager_id', $own?->id ?? 0))->orderBy('name')->get(['id', 'name']),
            'assignees' => $this->canAssign() ? AttendanceEmployee::where('is_active', true)->when(! $this->user()->canAny(['hr.manage', 'employees.manage']), fn ($q) => $q->whereIn('id', $this->teamEmployeeIds()->all() ?: [0]))->orderBy('name')->get(['id', 'name']) : collect(),
            'canAssign' => $this->canAssign(),
            'canAddOwn' => $this->canAddOwn(),
            'opened' => $opened,
            'parentChoices' => $parentChoices,
        ], $this->mine ? 'My Tasks' : 'Tasks');
    }
}
