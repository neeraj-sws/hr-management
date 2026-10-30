<?php

namespace App\Livewire\Hr;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\Announcement;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\AuditLog;
use App\Models\DailyWorkStatus;
use App\Models\LeaveRequest;
use App\Models\PayrollRun;
use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Models\TrainingAssignment;
use App\Services\ApprovalInbox;
use App\Services\AttendanceService;
use App\Services\CompanyService;
use App\Services\LeaveAccrualService;
use App\Support\Navigation;
use Livewire\Component;

class Dashboard extends Component
{
    use InteractsWithHr;

    /** Cards that open a details list when clicked. */
    public const DETAILS = [
        'employees' => 'Active employees', 'checked_in' => 'Checked in today', 'checked_out' => 'Checked out today',
        'on_break' => 'On lunch / break now', 'absent' => 'Absent today', 'on_leave' => 'On leave today', 'late' => 'Late arrivals today',
        'projects' => 'Active projects', 'open_tasks' => 'Open tasks', 'todays_tasks' => "Today's tasks",
        'approvals' => 'Pending approvals', 'payroll' => 'Payroll in progress',
    ];

    /** The card whose details popup is open ('' = none). */
    public string $detail = '';

    public function openDetail(string $key): void
    {
        $this->detail = array_key_exists($key, self::DETAILS) ? $key : '';
    }

    public function closeModal(): void
    {
        $this->detail = '';
    }

