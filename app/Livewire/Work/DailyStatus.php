<?php

namespace App\Livewire\Work;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\DailyWorkStatus;
use App\Models\Project;
use App\Models\Task;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class DailyStatus extends Component
{
    use InteractsWithHr, WithPagination;

    public string $summary = '';
    public string $plan = '';
    public string $blockers = '';
    public string $notes = '';
    public string $pending = '';
    public string $tomorrowPlan = '';
    /** project id => what was done on that project today */
    public array $projectUpdates = [];
    /** task id => what was done on that task today */
    public array $taskNotes = [];
    #[Url] public string $date = '';
    #[Url] public ?int $employee = null;
    #[Url] public ?int $project = null;

    public function mount(): void
    {
        $today = $this->ownEmployee() ? DailyWorkStatus::where('employee_id', $this->ownEmployee()->id)->whereDate('work_date', today())->first() : null;
        $this->fill(['summary' => (string) $today?->summary, 'plan' => (string) $today?->plan, 'blockers' => (string) $today?->blockers, 'notes' => (string) $today?->notes, 'pending' => (string) $today?->pending, 'tomorrowPlan' => (string) $today?->tomorrow_plan]);
        foreach ((array) ($today?->project_updates ?? []) as $line) {
            $this->projectUpdates[(string) ($line['project_id'] ?? 0)] = (string) ($line['update'] ?? '');
            foreach ((array) ($line['tasks'] ?? []) as $t) if (! empty($t['id']) && ($t['note'] ?? '') !== '') $this->taskNotes[(string) $t['id']] = (string) $t['note'];
        }
    }

    public function updated($property): void
    {
        if (in_array($property, ['date', 'employee', 'project'], true)) $this->resetPage();
    }

    /**
     * Today's work grouped by project: the employee's projects (member, manager or
     * with tasks) plus tasks touched today and time logged on them.
     *
     * @return \Illuminate\Support\Collection<int, array{project: ?Project, tasks: \Illuminate\Support\Collection, seconds: int}>
     */
    private function todayByProject(\App\Models\AttendanceEmployee $employee): \Illuminate\Support\Collection
    {
        $tasks = Task::with('project')->where('assignee_id', $employee->id)
            ->where(fn ($q) => $q->whereDate('completed_at', today())->orWhere('status', 'in_progress')->orWhereHas('timeEntries', fn ($t) => $t->whereDate('started_at', today())))
            ->get();
        $seconds = \App\Models\TaskTimeEntry::where('employee_id', $employee->id)->whereDate('started_at', today())->get()
            ->groupBy('task_id')->map(fn ($entries) => (int) $entries->sum(fn ($e) => $e->status === 'running' ? $e->started_at->diffInSeconds(now()) : (int) $e->duration_seconds));
        $projects = Project::where(fn ($q) => $q->where('manager_id', $employee->id)->orWhereHas('members', fn ($m) => $m->where('attendance_employees.id', $employee->id)))
            ->whereIn('status', ['planned', 'active'])->orderBy('name')->get()->keyBy('id');
        foreach ($tasks as $task) if ($task->project) $projects->put($task->project->id, $task->project);

        foreach ($tasks as $task) $task->setAttribute('seconds_today', (int) ($seconds[$task->id] ?? 0));

        return $projects->map(fn (Project $p) => ['project' => $p, 'tasks' => $tasks->where('project_id', $p->id)->values(), 'seconds' => (int) $tasks->where('project_id', $p->id)->sum('seconds_today')])
            ->push(['project' => null, 'tasks' => $tasks->whereNull('project_id')->values(), 'seconds' => (int) $tasks->whereNull('project_id')->sum('seconds_today')])
            ->filter(fn ($row) => $row['project'] || $row['tasks']->isNotEmpty())->values();
    }

    public function save(AuditLogger $audit): void
    {
        $employee = $this->requireEmployee();
        $this->validate(['summary' => ['nullable', 'string', 'max:4000'], 'plan' => ['nullable', 'string', 'max:4000'], 'blockers' => ['nullable', 'string', 'max:2000'], 'notes' => ['nullable', 'string', 'max:2000'], 'pending' => ['nullable', 'string', 'max:4000'], 'tomorrowPlan' => ['nullable', 'string', 'max:4000']], ['summary.required' => 'Write what you completed today.'], ['summary' => 'completed work']);
        $this->validate(['projectUpdates.*' => ['nullable', 'string', 'max:2000'], 'taskNotes.*' => ['nullable', 'string', 'max:1000']], [], ['projectUpdates.*' => 'project update', 'taskNotes.*' => 'task note']);
        // Project-wise lines: what the employee wrote per project, with the tasks and time logged on it today.
        $lines = $this->todayByProject($employee)->map(function ($row) {
            $key = (string) ($row['project']?->id ?? 0);
            $update = trim((string) ($this->projectUpdates[$key] ?? ''));
            if ($update === '' && $row['tasks']->isEmpty()) return null;

            return ['project_id' => $row['project']?->id, 'project' => $row['project']?->name ?? 'No project', 'update' => $update, 'tasks' => $row['tasks']->map(fn ($t) => ['id' => $t->id, 'title' => $t->title, 'status' => $t->status, 'progress' => $t->progress, 'seconds' => (int) $t->seconds_today, 'note' => trim((string) ($this->taskNotes[(string) $t->id] ?? ''))])->all(), 'seconds' => $row['seconds']];
        })->filter()->values()->all();
        // "Completed" may be left empty when project / task lines say what was done: it is built from them.
        if (trim($this->summary) === '') {
            $this->summary = collect($lines)->map(fn ($l) => collect([$l['update'] !== '' ? $l['project'].': '.$l['update'] : null])
                ->merge(collect($l['tasks'])->filter(fn ($t) => $t['note'] !== '')->map(fn ($t) => $l['project'].' › '.$t['title'].': '.$t['note'])))->flatten()->filter()->join("\n");
        }
        if (mb_strlen(trim($this->summary)) < 5) {
            $this->addError('summary', 'Write what you completed today — in "Completed", or a line for a project or task.');

            return;
        }
        $fields = ['summary' => $this->summary, 'plan' => $this->plan ?: null, 'blockers' => $this->blockers ?: null, 'notes' => $this->notes ?: null, 'pending' => $this->pending ?: null, 'tomorrow_plan' => $this->tomorrowPlan ?: null, 'submitted_at' => now(), 'project_updates' => $lines ?: null];
        $status = DB::transaction(function () use ($employee, $fields) {
            $status = DailyWorkStatus::where('employee_id', $employee->id)->whereDate('work_date', today())->lockForUpdate()->first();
            if ($status) {
                $status->update([...$fields, 'submitted_by' => auth()->id(), 'server_version' => (int) $status->server_version + 1]);
            } else {
                $status = DailyWorkStatus::create([...$fields, 'employee_id' => $employee->id, 'work_date' => today()->toDateString(), 'submitted_by' => auth()->id(), 'server_version' => 1]);
            }

            return $status;
        });
        $audit->record('work_status.submitted', $status, ['has_blockers' => (bool) $status->blockers, 'projects' => collect($lines)->pluck('project')->all()]);
        app(\App\Services\WorkService::class)->notifyBlocker($employee, $status);
        app(\App\Services\WorkService::class)->notifyDailyStatus($employee, $status);
        $this->toast('Daily status saved.');
    }

    /** Manager/HR nudges people who checked in but have not written today's status. */
    public function remindMissing(): void
    {
        $orgWide = $this->user()->canAny(['hr.manage', 'employees.manage']);
        $scope = $orgWide ? null : $this->statusScope();
        abort_unless($orgWide || $scope->count() > 1, 403);
        $sent = app(\App\Services\WorkService::class)->remindDailyStatus(today()->toDateString(), $scope?->all(), $this->user());
        $this->toast($sent ? "Reminder sent to {$sent} ".str('person')->plural($sent).' (in the app and by e-mail).' : 'Everyone listed was already reminded in the last hour — try again later.');
    }

    /** Own employee, direct reports and members of projects this person manages. */
    private function statusScope(): \Illuminate\Support\Collection
    {
        $own = $this->ownEmployee();
        if (! $own) return collect();
        $projectMembers = Project::where('manager_id', $own->id)->with('members:attendance_employees.id')->get()->flatMap(fn ($p) => $p->members->pluck('id'));

        return $this->teamEmployeeIds()->merge($projectMembers)->unique()->values();
    }

    public function render()
    {
        $user = $this->user();
        $own = $this->ownEmployee();
        $orgWide = $user->canAny(['hr.manage', 'employees.manage']);
        // Who the signed-in person may read: HR everyone; a manager their direct reports; a project manager the
        // members of their projects; everyone else only themselves.
        $scope = $orgWide ? null : $this->statusScope();
        $canSeeTeam = $orgWide || $scope->count() > 1;
        $statuses = DailyWorkStatus::with('employee')
            ->when(! $canSeeTeam, fn ($q) => $q->where('employee_id', $own?->id ?? 0))
            ->when($canSeeTeam && $scope, fn ($q) => $q->whereIn('employee_id', $scope->all() ?: [0]))
            ->when($this->date, fn ($q, $d) => $q->whereDate('work_date', $d))
            ->when($this->employee && ($scope === null || $scope->contains($this->employee)), fn ($q) => $q->where('employee_id', $this->employee))
            // project_updates is JSON: MySQL stores it as {"project_id": 5, …} (with a space), SQLite without.
            ->when($this->project, fn ($q, $id) => $q->where(fn ($q) => $q->where('project_updates', 'like', '%"project_id":'.(int) $id.',%')->orWhere('project_updates', 'like', '%"project_id": '.(int) $id.',%')))
            ->latest('work_date')->latest('id')->paginate(15);

        $missing = collect();
        // "Missing today" on the company's working days (Saturdays too where they are worked).
        if ($canSeeTeam && \App\Support\WorkCalendar::isWorkday(today()->toDateString(), app(\App\Services\PolicyConfigurationService::class)->forEmployee(null, 'calendar.working_days', today()->toDateString(), ['days' => [1, 2, 3, 4, 5]]))) {
            $submitted = DailyWorkStatus::whereDate('work_date', today())->pluck('employee_id');
            $missing = AttendanceEmployee::where('is_active', true)->when($scope, fn ($q) => $q->whereIn('id', $scope->all() ?: [0]))
                ->whereNotIn('id', $submitted)->whereHas('sessions', fn ($q) => $q->whereDate('work_date', today()))->inCodeOrder()->get();
        }

        return $this->page('livewire.work.daily-status', [
            'statuses' => $statuses,
            'own' => $own,
            'canSeeTeam' => $canSeeTeam,
            'missing' => $missing,
            'todayTasks' => $own ? Task::where('assignee_id', $own->id)->where(fn ($q) => $q->whereDate('completed_at', today())->orWhere('status', 'in_progress'))->get() : collect(),
            'byProject' => $own ? $this->todayByProject($own) : collect(),
            'projects' => $canSeeTeam ? Project::orderBy('name')->get(['id', 'name']) : collect(),
            'people' => $canSeeTeam ? AttendanceEmployee::where('is_active', true)->when($scope, fn ($q) => $q->whereIn('id', $scope->all() ?: [0]))->orderBy('name')->get(['id', 'name']) : collect(),
        ], 'Daily Work Status');
    }
}
