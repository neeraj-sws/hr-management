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
    use InteractsWithHr;

    #[Url] public string $date = '';
    #[Url] public string $search = '';
    #[Url] public ?int $department = null;
    #[Url] public string $show = 'all';
    public ?int $closingId = null;
    public string $closeTime = '';
    public string $closeNote = '';

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
        $this->fill(['closingId' => $session->id, 'closeTime' => now()->format('H:i'), 'closeNote' => '']);
        $this->resetValidation();
    }

    public function closeSession(AttendanceService $attendance, \App\Services\AuditLogger $audit): void
    {
        abort_unless($this->canClose(), 403);
        $this->validate(['closeTime' => ['required', 'date_format:H:i'], 'closeNote' => ['required', 'string', 'min:3', 'max:500']], ['closeNote.required' => 'Say why you are checking this person out.'], ['closeTime' => 'check-out time']);
        $session = AttendanceSession::with('employee')->findOrFail($this->closingId);
        $at = $session->work_date->copy()->setTimeFromTimeString($this->closeTime);
        if ($at->lt($session->check_in)) $at->addDay();
        if ($at->gt(now())) { $this->addError('closeTime', 'The check-out time cannot be in the future.'); return; }
        app(\App\Services\PayrollLockGuard::class)->changed($session->employee_id, $session->work_date->toDateString(), 'attendance');
        $closed = $attendance->checkOut($session->employee, 'admin', $at);
        $audit->record('attendance.checked_out_by_admin', $closed, ['check_out' => $at->toDateTimeString(), 'note' => $this->closeNote]);
        $closed->employee?->user?->notify(new \App\Notifications\ApprovalRequested('AttendanceSession', $closed->id, 'HR checked you out at '.$at->format('h:i A').': '.$this->closeNote, route('attendance.my', [], false)));
        $this->closingId = null;
        $this->toast('Checked out at '.$at->format('h:i A').'.');
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
            ->orderBy('name')->get();
        $since = $this->teamSince();
        if ($since) $employees = $employees->filter(fn ($e) => ! isset($since[$e->id]) || $since[$e->id] <= $this->date)->values();
        $sessions = AttendanceSession::with(['employee', 'device'])->whereIn('employee_id', $employees->pluck('id'))->whereDate('work_date', $this->date)->get()->keyBy('employee_id');
        $onLeave = LeaveRequest::with('leaveType')->whereIn('employee_id', $employees->pluck('id'))->where('status', 'approved')
            ->whereDate('starts_on', '<=', $this->date)->whereDate('ends_on', '>=', $this->date)->get()->keyBy('employee_id');
        $isPast = $this->date < today()->toDateString();
        $calendar = $policies->forEmployee(null, 'calendar.working_days', $this->date, ['days' => [1, 2, 3, 4, 5], 'holidays' => []]);
        $isWorkday = in_array(Carbon::parse($this->date)->isoWeekday(), $calendar['days'] ?? [1, 2, 3, 4, 5], true) && ! in_array($this->date, $calendar['holidays'] ?? [], true);

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

            return (object) ['employee' => $employee, 'session' => $session, 'state' => $state, 'leave' => $onLeave->get($employee->id), 'worked' => $session ? $attendance->workedSeconds($session) : 0];
        });

        $counts = ['present' => $rows->whereIn('state', ['present', 'late', 'half_day'])->count(), 'late' => $rows->where('state', 'late')->count(), 'half_day' => $rows->where('state', 'half_day')->count(), 'on_leave' => $rows->where('state', 'on_leave')->count(), 'absent' => $rows->where('state', 'absent')->count(), 'working' => $sessions->where('status', 'working')->count(), 'on_break' => $sessions->where('status', 'on_break')->count(), 'completed' => $sessions->where('status', 'completed')->count()];
        if ($this->show !== 'all') $rows = $rows->filter(fn ($row) => $this->show === 'present' ? in_array($row->state, ['present', 'late', 'half_day'], true) : $row->state === $this->show);

        return $this->page('livewire.attendance.dashboard', [
            'rows' => $rows->values(),
            'counts' => $counts,
            'total' => $employees->count(),
            'departments' => Department::orderBy('name')->get(),
        ], 'Team Attendance');
    }
}
