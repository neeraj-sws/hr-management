<?php

namespace App\Livewire\Attendance;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\LeaveRequest;
use App\Services\AttendanceService;
use App\Services\PolicyConfigurationService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Team attendance for a single day: who is in, late, on break, on leave or absent. */
class Dashboard extends Component
{
    use InteractsWithHr, \Livewire\WithPagination;

    #[Url] public string $date = '';
    #[Url] public string $search = '';
    #[Url] public ?int $department = null;
    #[Url] public string $show = 'all';
    public ?int $closingId = null;
    public string $closeTime = '';
    public string $closeReason = '';
    public string $closeNote = '';

    public function updated($property): void
    {
        if (in_array($property, ['date', 'search', 'department', 'show'], true)) $this->resetPage();
    }

    public function mount(): void
    {
        $this->date = $this->date && strtotime($this->date) ? $this->date : now()->toDateString();
    }

    private function canClose(): bool
    {
        return $this->user()->canAny(['attendance.correct', 'hr.manage']);
    }

    /** HR checks someone out who forgot (e.g. left at 18:02); an offline check-out arriving later is ignored as a duplicate. */
    public function openClose(int $sessionId): void
    {
        abort_unless($this->canClose(), 403);
        $session = AttendanceSession::findOrFail($sessionId);
        abort_if($session->check_out !== null, 422, 'This person is already checked out.');
        $this->fill(['closingId' => $session->id, 'closeTime' => now()->format('H:i'), 'closeReason' => '', 'closeNote' => '']);
        $this->resetValidation();
    }

    /**
     * Check-out on an employee's behalf. A reason is mandatory (and an
     * explanation for "Other"); see AttendanceService::forceCheckOut for the
     * audit record, notifications and the lock on further check-outs that day.
     */
    public function closeSession(AttendanceService $attendance): void
    {
        abort_unless($this->canClose(), 403);
        $this->validate([
            'closeTime' => ['required', 'date_format:H:i'],
            'closeReason' => ['required', \Illuminate\Validation\Rule::in(array_keys(AttendanceSession::FORCED_CHECKOUT_REASONS))],
            'closeNote' => [$this->closeReason === 'other' ? 'required' : 'nullable', 'string', 'min:3', 'max:500'],
        ], ['closeReason.required' => 'Choose why you are checking this person out.', 'closeNote.required' => 'Explain the reason when you choose "Other".'], ['closeTime' => 'check-out time', 'closeReason' => 'reason', 'closeNote' => 'explanation']);
        $session = AttendanceSession::with('employee')->findOrFail($this->closingId);
        $at = $session->work_date->copy()->setTimeFromTimeString($this->closeTime);
        if ($at->lt($session->check_in)) $at->addDay();
        if ($at->gt(now())) { $this->addError('closeTime', 'The check-out time cannot be in the future.'); return; }
        $closed = $attendance->forceCheckOut($session, $at, $this->closeReason, $this->closeNote ?: null, $this->user());
        $this->closingId = null;
        $this->toast("{$closed->employee->name} checked out at ".$closed->check_out->format('h:i A').'. They and their manager were notified.');
    }

    public function closeModal(): void
    {
        $this->closingId = null;
    }

    public function render(AttendanceService $attendance, PolicyConfigurationService $policies)
    {
        $user = $this->user();
        $employees = AttendanceEmployee::with('department')->where('is_active', true)
            ->when(! $user->canAny(['hr.manage', 'employees.manage']), fn ($q) => $q->whereIn('id', $this->teamEmployeeIds()->all() ?: [0]))
            ->when($this->department, fn ($q, $id) => $q->where('department_id', $id))
            ->when(trim($this->search) !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.trim($this->search).'%')->orWhere('employee_code', 'like', '%'.trim($this->search).'%')))
            // Newest employee number first (natural order: EMP-10 after EMP-9).
            ->orderByRaw('LENGTH(employee_code)')->orderBy('employee_code')->get();
        $since = $this->teamSince();
        if ($since) $employees = $employees->filter(fn ($e) => ! isset($since[$e->id]) || $since[$e->id] <= $this->date)->values();
        $sessions = AttendanceSession::with(['employee', 'device', 'forcedBy'])->whereIn('employee_id', $employees->pluck('id'))->whereDate('work_date', $this->date)->get()->keyBy('employee_id');
        $onLeave = LeaveRequest::with('leaveType')->whereIn('employee_id', $employees->pluck('id'))->where('status', 'approved')
            ->whereDate('starts_on', '<=', $this->date)->whereDate('ends_on', '>=', $this->date)->get()->keyBy('employee_id');
        $isPast = $this->date < today()->toDateString();
        $calendar = $policies->forEmployee(null, 'calendar.working_days', $this->date, ['days' => [1, 2, 3, 4, 5], 'holidays' => []]);
        $isWorkday = \App\Support\WorkCalendar::isWorkday($this->date, $calendar);

        $rows = $employees->map(function (AttendanceEmployee $employee) use ($sessions, $onLeave, $attendance, $isPast, $isWorkday) {
            $session = $sessions->get($employee->id);
            $state = match (true) {
                $session !== null && $attendance->isHalfDay($session) => 'half_day',
                $session !== null && $attendance->isLate($session) => 'late',
                $session !== null => 'present',
                $onLeave->has($employee->id) => 'on_leave',
                ! $isWorkday => 'weekend',
                default => $isPast || $this->date === today()->toDateString() ? 'absent' : 'upcoming',
            };

            return (object) ['employee' => $employee, 'session' => $session, 'state' => $state, 'leave' => $onLeave->get($employee->id), 'worked' => $session ? $attendance->workedSeconds($session) : 0, 'live' => $session ? $attendance->liveFigures($session) : null,
                'lateMinutes' => $session ? $attendance->lateMinutes($session) : 0];
        });

        $counts = ['present' => $rows->whereIn('state', ['present', 'late', 'half_day'])->count(), 'late' => $rows->where('state', 'late')->count(), 'half_day' => $rows->where('state', 'half_day')->count(), 'on_leave' => $rows->where('state', 'on_leave')->count(), 'absent' => $rows->where('state', 'absent')->count(), 'working' => $sessions->where('status', 'working')->count(), 'on_break' => $sessions->where('status', 'on_break')->count(), 'completed' => $sessions->where('status', 'completed')->count()];
        if ($this->show !== 'all') $rows = $rows->filter(fn ($row) => match ($this->show) {
            'present' => in_array($row->state, ['present', 'late', 'half_day'], true),
            'working', 'on_break' => $row->session?->status === $this->show && ! $row->session->check_out,
            default => $row->state === $this->show,
        });

        return $this->page('livewire.attendance.dashboard', [
            'rows' => $this->paginateCollection($rows->values(), 25),
            'counts' => $counts,
            'total' => $employees->count(),
            'departments' => Department::orderBy('name')->get(),
            'canClose' => $this->canClose(),
            'canMonitor' => app(\App\Services\TeamMonitoringService::class)->canUse($user),
        ], 'Team Attendance');
    }
}
