<?php

namespace App\Services;

use App\Models\AttendanceEmployee;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Models\User;
use App\Notifications\ApprovalRequested;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Projects, task assignment and work/productivity metrics. */
class WorkService
{
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
    public const STATUSES = ['todo', 'in_progress', 'on_hold', 'completed'];
    public const PROJECT_STATUSES = ['planned' => 'Planned', 'active' => 'Active', 'on_hold' => 'On hold', 'completed' => 'Completed'];

    /** Projects any of these employees manage, are a member of, or have tasks in. */
    public function projectsInvolving(iterable $employeeIds): \Illuminate\Database\Eloquent\Builder
    {
        $ids = collect($employeeIds)->map(fn ($id) => (int) $id)->unique()->values()->all() ?: [0];

        return Project::query()->where(fn ($q) => $q->whereIn('manager_id', $ids)
            ->orWhereHas('members', fn ($m) => $m->whereIn('attendance_employees.id', $ids))
            ->orWhereHas('tasks', fn ($t) => $t->whereIn('assignee_id', $ids)));
    }

    /** Number of projects in a query: total and per status. */
    public function projectCounts(\Illuminate\Database\Eloquent\Builder $query): array
    {
        $byStatus = (clone $query)->reorder()->toBase()->select('status', DB::raw('count(*) as aggregate'))->groupBy('status')->pluck('aggregate', 'status');
        $counts = ['total' => (int) $byStatus->sum()];
        foreach (array_keys(self::PROJECT_STATUSES) as $status) $counts[$status] = (int) ($byStatus[$status] ?? 0);

        return $counts;
    }

    public function __construct(private readonly AuditLogger $audit) {}

    public function saveProject(?Project $project, array $data, User $actor): Project
    {
        $isNew = ! $project;
        $previousManager = $project?->manager_id;
        $project ??= new Project();
        $project->fill($data)->save();
        $this->audit->record($isNew ? 'project.created' : 'project.updated', $project, ['manager_id' => $project->manager_id]);
        if ($project->manager_id && (int) $project->manager_id !== (int) $previousManager) {
            $manager = AttendanceEmployee::with('user')->find($project->manager_id);
            Notifier::send($manager?->user, 'ProjectManager', $project->id, "{$actor->name} made you the manager of the project \"{$project->name}\".", route('work.projects', [], false), $actor->id);
        }

        return $project;
    }

    /** Managers may only assign work to their direct reports (or themselves). */
    public function assertCanAssign(User $actor, AttendanceEmployee $assignee): void
    {
        if ($actor->canAny(['hr.manage', 'employees.manage'])) return;
        $own = AttendanceEmployee::where('user_id', $actor->id)->first();
        if (! $own || ($assignee->manager_id !== $own->id && $assignee->id !== $own->id)) {
            throw ValidationException::withMessages(['assignee_id' => 'You can assign tasks only to members of your team.']);
        }
    }

