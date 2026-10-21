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
    use InteractsWithHr, \Livewire\WithFileUploads;

    public AttendanceEmployee $employee;

    /** New photo being uploaded (saved as soon as it is chosen). */
    public $photo = null;

    public function updatedPhoto(\App\Services\EmployeePhotoService $photos): void
    {
        $this->validate(['photo' => \App\Services\EmployeePhotoService::RULES], ['photo.mimes' => 'Choose a JPG, PNG or WebP picture.', 'photo.max' => 'The picture must be 3 MB or smaller.']);
        $photos->save($this->employee, $this->photo, $this->user());
        $this->reset('photo');
        $this->employee->refresh();
        $this->toast('Photo updated.');
    }

    public function removePhoto(\App\Services\EmployeePhotoService $photos): void
    {
        $photos->remove($this->employee, $this->user());
        $this->employee->refresh();
        $this->toast('Photo removed.');
    }

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

    public function toggleAppUsage(\App\Services\AppUsageService $usage): void
    {
        $on = ! $usage->enabledFor($this->employee);
        $usage->setForEmployee($this->employee, $on, $this->user());
        $this->toast($on ? 'Application usage tracking turned on.' : 'Application usage tracking turned off.');
    }

    public function toggleWindowTitles(\App\Services\AppUsageService $usage): void
    {
        $on = ! $usage->titlesEnabledFor($this->employee);
        $usage->setTitlesForEmployee($this->employee, $on, $this->user());
        $this->toast($on ? 'Window-title tracking turned on.' : 'Window-title tracking turned off.');
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
        $balances = LeaveType::where('is_active', true)->get()->map(fn ($type) => ['type' => $type, ...$leave->balance($employee, $type, \App\Support\LeaveYear::current())]);
        $metrics = $work->productivity(collect([$employee]), now()->startOfMonth()->toDateString(), today()->toDateString())->first();
        $isSelf = $employee->user_id === $user->id;
        $showSalary = $isSelf || $user->canAny(['payroll.manage', 'payroll.approve', 'hr.manage']);
        $projectQuery = $work->projectsInvolving([$employee->id]);
        $projects = (clone $projectQuery)->with('manager:id,name')
            ->withCount(['tasks as my_tasks_count' => fn ($q) => $q->where('assignee_id', $employee->id), 'tasks as my_open_tasks_count' => fn ($q) => $q->where('assignee_id', $employee->id)->where('status', '!=', 'completed')])
            ->orderByRaw("case status when 'active' then 0 when 'planned' then 1 when 'on_hold' then 2 else 3 end")->orderBy('name')->limit(20)->get();

        // Attendance discipline over the last 90 days: late arrivals, early check-outs, and worked days
        // with no daily status (or one submitted after the day).
        $from = today()->subDays(89);
        $statuses = \App\Models\DailyWorkStatus::where('employee_id', $employee->id)->whereDate('work_date', '>=', $from)->get()->keyBy(fn ($s) => $s->work_date->toDateString());
        $discipline = collect();
        foreach ($attendance->days($employee, $from, today()) as $day) {
            $session = $day['session'];
            if (! $session) continue;
            if ($attendance->isLate($session)) $discipline->push(['date' => $day['date'], 'type' => 'late', 'label' => 'Late arrival', 'detail' => 'In '.$session->check_in->format('h:i A').' · late by '.\App\Support\Duration::fromMinutes($attendance->lateMinutes($session))]);
            if ($session->check_out && ($early = $attendance->earlyMinutes($session)) > 0) $discipline->push(['date' => $day['date'], 'type' => 'early', 'label' => 'Early check-out', 'detail' => 'Out '.$session->check_out->format('h:i A').' · early by '.\App\Support\Duration::fromMinutes($early)]);
            if ($day['date'] < today()->toDateString()) {
                $status = $statuses->get($day['date']);
                if (! $status) $discipline->push(['date' => $day['date'], 'type' => 'no_status', 'label' => 'Daily status missing', 'detail' => 'Worked but no daily status was submitted']);
                elseif ($status->submitted_at && $status->submitted_at->toDateString() > $day['date']) $discipline->push(['date' => $day['date'], 'type' => 'late_status', 'label' => 'Daily status late', 'detail' => 'Submitted '.$status->submitted_at->format('d M, h:i A')]);
            }
        }
        $canSeeDocs = $isSelf || $user->canAny(['lifecycle.manage', 'hr.manage', 'employees.manage']) || \App\Support\Access::isAdmin($user);

        return $this->page('livewire.people.employee-profile', [
            'employee' => $employee,
            'discipline' => $discipline->sortByDesc('date')->values(),
            'disciplineCounts' => $discipline->countBy('type'),
            'dailyStatuses' => \App\Models\DailyWorkStatus::where('employee_id', $employee->id)->latest('work_date')->limit(20)->get(),
            'canChangePhoto' => \App\Services\EmployeePhotoService::canChange($user, $employee),
            'isManager' =>$employee->directReports->where('is_active', true)->isNotEmpty(),
            'summary' => $summary,
            // The working day attendance and payroll really use (per-person rule, else the employee's hours).
            'dailyHours' => (float) $attendance->dailyHours($employee),
            'recentDays' => $days->reverse()->take(10),
            'balances' => $balances,
            'metrics' => $metrics,
            'projectCounts' => $work->projectCounts($projectQuery),
            'projects' => $projects,
            'tasks' => $employee->tasks()->where('status', '!=', 'completed')->latest()->limit(8)->get(),
            'assets' => $employee->assets()->latest()->get(),
            'documents' => $canSeeDocs ? $employee->documents()->latest()->get() : collect(),
            'showSalary' => $showSalary,
            'canEdit' => $user->canAny(['employees.manage', 'hr.manage']),
            'capture' => $user->can('monitoring.manage') ? app(\App\Services\ScreenCaptureService::class)->policy($employee) : null,
            'attendanceSources' => app(\App\Services\AttendanceService::class)->allowedSources($employee),
            'appUsageOn' => $user->can('monitoring.manage') ? app(\App\Services\AppUsageService::class)->enabledFor($employee) : null,
            'windowTitlesOn' => $user->can('monitoring.manage') ? app(\App\Services\AppUsageService::class)->titlesEnabledFor($employee) : null,
            'teamHistory' => \App\Models\EmployeeTeamHistory::with('team')->where('employee_id', $employee->id)->orderByDesc('starts_on')->get(),
            'managerHistory' => \App\Models\EmployeeManagerHistory::with('manager')->where('employee_id', $employee->id)->orderByDesc('starts_on')->get(),
            'history' => $user->canAny(['employees.manage', 'hr.manage', 'audit.view']) ? \App\Models\AuditLog::with('actor')->where('subject_type', $employee::class)->where('subject_id', $employee->id)->latest('id')->limit(15)->get() : collect(),
            'overtime' => $employee->overtimeRequests()->latest('work_date')->limit(10)->get(),
            'overtimeOn' => app(\App\Services\PeopleOperationsService::class)->overtimeEnabled($employee),
            'expenses' => $employee->expenseClaims()->latest('spent_on')->limit(10)->get(),
            'penalties' => \App\Models\EmployeePenalty::with('policyRule')->where('employee_id', $employee->id)->latest('effective_on')->limit(10)->get(),
            'payslips' => $showSalary ? $employee->payslips()->with('item.run')->latest('issued_at')->limit(10)->get() : collect(),
            'reviews' => $employee->reviews()->with('reviewer')->latest('period_ends_on')->limit(6)->get(),
            'training' => $employee->trainingAssignments()->with('course')->latest()->limit(10)->get(),
            'recentLeave' => $employee->leaveRequests()->with('leaveType')->latest('starts_on')->limit(10)->get(),

        ], $employee->name);
    }
}
