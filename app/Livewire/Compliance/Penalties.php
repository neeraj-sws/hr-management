<?php

namespace App\Livewire\Compliance;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\ApprovalRequest;
use App\Models\AttendanceEmployee;
use App\Models\EmployeePenalty;
use App\Models\PenaltyAppeal;
use App\Services\ApprovalInbox;
use App\Services\PeopleOperationsService;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Penalties extends Component
{
    use InteractsWithHr, WithPagination;

    #[Url] public string $status = '';
    #[Url] public ?int $employee = null;
    public bool $showForm = false;
    public ?int $employeeId = null;
    public string $amount = '';
    public string $reason = '';
    public ?int $actingId = null;
    public string $acting = '';
    public string $actAmount = '';
    public string $actNote = '';
    /** The employee's reason for a fine. */
    public ?int $appealingId = null;
    public string $appealReason = '';
    /** HR / admin deciding an employee's reason. */
    public ?int $reviewingId = null;
    public bool $reviewAccept = true;
    public string $reviewNote = '';

    /** Month filter ("2026-10") and the fines ticked for "Take back selected". */
    #[Url] public string $month = '';
    public array $selected = [];
    public bool $bulkOpen = false;
    public string $bulkNote = '';

    public function updated($property): void
    {
        if (in_array($property, ['status', 'employee', 'month'], true)) { $this->resetPage(); $this->selected = []; }
    }

    /** The fines the filters show (for "select all matching"). */
    private function filtered()
    {
        $own = $this->ownEmployee();

        return EmployeePenalty::query()
            ->when(! $this->canManage(), fn ($q) => $q->where('employee_id', $own?->id ?? 0))
            ->when($this->status, fn ($q, $s) => $q->where('status', $s))
            ->when($this->canManage() && $this->employee, fn ($q) => $q->where('employee_id', $this->employee))
            ->when(preg_match('/^\d{4}-\d{2}$/', $this->month), fn ($q) => $q->whereYear('effective_on', (int) substr($this->month, 0, 4))->whereMonth('effective_on', (int) substr($this->month, 5, 2)));
    }

    public function selectAllMatching(): void
    {
        abort_unless($this->canManage(), 403);
        $this->selected = $this->filtered()->whereIn('status', ['pending', 'approved'])->get()->filter->canBeRemoved()->pluck('id')->map(fn ($id) => (string) $id)->values()->all();
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    public function openBulk(): void
    {
        abort_unless($this->canManage() && $this->selected, 403);
        $this->fill(['bulkOpen' => true, 'bulkNote' => '']);
        $this->resetValidation();
    }

    public function saveBulk(PeopleOperationsService $people): void
    {
        abort_unless($this->canManage(), 403);
        $this->validate(['bulkNote' => ['required', 'string', 'min:5', 'max:1000']], ['bulkNote.required' => 'Write why these fines are taken back.', 'bulkNote.min' => 'Write why these fines are taken back (at least 5 characters).'], ['bulkNote' => 'reason']);
        $done = $people->removeFines($this->selected, $this->bulkNote, auth()->id());
        $this->fill(['bulkOpen' => false, 'selected' => []]);
        $this->toast($done['removed'].' fine(s) taken back'.($done['paid_back'] ? ', '.$done['paid_back'].' paid back in the next salary (already paid)' : '').($done['skipped'] ? ', '.$done['skipped'].' skipped' : '').'. The employee is told.');
    }

    private function canManage(): bool
    {
        return $this->user()->can('penalties.manage');
    }

    public function create(): void
    {
        abort_unless($this->canManage(), 403);
        $this->reset('employeeId', 'amount', 'reason');
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(PeopleOperationsService $people): void
    {
        abort_unless($this->canManage(), 403);
        $this->validate(['employeeId' => ['required', 'exists:attendance_employees,id'], 'amount' => ['required', 'numeric', 'gt:0', 'max:1000000'], 'reason' => ['required', 'string', 'max:255']], [], ['employeeId' => 'employee']);
        $target = AttendanceEmployee::findOrFail($this->employeeId);
        abort_unless($this->canManageEmployee($target) || $this->user()->can('penalties.manage'), 403);
        $people->requestPenalty($target, (float) $this->amount, $this->reason, auth()->id());
        $this->showForm = false;
        $this->toast('Penalty submitted for approval.');
    }

    public function decide(int $id, bool $approve, ApprovalInbox $inbox): void
    {
        $approval = ApprovalRequest::where('approvable_type', EmployeePenalty::class)->where('approvable_id', $id)->where('status', 'pending')->firstOrFail();
        $inbox->decide($this->user(), $approval, $approve);
        $this->toast($approve ? 'Penalty approved — it will be deducted in payroll.' : 'Penalty waived.');
    }

    /** reduce = approve a lower amount; reject = not applicable; waive = cancel a pending or approved (not yet locked) penalty. */
    public function openAction(int $id, string $action): void
    {
        $penalty = EmployeePenalty::findOrFail($id);
        $this->fill(['actingId' => $id, 'acting' => in_array($action, ['reduce', 'reject', 'waive'], true) ? $action : 'waive', 'actAmount' => (string) (float) $penalty->amount, 'actNote' => '']);
        $this->resetValidation();
    }

    public function act(ApprovalInbox $inbox, PeopleOperationsService $people): void
    {
        $penalty = EmployeePenalty::findOrFail($this->actingId);
        $this->validate(['actNote' => ['required', 'string', 'min:3', 'max:1000'], 'actAmount' => [$this->acting === 'reduce' ? 'required' : 'nullable', 'numeric', 'gt:0', 'lte:'.(float) $penalty->amount]], ['actNote.required' => 'Add a reason.', 'actAmount.lte' => 'A penalty can only be reduced.'], ['actAmount' => 'amount']);
        if ($this->acting === 'waive') {
            abort_unless($this->user()->can('penalties.manage'), 403);
            $people->waivePenalty($penalty, auth()->id(), $this->actNote);
            $this->toast('Penalty waived.');
        } else {
            $approval = ApprovalRequest::where('approvable_type', EmployeePenalty::class)->where('approvable_id', $penalty->id)->where('status', 'pending')->firstOrFail();
            $inbox->decide($this->user(), $approval, $this->acting === 'reduce', $this->actNote, $this->acting === 'reduce' ? (float) $this->actAmount : null);
            $this->toast($this->acting === 'reduce' ? 'Penalty approved at '.number_format((float) $this->actAmount, 2).'.' : 'Penalty rejected.');
        }
        $this->actingId = null;
    }

    public function openAppeal(int $id): void
    {
        $penalty = EmployeePenalty::with('appeal')->where('employee_id', $this->ownEmployee()?->id ?? 0)->findOrFail($id);
        abort_unless($penalty->canAppeal(), 403);
        $this->fill(['appealingId' => $id, 'appealReason' => '']);
        $this->resetValidation();
    }

    public function saveAppeal(PeopleOperationsService $people): void
    {
        $this->validate(['appealReason' => ['required', 'string', 'min:5', 'max:1000']], ['appealReason.min' => 'Write the reason (at least 5 characters).'], ['appealReason' => 'reason']);
        $people->appealPenalty(EmployeePenalty::with('employee')->findOrFail($this->appealingId), $this->user(), $this->appealReason);
        $this->appealingId = null;
        $this->toast('Reason sent to HR. If it is accepted, the fine is removed.');
    }

    /** accept = the fine is removed; reject = it stays (a note is required). */
    public function openReview(int $appealId, bool $accept): void
    {
        abort_unless($this->canManage(), 403);
        $this->fill(['reviewingId' => $appealId, 'reviewAccept' => $accept, 'reviewNote' => '']);
        $this->resetValidation();
    }

    public function saveReview(ApprovalInbox $inbox): void
    {
        abort_unless($this->canManage(), 403);
        $this->validate(['reviewNote' => [$this->reviewAccept ? 'nullable' : 'required', 'string', 'max:1000']], ['reviewNote.required' => 'Say why the reason is not accepted.'], ['reviewNote' => 'note']);
        $approval = ApprovalRequest::where('approvable_type', PenaltyAppeal::class)->where('approvable_id', $this->reviewingId)->where('status', 'pending')->firstOrFail();
        $inbox->decide($this->user(), $approval, $this->reviewAccept, trim($this->reviewNote) ?: null);
        $this->reviewingId = null;
        $this->toast($this->reviewAccept ? 'Reason accepted: the fine is removed.' : 'Reason not accepted: the fine stays.');
    }

    public function closeModal(): void
    {
        $this->showForm = false;
        $this->actingId = null;
        $this->appealingId = null;
        $this->reviewingId = null;
        $this->bulkOpen = false;
    }

    public function render(ApprovalInbox $inbox)
    {
        $own = $this->ownEmployee();
        $query = $this->filtered()->with(['employee', 'policyRule', 'payrollItem.run', 'appeal.decider', 'decider']);

        return $this->page('livewire.compliance.penalties', [
            'penalties' => (clone $query)->latest('id')->paginate(20),
            'totals' => ['pending' => (float) (clone $query)->where('status', 'pending')->sum('amount'), 'approved' => (float) (clone $query)->where('status', 'approved')->sum('amount')],
            'canManage' => $this->canManage(),
            'ownId' => $own?->id,
            'selectedTotal' => $this->selected ? (float) EmployeePenalty::whereIn('id', array_map('intval', $this->selected))->sum('amount') : 0.0,
            'decidable' => $inbox->pending($this->user(), EmployeePenalty::class)->pluck('approvable_id')->all(),
            'reviewable' => $inbox->pending($this->user(), PenaltyAppeal::class)->pluck('approvable_id')->all(),
            'employees' => $this->canManage() ? AttendanceEmployee::where('is_active', true)->orderBy('name')->get(['id', 'name']) : collect(),
        ], 'Penalties');
    }
}
