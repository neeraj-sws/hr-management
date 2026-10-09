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
    use InteractsWithHr;

    public function render()
    {
        $managers = AttendanceEmployee::with(['department', 'user.roles', 'directReports' => fn ($q) => $q->where('is_active', true)->orderBy('name')])
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereHas('directReports')->orWhereHas('user.roles', fn ($r) => $r->where('name', 'Manager')))
            ->orderBy('name')->get()
            ->map(function (AttendanceEmployee $manager) {
                $ids = $manager->directReports->pluck('id');
                $manager->setAttribute('pending_leave', LeaveRequest::whereIn('employee_id', $ids)->where('status', 'pending')->count());
                $manager->setAttribute('pending_overtime', OvertimeRequest::whereIn('employee_id', $ids)->where('status', 'pending')->count());
                $manager->setAttribute('overdue_tasks', Task::whereIn('assignee_id', $ids)->where('status', '!=', 'completed')->whereDate('due_on', '<', today())->count());

                return $manager;
            });

        return $this->page('livewire.people.managers', ['managers' => $managers], 'Managers');
    }
}
