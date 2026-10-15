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

    public function render(ApprovalInbox $inbox, AttendanceService $attendance, CompanyService $company)
    {
        $user = $this->user();
        $profile = $this->ownEmployee();
        $orgWide = $user->canAny(['hr.manage', 'employees.manage']);
        $employeeQuery = AttendanceEmployee::query()->where('is_active', true);
        if (! $orgWide) $employeeQuery->whereIn('id', $this->teamEmployeeIds()->all() ?: [0]);

        $employees = $employeeQuery->get();
        $employeeIds = $employees->pluck('id');
        $today = today();
        $sessions = AttendanceSession::with('employee')->whereIn('employee_id', $employeeIds)->whereDate('work_date', $today)->latest('check_in')->get();
        $presentIds = $sessions->pluck('employee_id')->unique();
        $onLeaveIds = LeaveRequest::whereIn('employee_id', $employeeIds)->where('status', 'approved')
            ->whereDate('starts_on', '<=', $today)->whereDate('ends_on', '>=', $today)->pluck('employee_id')->unique()->diff($presentIds);
        $tasks = Task::with('assignee')->whereIn('assignee_id', $employeeIds)->get();
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
        $projectCounts = $orgWide || $profile ? $work->projectCounts($orgWide ? \App\Models\Project::query() : $work->projectsInvolving($teamIds)) : null;

        $mySession = $profile ? $attendance->today($profile) : null;
        $myTasks = $profile ? Task::where('assignee_id', $profile->id)->where('status', '!=', 'completed')->orderByRaw('due_on IS NULL')->orderBy('due_on')->limit(5)->get() : collect();
        $runningTimer = $profile ? TaskTimeEntry::with('task')->where('employee_id', $profile->id)->where('status', 'running')->first() : null;
        $myBalances = $profile ? $profile->leaveBalances()->with('leaveType')->where('year', \App\Support\LeaveYear::current())->get()
            ->filter(fn ($balance) => $balance->leaveType?->paid)->map(fn ($balance) => ['type' => $balance->leaveType?->name, 'remaining' => app(LeaveAccrualService::class)->balance($profile, $balance->leaveType, \App\Support\LeaveYear::current())['remaining']]) : collect();

        return $this->page('livewire.hr.dashboard', [
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
            'inbox' => $inbox,
            'mySession' => $mySession,
            'myWorked' => $mySession ? $attendance->workedSeconds($mySession) : 0,
            'myTasks' => $myTasks,
            'runningTimer' => $runningTimer,
            'myBalances' => $myBalances,
            'canTeamAttendance' => Navigation::canAccess($user, 'attendance.dashboard'),
            'announcements' => Announcement::visibleTo($profile)->orderByDesc('is_pinned')->latest('published_at')->limit(3)->get(),
            'celebrations' => $company->celebrations(7)->take(6),
            'nextHoliday' => $company->holidays($profile, $today->year)->merge($company->holidays($profile, $today->year + 1))->first(fn ($h) => $h['date']->gte($today)),
            'probationDue' => $orgWide ? AttendanceEmployee::where('is_active', true)->whereNull('confirmed_on')->whereNotNull('probation_ends_on')->whereDate('probation_ends_on', '<=', $today->copy()->addDays(30))->orderBy('probation_ends_on')->limit(5)->get() : collect(),
            'myTraining' => $profile ? TrainingAssignment::with('course')->where('employee_id', $profile->id)->where('status', '!=', 'completed')->orderByRaw('due_on IS NULL')->orderBy('due_on')->limit(3)->get() : collect(),
        ], 'Dashboard');
    }
}