    public function saveTask(?Task $task, array $data, User $actor): Task
    {
        $assignee = AttendanceEmployee::where('is_active', true)->findOrFail($data['assignee_id']);
        $this->assertCanAssign($actor, $assignee);
        if (! empty($data['parent_id'])) {
            $parent = Task::findOrFail($data['parent_id']);
            if ($task && (int) $parent->id === (int) $task->id) throw ValidationException::withMessages(['parent_id' => 'A task cannot be its own subtask.']);
            if ($parent->parent_id) throw ValidationException::withMessages(['parent_id' => 'Subtasks can only be one level deep.']);
            if ($task && $task->subtasks()->exists()) throw ValidationException::withMessages(['parent_id' => 'This task has its own subtasks, so it cannot become a subtask.']);
            $data['project_id'] ??= $parent->project_id;
        }
        if (! empty($data['project_id'])) {
            $project = Project::find($data['project_id']);
            if ($project && $project->members()->exists() && ! $project->members()->whereKey($assignee->id)->exists()) {
                throw ValidationException::withMessages(['assignee_id' => "{$assignee->name} is not a member of {$project->name}. Add them to the project first."]);
            }
        }
        if (! $task && $assignee->user_id === $actor->id) $data['work_date'] ??= today()->toDateString(); // self-entered work is dated

        return DB::transaction(function () use ($task, $data, $actor, $assignee) {
            $isNew = ! $task;
            $previousAssignee = $task?->assignee_id;
            $task ??= new Task(['created_by' => $actor->id, 'status' => 'todo']);
            if (! $isNew && $task->status === 'completed' && ($data['status'] ?? 'completed') !== 'completed') {
                $data['completed_at'] = null;
            }
            $becameCompleted = ($data['status'] ?? null) === 'completed' && $task->status !== 'completed';
            if (($data['status'] ?? null) === 'completed' && ! $task->completed_at) {
                TaskTimeEntry::where('task_id', $task->id)->where('status', 'running')->get()
                    ->each(fn ($entry) => app(HrWorkflowService::class)->stopTaskTimer($entry->employee, $entry));
                $data['completed_at'] = now();
            }
            $task->fill($data)->save();
            if (! $isNew) $task->increment('server_version');
            $this->audit->record($isNew ? 'task.assigned' : 'task.updated', $task, ['assignee_id' => $assignee->id]);
            if ($isNew || $previousAssignee !== $assignee->id) {
                $details = collect([$task->project?->name ? 'project '.$task->project->name : null, $task->due_on ? 'due '.$task->due_on->format('d M Y') : null, $task->priority !== 'normal' ? $task->priority.' priority' : null])->filter()->join(', ');
                // Assigning work to yourself needs no notification.
                if ($assignee->user_id !== $actor->id) {
                    Notifier::send($assignee->user, 'TaskAssignment', $task->id, "{$actor->name} assigned you a task: \"{$task->title}\"".($details ? " ({$details})" : '').'.', route('work.my-tasks', ['task' => $task->id], false), $actor->id);
                }
            }
            if ($becameCompleted) $this->taskCompleted($task, $actor);

            return $task->fresh();
        });
    }

    /**
     * Tells the person who assigned a task (and the assignee's manager, if
     * different) that it is done. The completion itself is kept in the audit
     * log, which the task's History shows.
     */
    public function taskCompleted(Task $task, ?User $by = null): void
    {
        $task->loadMissing('assignee.manager.user', 'creator');
        $who = $by?->name ?? $task->assignee?->name ?? 'Someone';
        $message = "{$who} completed the task \"{$task->title}\"".($task->wasCompletedLate() ? ' (after its due date)' : '').'.';
        $url = route('work.tasks', ['task' => $task->id, 'status' => 'completed'], false);
        Notifier::send([$task->creator, $task->assignee?->manager?->user], 'TaskCompleted', $task->id, $message, $url, $by?->id ?? $task->assignee?->user_id);
    }

    /** Assignee, creator, the assignee's manager, or task/HR managers with the employee in scope. */
    public function canSeeTask(User $user, Task $task): bool
    {
        $assignee = $task->assignee;
        if ($task->created_by === $user->id || $assignee?->user_id === $user->id) return true;
        if ($user->canAny(['hr.manage', 'employees.manage'])) return true;
        $own = AttendanceEmployee::where('user_id', $user->id)->first();

        return $own && $assignee && $assignee->manager_id === $own->id;
    }

    public function addComment(Task $task, User $author, string $body): \App\Models\TaskComment
    {
        if (! $this->canSeeTask($author, $task)) abort(403);
        $body = trim($body);
        if ($body === '') throw ValidationException::withMessages(['comment' => 'Write a comment.']);
        $comment = $task->comments()->create(['user_id' => $author->id, 'body' => $body]);
        $this->audit->record('task.commented', $task, ['comment_id' => $comment->id]);
        Notifier::send([$task->assignee?->user, User::find($task->created_by), $task->assignee?->manager?->user], 'TaskComment', $task->id, "{$author->name} commented on \"{$task->title}\": ".\Illuminate\Support\Str::limit($body, 80), route('work.my-tasks', ['task' => $task->id], false), $author->id);

        return $comment;
    }

