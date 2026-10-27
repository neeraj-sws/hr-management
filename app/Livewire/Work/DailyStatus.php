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
    /** Team view filters: department, blockers, free text, quick period, sort. */
    #[Url] public ?int $department = null;
    #[Url(as: 'blockers')] public string $blockerFilter = '';
    #[Url(as: 'q')] public string $search = '';
    #[Url] public string $period = 'all';
    #[Url] public string $sortBy = 'latest';
    /** View details drawer: the status shown and its tab. */
    public ?int $viewing = null;
    /** Today's status form opens from the "Add today's status" button (?write=1 opens it straight away). */
    public bool $showStatusForm = false;
    public string $viewTab = 'work';

    public function mount(): void
    {
        $this->showStatusForm = request()->boolean('write') && $this->ownEmployee() !== null;
        $today = $this->ownEmployee() ? DailyWorkStatus::where('employee_id', $this->ownEmployee()->id)->whereDate('work_date', today())->first() : null;
        $this->fill(['summary' => (string) $today?->summary, 'plan' => (string) $today?->plan, 'blockers' => (string) $today?->blockers, 'notes' => (string) $today?->notes, 'pending' => (string) $today?->pending, 'tomorrowPlan' => (string) $today?->tomorrow_plan]);
        foreach ((array) ($today?->project_updates ?? []) as $line) {
            $this->projectUpdates[(string) ($line['project_id'] ?? 0)] = (string) ($line['update'] ?? '');
            foreach ((array) ($line['tasks'] ?? []) as $t) if (! empty($t['id']) && ($t['note'] ?? '') !== '') $this->taskNotes[(string) $t['id']] = (string) $t['note'];
        }
    }

    public function updated($property): void
    {
        if (in_array($property, ['date', 'employee', 'project', 'department', 'blockerFilter', 'search', 'period', 'sortBy'], true)) $this->resetPage();
        // Picking a date replaces the quick period tabs.
        if ($property === 'date' && $this->date) $this->period = 'all';
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
        $this->showStatusForm = false;
        $this->toast('Daily status saved.');
    }

    public function openStatusForm(): void
    {
        abort_unless($this->ownEmployee(), 403);
        $this->resetValidation();
        $this->showStatusForm = true;
    }

    public function closeModal(): void
    {
        $this->showStatusForm = false;
    }

    /** "Edit my status" in View details: close the drawer, open the form. */
    public function editFromDrawer(): void
    {
        $this->viewing = null;
        $this->openStatusForm();
    }

    /** "Who must write it" (HR / admins): per person compulsory + reminder, compulsory only, or not compulsory. */
    public bool $showRules = false;
    public string $rulesSearch = '';

    private function canSetRules(): bool
    {
        return $this->user()->canAny(['hr.manage', 'employees.manage']) || \App\Support\Access::isAdmin($this->user());
    }

    public function openRules(): void
    {
        abort_unless($this->canSetRules(), 403);
        $this->showRules = true;
    }

    public function closeRules(): void
    {
        $this->showRules = false;
    }

    /** One person: a mode, or 'default' to follow the company default. Applies from today. */
    public function setRule(int $employeeId, string $mode): void
    {
        abort_unless($this->canSetRules(), 403);
        \App\Services\ImpersonationService::block();
        $employee = AttendanceEmployee::findOrFail($employeeId);
        if ($mode === 'default') \App\Support\DailyStatusRule::useDefault($employee);
        else \App\Support\DailyStatusRule::set($employee, $mode, $this->user()->id);
        app(AuditLogger::class)->record('daily_status.rule_set', $employee, ['mode' => $mode]);
        $this->toast("{$employee->name}: ".(\App\Support\DailyStatusRule::MODES[\App\Support\DailyStatusRule::mode($employee)] ?? $mode).'.');
    }

    /** Company default: everyone without their own choice (and new employees). */
    public function setDefaultRule(string $mode): void
    {
        abort_unless($this->canSetRules(), 403);
        \App\Services\ImpersonationService::block();
        \App\Support\DailyStatusRule::set(null, $mode, $this->user()->id);
        app(AuditLogger::class)->record('daily_status.rule_default', null, ['mode' => $mode]);
        $this->toast('Company default: '.\App\Support\DailyStatusRule::MODES[$mode].'.');
    }

    /** Everyone shown in the list (after the search) set to one mode. */
    public function setRuleForAll(string $mode): void
    {
        abort_unless($this->canSetRules() && isset(\App\Support\DailyStatusRule::MODES[$mode]), 403);
        \App\Services\ImpersonationService::block();
        $people = $this->rulePeople();
        foreach ($people as $employee) \App\Support\DailyStatusRule::set($employee, $mode, $this->user()->id);
        app(AuditLogger::class)->record('daily_status.rule_bulk', null, ['mode' => $mode, 'employees' => $people->count()]);
        $this->toast($people->count().' '.str('person')->plural($people->count()).': '.\App\Support\DailyStatusRule::MODES[$mode].'.');
    }

    private function rulePeople(): \Illuminate\Support\Collection
    {
        $q = trim($this->rulesSearch);

        return AttendanceEmployee::with('department')->where('is_active', true)
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%{$q}%")->orWhere('employee_code', 'like', "%{$q}%")->orWhereHas('department', fn ($d) => $d->where('name', 'like', "%{$q}%"))))
            ->inCodeOrder()->get();
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

    public function resetFilters(): void
    {
        $this->reset('date', 'employee', 'project', 'department', 'blockerFilter', 'search', 'period', 'sortBy');
        $this->resetPage();
    }

    /** Reminder to one person who has not written today's status. */
    public function remindOne(int $employeeId): void
    {
        $orgWide = $this->user()->canAny(['hr.manage', 'employees.manage']);
        $scope = $orgWide ? null : $this->statusScope();
        abort_unless($orgWide || ($scope->count() > 1 && $scope->contains($employeeId)), 403);
        $sent = app(\App\Services\WorkService::class)->remindDailyStatus(today()->toDateString(), [$employeeId], $this->user());
        $this->toast($sent ? 'Reminder sent (in the app and by e-mail).' : 'Already reminded in the last hour — try again later.');
    }

    public function view(int $id): void
    {
        $this->viewing = $this->visibleStatus($id)->id;
        $this->viewTab = 'work';
    }

    public function closeView(): void
    {
        $this->viewing = null;
    }

    public function showTab(string $tab): void
    {
        if (in_array($tab, ['work', 'timesheet', 'tasks', 'projects', 'attendance', 'leaves', 'activity'], true)) $this->viewTab = $tab;
    }

    /** Previous / next day's status of the same person. */
    public function stepDay(int $direction): void
    {
        $current = $this->visibleStatus((int) $this->viewing);
        $next = DailyWorkStatus::where('employee_id', $current->employee_id)
            ->whereDate('work_date', $direction < 0 ? '<' : '>', $current->work_date->toDateString())
            ->orderBy('work_date', $direction < 0 ? 'desc' : 'asc')->first();
        if ($next) $this->viewing = $next->id;
    }

    /** A status the signed-in person may read (own, team / project scope, or everyone for HR) — 403 otherwise. */
    private function visibleStatus(int $id): DailyWorkStatus
    {
        $status = DailyWorkStatus::findOrFail($id);
        $user = $this->user();
        $allowed = $user->canAny(['hr.manage', 'employees.manage']) || $status->employee_id === $this->ownEmployee()?->id || $this->statusScope()->contains($status->employee_id);
        abort_unless($allowed, 403);

        return $status;
    }

    /** Everything the View details drawer shows for one status. */
    private function details(DailyWorkStatus $status): array
    {
        $status->loadMissing(['employee.department', 'employee.manager', 'employee.user']);
        $employee = $status->employee;
        $day = $status->work_date->toDateString();
        $tasks = Task::with('project')->where('assignee_id', $employee->id)
            ->where(fn ($q) => $q->whereDate('completed_at', $day)->orWhereDate('started_at', $day)->orWhereDate('work_date', $day)
                ->orWhereHas('timeEntries', fn ($t) => $t->whereDate('started_at', $day))
                ->orWhere(fn ($q) => $q->where('status', '!=', 'completed')->whereDate('created_at', '<=', $day)))
            ->orderByDesc('updated_at')->limit(40)->get();
        $data = [
            'status' => $status, 'employee' => $employee,
            'prev' => DailyWorkStatus::where('employee_id', $employee->id)->whereDate('work_date', '<', $day)->exists(),
            'next' => DailyWorkStatus::where('employee_id', $employee->id)->whereDate('work_date', '>', $day)->exists(),
            'taskSummary' => ['completed' => $tasks->filter(fn ($t) => $t->completed_at?->toDateString() === $day)->count(), 'in_progress' => $tasks->where('status', 'in_progress')->count(), 'open' => $tasks->where('status', '!=', 'completed')->count()],
            'projectLines' => collect((array) ($status->project_updates ?? [])),
            'canEdit' => $employee->user_id === auth()->id() && $status->work_date->isToday(),
            'onTime' => $status->submitted_at && $employee->work_end ? $status->submitted_at->lte(\Illuminate\Support\Carbon::parse($day.' '.$employee->work_end)->addHours(2)) : null,
        ];
        // Only the open tab's data is loaded.
        $data['tab'] = match ($this->viewTab) {
            'timesheet' => \App\Models\TaskTimeEntry::with('task')->where('employee_id', $employee->id)->whereDate('started_at', $day)->orderBy('started_at')->get(),
            'tasks' => $tasks,
            'projects' => Project::where(fn ($q) => $q->where('manager_id', $employee->id)->orWhereHas('members', fn ($m) => $m->where('attendance_employees.id', $employee->id)))->orderBy('name')->get(),
            'attendance' => \App\Models\AttendanceSession::where('employee_id', $employee->id)->whereDate('work_date', $day)->orderBy('check_in')->get(),
            'leaves' => \App\Models\LeaveRequest::with('leaveType')->where('employee_id', $employee->id)->whereDate('ends_on', '>=', \Illuminate\Support\Carbon::parse($day)->subDays(60))->orderByDesc('starts_on')->limit(10)->get(),
            'activity' => \App\Models\AuditLog::with('actor')->where(fn ($q) => $q->where(fn ($q) => $q->where('subject_type', DailyWorkStatus::class)->where('subject_id', $status->id))
                ->orWhere(fn ($q) => $q->where('actor_id', $employee->user_id ?? 0)->whereDate('created_at', $day)))->latest('id')->limit(25)->get(),
            default => null,
        };

        return $data;
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
        $day = $this->date ?: today()->toDateString();
        $term = trim($this->search);

        // The filters every count shares (scope, employee, department, project, blockers, search) — not the period or date.
        $filtered = fn () => DailyWorkStatus::query()
            ->when(! $canSeeTeam, fn ($q) => $q->where('employee_id', $own?->id ?? 0))
            ->when($canSeeTeam && $scope, fn ($q) => $q->whereIn('employee_id', $scope->all() ?: [0]))
            ->when($this->employee && ($scope === null || $scope->contains($this->employee)), fn ($q) => $q->where('employee_id', $this->employee))
            ->when($this->department, fn ($q, $id) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $id)))
            // project_updates is JSON: MySQL stores it as {"project_id": 5, …} (with a space), SQLite without.
            ->when($this->project, fn ($q, $id) => $q->where(fn ($q) => $q->where('project_updates', 'like', '%"project_id":'.(int) $id.',%')->orWhere('project_updates', 'like', '%"project_id": '.(int) $id.',%')))
            ->when($this->blockerFilter === 'yes', fn ($q) => $q->whereNotNull('blockers')->where('blockers', '!=', ''))
            ->when($this->blockerFilter === 'no', fn ($q) => $q->where(fn ($q) => $q->whereNull('blockers')->orWhere('blockers', '')))
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->where('summary', 'like', "%{$term}%")->orWhere('plan', 'like', "%{$term}%")->orWhere('blockers', 'like', "%{$term}%")
                ->orWhereHas('employee', fn ($e) => $e->where('name', 'like', "%{$term}%")->orWhere('employee_code', 'like', "%{$term}%"))));
        $periods = [
            'today' => fn ($q) => $q->whereDate('work_date', today()),
            'yesterday' => fn ($q) => $q->whereDate('work_date', today()->subDay()),
            'week' => fn ($q) => $q->whereDate('work_date', '>=', today()->startOfWeek())->whereDate('work_date', '<=', today()),
        ];
        $statuses = $filtered()->with(['employee.department'])
            ->when($this->date, fn ($q, $d) => $q->whereDate('work_date', $d))
            ->when(! $this->date && isset($periods[$this->period]), fn ($q) => $periods[$this->period]($q))
            ->when($this->sortBy === 'oldest', fn ($q) => $q->orderBy('work_date')->orderBy('submitted_at'))
            ->when($this->sortBy === 'name', fn ($q) => $q->orderBy(AttendanceEmployee::select('name')->whereColumn('attendance_employees.id', 'daily_work_statuses.employee_id'))->latest('work_date'))
            ->when(! in_array($this->sortBy, ['oldest', 'name'], true), fn ($q) => $q->latest('work_date')->orderByDesc('submitted_at')->latest('id'))
            ->paginate(10);

        $missing = collect();
        $expected = 0;
        // "Missing" on the company's working days (Saturdays too where they are worked): checked in, no status yet.
        if ($canSeeTeam && \App\Support\WorkCalendar::isWorkday($day, app(\App\Services\PolicyConfigurationService::class)->forEmployee(null, 'calendar.working_days', $day, ['days' => [1, 2, 3, 4, 5]]))) {
            $submitted = DailyWorkStatus::whereDate('work_date', $day)->pluck('employee_id');
            $people = AttendanceEmployee::with('department')->where('is_active', true)->when($scope, fn ($q) => $q->whereIn('id', $scope->all() ?: [0]))
                ->whereHas('sessions', fn ($q) => $q->whereDate('work_date', $day));
            // People whose daily status is not compulsory (Who must write it) are never "missing".
            $optional = (clone $people)->whereNotIn('id', $submitted)->get()->reject(fn ($p) => \App\Support\DailyStatusRule::required($p, $day))->pluck('id');
            $expected = (clone $people)->count() - $optional->count() + DailyWorkStatus::whereDate('work_date', $day)->when($scope, fn ($q) => $q->whereIn('employee_id', $scope->all() ?: [0]))
                ->whereNotIn('employee_id', (clone $people)->pluck('id'))->count();
            $missing = $people->whereNotIn('id', $submitted)->whereNotIn('id', $optional->all())->inCodeOrder()->get();
        }
        $onDay = fn (string $d) => $filtered()->whereDate('work_date', $d);
        $submittedOnDay = $onDay($day)->count();
        $blockers = $canSeeTeam ? $onDay($day)->with('employee')->whereNotNull('blockers')->where('blockers', '!=', '')->latest('submitted_at')->get() : collect();

        $detail = null;
        if ($this->viewing) {
            $detail = rescue(fn () => $this->details($this->visibleStatus($this->viewing)), null, false);
            if (! $detail) $this->viewing = null;
        }

        $rules = $this->showRules && $this->canSetRules() ? [
            'default' => \App\Support\DailyStatusRule::mode(null),
            'people' => $this->rulePeople()->map(fn ($e) => ['employee' => $e, 'mode' => \App\Support\DailyStatusRule::mode($e), 'own' => \App\Support\DailyStatusRule::hasOwnChoice($e)]),
        ] : null;

        return $this->page('livewire.work.daily-status', [
            'rules' => $rules,
            'canSetRules' => $this->canSetRules(),
            'statuses' => $statuses,
            'own' => $own,
            'canSeeTeam' => $canSeeTeam,
            'missing' => $missing,
            'day' => \Illuminate\Support\Carbon::parse($day),
            'cards' => [
                'updates' => $statuses->total(),
                'people' => $canSeeTeam ? ($scope ? $scope->count() : AttendanceEmployee::where('is_active', true)->count()) : 1,
                'submitted' => $submittedOnDay,
                'expected' => max($expected, $submittedOnDay),
                'submittedBefore' => $onDay(\Illuminate\Support\Carbon::parse($day)->subDay()->toDateString())->count(),
                'missing' => $missing->count(),
                'blockers' => $blockers->count(),
            ],
            'needsAttention' => $blockers->take(5),
            'periodCounts' => ['all' => $filtered()->when($this->date, fn ($q, $d) => $q->whereDate('work_date', $d))->count()] + collect($periods)->map(fn ($apply) => $apply($filtered())->count())->all(),
            'todayTasks' => $own ? Task::where('assignee_id', $own->id)->where(fn ($q) => $q->whereDate('completed_at', today())->orWhere('status', 'in_progress'))->get() : collect(),
            'byProject' => $own ? $this->todayByProject($own) : collect(),
            'projects' => $canSeeTeam ? Project::orderBy('name')->get(['id', 'name']) : collect(),
            'departments' => $canSeeTeam ? \App\Models\Department::orderBy('name')->get(['id', 'name']) : collect(),
            'people' => $canSeeTeam ? AttendanceEmployee::where('is_active', true)->when($scope, fn ($q) => $q->whereIn('id', $scope->all() ?: [0]))->orderBy('name')->get(['id', 'name']) : collect(),
            'detail' => $detail,
            'savedToday' => $own ? DailyWorkStatus::where('employee_id', $own->id)->whereDate('work_date', today())->first() : null,
            'projectNames' => Project::pluck('name', 'id'),
        ], 'Daily Work Status');
    }
}
