<?php

namespace App\Livewire\Billing;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\BillingCreditNote;
use App\Models\BillingCustomer;
use App\Models\BillingInvoice;
use App\Services\AuditLogger;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Credit notes — reduce what a customer owes on an invoice. */
class CreditNotes extends Component
{
    use InteractsWithHr, WithPagination;

    #[Url] public string $search = '';
    public bool $showForm = false;
    public ?int $editingId = null;
    public ?int $invoiceId = null;
    public ?int $customerId = null;
    public string $amount = '0';
    public string $date = '';
    public string $description = '';

    public function updatedSearch(): void { $this->resetPage(); }

    private function guard(): void { abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403); }

    public function create(): void
    {
        $this->guard();
        $this->reset('editingId', 'invoiceId', 'customerId', 'amount', 'description');
        $this->date = today()->toDateString();
        $this->resetValidation();
        $this->showForm = true;
    }

    /** Pick up customer from the chosen invoice. */
    public function updatedInvoiceId($value): void
    {
        if ($value && $inv = BillingInvoice::find($value)) {
            $this->customerId = $inv->customer_id;
        }
    }

    public function edit(int $id): void
    {
        $this->guard();
        $c = BillingCreditNote::findOrFail($id);
        $this->fill(['editingId' => $c->id, 'invoiceId' => $c->invoice_id, 'customerId' => $c->customer_id,
            'amount' => (string) (float) $c->amount, 'date' => $c->date->toDateString(), 'description' => (string) $c->description]);
        $this->showForm = true;
    }

    public function save(AuditLogger $audit): void
    {
        $this->guard();
        $data = $this->validate([
            'invoiceId' => ['nullable', 'exists:billing_invoices,id'],
            'customerId' => ['nullable', 'exists:billing_customers,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
        $note = BillingCreditNote::updateOrCreate(['id' => $this->editingId], [
            'invoice_id' => $data['invoiceId'] ?: null, 'customer_id' => $data['customerId'] ?: null,
            'amount' => $data['amount'], 'date' => $data['date'], 'description' => $data['description'] ?: null,
            'created_by' => $this->editingId ? BillingCreditNote::find($this->editingId)->created_by : auth()->id(),
        ]);
        if ($note->invoice_id && $inv = BillingInvoice::with('items', 'payments', 'creditNotes')->find($note->invoice_id)) {
            $inv->refreshStatus();
        }
        $audit->record($this->editingId ? 'billing.credit_note_updated' : 'billing.credit_note_created', $note);
        $this->showForm = false;
        $this->toast('Credit note saved.');
    }

    public function closeModal(): void { $this->showForm = false; }

    public function render()
    {
        $this->guard();
        $term = trim($this->search);
        $notes = BillingCreditNote::with('customer', 'invoice')
            ->when($term !== '', fn ($q) => $q->whereHas('customer', fn ($q) => $q->where('name', 'like', "%{$term}%")))
            ->orderByDesc('date')->paginate(20);

        return $this->page('livewire.billing.credit-notes', [
            'notes' => $notes,
            'total' => BillingCreditNote::sum('amount'),
            'customers' => BillingCustomer::orderBy('name')->get(['id', 'name']),
            'invoices' => BillingInvoice::with('customer')->orderByDesc('issue_date')->get(),
        ], 'Credit notes');
    }
}