    /**
     * Sets a project's members. People added (or removed) are told, and so is
     * the project manager when someone else changed the team.
     *
     * @param array<int> $employeeIds
     */
    public function setProjectMembers(Project $project, array $employeeIds, User $actor): void
    {
        $before = $project->members()->pluck('attendance_employees.id')->all();
        $changes = $project->members()->sync(AttendanceEmployee::whereIn('id', $employeeIds)->pluck('id')->all());
        $this->audit->record('project.members_updated', $project, ['before' => $before, 'after' => $project->members()->pluck('attendance_employees.id')->all(), 'added' => $changes['attached'], 'removed' => $changes['detached'], 'by' => $actor->id]);

        $added = AttendanceEmployee::with('user')->whereIn('id', $changes['attached'] ?: [0])->get();
        $removed = AttendanceEmployee::with('user')->whereIn('id', $changes['detached'] ?: [0])->get();
        foreach ($added as $member) {
            Notifier::send($member->user, 'ProjectMember', $project->id, "{$actor->name} added you to the project \"{$project->name}\".", route('work.my-projects', [], false), $actor->id);
        }
        foreach ($removed as $member) {
            Notifier::send($member->user, 'ProjectMember', $project->id, "{$actor->name} removed you from the project \"{$project->name}\".", route('work.my-projects', [], false), $actor->id);
        }
        $manager = $project->manager()->with('user')->first();
        if ($manager?->user && ($added->isNotEmpty() || $removed->isNotEmpty())) {
            $summary = collect([$added->isNotEmpty() ? 'added '.$added->pluck('name')->join(', ') : null, $removed->isNotEmpty() ? 'removed '.$removed->pluck('name')->join(', ') : null])->filter()->join('; ');
            Notifier::send($manager->user, 'ProjectMember', $project->id, "{$actor->name} {$summary} on your project \"{$project->name}\".", route('work.projects', [], false), $actor->id);
        }
    }

    /**
     * Tells the manager (and the managers of the projects in the update) that a
     * daily status was submitted, with the project-wise lines. Sent once per day
     * per person; later edits the same day do not send it again.
     */
    public function notifyDailyStatus(AttendanceEmployee $employee, \App\Models\DailyWorkStatus $status): void
    {
        $projectIds = collect((array) ($status->project_updates ?? []))->pluck('project_id')->filter()->all();
        $recipients = collect([$employee->manager?->user])
            ->merge(Project::with('manager.user')->whereIn('id', $projectIds ?: [0])->get()->map(fn ($p) => $p->manager?->user))
            ->filter()->unique('id')->reject(fn ($u) => $u->id === $employee->user_id)
            ->reject(fn ($u) => $u->notifications()->where('data->kind', 'DailyStatusSubmitted')->where('data->record_id', $status->id)->exists());
        if ($recipients->isEmpty()) return;
        $projects = collect((array) ($status->project_updates ?? []))->map(fn ($l) => $l['project'].($l['update'] !== '' ? ': '.\Illuminate\Support\Str::limit($l['update'], 60) : ''))->join('; ');
        Notifier::send($recipients, 'DailyStatusSubmitted', $status->id, "{$employee->name} submitted the daily status for {$status->work_date->format('d M')}".($projects ? " — {$projects}" : ': '.\Illuminate\Support\Str::limit($status->summary, 100)), route('work.daily-status', ['employee' => $employee->id, 'date' => $status->work_date->toDateString()], false), $employee->user_id);
    }

