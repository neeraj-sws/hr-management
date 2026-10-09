<?php

namespace App\Livewire\Compliance;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\ApprovalRequest;
use App\Models\AttendanceEmployee;
use App\Models\EmployeePenalty;
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

    public function closeModal(): void
    {
        $this->showForm = false;
        $this->actingId = null;
    }

    public function render(ApprovalInbox $inbox)
    {
        $own = $this->ownEmployee();
        $query = EmployeePenalty::with(['employee', 'policyRule', 'payrollItem.run'])
            ->when(! $this->canManage(), fn ($q) => $q->where('employee_id', $own?->id ?? 0))
            ->when($this->status, fn ($q, $s) => $q->where('status', $s))
            ->when($this->canManage() && $this->employee, fn ($q) => $q->where('employee_id', $this->employee));

        return $this->page('livewire.compliance.penalties', [
            'penalties' => (clone $query)->latest('effective_on')->latest('id')->paginate(20),
            'totals' => ['pending' => (float) (clone $query)->where('status', 'pending')->sum('amount'), 'approved' => (float) (clone $query)->where('status', 'approved')->sum('amount')],
            'canManage' => $this->canManage(),
            'decidable' => $inbox->pending($this->user(), EmployeePenalty::class)->pluck('approvable_id')->all(),
            'employees' => $this->canManage() ? AttendanceEmployee::where('is_active', true)->orderBy('name')->get(['id', 'name']) : collect(),
        ], 'Penalties');
    }
}
