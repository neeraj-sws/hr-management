<?php

namespace App\Livewire\Lifecycle;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\ApprovalRequest;
use App\Models\EmployeeFinalSettlement;
use App\Models\EmployeeLifecycleCase;
use App\Services\ApprovalInbox;
use App\Services\FinalSettlementService;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Final settlement calculated from real attendance, leave, OT, adjustments, penalties, advances and locked payroll. */
class Settlements extends Component
{
    use InteractsWithHr;

    #[Url] public ?int $case = null;
    public string $paymentAmount = '';
    public string $paymentReference = '';
    public string $rejectNote = '';

    public function prepare(FinalSettlementService $settlements): void
    {
        abort_unless($this->user()->can('payroll.manage'), 403);
        $settlements->prepare(EmployeeLifecycleCase::findOrFail($this->case), auth()->id());
        $this->toast('Final settlement calculated and sent for approval.');
    }

    public function approve(ApprovalInbox $inbox): void
    {
        $inbox->decide($this->user(), $this->approval(), true);
        $this->toast('Final settlement approved.');
    }

    public function reject(ApprovalInbox $inbox): void
    {
        $this->validate(['rejectNote' => ['required', 'string', 'max:1000']], [], ['rejectNote' => 'reason']);
        $inbox->decide($this->user(), $this->approval(), false, $this->rejectNote);
        $this->rejectNote = '';
        $this->toast('Settlement rejected — recalculate after corrections.');
    }

    public function pay(FinalSettlementService $settlements): void
    {
        abort_unless($this->user()->can('payroll.manage'), 403);
        $this->validate(['paymentAmount' => ['required', 'numeric', 'gt:0'], 'paymentReference' => ['nullable', 'string', 'max:255']], [], ['paymentAmount' => 'amount']);
        $settlement = EmployeeFinalSettlement::where('lifecycle_case_id', $this->case)->firstOrFail();
        $settlements->recordPayment($settlement, (float) $this->paymentAmount, $this->paymentReference ?: null, auth()->id());
        $this->reset('paymentAmount', 'paymentReference');
        $this->toast('Settlement payment recorded.');
    }

    private function approval(): ApprovalRequest
    {
        $settlement = EmployeeFinalSettlement::where('lifecycle_case_id', $this->case)->firstOrFail();

        return ApprovalRequest::where('approvable_type', EmployeeFinalSettlement::class)->where('approvable_id', $settlement->id)->where('status', 'pending')->latest('id')->firstOrFail();
    }

    public function render(ApprovalInbox $inbox)
    {
        $cases = EmployeeLifecycleCase::with(['employee', 'settlement.payments'])->where('kind', 'offboarding')->latest()->get();
        $selected = $this->case ? EmployeeLifecycleCase::with(['employee', 'clearances', 'settlement.payments'])->where('kind', 'offboarding')->find($this->case) : null;
        $settlement = $selected?->settlement;
        $approval = $settlement ? ApprovalRequest::with('approver')->where('approvable_type', EmployeeFinalSettlement::class)->where('approvable_id', $settlement->id)->latest('id')->first() : null;
        if ($settlement && $this->paymentAmount === '' && in_array($settlement->status, ['approved', 'partially_paid'], true)) {
            $this->paymentAmount = number_format(max(0, (float) $settlement->net_amount - (float) $settlement->payments->sum('amount')), 2, '.', '');
        }

        return $this->page('livewire.lifecycle.settlements', [
            'cases' => $cases,
            'selected' => $selected,
            'settlement' => $settlement,
            'approval' => $approval,
            'canDecide' => $approval && $approval->status === 'pending' && $inbox->canDecide($this->user(), $approval->load('approvable')),
            'clearancesDone' => $selected ? $selected->clearances->every(fn ($item) => $item->status === 'cleared') : false,
        ], 'Final Settlement');
    }
}