    /** Tells the manager when a daily status reports a blocker (once per status). */
    public function notifyBlocker(AttendanceEmployee $employee, \App\Models\DailyWorkStatus $status): void
    {
        if (! $status->blockers || ! $employee->manager?->user) return;
        $already = $employee->manager->user->notifications()->where('data->kind', 'DailyStatus')->where('data->record_id', $status->id)->exists();
        if (! $already) $employee->manager->user->notify(new ApprovalRequested('DailyStatus', $status->id, "{$employee->name} reported a blocker: ".\Illuminate\Support\Str::limit($status->blockers, 80), route('work.daily-status', ['employee' => $employee->id], false)));
    }

    /**
     * Reminds people who checked in on $date but have not submitted a daily
     * status (scheduled in the evening, or sent by a manager). One per day.
     *
     * @param  array<int>|null  $employeeIds  null = everyone
     */
    /** One alert per overdue open task, to the assignee and their manager; it re-arms when the due date moves. */
    public function notifyOverdueTasks(): int
    {
        $count = 0;
        Task::with('assignee.manager.user', 'assignee.user')->whereNull('overdue_notified_at')->whereNotNull('assignee_id')
            ->where('status', '!=', 'completed')->whereDate('due_on', '<', today())
            ->chunkById(200, function ($tasks) use (&$count) {
                foreach ($tasks as $task) {
                    $due = $task->due_on->format('d M');
                    $task->assignee?->user?->notify(new \App\Notifications\ApprovalRequested('TaskOverdue', $task->id, "Task \"{$task->title}\" was due on {$due} and is not completed."));
                    $task->assignee?->manager?->user?->notify(new \App\Notifications\ApprovalRequested('TaskOverdue', $task->id, "{$task->assignee->name}'s task \"{$task->title}\" is overdue (due {$due})."));
                    $task->forceFill(['overdue_notified_at' => now()])->saveQuietly();
                    $count++;
                }
            });

        return $count;
    }

    public function remindDailyStatus(string $date, ?array $employeeIds = null): int
    {
        $submitted = \App\Models\DailyWorkStatus::whereDate('work_date', $date)->pluck('employee_id');
        $people = AttendanceEmployee::with('user')->where('is_active', true)->whereNotIn('id', $submitted)
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('id', $employeeIds ?: [0]))
            ->whereHas('sessions', fn ($q) => $q->whereDate('work_date', $date))->get();
        $sent = 0;
        foreach ($people as $person) {
            if (! $person->user) continue;
            if ($person->user->notifications()->where('data->kind', 'DailyStatusReminder')->whereDate('created_at', today())->exists()) continue;
            $person->user->notify(new ApprovalRequested('DailyStatusReminder', $person->id, 'Please submit your daily work status for '.\Illuminate\Support\Carbon::parse($date)->format('d M').'.', route('work.daily-status', [], false)));
            $sent++;
        }

