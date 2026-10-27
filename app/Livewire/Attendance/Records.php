<?php

namespace App\Livewire\Attendance;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Services\AttendanceService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Organisation-wide attendance sessions over a date range (HR). */
class Records extends Component
{
    use InteractsWithHr, WithPagination;

    public const PER_PAGE = [10, 25, 50, 100];

    #[Url] public string $from = '';
    #[Url] public string $to = '';
    #[Url] public ?int $employee = null;
    #[Url] public ?int $department = null;
    #[Url] public string $status = '';
    /** One employee chosen: list | calendar (the month of From). */
    #[Url] public string $view = 'list';
    #[Url] public int $perPage = 25;
    /** The session open in the Screen activity details panel, and its tab. */
    public ?int $detail = null;
    public string $detailTab = 'timeline';

    public function mount(): void
    {
        $this->from = $this->from ?: now()->startOfMonth()->toDateString();
        $this->to = $this->to ?: today()->toDateString();
    }

    public function updated(string $name): void
    {
        if (! in_array($name, ['detail', 'detailTab'], true)) $this->resetPage();
    }

    /** "Search": the filters are applied when this is pressed (and when the page loads from a link). */
    public function search(): void
    {
        $this->resetPage();
    }

    /** "Reset": back to this month, everyone, any session. */
    public function resetFilters(): void
    {
        $this->from = now()->startOfMonth()->toDateString();
        $this->to = today()->toDateString();
        $this->employee = null;
        $this->department = null;
        $this->status = '';
        $this->view = 'list';
        $this->resetPage();
    }

    /** Ex-employee: the day list stops at the end of the month they left in (no rows for the months after). */
    private function listEnd(AttendanceEmployee $employee): Carbon
    {
        $to = Carbon::parse($this->to);
        if ($employee->exit_date) $to = $to->min(Carbon::parse($employee->exit_date)->endOfMonth()->startOfDay())->max(Carbon::parse($this->from)->subDay());

        return $to;
    }

    public function openDetail(int $id, ?string $tab = null): void
    {
        $this->detail = AttendanceSession::whereKey($id)->exists() ? $id : null;
        if ($tab !== null) $this->detailTab = in_array($tab, ['timeline', 'apps', 'screenshots', 'details'], true) ? $tab : 'timeline';
    }

    public function closeDetail(): void
    {
        $this->detail = null;
    }

    /**
     * The Screen activity details panel: timeline, the day's apps and websites, screenshots, and the
     * same person's previous / next attendance day for the arrows. Apps and screenshots follow the
     * monitoring permissions (the same people who see them under Monitoring).
     */
    private function activity(AttendanceSession $session): array
    {
        $user = $this->user();
        $employee = $session->employee;
        $canMonitor = $user->canAny(['monitoring.view', 'monitoring.manage']) || \App\Support\Access::isAdmin($user);
        $day = $session->work_date->toDateString();
        $apps = $canMonitor && $employee ? \App\Models\AppUsage::where('employee_id', $employee->id)->whereDate('work_date', $day)->orderByDesc('seconds')->get(['app_name', 'seconds']) : collect();
        $titles = $canMonitor && $employee ? \App\Models\ActivityTitle::where('employee_id', $employee->id)->whereDate('work_date', $day)->orderByDesc('seconds')->limit(40)->get(['app_name', 'title', 'seconds']) : collect();
        $captures = app(\App\Services\ScreenCaptureService::class);
        $shots = $employee ? \App\Models\Screenshot::where('employee_id', $employee->id)
            ->where(fn ($q) => $q->where('attendance_session_id', $session->id)->orWhereDate('captured_at', $day))
            ->orderBy('captured_at')->limit(120)->get()
            ->filter(fn ($shot) => $captures->canView($user, $shot) && ! $captures->isExpired($shot))->values() : collect();
        $neighbour = fn (string $op, string $dir) => AttendanceSession::where('employee_id', $session->employee_id)->whereDate('work_date', $op, $day)->orderBy('work_date', $dir)->value('id');

        return [
            'timeline' => \App\Support\ActivityTimeline::for($session),
            'apps' => $apps,
            'titles' => $titles,
            'shots' => $shots,
            'canMonitor' => $canMonitor,
            'prev' => $neighbour('<', 'desc'),
            'next' => $neighbour('>', 'asc'),
        ];
    }

