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
            ->orderByDesc('work_date')->orderBy('employee_id')->paginate(25);

        return $this->page('livewire.attendance.records', [
            'sessions' => $sessions,
            'attendance' => $attendance,
            'employees' => AttendanceEmployee::orderBy('name')->get(['id', 'name', 'employee_code']),
            'departments' => Department::orderBy('name')->get(),
        ], 'Attendance');
    }
}
