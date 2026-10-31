<?php

namespace App\Livewire\Attendance;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\LeaveRequest;
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
    /** Change time (HR / admin): one day of one employee, with a compulsory reason. */
    public ?int $editEmployee = null;
    public string $editDate = '';
    public string $editIn = '';
    public string $editOut = '';
    public string $editBreak = '';
    public string $editReason = '';

    public function mount(): void
    {
        // Opens on today; pick other dates to see them (the cards follow).
        $this->from = $this->from ?: today()->toDateString();
        $this->to = $this->to ?: $this->from;
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

    /** "Reset": back to today, everyone, any session. */
    public function resetFilters(): void
    {
        $this->from = today()->toDateString();
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

    private function canEditTime(): bool
    {
        return $this->user()->canAny(['attendance.correct', 'hr.manage', 'employees.manage']) || \App\Support\Access::isAdmin($this->user());
    }

    /** Opens "Change time" for a day (filled in with the day's times when there is attendance). */
    public function openEdit(int $employeeId, string $date): void
    {
        abort_unless($this->canEditTime(), 403);
        $session = AttendanceSession::where('employee_id', $employeeId)->whereDate('work_date', $date)->latest('id')->first();
        $this->fill(['editEmployee' => $employeeId, 'editDate' => Carbon::parse($date)->toDateString(), 'editIn' => (string) $session?->check_in?->format('H:i'), 'editOut' => (string) $session?->check_out?->format('H:i'),
            'editBreak' => $session ? (string) intdiv((int) $session->break_seconds, 60) : '', 'editReason' => '']);
        $this->resetValidation();
    }

    public function closeEdit(): void
    {
        $this->editEmployee = null;
    }

    public function saveEdit(\App\Services\AttendanceCorrectionService $corrections): void
    {
        abort_unless($this->canEditTime() && $this->editEmployee, 403);
        \App\Services\ImpersonationService::block();
        $this->validate([
            'editIn' => ['nullable', 'date_format:H:i'], 'editOut' => ['nullable', 'date_format:H:i'],
            'editBreak' => ['nullable', 'integer', 'min:0', 'max:720'], 'editReason' => ['required', 'string', 'min:5', 'max:1000'],
        ], ['editReason.required' => 'Write why the time is changed.'], ['editIn' => 'check-in', 'editOut' => 'check-out', 'editBreak' => 'break minutes', 'editReason' => 'reason']);
        $employee = AttendanceEmployee::findOrFail($this->editEmployee);
        try {
            $corrections->adminChange($employee, $this->editDate, $this->editIn ?: null, $this->editOut ?: null, $this->editBreak === '' ? null : (int) $this->editBreak, $this->editReason, $this->user());
        } catch (\Illuminate\Validation\ValidationException $e) {
            // The service names fields like the employee form (checkIn, workDate ...): show them on this form.
            $map = ['checkIn' => 'editIn', 'checkOut' => 'editOut', 'breakMinutes' => 'editBreak', 'workDate' => 'editReason'];
            foreach ($e->errors() as $field => $messages) $this->addError($map[$field] ?? $field, $messages[0]);

            return;
        }
        $this->toast("{$employee->name}: time for ".Carbon::parse($this->editDate)->format('d M')." changed. They were told; salary follows on the next payroll calculation.");
        $this->editEmployee = null;
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
     * The cards follow the filters: the chosen dates (today when the page opens), department and employee. One day:
     * people present / late / time worked and, for today, who is working now (else who was absent), compared with
     * the day before. A range: attendance days, late arrivals and time worked in it.
     */
    private function rangeSummary(AttendanceService $attendance): array
    {
        $scope = fn ($q) => $q->when($this->employee, fn ($q, $id) => $q->where('employee_id', $id))
            ->when($this->department, fn ($q, $id) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $id)));
        $range = function (string $from, string $to) use ($scope, $attendance) {
            $sessions = AttendanceSession::with('employee')->whereDate('work_date', '>=', $from)->whereDate('work_date', '<=', $to)->whereNotNull('check_in')->tap($scope)->get();
            $late = $sessions->filter(fn ($s) => $s->employee && $attendance->isLate($s));

            return [
                'days' => $sessions->count(),
                'people' => $sessions->pluck('employee_id')->unique()->count(),
                'late' => $late->count(),
                'late_people' => $late->pluck('employee_id')->unique()->count(),
                'worked' => (int) $sessions->sum(fn ($s) => $attendance->workedSeconds($s)),
                'working' => $sessions->whereNull('check_out')->where('status', 'working')->pluck('employee_id')->unique()->count(),
                'on_break' => $sessions->whereNull('check_out')->where('status', 'on_break')->pluck('employee_id')->unique()->count(),
            ];
        };
        $single = $this->from === $this->to;
        $now = $range($this->from, $this->to);
        $before = $single ? $range($day = Carbon::parse($this->from)->subDay()->toDateString(), $day) : null;
        $employees = AttendanceEmployee::where('is_active', true)->onPayroll()->when($this->employee, fn ($q, $id) => $q->whereKey($id))->when($this->department, fn ($q, $id) => $q->where('department_id', $id))->count();
        $includesToday = $this->from <= today()->toDateString() && $this->to >= today()->toDateString();
        $onLeave = $single ? LeaveRequest::where('status', 'approved')->whereDate('starts_on', '<=', $this->from)->whereDate('ends_on', '>=', $this->from)
            ->when($this->employee, fn ($q, $id) => $q->where('employee_id', $id))->when($this->department, fn ($q, $id) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $id)))->distinct()->count('employee_id') : 0;
        $from = Carbon::parse($this->from);

        return [
            ...$now,
            'single' => $single,
            'employee' => (bool) $this->employee,
            'employees' => $employees,
            'when' => $single ? ($from->isToday() ? 'today' : ($from->isYesterday() ? 'yesterday' : $from->format('d M Y'))) : $from->format('d M').' – '.Carbon::parse($this->to)->format('d M Y'),
            'live' => $single && $includesToday,
            'absent' => $single && ! $from->isFuture() ? max(0, $employees - $now['people'] - $onLeave) : null,
            'on_leave' => $onLeave,
            'present_change' => $before ? $now['people'] - $before['people'] : 0,
            'late_change' => $before ? $now['late_people'] - $before['late_people'] : 0,
            'worked_change' => $before && $before['worked'] > 0 ? (int) round(($now['worked'] - $before['worked']) * 100 / $before['worked']) : null,
        ];
    }

    /**
     * One employee chosen: their whole range at a glance, so nobody has to scroll the rows - present, late, half
     * days, absent with no leave, leave waiting for approval, approved leave, extra working, off days, time worked,
     * average check-in, forgotten check-outs (closed automatically) and fines.
     */
    private function personSummary(array $days, AttendanceEmployee $employee, AttendanceService $attendance): array
    {
        $rows = collect($days);
        $sessions = $rows->pluck('session')->filter();
        $checkIns = $sessions->map(fn ($s) => $s->check_in ? $s->check_in->hour * 60 + $s->check_in->minute : null)->filter(fn ($m) => $m !== null);
        $fines = \App\Models\EmployeePenalty::where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved'])->whereDate('effective_on', '>=', $this->from)->whereDate('effective_on', '<=', $this->to)->get();
        $worked = (int) $sessions->sum(fn ($s) => $attendance->workedSeconds($s));
        // Idle, break and meeting time: the same figures as each row.
        $figures = $sessions->map(fn ($s) => $attendance->liveFigures($s));

        return [
            // Working days in the range (weekly offs and holidays not counted; before joining / after leaving neither).
            'working_days' => $rows->where('workday', true)->whereNotIn('status', ['not_joined', 'left'])->count(),
            'present' => $sessions->count(),
            'late' => $rows->where('status', 'late')->count(),
            'half_day' => $rows->where('status', 'half_day')->count(),
            'absent' => $rows->where('status', 'absent')->where('leave_pending', false)->count(),
            'leave_pending' => $rows->where('leave_pending', true)->count(),
            'on_leave' => $rows->where('status', 'on_leave')->count(),
            'extra' => $rows->where('extra', true)->count(),
            'off' => $rows->whereIn('status', ['weekend', 'holiday'])->count(),
            'conflict' => $rows->where('status', 'leave_conflict')->count(),
            'worked' => $worked,
            'average' => $sessions->count() ? intdiv($worked, $sessions->count()) : 0,
            'avg_in' => $checkIns->count() ? Carbon::today()->addMinutes((int) round($checkIns->avg()))->format('h:i A') : null,
            'auto_out' => $sessions->where('closed_automatically', true)->count(),
            'idle' => (int) $figures->sum('idle'),
            'break' => (int) $figures->sum('break'),
            'meeting' => (int) $figures->sum(fn ($f) => (int) ($f['screen']['meeting'] ?? 0)),
            'fines' => (float) $fines->where('status', 'approved')->sum('amount'),
            'fines_count' => $fines->where('status', 'approved')->count(),
            'fines_pending' => $fines->where('status', 'pending')->count(),
        ];
    }

    public function render(AttendanceService $attendance)
    {
        if (! strtotime($this->from)) $this->from = today()->toDateString();
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
            'summary' => $this->rangeSummary($attendance),
            'person' => $dayEmployee && $dayRows !== null ? $this->personSummary($dayRows, $dayEmployee, $attendance) : null,
            'detailSession' => $detail,
            'activity' => $activity,
            'canEditTime' => $this->canEditTime(),
            'editPerson' => $this->editEmployee ? AttendanceEmployee::find($this->editEmployee) : null,
        ], 'Attendance');
    }
}