    /**
     * Today's numbers for the cards (the Department filter applies), each compared with the day before:
     * people checked in, late arrivals, time worked, and who is working right now.
     */
    private function todaySummary(AttendanceService $attendance): array
    {
        $scope = fn ($q) => $q->when($this->department, fn ($q, $id) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $id)));
        $day = function (Carbon $date) use ($scope, $attendance) {
            $sessions = AttendanceSession::with('employee')->whereDate('work_date', $date->toDateString())->whereNotNull('check_in')->tap($scope)->get();

            return [
                'present' => $sessions->pluck('employee_id')->unique()->count(),
                'late' => $sessions->filter(fn ($s) => $s->employee && $attendance->isLate($s))->pluck('employee_id')->unique()->count(),
                'worked' => (int) $sessions->sum(fn ($s) => $attendance->workedSeconds($s)),
                'working' => $sessions->whereNull('check_out')->where('status', 'working')->pluck('employee_id')->unique()->count(),
                'on_break' => $sessions->whereNull('check_out')->where('status', 'on_break')->pluck('employee_id')->unique()->count(),
            ];
        };
        $today = $day(today());
        $before = $day(today()->subDay());

        return [
            ...$today,
            'employees' => AttendanceEmployee::where('is_active', true)->when($this->department, fn ($q, $id) => $q->where('department_id', $id))->count(),
            'present_change' => $today['present'] - $before['present'],
            'late_change' => $today['late'] - $before['late'],
            'worked_change' => $before['worked'] > 0 ? (int) round(($today['worked'] - $before['worked']) * 100 / $before['worked']) : null,
        ];
    }

    public function render(AttendanceService $attendance)
    {
        if (! strtotime($this->from)) $this->from = now()->startOfMonth()->toDateString();
        if (! strtotime($this->to) || $this->to < $this->from) $this->to = max($this->from, today()->toDateString());
        if (! in_array($this->perPage, self::PER_PAGE, true)) $this->perPage = 25;
        $sessions = AttendanceSession::with(['employee.department', 'employee.user.roles', 'device', 'checkInOffice', 'checkOutOffice'])
            // "View activity (N)": state changes, breaks and "I was working" answers recorded that day.
            ->withCount(['events as activity_count' => fn ($q) => $q->whereIn('event_type', ['screen_state', 'break_start', 'away_worked'])])
            ->whereDate('work_date', '>=', $this->from)->whereDate('work_date', '<=', $this->to)
            ->when($this->employee, fn ($q, $id) => $q->where('employee_id', $id))
            ->when($this->department, fn ($q, $id) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $id)))
            ->when($this->status, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('work_date')
            // Within a day: employee-number order (EMP-01, EMP-02 … EMP-10).
            ->orderBy(AttendanceEmployee::selectRaw('LENGTH(employee_code)')->whereColumn('attendance_employees.id', 'attendance_sessions.employee_id'))
            ->orderBy(AttendanceEmployee::select('employee_code')->whereColumn('attendance_employees.id', 'attendance_sessions.employee_id'))->paginate($this->perPage);

        // One employee chosen: list every day of the range (leave, weekly off / 3rd Saturday, absent too), newest first.
        $dayEmployee = $this->employee ? AttendanceEmployee::with('department')->find($this->employee) : null;
        $dayRows = $dayEmployee && ! $this->status && Carbon::parse($this->from)->diffInDays($this->to) <= 400
            ? $attendance->days($dayEmployee, Carbon::parse($this->from), $this->listEnd($dayEmployee))->reverse()->values()->all()
            : null;

        $detail = $this->detail ? AttendanceSession::with(['employee.department', 'employee.manager', 'device', 'checkInOffice', 'checkOutOffice', 'events' => fn ($q) => $q->orderBy('event_time')])->find($this->detail) : null;
        $activity = $detail ? $this->activity($detail) : null;

        return $this->page('livewire.attendance.records', [
            'sessions' => $sessions,
            'dayRows' => $dayRows,
            'dayEmployee' => $dayEmployee,
            'calendarMonth' => $calMonth = Carbon::parse($this->from)->startOfMonth(),
            'calendarDays' => $dayEmployee && $this->view === 'calendar' ? $attendance->days($dayEmployee, $calMonth->copy(), $calMonth->copy()->endOfMonth()) : collect(),
            'attendance' => $attendance,
            // Current staff only: no ex-employees and no trainees (one already chosen, e.g. from a link, stays listed).
            'employees' => AttendanceEmployee::where(fn ($q) => $q
                    ->where(fn ($q) => $q->where('is_active', true)->onPayroll()
                        ->where(fn ($q) => $q->whereNull('employment_type')->orWhere('employment_type', '!=', \App\Services\EmployeeService::EX_EMPLOYEE))
                        ->where(fn ($q) => $q->whereNull('exit_date')->orWhereDate('exit_date', '>=', today())))
                    ->when($this->employee, fn ($q, $id) => $q->orWhere('id', $id)))
                ->inCodeOrder()->get(['id', 'name', 'employee_code']),
            'departments' => Department::orderBy('name')->get(),
            'summary' => $this->todaySummary($attendance),
            'detailSession' => $detail,
            'activity' => $activity,
        ], 'Attendance');
    }
}
