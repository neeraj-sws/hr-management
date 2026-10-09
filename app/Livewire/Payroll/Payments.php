<?php

namespace App\Livewire\Payroll;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\PayrollItem;
use App\Models\SalaryPayment;
use App\Services\HrWorkflowService;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Salary payments ledger plus outstanding balances on locked payroll (partial payments supported). */
class Payments extends Component
{
    use InteractsWithHr, WithPagination;

    #[Url] public string $from = '';
    #[Url] public string $to = '';
    #[Url] public string $search = '';
    public ?int $payingItemId = null;
    public string $amount = '';
    public string $reference = '';
    public string $method = 'bank_transfer';
    public string $paidOn = '';

    public function mount(): void
    {
        $this->from = $this->from ?: now()->subMonths(3)->startOfMonth()->toDateString();
        $this->to = $this->to ?: today()->toDateString();
    }

    public function openPayment(int $itemId): void
    {
        abort_unless($this->user()->can('payroll.manage'), 403);
        $item = PayrollItem::with('payments')->findOrFail($itemId);
        $this->fill(['payingItemId' => $itemId, 'amount' => number_format(max(0, (float) $item->net_amount - (float) $item->payments->sum('amount')), 2, '.', ''), 'reference' => '', 'method' => 'bank_transfer', 'paidOn' => today()->toDateString()]);
        $this->resetValidation();
    }

    public function pay(HrWorkflowService $workflow): void
    {
        abort_unless($this->user()->can('payroll.manage'), 403);
        $this->validate(['amount' => ['required', 'numeric', 'gt:0'], 'method' => ['required', \Illuminate\Validation\Rule::in(array_keys(\App\Models\SalaryPayment::METHODS))], 'paidOn' => ['required', 'date', 'before_or_equal:today'], 'reference' => ['nullable', 'string', 'max:255']], [], ['paidOn' => 'payment date']);
        $workflow->pay(PayrollItem::findOrFail($this->payingItemId), ['amount' => $this->amount, 'paid_on' => $this->paidOn, 'method' => $this->method, 'reference' => $this->reference ?: null], auth()->id());
        $this->payingItemId = null;
        $this->toast('Payment recorded.');
    }

    public function closeModal(): void
    {
        $this->payingItemId = null;
    }

    public function render()
    {
        $term = trim($this->search);
        $payments = SalaryPayment::with(['payrollItem.employee', 'payrollItem.run'])
            ->when(strtotime($this->from), fn ($q) => $q->whereDate('paid_on', '>=', $this->from))
            ->when(strtotime($this->to), fn ($q) => $q->whereDate('paid_on', '<=', $this->to))
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->where('reference', 'like', "%{$term}%")->orWhereHas('payrollItem.employee', fn ($e) => $e->where('name', 'like', "%{$term}%"))))
            ->latest('paid_on')->latest('id')->paginate(20);
        $outstanding = PayrollItem::with(['employee', 'run', 'payments'])->whereHas('run', fn ($q) => $q->where('status', 'locked'))->get()
            ->map(fn ($item) => tap($item, fn ($i) => $i->setAttribute('balance', round((float) $i->net_amount - (float) $i->payments->sum('amount'), 2))))
            ->filter(fn ($item) => $item->balance > 0)->values();

        return $this->page('livewire.payroll.payments', [
            'payments' => $payments,
            'outstanding' => $outstanding,
            'paying' => $this->payingItemId ? PayrollItem::with(['employee', 'payments'])->find($this->payingItemId) : null,
        ], 'Payments');
    }
}
