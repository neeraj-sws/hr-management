<?php

namespace App\Livewire\Leave;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\ApprovalRequest;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\ApprovalInbox;
use App\Services\AuditLogger;
use App\Services\HrWorkflowService;
use App\Services\LeaveAccrualService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Requests extends Component
{
    use InteractsWithHr, WithPagination;

    #[Url] public string $tab = '';
    #[Url] public string $status = '';

    public bool $showForm = false;
    public ?int $leaveTypeId = null;
    public string $duration = 'full';
    public string $startsOn = '';
    public string $endsOn = '';
    public string $reason = '';
    public ?int $decidingId = null;
    public string $decisionNote = '';

    public function mount(): void
    {
        $canApprove = $this->canApprove();
        if (! in_array($this->tab, ['mine', 'approvals', 'all'], true)) $this->tab = ! $this->ownEmployee() || ($canApprove && app(ApprovalInbox::class)->pending($this->user(), LeaveRequest::class)->isNotEmpty()) ? 'approvals' : 'mine';
        if ($this->tab !== 'mine' && ! $canApprove) $this->tab = 'mine';
    }

    /** Leave administrators/approvers, and team leads for their own team's requests. */
    private function canApprove(): bool
    {
        return $this->user()->canAny(['leave.manage', 'leave.approve']) || ApprovalInbox::isTeamLead($this->user());
    }

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->requireEmployee();
        $this->reset('leaveTypeId', 'startsOn', 'endsOn', 'reason');
        $this->duration = 'full';
        $this->resetValidation();
        $this->startsOn = $this->endsOn = today()->addDay()->toDateString();
        $this->showForm = true;
    }

    /** Half-day and short leave are single-day, so keep the end date on the start date. */
    public function updatedDuration(): void
    {
        if ($this->duration !== 'full') $this->endsOn = $this->startsOn;
    }

    public function updatedStartsOn(): void
    {
        if ($this->duration !== 'full') $this->endsOn = $this->startsOn;
    }

    public function submit(HrWorkflowService $workflow): void
    {
        $this->validate([
            'leaveTypeId' => ['required', 'exists:leave_types,id'],
            'duration' => ['required', 'in:full,half,short'],
            'startsOn' => ['required', 'date'],
            'endsOn' => ['required', 'date', 'after_or_equal:startsOn'],
            'reason' => ['required', 'string', 'max:2000'],
        ], [], ['leaveTypeId' => 'leave type', 'startsOn' => 'start date', 'endsOn' => 'end date']);
        if ($this->duration !== 'full') $this->endsOn = $this->startsOn;
        $workflow->requestLeave($this->requireEmployee(), ['leave_type_id' => $this->leaveTypeId, 'duration' => $this->duration, 'starts_on' => Carbon::parse($this->startsOn), 'ends_on' => Carbon::parse($this->endsOn), 'reason' => $this->reason]);
        $this->showForm = false;
        $this->toast('Leave request submitted for approval.');
    }

    public function cancel(int $id, AuditLogger $audit): void
    {
        $employee = $this->requireEmployee();
        DB::transaction(function () use ($id, $employee, $audit) {
            $leave = LeaveRequest::where('employee_id', $employee->id)->lockForUpdate()->findOrFail($id);
            if ($leave->status !== 'pending') throw ValidationException::withMessages(['leave' => 'Only pending requests can be cancelled.']);
            $leave->update(['status' => 'cancelled', 'decided_at' => now(), 'decision_note' => 'Cancelled by employee']);
            ApprovalRequest::where('approvable_type', LeaveRequest::class)->where('approvable_id', $leave->id)->where('status', 'pending')->update(['status' => 'cancelled', 'decided_at' => now()]);
            $audit->record('leave.cancelled', $leave);
            $range = $leave->starts_on->format('d M').($leave->ends_on->isSameDay($leave->starts_on) ? '' : ' – '.$leave->ends_on->format('d M Y'));
            \App\Services\Notifier::send(\App\Services\Notifier::withPermission('leave.manage')->push($employee->manager?->user), 'LeaveRequest', $leave->id, "{$employee->name} cancelled their leave request ({$range}). No action is needed.", route('leave.requests', ['tab' => 'all'], false), $employee->user_id);
        });
        $this->toast('Leave request cancelled.');
    }

    public function openDecision(int $id): void
    {
        $this->decidingId = $id;
        $this->decisionNote = '';
    }

    public function decide(bool $approve, ApprovalInbox $inbox): void
    {
        $this->validate(['decisionNote' => [$approve ? 'nullable' : 'required', 'string', 'max:1000']], ['decisionNote.required' => 'Add a reason when rejecting.']);
        $approval = ApprovalRequest::where('approvable_type', LeaveRequest::class)->where('approvable_id', $this->decidingId)->where('status', 'pending')->firstOrFail();
        $inbox->decide($this->user(), $approval, $approve, $this->decisionNote ?: null);
        $this->closeModal();
        $this->toast($approve ? 'Leave approved.' : 'Leave rejected.');
    }

    public function closeModal(): void
    {
        $this->showForm = false;
        $this->decidingId = null;
        $this->resetValidation();
    }

    public function render(ApprovalInbox $inbox, LeaveAccrualService $accrual)
    {
        $user = $this->user();
        $own = $this->ownEmployee();
        $canApprove = $this->canApprove();
        $query = LeaveRequest::with(['employee', 'leaveType', 'decider'])->latest('starts_on')->latest('id');
        if ($this->tab === 'mine') {
            $query->where('employee_id', $own?->id ?? 0);
        } elseif ($this->tab === 'approvals') {
            $ids = $inbox->pending($user, LeaveRequest::class)->pluck('approvable_id');
            $query->whereIn('id', $ids->all() ?: [0]);
        } else {
            $query->when(! $user->can('leave.manage'), fn ($q) => $q->whereIn('employee_id', $this->teamEmployeeIds()->all() ?: [0]));
        }
        $query->when($this->status, fn ($q, $s) => $q->where('status', $s));

        $types = LeaveType::where('is_active', true)->orderBy('name')->get();

        return $this->page('livewire.leave.requests', [
            'requests' => $query->paginate(15),
            'canApprove' => $canApprove,
            'pendingCount' => $canApprove ? $inbox->pending($user, LeaveRequest::class)->count() : 0,
            'types' => $types,
            'balances' => $own ? $types->map(fn ($t) => ['type' => $t, ...$accrual->balance($own, $t, today()->year)]) : collect(),
            'deciding' => $this->decidingId ? LeaveRequest::with(['employee', 'leaveType'])->find($this->decidingId) : null,
            'own' => $own,
        ], 'Leave Requests');
    }
}
