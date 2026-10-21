<?php

namespace App\Livewire\Attendance;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Services\AttendanceService;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Organisation-wide attendance sessions over a date range (HR). */
class Records extends Component
{
    use InteractsWithHr, WithPagination;

    #[Url] public string $from = '';
    #[Url] public string $to = '';
    #[Url] public ?int $employee = null;
    #[Url] public ?int $department = null;
    #[Url] public string $status = '';
    /** One employee chosen: list | calendar (the month of From). */
    #[Url] public string $view = 'list';

    public function mount(): void
    {
        $this->from = $this->from ?: now()->startOfMonth()->toDateString();
        $this->to = $this->to ?: today()->toDateString();
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    public function render(AttendanceService $attendance)
    {
        if (! strtotime($this->from)) $this->from = now()->startOfMonth()->toDateString();
        if (! strtotime($this->to) || $this->to < $this->from) $this->to = max($this->from, today()->toDateString());
        $sessions = AttendanceSession::with(['employee.department', 'device', 'checkInOffice', 'checkOutOffice'])
            ->whereDate('work_date', '>=', $this->from)->whereDate('work_date', '<=', $this->to)
            ->when($this->employee, fn ($q, $id) => $q->where('employee_id', $id))
            ->when($this->department, fn ($q, $id) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $id)))
            ->when($this->status, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('work_date')
            // Within a day: employee-number order (EMP-01, EMP-02 … EMP-10).
            ->orderBy(AttendanceEmployee::selectRaw('LENGTH(employee_code)')->whereColumn('attendance_employees.id', 'attendance_sessions.employee_id'))
            ->orderBy(AttendanceEmployee::select('employee_code')->whereColumn('attendance_employees.id', 'attendance_sessions.employee_id'))->paginate(25);

        // One employee chosen: list every day of the range (leave, weekly off / 3rd Saturday, absent too), newest first.
        $dayEmployee = $this->employee ? AttendanceEmployee::with('department')->find($this->employee) : null;
        $dayRows = $dayEmployee && ! $this->status && \Illuminate\Support\Carbon::parse($this->from)->diffInDays($this->to) <= 400
            ? $attendance->days($dayEmployee, \Illuminate\Support\Carbon::parse($this->from), \Illuminate\Support\Carbon::parse($this->to))->reverse()->values()->all()
            : null;

        return $this->page('livewire.attendance.records', [
            'sessions' => $sessions,
            'dayRows' => $dayRows,
            'dayEmployee' => $dayEmployee,
            'calendarMonth' => $calMonth = \Illuminate\Support\Carbon::parse($this->from)->startOfMonth(),
            'calendarDays' => $dayEmployee && $this->view === 'calendar' ? $attendance->days($dayEmployee, $calMonth->copy(), $calMonth->copy()->endOfMonth()) : collect(),
            'attendance' => $attendance,
            'employees' => AttendanceEmployee::inCodeOrder()->get(['id', 'name', 'employee_code']),
            'departments' => Department::orderBy('name')->get(),
        ], 'Attendance');
    }
}
