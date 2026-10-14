<?php

namespace App\Livewire\Attendance;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\LeaveConflictResolution;
use App\Models\LeaveRequest;
use App\Services\LeaveConflictService;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Days with approved leave and attendance: HR decides whether each counts as worked or as leave. */
class Conflicts extends Component
{
    use InteractsWithHr, \Livewire\WithPagination;

    #[Url] public string $from = '';
    #[Url] public string $to = '';

    public function mount(): void
    {
        $this->from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->from) ? $this->from : today()->subDays(60)->toDateString();
        $this->to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->to) ? $this->to : today()->addDays(30)->toDateString();
    }

    public function resolve(int $leaveId, string $date, string $resolution, LeaveConflictService $conflicts): void
    {
        abort_unless($this->user()->canAny(['attendance.correct', 'leave.manage', 'hr.manage']), 403);
        $conflicts->resolve(LeaveRequest::findOrFail($leaveId), $date, $resolution, auth()->id(), $resolution === 'worked' ? 'Employee worked on a leave day' : 'Counted as leave');
        $this->toast($resolution === 'worked' ? 'Counted as worked — the leave day was returned to the balance.' : 'Counted as leave.');
    }

    public function render(LeaveConflictService $conflicts)
    {
        $open = $conflicts->open($this->from, $this->to);

        return $this->page('livewire.attendance.conflicts', [
            'open' => $this->paginateCollection(collect($open)->values(), 15, 'openPage'),
            'resolved' => LeaveConflictResolution::with(['employee', 'leaveRequest.leaveType', 'resolver'])->latest('id')->paginate(15, ['*'], 'resolvedPage'),
        ], 'Leave & Attendance Conflicts');
    }
}
