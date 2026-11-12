<?php

namespace App\Livewire\Billing;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\BillingCreditNote;
use App\Models\BillingCustomer;
use App\Models\BillingInvoice;
use App\Services\AuditLogger;
use Livewire\Attributes\Url;
use Livewire\Component;
use App\Livewire\Concerns\WithPaginationNoUrl as WithPagination;

/** Credit notes — reduce what a customer owes on an invoice. */
class CreditNotes extends Component
{
    use InteractsWithHr, WithPagination;

    #[Url] public string $search = '';
    #[Url] public string $customer = '';
    #[Url] public string $from = '';
    #[Url] public string $to = '';
    #[Url] public int $perPage = 10;
    public bool $showForm = false;
    public ?int $editingId = null;
    public ?int $invoiceId = null;
    public ?int $customerId = null;
    public string $amount = '';
    public string $date = '';
    public string $description = '';

    public function updated($property): void
    {
        if (in_array($property, ['search', 'customer', 'from', 'to', 'perPage'], true)) $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'customer', 'from', 'to');
        $this->resetPage();
    }

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
        // Like the CMS: a credit note is always against an invoice; the customer comes from it and it can't exceed what is due.
        $invoice = $this->invoiceId ? BillingInvoice::with('items.tax', 'payments', 'creditNotes')->find($this->invoiceId) : null;
        $current = $this->editingId && $invoice ? (float) ($invoice->creditNotes->firstWhere('id', $this->editingId)?->amount ?? 0) : 0.0;
        $max = $invoice ? round(max(0, $invoice->due()) + $current, 2) : 0;
        $data = $this->validate([
            'invoiceId' => ['required', 'exists:billing_invoices,id'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:'.max(0.01, $max)],
            'date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:500'],
        ], ['amount.max' => 'A credit note cannot be more than the invoice due (₹'.number_format($max, 2).').', 'invoiceId.required' => 'Choose an invoice.'], ['invoiceId' => 'invoice']);
        $previousInvoice = $this->editingId ? BillingCreditNote::find($this->editingId)?->invoice_id : null;
        $note = BillingCreditNote::updateOrCreate(['id' => $this->editingId], [
            'invoice_id' => $invoice->id, 'customer_id' => $invoice->customer_id,
            'amount' => $data['amount'], 'date' => $data['date'], 'description' => $data['description'] ?: null,
            'created_by' => $this->editingId ? BillingCreditNote::find($this->editingId)->created_by : auth()->id(),
        ]);
        foreach (array_unique(array_filter([$note->invoice_id, $previousInvoice])) as $iid) {
            $inv = BillingInvoice::with('items.tax', 'payments', 'creditNotes')->find($iid);
            if (! $inv) continue;
            if ($inv->status === 'Paid' && $inv->due() > 0) $inv->status = $inv->paidAmount() > 0 ? 'Partially Paid' : 'Unpaid';
            $inv->refreshStatus();
        }
        $audit->record($this->editingId ? 'billing.credit_note_updated' : 'billing.credit_note_created', $note);
        $this->showForm = false;
        $this->toast('Credit note saved.');
    }

    public function delete(int $id, AuditLogger $audit): void
    {
        $this->guard();
        $note = BillingCreditNote::findOrFail($id);
        $audit->record('billing.credit_note_deleted', $note->invoice ?? $note, ['amount' => (float) $note->amount]);
        $note->delete();
        if ($note->invoice_id && $inv = BillingInvoice::with('items', 'payments', 'creditNotes')->find($note->invoice_id)) {
            if ($inv->status === 'Paid') $inv->status = $inv->paidAmount() > 0 ? 'Partially Paid' : 'Unpaid';
            $inv->refreshStatus();
        }
        $this->toast('Credit note removed.');
    }

    public function closeModal(): void { $this->showForm = false; }

    public function render()
    {
        $this->guard();
        $term = trim($this->search);
        $date = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
        $query = BillingCreditNote::query()
            ->when($this->customer, fn ($q, $c) => $q->where('customer_id', (int) $c))
            ->when($date($this->from), fn ($q, $d) => $q->whereDate('date', '>=', $d))
            ->when($date($this->to), fn ($q, $d) => $q->whereDate('date', '<=', $d))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->whereHas('customer', fn ($c) => $c->where('name', 'like', "%{$term}%"))
                ->orWhere('description', 'like', "%{$term}%")
                ->when(preg_match('/(\d+)$/', $term, $m) ? (int) $m[1] : null, fn ($n, $number) => $n->orWhereHas('invoice', fn ($i) => $i->where('number', $number)))));
        $notes = (clone $query)->with('customer', 'invoice')->orderByDesc('date')->orderByDesc('id')->paginate(in_array($this->perPage, [10, 25, 50, 100], true) ? $this->perPage : 10);

        return $this->page('livewire.billing.credit-notes', [
            'notes' => $notes,
            'total' => (float) (clone $query)->sum('amount'),
            'customers' => BillingCustomer::orderBy('name')->get(['id', 'name']),
            'invoices' => BillingInvoice::with('customer')->orderByDesc('issue_date')->get(),
        ], 'Credit notes');
    }
}
