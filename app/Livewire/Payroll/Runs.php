<?php

namespace App\Livewire\Payroll;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\ApprovalRequest;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Services\ApprovalInbox;
use App\Services\HrWorkflowService;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Payroll flow: attendance → leave → OT → compliance → salary → adjustments →
 * approval → lock → payment → payslip. "Payroll Approvals" is the same page
 * filtered to runs awaiting approval.
 */
class Runs extends Component
{
    use InteractsWithHr, \Livewire\WithPagination;

    #[Locked] public bool $approvalsOnly = false;
    #[Url] public ?int $run = null;

    public string $periodStart = '';
    public string $periodEnd = '';
    public ?int $payingItemId = null;
    public string $paymentAmount = '';
    public string $paymentReference = '';
    public string $rejectNote = '';
    public ?int $breakdownItemId = null;

    public function mount(): void
    {
        $this->approvalsOnly = request()->routeIs('payroll.approvals');
        $this->periodStart = now()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $this->periodEnd = now()->subMonthNoOverflow()->endOfMonth()->toDateString();
    }

    public function calculate(HrWorkflowService $workflow): void
    {
        abort_unless($this->user()->can('payroll.manage'), 403);
        $this->validate(['periodStart' => ['required', 'date'], 'periodEnd' => ['required', 'date', 'after_or_equal:periodStart']], [], ['periodStart' => 'period start', 'periodEnd' => 'period end']);
        $run = $workflow->calculatePayroll($this->periodStart, $this->periodEnd, auth()->id());
        $this->run = $run->id;
        $this->toast("Payroll calculated for {$run->items->count()} employees and sent for approval.");
    }

    public function recalculate(HrWorkflowService $workflow): void
    {
        abort_unless($this->user()->can('payroll.manage'), 403);
        $run = PayrollRun::findOrFail($this->run);
        $workflow->calculatePayroll($run->period_starts_on->toDateString(), $run->period_ends_on->toDateString(), auth()->id());
        $this->showCompare = false;
        $this->toast('Draft payroll recalculated from the latest data.');
    }

    public function approve(ApprovalInbox $inbox): void
    {
        $approval = ApprovalRequest::where('approvable_type', PayrollRun::class)->where('approvable_id', $this->run)->where('status', 'pending')->latest('id')->firstOrFail();
        $inbox->decide($this->user(), $approval, true);
        $this->toast('Payroll approved. Accounts can now lock it.');
    }

    public function reject(ApprovalInbox $inbox): void
    {
        $this->validate(['rejectNote' => ['required', 'string', 'max:1000']], [], ['rejectNote' => 'rejection reason']);
        $approval = ApprovalRequest::where('approvable_type', PayrollRun::class)->where('approvable_id', $this->run)->where('status', 'pending')->latest('id')->firstOrFail();
        $inbox->decide($this->user(), $approval, false, $this->rejectNote);
        $this->rejectNote = '';
        $this->toast('Payroll sent back for correction.');
    }

    public function lock(HrWorkflowService $workflow): void
    {
        abort_unless($this->user()->can('payroll.manage'), 403);
        $workflow->lockPayroll(PayrollRun::findOrFail($this->run), auth()->id());
        $this->toast('Payroll locked. No further changes are possible; record payments next.');
    }

    public string $reopenReason = '';
    public bool $showReopen = false;

    /** "Compare": this draft's stored salary for days worked against a fresh calculation now (nothing is saved). */
    public bool $showCompare = false;

    public function openCompare(): void
    {
        abort_unless($this->user()->can('payroll.manage'), 403);
        $this->showCompare = true;
    }

    private function compareRows(PayrollRun $run): array
    {
        $workflow = app(HrWorkflowService::class);
        $rows = [];
        foreach ($run->items as $item) {
            $b = (array) $item->calculation_breakdown;
            if (! empty($b['old_sheet']) || ! $item->employee) continue; // months from the old salary sheet do not change
            $now = $workflow->payEstimate($item->employee, $run->period_starts_on->toDateString(), $run->period_ends_on->toDateString());
            if (! $now) continue;
            $before = (float) ($b['base_salary'] ?? 0);
            $after = (float) $now['breakdown']['base_salary'];
            $rows[] = ['name' => $item->employee->name, 'code' => $item->employee->employee_code,
                'present_before' => (float) $item->present_days, 'present_after' => (float) $now['present_days'],
                'half_days' => $now['breakdown']['half_days'] ?? [], 'before' => $before, 'after' => $after, 'diff' => round($after - $before, 2)];
        }

        return $rows;
    }

