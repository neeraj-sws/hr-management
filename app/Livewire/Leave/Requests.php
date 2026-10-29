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
    /** one = a single full day (one date), several = From/To, half / short = part of one day. */
    public string $dayChoice = 'one';
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
        // ?new=1 (Quick actions in the search): open "Apply leave" straight away.
        if (request()->boolean('new') && $this->ownEmployee()) $this->create();
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
        $this->dayChoice = 'one';
        $this->resetValidation();
        $this->startsOn = $this->endsOn = today()->addDay()->toDateString();
        $this->showForm = true;
    }

    /** Half-day and short leave are single-day, so keep the end date on the start date. */
    public function updatedDuration(): void
    {
        // Set directly (older callers): "full" has always meant one or more days.
        $this->dayChoice = $this->duration === 'full' ? 'several' : $this->duration;
        if ($this->duration !== 'full') $this->endsOn = $this->startsOn;
    }

    /** One day, several days, half day or short leave: only "several days" has a To date. */
    public function updatedDayChoice(): void
    {
        $this->duration = in_array($this->dayChoice, ['half', 'short'], true) ? $this->dayChoice : 'full';
        if ($this->dayChoice !== 'several') $this->endsOn = $this->startsOn;
    }

    /** A field the person just changed no longer shows its old error (e.g. "leave type is required"). */
    public function updated($property): void
    {
        $this->resetErrorBag($property);
    }

    public function updatedStartsOn(): void
    {
        if ($this->duration !== 'full' || $this->dayChoice !== 'several') $this->endsOn = $this->startsOn;
    }

    public function submit(HrWorkflowService $workflow): void
    {
        if ($this->dayChoice !== 'several') $this->endsOn = $this->startsOn;
        $this->validate([
            'leaveTypeId' => ['required', 'exists:leave_types,id'],
            'duration' => ['required', 'in:full,half,short'],
            // A past day can be asked for too (back to the start of last month): it is marked "Old date", the
            // approver must give a reason, and a "Leave applied late" fine may apply (Compliance).
            'startsOn' => ['required', 'date', 'after_or_equal:'.today()->subMonthNoOverflow()->startOfMonth()->toDateString()],
            'endsOn' => ['required', 'date', 'after_or_equal:startsOn'],
            'reason' => ['required', 'string', 'max:2000'],
        ], ['startsOn.after_or_equal' => 'Leave can be asked for back to '.today()->subMonthNoOverflow()->startOfMonth()->format('d M Y').' at the earliest; for older days ask HR.'], ['leaveTypeId' => 'leave type', 'startsOn' => 'start date', 'endsOn' => 'end date']);
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

    /** Employee: change the leave type / reason of their own pending request. */
    public ?int $editingId = null;
    public ?int $editTypeId = null;
    public string $editReason = '';

    public function openEdit(int $id): void
    {
        $leave = LeaveRequest::where('employee_id', $this->requireEmployee()->id)->where('status', 'pending')->findOrFail($id);
        $this->fill(['editingId' => $leave->id, 'editTypeId' => $leave->leave_type_id, 'editReason' => (string) $leave->reason]);
        $this->resetValidation();
    }

    public function saveEdit(HrWorkflowService $workflow): void
    {
        $this->validate(['editTypeId' => ['required', 'integer'], 'editReason' => ['required', 'string', 'min:3', 'max:1000']], [], ['editTypeId' => 'leave type', 'editReason' => 'reason']);
        $employee = $this->requireEmployee();
        try {
            $leave = $workflow->changeLeaveType(LeaveRequest::findOrFail($this->editingId), $employee, (int) $this->editTypeId, $this->editReason, $this->user()->id);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw \Illuminate\Validation\ValidationException::withMessages(['editTypeId' => collect($e->errors())->flatten()->first()]);
        }
        $this->editingId = null;
        $this->toast('Leave request changed to '.$leave->leaveType?->name.'. Your approver is told.');
    }

    /** HR / administrator: change a decided leave (approved or rejected by mistake), with a reason. */
    public ?int $changingId = null;
    public string $changeReason = '';

    private function canChangeDecisions(): bool
    {
        return \App\Support\Access::isAdmin($this->user()) || $this->user()->canAny(['leave.manage', 'hr.manage']);
    }

    public function openChange(int $id): void
    {
        abort_unless($this->canChangeDecisions(), 403);
        $this->fill(['changingId' => $id, 'changeReason' => '']);
        $this->resetValidation();
    }

    public function saveChange(HrWorkflowService $workflow): void
    {
        abort_unless($this->canChangeDecisions(), 403);
        $this->validate(['changeReason' => ['required', 'string', 'min:5', 'max:1000']], ['changeReason.required' => 'Write why the decision is changed.', 'changeReason.min' => 'Write why the decision is changed (at least 5 characters).'], ['changeReason' => 'reason']);
        $leave = LeaveRequest::findOrFail($this->changingId);
        $changed = $workflow->changeLeaveDecision($leave, $this->user(), $leave->status === 'rejected', $this->changeReason);
        $this->changingId = null;
        $this->editingId = null;
        $this->toast('Leave is now '.$changed->status.'. The employee is told.');
    }

    public function closeModal(): void
    {
        $this->showForm = false;
        $this->decidingId = null;
        $this->changingId = null;
        $this->resetValidation();
    }

    public function render(ApprovalInbox $inbox, LeaveAccrualService $accrual)
    {
        $user = $this->user();
        $own = $this->ownEmployee();
        $canApprove = $this->canApprove();
        $query = LeaveRequest::with(['employee', 'leaveType', 'decider', 'conflictResolutions'])->latest('id'); // newest request first
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

        // Leave for a month whose salary is already processed is still possible; the employee is told how it is settled.
        $validDates = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->startsOn) && (! $this->endsOn || preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->endsOn));
        $payrollNotice = $this->showForm && $own && $validDates ? app(\App\Services\PayrollLockGuard::class)->closedNotice($own->id, $this->startsOn, max($this->startsOn, $this->endsOn ?: $this->startsOn)) : null;

        return $this->page('livewire.leave.requests', [
            'payrollNotice' => $payrollNotice,
            'requests' => $query->paginate(15),
            'canApprove' => $canApprove,
            // Counts on the status cards: my own, and everyone I may see (the same scope as the list).
            'tabCounts' => ['mine' => $own ? \App\Models\LeaveRequest::where('employee_id', $own->id)->count() : 0,
                'all' => $canApprove ? \App\Models\LeaveRequest::query()->when(! $user->can('leave.manage'), fn ($q) => $q->whereIn('employee_id', $this->teamEmployeeIds()->all() ?: [0]))->count() : 0],
            'pendingCount' => $canApprove ? $inbox->pending($user, LeaveRequest::class)->count() : 0,
            'types' => $types,
            'balances' => $own ? $types->map(fn ($t) => ['type' => $t, ...$accrual->balance($own, $t, \App\Support\LeaveYear::current())]) : collect(),
            'canChangeDecisions' => $this->canChangeDecisions(),
            'editing' => $this->editingId ? LeaveRequest::with('leaveType')->find($this->editingId) : null,
            'changing' => $this->changingId ? LeaveRequest::with(['employee', 'leaveType', 'decider'])->find($this->changingId) : null,
            'deciding' => $this->decidingId ? LeaveRequest::with(['employee', 'leaveType'])->find($this->decidingId) : null,
            'own' => $own,
        ], 'Leave Requests');
    }
}
