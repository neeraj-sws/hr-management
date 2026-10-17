<?php

namespace App\Http\Controllers\Api;

use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Services\HrWorkflowService;
use App\Services\WorkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends ApiController
{
    public function __construct(private readonly WorkService $work, private readonly HrWorkflowService $workflow) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->apiUser($request);
        $filters = $request->validate(['scope' => ['nullable', 'in:mine,team'], 'status' => ['nullable', 'in:open,todo,in_progress,on_hold,completed'], 'overdue' => ['nullable', 'boolean']]);
        $employee = $user->employeeProfile;
        $query = Task::with(['project', 'assignee', 'timeEntries']);
        if (($filters['scope'] ?? 'mine') === 'team') {
            abort_unless($user->canAny(['tasks.manage', 'hr.manage']), 403);
            if (! $user->canAny(['hr.manage', 'employees.manage'])) $query->whereIn('assignee_id', $employee ? $employee->directReports()->pluck('id')->push($employee->id) : [0]);
        } else {
            $query->where('assignee_id', $employee?->id ?? 0);
        }
        $status = $filters['status'] ?? null;
        $query->when($status === 'open', fn ($q) => $q->where('status', '!=', 'completed'))
            ->when($status && $status !== 'open', fn ($q) => $q->where('status', $status))
            ->when($request->boolean('overdue'), fn ($q) => $q->where('status', '!=', 'completed')->whereDate('due_on', '<', today()));

        return response()->json($query->orderByRaw('due_on IS NULL')->orderBy('due_on')->paginate(50)->through(fn ($task) => $this->present($task)));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->apiUser($request);
        $data = $this->validated($request, ! $user->canAny(['tasks.manage', 'hr.manage']));
        if (! $user->canAny(['tasks.manage', 'hr.manage'])) {
            // Employees add tasks for themselves only.
            $own = $this->apiEmployee($request)->id;
            abort_if(! empty($data['assignee_id']) && (int) $data['assignee_id'] !== $own, 403, 'You can only add tasks for yourself.');
            $data['assignee_id'] = $own;
        }
        $task = $this->work->saveTask(null, $data, $user);

        return response()->json(['data' => $this->present($task->load(['project', 'assignee', 'timeEntries']))], 201);
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        $user = $this->requirePermission($request, 'tasks.manage', 'hr.manage');
        $this->work->assertCanAssign($user, $task->assignee);
        $task = $this->work->saveTask($task, $this->validated($request), $user);

        return response()->json(['data' => $this->present($task->load(['project', 'assignee', 'timeEntries']))]);
    }

    public function start(Request $request, Task $task): JsonResponse
    {
        $this->workflow->startTask($this->apiEmployee($request), $task);

        return response()->json(['data' => $this->present($task->fresh(['project', 'assignee', 'timeEntries']))]);
    }

    public function pause(Request $request, Task $task): JsonResponse
    {
        $employee = $this->apiEmployee($request);
        $entry = TaskTimeEntry::where('task_id', $task->id)->where('employee_id', $employee->id)->where('status', 'running')->firstOrFail();
        $this->workflow->stopTaskTimer($employee, $entry);

        return response()->json(['data' => $this->present($task->fresh(['project', 'assignee', 'timeEntries']))]);
    }

    public function complete(Request $request, Task $task): JsonResponse
    {
        $this->workflow->completeTask($this->apiEmployee($request), $task);

        return response()->json(['data' => $this->present($task->fresh(['project', 'assignee', 'timeEntries']))]);
    }

    public function comments(Request $request, Task $task): JsonResponse
    {
        abort_unless($this->work->canSeeTask($this->apiUser($request), $task), 403);

        return response()->json(['data' => $task->comments()->with('author')->get()->map(fn ($c) => ['id' => $c->id, 'author' => $c->author?->name, 'body' => $c->body, 'created_at' => $c->created_at->toIso8601String()])->values()]);
    }

    public function comment(Request $request, Task $task): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);
        $comment = $this->work->addComment($task, $this->apiUser($request), $data['body']);

        return response()->json(['data' => ['id' => $comment->id, 'author' => $comment->author?->name, 'body' => $comment->body, 'created_at' => $comment->created_at->toIso8601String()]], 201);
    }

    private function validated(Request $request, bool $own = false): array
    {
        // Desktop app versions up to 1.9 send "medium"; it is the "normal" priority.
        if ($request->input('priority') === 'medium') $request->merge(['priority' => 'normal']);

        return $request->validate([
            'work_date' => ['nullable', 'date', 'before_or_equal:today'],
            'parent_id' => ['nullable', 'exists:tasks,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'project_id' => ['nullable', 'exists:projects,id'],
            'assignee_id' => [$own ? 'nullable' : 'required', 'exists:attendance_employees,id'],
            'priority' => ['required', 'in:'.implode(',', WorkService::PRIORITIES)],
            'status' => ['nullable', 'in:'.implode(',', WorkService::STATUSES)],
            'estimated_hours' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'progress' => ['nullable', 'integer', 'min:0', 'max:100'],
            'due_on' => ['nullable', 'date'],
        ]);
    }

    private function present(Task $task): array
    {
        $running = $task->timeEntries->firstWhere('status', 'running');

        return [
            'id' => $task->id, 'title' => $task->title, 'description' => $task->description,
            'project' => $task->project ? ['id' => $task->project->id, 'name' => $task->project->name] : null,
            'assignee' => $task->assignee ? ['id' => $task->assignee->id, 'name' => $task->assignee->name] : null,
            'priority' => $task->priority, 'status' => $task->status,
            'estimated_hours' => $task->estimated_hours !== null ? (float) $task->estimated_hours : null,
            'logged_seconds' => $logged = $this->work->loggedSeconds($task),
            'progress' => $task->progressPercent($logged),
            'due_on' => $task->due_on?->toDateString(), 'overdue' => $task->isOverdue(), 'completed_late' => $task->wasCompletedLate(),
            'timer_running_since' => $running?->started_at?->toIso8601String(),
            'completed_at' => $task->completed_at?->toIso8601String(),
            'server_version' => (int) $task->server_version,
            'work_date' => $task->work_date?->toDateString(), 'parent_id' => $task->parent_id,
            'subtasks' => $task->subtasks()->count(), 'comments' => $task->comments()->count(),
        ];
    }
}