    /**
     * The rows of one card's popup: ['headers' => [...], 'rows' => [[cell, ...], ...]] where a cell is
     * [main text, optional small text]; 'links' holds an optional page per row.
     */
    private function detailRows(string $key, array $ctx): array
    {
        $att = app(AttendanceService::class);
        $person = fn ($e) => [$e?->name ?? '—', trim(($e?->employee_code ?? '').($e?->department ? ' · '.$e->department->name : ''), ' ·')];
        $time = fn ($t) => $t?->format('h:i A') ?? '—';
        $sessionsBy = $ctx['sessions']->groupBy('employee_id');
        $first = fn ($id) => $sessionsBy->get($id)?->sortBy('check_in')->first();
        $last = fn ($id) => $sessionsBy->get($id)?->sortByDesc('check_in')->first();
        $worked = fn ($id) => \App\Support\Duration::hms((int) collect($sessionsBy->get($id, []))->sum(fn ($s) => $att->workedSeconds($s)));
        $employees = $ctx['employees']->keyBy('id');

        return match ($key) {
            'employees' => ['headers' => ['Employee', 'Designation', 'Manager', 'Joined'], 'rows' => $ctx['employees']->sortBy(fn ($e) => sprintf('%04d%s', strlen($e->employee_code), $e->employee_code))->map(fn ($e) => [$person($e), [$e->designation ?: '—', \App\Services\EmployeeService::EMPLOYMENT_TYPES[$e->employment_type] ?? null], [$e->manager?->name ?? '—'], [$e->joined_at?->format('d M Y') ?? '—']])->values()->all(),
                'links' => $ctx['employees']->sortBy(fn ($e) => sprintf('%04d%s', strlen($e->employee_code), $e->employee_code))->map(fn ($e) => route('people.employees.show', $e))->values()->all()],
            'checked_in' => ['headers' => ['Employee', 'Designation', 'Check in', 'Status'], 'rows' => $ctx['presentIds']->map(fn ($id) => [$person($employees[$id] ?? null), [$employees[$id]?->designation ?: '—'], [$time($first($id)?->check_in), $first($id) && $att->isLate($first($id)) ? 'late by '.\App\Support\Duration::fromMinutes($att->lateMinutes($first($id))) : null], [str($last($id)?->status ?? '')->replace('_', ' ')->ucfirst()->toString(), $last($id)?->check_out ? 'out '.$time($last($id)->check_out) : 'still in']])->sortBy(fn ($r) => sprintf('%04d%s', strlen(strtok((string) $r[0][1], ' ')), $r[0][1]))->values()->all()],
            'checked_out' => ['headers' => ['Employee', 'Check in', 'Check out', 'Worked'], 'rows' => $ctx['sessions']->whereNotNull('check_out')->pluck('employee_id')->unique()->map(fn ($id) => [$person($employees[$id] ?? null), [$time($first($id)?->check_in)], [$time($last($id)?->check_out)], [$worked($id)]])->sortBy(fn ($r) => sprintf('%04d%s', strlen(strtok((string) $r[0][1], ' ')), $r[0][1]))->values()->all()],
            'on_break' => ['headers' => ['Employee', 'Designation', 'Check in', 'On break since'], 'rows' => $ctx['sessions']->where('status', 'on_break')->map(fn ($s) => [$person($s->employee), [$s->employee?->designation ?: '—'], [$time($s->check_in)], [($since = $s->events()->where('event_type', 'break_start')->latest('event_time')->value('event_time')) ? \Illuminate\Support\Carbon::parse($since)->format('h:i A') : '—', $since ? \Illuminate\Support\Carbon::parse($since)->diffForHumans(null, true).' ago' : null]])->sortBy(fn ($r) => sprintf('%04d%s', strlen(strtok((string) $r[0][1], ' ')), $r[0][1]))->values()->all()],
            'absent' => ['headers' => ['Employee', 'Designation', 'Manager', 'Phone'], 'rows' => $ctx['employees']->reject(fn ($e) => $ctx['presentIds']->contains($e->id) || $ctx['onLeaveIds']->contains($e->id))->sortBy(fn ($e) => sprintf('%04d%s', strlen($e->employee_code), $e->employee_code))->map(fn ($e) => [$person($e), [$e->designation ?: '—'], [$e->manager?->name ?? '—'], [$e->phone ?: '—']])->values()->all()],
            'on_leave' => ['headers' => ['Employee', 'Designation', 'Leave type', 'Duration'], 'rows' => $ctx['onLeave']->map(fn ($l) => [$person($l->employee), [$l->employee?->designation ?: '—'], [$l->typeName() ?? '—', \App\Models\LeaveRequest::DURATION_LABELS[$l->duration] ?? null],
                [$l->starts_on->format('d M').($l->ends_on->isSameDay($l->starts_on) ? '' : ' – '.$l->ends_on->format('d M')), rtrim(rtrim(number_format((float) $l->days, 2), '0'), '.').' '.((float) $l->days == 1 ? 'day' : 'days').' · back '.$l->ends_on->copy()->addDay()->format('D d M')]])->values()->all()],
            'late' => ['headers' => ['Employee', 'Designation', 'Check in', 'Late by'], 'rows' => $ctx['sessions']->filter(fn ($s) => $att->isLate($s))->unique('employee_id')->map(fn ($s) => [$person($s->employee), [$s->employee?->designation ?: '—'], [$time($s->check_in)], [\App\Support\Duration::fromMinutes($att->lateMinutes($s))]])->sortBy(fn ($r) => sprintf('%04d%s', strlen(strtok((string) $r[0][1], ' ')), $r[0][1]))->values()->all()],
            'projects' => ['headers' => ['Project', 'Manager', 'Dates', 'Open tasks'], 'rows' => ($projects = $ctx['projectQuery']?->where('status', 'active')->with('manager')->withCount(['tasks as open_tasks' => fn ($q) => $q->where('status', '!=', 'completed')])->orderBy('name')->get() ?? collect())->map(fn ($p) => [[$p->name, $p->client_name], [$p->manager?->name ?? '—'], [$p->starts_on?->format('d M Y') ?? '—', $p->ends_on ? 'ends '.$p->ends_on->format('d M Y') : null], [(string) $p->open_tasks]])->values()->all()],
            'open_tasks', 'todays_tasks' => ['headers' => ['Task', 'Assigned to', 'Due', 'Status'], 'rows' => $ctx['tasks']->filter(fn (Task $t) => $key === 'open_tasks' ? $t->status !== 'completed' : (($t->status === 'completed' && $t->completed_at?->isToday()) || ($t->status !== 'completed' && $t->due_on && $t->due_on->lte(today()))))
                ->sortBy(fn (Task $t) => $t->due_on?->toDateString() ?? '9999')->map(fn (Task $t) => [[$t->title, $t->project?->name], [$t->assignee?->name ?? '—'], [$t->due_on?->format('d M Y') ?? 'No due date', $t->isOverdue() ? $t->due_on->diffInDays(today()).' days late' : null], [$t->isOverdue() ? 'Overdue' : str($t->status)->replace('_', ' ')->ucfirst()->toString(), $t->completed_at?->isToday() ? 'done '.$t->completed_at->format('h:i A') : null]])->values()->all()],
            'approvals' => ['headers' => ['Type', 'Request', 'Submitted'], 'rows' => $ctx['inbox']->pending($ctx['user'])->map(fn ($a) => [[$ctx['inbox']->typeLabel($a->approvable_type)], [$ctx['inbox']->summary($a)], [$a->created_at->diffForHumans()]])->values()->all(), 'more' => [route('approvals.pending'), 'Open Pending Approvals']],
            'payroll' => ['headers' => ['Period', 'Status', 'Employees', 'Net pay'], 'rows' => PayrollRun::withCount('items')->withSum('items', 'net_amount')->whereIn('status', ['draft', 'approved'])->latest('period_starts_on')->get()->map(fn ($r) => [[$r->period_starts_on->format('d M').' – '.$r->period_ends_on->format('d M Y')], [ucfirst($r->status), $r->needs_recalculation ? 'needs recalculation' : null], [(string) $r->items_count], [number_format((float) $r->items_sum_net_amount, 2)]])->values()->all(), 'more' => [route('payroll.runs'), 'Open Payroll']],
            default => ['headers' => [], 'rows' => []],
        };
    }

