<?php

namespace App\Livewire\Payroll;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\ApprovalRequest;
use App\Models\SalaryAdvance;
use App\Services\ApprovalInbox;
use App\Services\PeopleOperationsService;
use Livewire\Attributes\Url;
use Livewire\Component;

class Advances extends Component
{
    use InteractsWithHr, \Livewire\WithPagination;

    #[Url] public string $status = '';
    public bool $showForm = false;
    public string $amount = '';
    public string $installment = '';
    public string $reason = '';
    public ?int $disbursingId = null;
    public string $disburseAmount = '';

    private function canManage(): bool
    {
        return $this->user()->can('salary.advance');
    }

    public function create(): void
    {
        $this->requireEmployee();
        $this->reset('amount', 'installment', 'reason');
        $this->resetValidation();
        $this->showForm = true;
    }

    public function submit(PeopleOperationsService $people): void
    {
        $this->validate(['amount' => ['required', 'numeric', 'gt:0', 'max:10000000'], 'installment' => ['required', 'numeric', 'gt:0', 'lte:amount'], 'reason' => ['required', 'string', 'max:255']], ['installment.lte' => 'The monthly recovery cannot exceed the advance amount.'], ['installment' => 'monthly recovery']);
        $people->requestAdvance($this->requireEmployee(), (float) $this->amount, (float) $this->installment, $this->reason, auth()->id());
        $this->showForm = false;
        $this->toast('Advance request submitted.');
    }

    public function decide(int $id, bool $approve, ApprovalInbox $inbox): void
    {
        $approval = ApprovalRequest::where('approvable_type', SalaryAdvance::class)->where('approvable_id', $id)->where('status', 'pending')->firstOrFail();
        $inbox->decide($this->user(), $approval, $approve);
        $this->toast($approve ? 'Advance approved — record the disbursement next.' : 'Advance rejected.');
    }

    public function openDisburse(int $id): void
    {
        abort_unless($this->canManage(), 403);
        $advance = SalaryAdvance::findOrFail($id);
        $this->fill(['disbursingId' => $id, 'disburseAmount' => number_format((float) $advance->amount - (float) $advance->disbursed_amount, 2, '.', '')]);
        $this->resetValidation();
    }

    public function disburse(PeopleOperationsService $people): void
    {
        abort_unless($this->canManage(), 403);
        $this->validate(['disburseAmount' => ['required', 'numeric', 'gt:0']], [], ['disburseAmount' => 'amount']);
        $people->disburseAdvance(SalaryAdvance::findOrFail($this->disbursingId), (float) $this->disburseAmount, auth()->id());
        $this->disbursingId = null;
        $this->toast('Disbursement recorded. Recovery starts in the next payroll.');
    }

    public function closeModal(): void
    {
        $this->showForm = false;
        $this->disbursingId = null;
    }

    public function render(ApprovalInbox $inbox)
    {
        $own = $this->ownEmployee();
        $advances = SalaryAdvance::with(['employee', 'repayments.payrollItem.run'])
            ->when(! $this->canManage(), fn ($q) => $q->where('employee_id', $own?->id ?? 0))
            ->when($this->status, fn ($q, $s) => $q->where('status', $s))
            ->latest()->paginate(15);

        return $this->page('livewire.payroll.advances', [
            'advances' => $advances,
            'canManage' => $this->canManage(),
            'own' => $own,
            'decidable' => $inbox->pending($this->user(), SalaryAdvance::class)->pluck('approvable_id')->all(),
            'outstanding' => $advances->whereIn('status', ['approved', 'disbursed'])->sum(fn ($a) => max(0, (float) $a->disbursed_amount - (float) $a->recovered_amount)),
            'disbursing' => $this->disbursingId ? SalaryAdvance::with('employee')->find($this->disbursingId) : null,
        ], 'Advances');
    }
}