        return $sent;
    }

    /** Logged task seconds including a running timer. */
    public function loggedSeconds(Task $task): int
    {
        return (int) $task->timeEntries->sum(fn ($entry) => $entry->status === 'running' ? $entry->started_at->diffInSeconds(now()) : (int) $entry->duration_seconds);
    }

    /**
     * Productivity metrics per employee for a date range, from tasks, time
     * entries and attendance sessions.
     *
     * @param  Collection<int, AttendanceEmployee>  $employees
     */
    public function productivity(Collection $employees, string $from, string $to): Collection
    {
        $ids = $employees->pluck('id');
        $completed = Task::whereIn('assignee_id', $ids)->where('status', 'completed')
            ->whereDate('completed_at', '>=', $from)->whereDate('completed_at', '<=', $to)->get()->groupBy('assignee_id');
        $open = Task::whereIn('assignee_id', $ids)->where('status', '!=', 'completed')->get()->groupBy('assignee_id');
        // Task time per employee per day. A timer left running overnight (or for days) only counts until the end of
        // the day it started, and a day never counts more than that day's attendance — so stale timers do not inflate it.
        $dailyActive = DB::table('attendance_sessions')->whereIn('employee_id', $ids)->whereDate('work_date', '>=', $from)->whereDate('work_date', '<=', $to)->whereNotNull('check_in')
            ->selectRaw('employee_id, DATE(work_date) as day, SUM(active_seconds) as active')->groupBy('employee_id', DB::raw('DATE(work_date)'))->get()
            ->mapWithKeys(fn ($r) => [$r->employee_id.'|'.$r->day => (int) $r->active]);
        $perDay = $rawPerDay = [];
        foreach (TaskTimeEntry::whereIn('employee_id', $ids)->whereDate('started_at', '>=', $from)->whereDate('started_at', '<=', $to)->get(['employee_id', 'started_at', 'ended_at', 'duration_seconds', 'status']) as $entry) {
            $dayEnd = $entry->started_at->copy()->endOfDay();
            $raw = max(0, $entry->status === 'running' ? (int) $entry->started_at->diffInSeconds(now()) : (int) $entry->duration_seconds);
            $seconds = min($raw, (int) $entry->started_at->diffInSeconds($dayEnd));
            $key = $entry->employee_id.'|'.$entry->started_at->toDateString();
            $perDay[$key] = ($perDay[$key] ?? 0) + $seconds;
            $rawPerDay[$key] = ($rawPerDay[$key] ?? 0) + $raw;
        }
        $logged = [];
        $trimmed = [];
        foreach ($perDay as $key => $seconds) {
            [$employeeId] = explode('|', $key);
            $cap = $dailyActive[$key] ?? null;
            $counted = $cap ? min($seconds, $cap) : $seconds;
            $logged[$employeeId] = ($logged[$employeeId] ?? 0) + $counted;
            $trimmed[$employeeId] = ($trimmed[$employeeId] ?? 0) + ($rawPerDay[$key] - $counted);
        }
        $attendance = DB::table('attendance_sessions')->whereIn('employee_id', $ids)->whereDate('work_date', '>=', $from)->whereDate('work_date', '<=', $to)->whereNotNull('check_in')
            ->selectRaw('employee_id, COUNT(*) as days, SUM(active_seconds) as active')->groupBy('employee_id')->get()->keyBy('employee_id');

        return $employees->map(function (AttendanceEmployee $employee) use ($completed, $open, $logged, $trimmed, $attendance) {
            $done = $completed->get($employee->id, collect());
            $pending = $open->get($employee->id, collect());
            $late = $done->filter(fn (Task $task) => $task->wasCompletedLate())->count();
            $overdue = $pending->filter(fn (Task $task) => $task->isOverdue())->count();
            $estimated = (float) $done->sum('estimated_hours');
            $loggedSeconds = (int) ($logged[$employee->id] ?? 0);
            $activeSeconds = (int) ($attendance[$employee->id]->active ?? 0);
            $onTime = $done->count() ? round((($done->count() - $late) / $done->count()) * 100) : null;

            return (object) [
                'employee' => $employee,
                'completed_tasks' => $done->count(),
                'completed_late' => $late,
                'open_tasks' => $pending->count(),
                'overdue_tasks' => $overdue,
                'on_time_rate' => $onTime,
                'estimated_hours' => round($estimated, 2),
                'logged_hours' => round($loggedSeconds / 3600, 2),
                'logged_seconds' => $loggedSeconds,
                'trimmed_seconds' => (int) ($trimmed[$employee->id] ?? 0),
                'attendance_days' => (int) ($attendance[$employee->id]->days ?? 0),
                'active_hours' => round($activeSeconds / 3600, 2),
                'active_seconds' => $activeSeconds,
                'utilization' => $activeSeconds > 0 ? min(100, (int) round($loggedSeconds / $activeSeconds * 100)) : null,
            ];
        })->values();
    }
}