    public function render(ApprovalInbox $inbox, AttendanceService $attendance, CompanyService $company)
    {
        $user = $this->user();
        $profile = $this->ownEmployee();
        $orgWide = $user->canAny(['hr.manage', 'employees.manage']);
        $employeeQuery = AttendanceEmployee::query()->where('is_active', true);
        if (! $orgWide) $employeeQuery->whereIn('id', $this->teamEmployeeIds()->all() ?: [0]);

        $employees = $employeeQuery->with(['department', 'manager'])->get();
        $employeeIds = $employees->pluck('id');
        $today = today();
        $sessions = AttendanceSession::with('employee')->whereIn('employee_id', $employeeIds)->whereDate('work_date', $today)->latest('check_in')->get();
        $presentIds = $sessions->pluck('employee_id')->unique();
        $leavesToday = LeaveRequest::with(['employee.department', 'leaveType'])->whereIn('employee_id', $employeeIds)->where('status', 'approved')
            ->whereDate('starts_on', '<=', $today)->whereDate('ends_on', '>=', $today)->get();
        $onLeaveIds = $leavesToday->pluck('employee_id')->unique()->diff($presentIds);
        $onLeave = $leavesToday->whereIn('employee_id', $onLeaveIds->all())->unique('employee_id')->sortBy(fn ($leave) => sprintf('%04d%s', strlen((string) $leave->employee?->employee_code), $leave->employee?->employee_code))->values();
        $tasks = Task::with(['assignee', 'project'])->whereIn('assignee_id', $employeeIds)->get();
        $completedToday = $tasks->filter(fn (Task $task) => $task->status === 'completed' && $task->completed_at?->isToday())->count();
        // Today's work = tasks finished today + open tasks that are due today or already overdue.
        // The percentage is how much of today's work is done.
        $dueOpen = $tasks->filter(fn (Task $task) => $task->status !== 'completed' && $task->due_on && $task->due_on->lte($today))->count();
        $todaysTasks = $completedToday + $dueOpen;
        $taskCompletionPct = $todaysTasks > 0 ? (int) round($completedToday / $todaysTasks * 100) : 0;

        // Projects: the whole company for HR/admin, the team's projects for a manager, otherwise the person's own.
        $work = app(\App\Services\WorkService::class);
        $teamIds = $this->teamEmployeeIds();
        $projectScope = $orgWide ? 'company' : ($teamIds->count() > 1 ? 'team' : 'mine');
        $projectQuery = $orgWide || $profile ? ($orgWide ? \App\Models\Project::query() : $work->projectsInvolving($teamIds)) : null;
        $projectCounts = $projectQuery ? $work->projectCounts(clone $projectQuery) : null;

        $mySession = $profile ? $attendance->today($profile) : null;
        $myTasks = $profile ? Task::where('assignee_id', $profile->id)->where('status', '!=', 'completed')->orderByRaw('due_on IS NULL')->orderBy('due_on')->limit(5)->get() : collect();
        $runningTimer = $profile ? TaskTimeEntry::with('task')->where('employee_id', $profile->id)->where('status', 'running')->first() : null;
        $myBalances = $profile ? $profile->leaveBalances()->with('leaveType')->where('year', \App\Support\LeaveYear::current())->get()
            ->filter(fn ($balance) => $balance->leaveType?->paid)->map(fn ($balance) => ['type' => $balance->leaveType?->name, 'remaining' => app(LeaveAccrualService::class)->balance($profile, $balance->leaveType, \App\Support\LeaveYear::current())['remaining']]) : collect();

        // Monitoring transparency: the employee always sees on their dashboard when screen capture is on.
        $captures = app(\App\Services\ScreenCaptureService::class);
        $capturePolicy = $profile ? $captures->policy($profile) : null;
        $capture = $capturePolicy && $capturePolicy['enabled'] ? ['terms' => $captures->describe($capturePolicy), 'acknowledged' => $captures->isAcknowledged($this->user(), $capturePolicy)] : null;

        return $this->page('livewire.hr.dashboard', [
            'capture' => $capture,
            'details' => $this->detail === '' ? null : ['title' => self::DETAILS[$this->detail], ...$this->detailRows($this->detail, [
                'employees' => $employees, 'sessions' => $sessions, 'presentIds' => $presentIds, 'onLeaveIds' => $onLeaveIds, 'onLeave' => $onLeave,
                'tasks' => $tasks, 'projectQuery' => $projectQuery ? clone $projectQuery : null, 'inbox' => $inbox, 'user' => $user,
            ])],
            'orgWide' => $orgWide,
            'profile' => $profile,
            'metrics' => [
                'employees' => $employees->count(),
                'present' => $presentIds->count(),
                'absent' => max(0, $employees->count() - $presentIds->count() - $onLeaveIds->count()),
                'on_leave' => $onLeaveIds->count(),
                'late' => $sessions->filter(fn ($session) => $attendance->isLate($session))->count(),
                'on_break' => $sessions->where('status', 'on_break')->count(),
                'working' => $sessions->where('status', 'working')->count(),
                'completed' => $sessions->where('status', 'completed')->count(),
                'checked_in' => $presentIds->count(),
                'still_in' => $sessions->whereNull('check_out')->pluck('employee_id')->unique()->count(),
                'checked_out' => $sessions->whereNotNull('check_out')->pluck('employee_id')->unique()->count(),
                'todays_tasks' => $todaysTasks,
                'active_tasks' => $tasks->where('status', '!=', 'completed')->count(),
                'overdue_tasks' => $tasks->filter(fn (Task $task) => $task->isOverdue())->count(),
                'completed_today' => $completedToday,
                'task_completion_pct' => $taskCompletionPct,
                'pending_approvals' => $user->canAny(\App\Support\Access::APPROVER_PERMISSIONS) ? $inbox->pendingCount($user) : null,
                'payroll_pending' => $user->canAny(['payroll.manage', 'payroll.approve']) ? PayrollRun::whereIn('status', ['draft', 'approved'])->count() : null,
            ],
            'projectCounts' => $projectCounts,
            'projectScope' => $projectScope,
            'recentAttendance' => $sessions->take(6),
            'overdueTasks' => $tasks->filter(fn (Task $task) => $task->isOverdue())->sortBy('due_on')->take(5),
            'recentStatuses' => DailyWorkStatus::with('employee')->whereIn('employee_id', $employeeIds)->latest('work_date')->limit(5)->get(),
            'recentAudit' => $user->can('audit.view') ? AuditLog::with('actor')->latest('id')->limit(6)->get() : collect(),
            'pendingApprovals' => $user->canAny(\App\Support\Access::APPROVER_PERMISSIONS) ? $inbox->pending($user)->take(5) : collect(),
            // Leave nobody has decided yet (the whole company for HR / admin, the team for a manager), oldest first.
            'undecidedLeave' => ($orgWide || $user->canAny(['leave.manage', 'leave.approve'])) ? LeaveRequest::with(['employee', 'leaveType'])->where('status', 'pending')
                ->whereIn('employee_id', $orgWide ? AttendanceEmployee::pluck('id') : $employeeIds->reject(fn ($id) => $id === $profile?->id))->oldest()->get() : collect(),
            'inbox' => $inbox,
            'mySession' => $mySession,
            'myWorked' => $mySession ? $attendance->workedSeconds($mySession) : 0,
            'myTasks' => $myTasks,
            'runningTimer' => $runningTimer,
            'myBalances' => $myBalances,
            'canTeamAttendance' => Navigation::canAccess($user, 'attendance.dashboard'),
            'announcements' => Announcement::visibleTo($profile)->orderByDesc('is_pinned')->latest('published_at')->limit(3)->get(),
            'celebrations' => $company->celebrations(7)->take(6),
            'nextSpecialOff' => collect(\App\Support\WorkCalendar::upcomingSpecialOffs(app(\App\Services\PolicyConfigurationService::class)->forEmployee($profile, 'calendar.working_days', $today->toDateString(), ['days' => [1, 2, 3, 4, 5], 'holidays' => []]), $today, 45))->first(),
            'nextHoliday' => $company->holidays($profile, $today->year)->merge($company->holidays($profile, $today->year + 1))->first(fn ($h) => $h['date']->gte($today)),
            'probationDue' => $orgWide ? AttendanceEmployee::where('is_active', true)->whereNull('confirmed_on')->whereNotNull('probation_ends_on')->whereDate('probation_ends_on', '<=', $today->copy()->addDays(30))->orderBy('probation_ends_on')->limit(5)->get() : collect(),
            'myTraining' => $profile ? TrainingAssignment::with('course')->where('employee_id', $profile->id)->where('status', '!=', 'completed')->orderByRaw('due_on IS NULL')->orderBy('due_on')->limit(3)->get() : collect(),
        ], 'Dashboard');
    }
}
