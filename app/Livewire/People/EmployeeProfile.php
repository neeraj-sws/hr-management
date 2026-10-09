<?php

namespace App\Livewire\People;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\LeaveType;
use App\Services\AttendanceService;
use App\Services\LeaveAccrualService;
use App\Services\WorkService;
use Livewire\Component;

class EmployeeProfile extends Component
{
    use InteractsWithHr;

    public AttendanceEmployee $employee;

    public function mount(AttendanceEmployee $employee): void
    {
        abort_unless($this->user()->can('view', $employee), 403);
        $this->employee = $employee;
    }

    public function confirmEmployment(\App\Services\EmployeeService $employees): void
    {
        abort_unless($this->user()->canAny(['employees.manage', 'hr.manage']), 403);
        $employees->confirm($this->employee, today()->toDateString(), $this->user());
        $this->employee->refresh();
        $this->toast('Employment confirmed.');
    }

    /** Admin sets which ways this employee may mark attendance (web / mobile / desktop). */
    public function setAttendanceSources(array $sources, \App\Services\PeopleOperationsService $people, \App\Services\AttendanceService $attendance): void
    {
        abort_unless($this->user()->canAny(['employees.manage', 'hr.manage']), 403);
        $sources = array_values(array_intersect(array_keys(\App\Services\AttendanceService::SOURCES), $sources));
        if (! $sources) { $this->toast('Keep at least one way to mark attendance.'); return; }
        $people->setPolicy('attendance.sources', 'attendance', 'Attendance methods', ['allowed' => $sources], $this->employee->id, $this->user()->id, today()->toDateString());
        $this->toast('Attendance methods updated.');
    }

    public function toggleCapture(\App\Services\ScreenCaptureService $captures): void
    {
        $on = ! $captures->policy($this->employee)['enabled'];
        $captures->setForEmployee($this->employee, $on, $this->user());
        $this->toast($on ? 'Screen capture turned on.' : 'Screen capture turned off.');
    }

    public function render(AttendanceService $attendance, LeaveAccrualService $leave, WorkService $work)
    {
        $employee = $this->employee->load(['department', 'manager', 'teams', 'user.roles', 'directReports', 'salaryProfiles' => fn ($q) => $q->orderByDesc('effective_from')]);
        $user = $this->user();
        $days = $attendance->days($employee, now()->startOfMonth(), today());
        $summary = $days->countBy('status');
        $balances = LeaveType::where('is_active', true)->get()->map(fn ($type) => ['type' => $type, ...$leave->balance($employee, $type, today()->year)]);
        $metrics = $work->productivity(collect([$employee]), now()->startOfMonth()->toDateString(), today()->toDateString())->first();
        $isSelf = $employee->user_id === $user->id;

        return $this->page('livewire.people.employee-profile', [
            'employee' => $employee,
            'summary' => $summary,
            'recentDays' => $days->reverse()->take(10),
            'balances' => $balances,
            'metrics' => $metrics,
            'tasks' => $employee->tasks()->where('status', '!=', 'completed')->latest()->limit(8)->get(),
            'assets' => $employee->assets()->latest()->get(),
            'documents' => ($isSelf || $user->can('lifecycle.manage')) ? $employee->documents()->latest()->get() : collect(),
            'showSalary' => $isSelf || $user->canAny(['payroll.manage', 'payroll.approve', 'hr.manage']),
            'canEdit' => $user->canAny(['employees.manage', 'hr.manage']),
            'capture' => $user->can('monitoring.manage') ? app(\App\Services\ScreenCaptureService::class)->policy($employee) : null,
            'attendanceSources' => app(\App\Services\AttendanceService::class)->allowedSources($employee),
            'teamHistory' => \App\Models\EmployeeTeamHistory::with('team')->where('employee_id', $employee->id)->orderByDesc('starts_on')->get(),
            'managerHistory' => \App\Models\EmployeeManagerHistory::with('manager')->where('employee_id', $employee->id)->orderByDesc('starts_on')->get(),
            'history' => $user->canAny(['employees.manage', 'hr.manage', 'audit.view']) ? \App\Models\AuditLog::with('actor')->where('subject_type', $employee::class)->where('subject_id', $employee->id)->latest('id')->limit(15)->get() : collect(),
        ], $employee->name);
    }
}
