<?php

namespace App\Livewire\People;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\Task;
use Livewire\Component;

/** Managers directory: anyone with direct reports or the Manager role, with team load. */
class Managers extends Component
{
    use InteractsWithHr, \Livewire\WithPagination;

    public function render()
    {
        $managers = AttendanceEmployee::with(['department', 'user.roles', 'directReports' => fn ($q) => $q->where('is_active', true)->orderByRaw('LENGTH(employee_code)')->orderBy('employee_code')])
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereHas('directReports')->orWhereHas('user.roles', fn ($r) => $r->where('name', 'Manager')))
            ->inCodeOrder()->get()
            ->map(function (AttendanceEmployee $manager) {
                $ids = $manager->directReports->pluck('id');
                $manager->setAttribute('pending_leave', LeaveRequest::whereIn('employee_id', $ids)->where('status', 'pending')->count());
                $manager->setAttribute('pending_overtime', OvertimeRequest::whereIn('employee_id', $ids)->where('status', 'pending')->count());
                $manager->setAttribute('overdue_tasks', Task::whereIn('assignee_id', $ids)->where('status', '!=', 'completed')->whereDate('due_on', '<', today())->count());

                return $manager;
            });

        return $this->page('livewire.people.managers', [
            'managers' => $this->paginateCollection($managers, 15),
            'overtimeOn' => app(\App\Services\PeopleOperationsService::class)->overtimeEnabled(),
            'totals' => ['managers' => $managers->count(), 'reports' => $managers->sum(fn ($m) => $m->directReports->count()), 'pending_leave' => $managers->sum('pending_leave'), 'pending_overtime' => $managers->sum('pending_overtime'), 'overdue' => $managers->sum('overdue_tasks'), 'without_manager' => AttendanceEmployee::where('is_active', true)->whereNull('manager_id')->count()],
        ], 'Managers');
    }
}