    public string $deleteReason = '';
    public bool $showDelete = false;

    public function openDelete(): void
    {
        abort_unless($this->user()->can('payroll.manage'), 403);
        $this->fill(['deleteReason' => '', 'showDelete' => true]);
        $this->resetValidation();
    }

    /** Deletes the selected draft run; its adjustments, claims, penalties and advances are free again. */
    public function deleteRun(HrWorkflowService $workflow): void
    {
        $this->validate(['deleteReason' => ['required', 'string', 'min:5', 'max:500']], [], ['deleteReason' => 'reason']);
        $workflow->deleteDraftPayroll(PayrollRun::findOrFail($this->run), $this->user(), $this->deleteReason);
        $this->showDelete = false;
        $this->run = null;
        $this->toast('Draft payroll deleted. Its adjustments, claims and advance recoveries go into the right payroll on its next calculation.');
    }

    public function openReopen(): void
    {
        abort_unless(\App\Support\Access::isAdmin($this->user()), 403);
        $this->fill(['reopenReason' => '', 'showReopen' => true]);
        $this->resetValidation();
    }

    public function reopen(HrWorkflowService $workflow): void
    {
        $this->validate(['reopenReason' => ['required', 'string', 'min:5', 'max:500']], [], ['reopenReason' => 'reason']);
        $workflow->reopenPayroll(PayrollRun::findOrFail($this->run), $this->user(), $this->reopenReason);
        $this->showReopen = false;
        $this->toast('Payroll reopened as a draft. Corrections are allowed again; recalculate before approving.');
    }

    public function openPayment(int $itemId): void
    {
        abort_unless($this->user()->can('payroll.manage'), 403);
        $item = PayrollItem::with('payments')->findOrFail($itemId);
        $this->fill(['payingItemId' => $item->id, 'paymentAmount' => number_format(max(0, (float) $item->net_amount - (float) $item->payments->sum('amount')), 2, '.', ''), 'paymentReference' => '']);
        $this->resetValidation();
    }

    public function pay(HrWorkflowService $workflow): void
    {
        abort_unless($this->user()->can('payroll.manage'), 403);
        $this->validate(['paymentAmount' => ['required', 'numeric', 'gt:0'], 'paymentReference' => ['nullable', 'string', 'max:255']], [], ['paymentAmount' => 'amount']);
        $workflow->pay(PayrollItem::findOrFail($this->payingItemId), ['amount' => $this->paymentAmount, 'paid_on' => today(), 'reference' => $this->paymentReference ?: null], auth()->id());
        $this->payingItemId = null;
        $this->toast('Payment recorded and payslip issued.');
    }

    public function closeModal(): void
    {
        $this->payingItemId = null;
        $this->breakdownItemId = null;
        $this->showReopen = false;
        $this->showDelete = false;
        $this->showCompare = false;
    }

    public function render(ApprovalInbox $inbox)
    {
        $runs = PayrollRun::withCount('items')->withSum('items', 'net_amount')
            ->when($this->approvalsOnly, fn ($q) => $q->where('status', 'draft'))
            ->latest('period_starts_on')->paginate(12);
        $selected = $this->run ? PayrollRun::with(['items.employee', 'items.payments', 'items.payslip'])->find($this->run) : null;
        $approval = $selected ? ApprovalRequest::with('approver')->where('approvable_type', PayrollRun::class)->where('approvable_id', $selected->id)->latest('id')->first() : null;

        return $this->page('livewire.payroll.runs', [
            'runs' => $runs,
            'selected' => $selected,
            'approval' => $approval,
            'canDecide' => $approval && $approval->status === 'pending' && $inbox->canDecide($this->user(), $approval->load('approvable')),
            'paying' => $this->payingItemId ? PayrollItem::with(['employee', 'payments'])->find($this->payingItemId) : null,
            'breakdown' => $this->breakdownItemId ? PayrollItem::with('employee')->find($this->breakdownItemId) : null,
            'compare' => $this->showCompare && $selected && $selected->status === 'draft' ? $this->compareRows($selected) : null,
        ], $this->approvalsOnly ? 'Payroll Approvals' : 'Payroll');
    }
}
