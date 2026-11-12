<?php

namespace App\Livewire\Billing;

use App\Http\Controllers\BillingFileController;
use App\Livewire\Concerns\InteractsWithHr;
use App\Models\BillingBankAccount;
use App\Models\BillingBill;
use App\Models\BillingBillPayment;
use App\Models\BillingDebitNote;
use App\Services\AuditLogger;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/** A supplier bill: items, payments made (with account and receipt), debit notes. */
class BillShow extends Component
{
    use InteractsWithHr, WithFileUploads;

    #[Locked] public int $billId;
    public bool $showPayment = false;
    public string $payAmount = '';
    public string $payDate = '';
    public ?int $payAccountId = null;
    public string $payMethod = 'Bank';
    public string $payReference = '';
    public string $payNote = '';
    public $payReceipt = null;
    public bool $showDebit = false;
    public ?int $debitId = null;
    public string $debitAmount = '';
    public string $debitDate = '';
    public string $debitDescription = '';

    private function guard(): void { abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403); }

    public function mount(BillingBill $bill): void
    {
        $this->guard();
        $this->billId = $bill->id;
    }

    private function bill(): BillingBill
    {
        return BillingBill::with(['vendor', 'category', 'items.tax', 'payments.account', 'payments.recorder', 'debitNotes'])->findOrFail($this->billId);
    }

    /** "Received": the bill is booked (Draft → Sent / Unpaid). */
    public function markReceived(AuditLogger $audit): void
    {
        $this->guard();
        $bill = $this->bill();
        if ($bill->status === 'Draft') { $bill->status = 'Sent'; $bill->refreshStatus(); $audit->record('billing.bill_received', $bill); }
        $this->toast('Bill marked as received.');
    }

    public function openPayment(): void
    {
        $this->guard();
        $this->payAmount = (string) max(0, $this->bill()->due());
        $this->payDate = today()->toDateString();
        $this->payAccountId = BillingBankAccount::where('is_active', true)->where('bank_name', '!=', 'Cash')->orderBy('id')->value('id') ?? BillingBankAccount::where('is_active', true)->value('id');
        $this->payMethod = 'Bank';
        $this->reset('payReference', 'payNote', 'payReceipt');
        $this->resetValidation();
        $this->showPayment = true;
    }

    public function recordPayment(AuditLogger $audit): void
    {
        $this->guard();
        $bill = $this->bill();
        $this->validate([
            'payAmount' => ['required', 'numeric', 'min:0.01', 'max:'.max(0.01, $bill->due() + 0.01)], 'payDate' => ['required', 'date'],
            'payAccountId' => ['required', 'exists:billing_bank_accounts,id'], 'payMethod' => ['required', 'string', 'max:50'],
            'payReference' => ['nullable', 'string', 'max:100'], 'payNote' => ['nullable', 'string', 'max:500'], 'payReceipt' => BillingFileController::RULES,
        ], ['payAmount.max' => 'Bill par itna due nahi hai.'], ['payAmount' => 'amount', 'payDate' => 'date', 'payAccountId' => 'account']);
        BillingBillPayment::create(['bill_id' => $bill->id, 'date' => $this->payDate, 'amount' => (float) $this->payAmount, 'account_id' => $this->payAccountId,
            'method' => $this->payMethod, 'reference' => $this->payReference ?: null, 'note' => trim($this->payNote) ?: null,
            'receipt_path' => $this->payReceipt ? BillingFileController::store($this->payReceipt) : null, 'recorded_by' => auth()->id()]);
        $bill = $this->bill();
        if ($bill->status === 'Draft') $bill->status = 'Sent';
        $bill->refreshStatus();
        $audit->record('billing.bill_payment_recorded', $bill, ['amount' => (float) $this->payAmount]);
        $this->reset('payReceipt');
        $this->showPayment = false;
        $this->toast('Payment recorded.');
    }

    public function deletePayment(int $id, AuditLogger $audit): void
    {
        $this->guard();
        $p = BillingBillPayment::where('bill_id', $this->billId)->findOrFail($id);
        $audit->record('billing.bill_payment_deleted', $this->bill(), ['amount' => (float) $p->amount]);
        BillingFileController::forget($p->receipt_path);
        $p->delete();
        $bill = $this->bill();
        if ($bill->status === 'Paid' || $bill->status === 'Partially Paid') $bill->status = 'Unpaid';
        $bill->refreshStatus();
        $this->toast('Payment removed.');
    }

    public function openDebit(?int $id = null): void
    {
        $this->guard();
        $note = $id ? ($this->bill()->debitNotes->firstWhere('id', $id) ?? abort(404)) : null;
        $this->debitId = $note?->id;
        $this->debitAmount = $note ? (string) (float) $note->amount : (string) max(0, $this->bill()->due());
        $this->debitDate = $note?->date?->toDateString() ?? today()->toDateString();
        $this->debitDescription = (string) $note?->description;
        $this->resetValidation();
        $this->showDebit = true;
    }

    public function saveDebit(AuditLogger $audit): void
    {
        $this->guard();
        $bill = $this->bill();
        $existing = $this->debitId ? $bill->debitNotes->firstWhere('id', $this->debitId) : null;
        $max = round(max(0, $bill->due()) + (float) $existing?->amount, 2);
        $this->validate(['debitAmount' => ['required', 'numeric', 'min:0.01', 'max:'.max(0.01, $max)], 'debitDate' => ['required', 'date'], 'debitDescription' => ['nullable', 'string', 'max:500']],
            ['debitAmount.max' => 'Debit note due amount (₹'.number_format($max, 2).') se zyada nahi ho sakta.'], ['debitAmount' => 'amount', 'debitDate' => 'date']);
        $values = ['bill_id' => $bill->id, 'vendor_id' => $bill->vendor_id, 'amount' => (float) $this->debitAmount, 'date' => $this->debitDate, 'description' => trim($this->debitDescription) ?: null];
        if ($existing) $existing->update($values);
        $note = $existing ?? BillingDebitNote::create($values + ['created_by' => auth()->id()]);
        $this->bill()->refreshStatus();
        $audit->record($existing ? 'billing.debit_note_updated' : 'billing.debit_note_created', $note, ['amount' => (float) $this->debitAmount]);
        $this->showDebit = false;
        $this->toast('Debit note saved.');
    }

    public function deleteDebit(int $id, AuditLogger $audit): void
    {
        $this->guard();
        $note = BillingDebitNote::where('bill_id', $this->billId)->findOrFail($id);
        $audit->record('billing.debit_note_deleted', $this->bill(), ['amount' => (float) $note->amount]);
        $note->delete();
        $bill = $this->bill();
        if ($bill->status === 'Paid') $bill->status = $bill->paidAmount() > 0 ? 'Partially Paid' : 'Unpaid';
        $bill->refreshStatus();
        $this->toast('Debit note removed.');
    }

    public function render()
    {
        $this->guard();

        return $this->page('livewire.billing.bill-show', [
            'bill' => $this->bill(),
            'accounts' => BillingBankAccount::where('is_active', true)->orderBy('bank_name')->get(),
        ], 'Bill');
    }
}
