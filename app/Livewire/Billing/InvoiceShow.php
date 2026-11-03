<?php

namespace App\Livewire\Billing;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AuditLog;
use App\Models\BillingInvoice;
use App\Models\BillingInvoicePayment;
use App\Services\AuditLogger;
use Livewire\Attributes\Locked;
use Livewire\Component;

class InvoiceShow extends Component
{
    use InteractsWithHr;

    #[Locked] public int $invoiceId;
    public bool $showPayment = false;
    public string $payAmount = '';
    public string $payDate = '';
    public string $payMethod = 'Bank';
    public string $payReference = '';

    public function mount(BillingInvoice $invoice): void
    {
        abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403);
        $this->invoiceId = $invoice->id;
    }

    private function invoice(): BillingInvoice
    {
        return BillingInvoice::with(['customer', 'items.tax', 'payments.recorder', 'creditNotes', 'project'])->findOrFail($this->invoiceId);
    }

    public function markSent(AuditLogger $audit): void
    {
        $inv = $this->invoice();
        if ($inv->status === 'Draft') { $inv->update(['status' => 'Sent', 'send_date' => now()->toDateString()]); $audit->record('billing.invoice_sent', $inv); }
        $this->toast('Invoice marked as sent.');
    }

    public function openPayment(): void
    {
        $inv = $this->invoice();
        $this->payAmount = (string) max(0, $inv->due());
        $this->payDate = now()->toDateString();
        $this->payMethod = 'Bank';
        $this->payReference = '';
        $this->resetValidation();
        $this->showPayment = true;
    }

    public function recordPayment(AuditLogger $audit): void
    {
        $inv = $this->invoice();
        $this->validate([
            'payAmount' => ['required', 'numeric', 'min:0.01', 'max:'.max(0.01, $inv->due() + 0.01)],
            'payDate' => ['required', 'date'],
            'payMethod' => ['required', 'string', 'max:50'],
            'payReference' => ['nullable', 'string', 'max:100'],
        ], [], ['payAmount' => 'amount', 'payDate' => 'date', 'payMethod' => 'method']);
        BillingInvoicePayment::create(['invoice_id' => $inv->id, 'date' => $this->payDate, 'amount' => (float) $this->payAmount,
            'method' => $this->payMethod, 'reference' => $this->payReference ?: null, 'recorded_by' => auth()->id()]);
        $inv->load('payments', 'creditNotes', 'items');
        if ($inv->status === 'Draft') $inv->status = 'Sent';
        $inv->refreshStatus();
        $audit->record('billing.payment_recorded', $inv, ['amount' => (float) $this->payAmount]);
        $this->showPayment = false;
        $this->toast('Payment recorded.');
    }

    /** Removes a payment entered by mistake; the invoice's status and due amount follow. */
    public function deletePayment(int $paymentId, AuditLogger $audit): void
    {
        abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403);
        $payment = BillingInvoicePayment::where('invoice_id', $this->invoiceId)->findOrFail($paymentId);
        $audit->record('billing.payment_deleted', $payment->invoice, ['amount' => (float) $payment->amount, 'date' => $payment->date?->toDateString(), 'reference' => $payment->reference]);
        $payment->delete();
        $inv = $this->invoice();
        if ($inv->status === 'Paid' || $inv->status === 'Partially Paid') $inv->status = 'Unpaid';
        $inv->refreshStatus();
        $this->toast('Payment removed.');
    }

    public function render(\App\Services\BillingService $billing)
    {
        $invoice = $this->invoice();
        $taxes = $billing->taxSplit($invoice);
        // One GST rate on the invoice → show it next to CGST / SGST / IGST; several rates → no single figure.
        $rates = collect($taxes)->pluck('rate')->filter(fn ($r) => $r > 0)->unique()->values();
        $rate = $rates->count() === 1 ? (float) $rates->first() : ($rates->isEmpty() ? 0.0 : null);

        return $this->page('livewire.billing.invoice-show', [
            'invoice' => $invoice, 'taxes' => $taxes, 'taxRate' => $rate,
            'interState' => $billing->isInterState($invoice->place_of_supply ?: $invoice->customer?->billing_state),
            'terms' => (string) ($billing->company()['terms'] ?? ''),
            'activity' => $this->activity($invoice),
        ], 'Invoice');
    }

    /** What happened to this invoice (and its credit notes), newest first, in plain words with a colour for the pill. */
    private function activity(BillingInvoice $invoice): \Illuminate\Support\Collection
    {
        $money = fn ($m) => isset($m['amount']) ? '₹'.number_format((float) $m['amount'], 2) : null;

        return AuditLog::with('actor')
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('subject_type', BillingInvoice::class)->where('subject_id', $invoice->id))
                ->when($invoice->creditNotes->isNotEmpty(), fn ($w) => $w->orWhere(fn ($c) => $c->where('subject_type', \App\Models\BillingCreditNote::class)->whereIn('subject_id', $invoice->creditNotes->pluck('id')))))
            ->latest('created_at')->latest('id')->limit(50)->get()
            ->map(function (AuditLog $log) use ($money) {
                $m = (array) ($log->metadata ?? []);
                [$label, $tone, $detail] = match ($log->action) {
                    'billing.invoice_created' => ['Invoice created', 'blue', 'Invoice created.'],
                    'billing.invoice_updated' => ['Invoice updated', 'violet', 'Invoice details changed.'],
                    'billing.invoice_sent' => ['Marked as sent', 'blue', 'Invoice marked as sent.'],
                    'billing.payment_recorded' => ['Payment added', 'green', 'Payment of '.($money($m) ?? '—').' recorded.'],
                    'billing.payment_deleted' => ['Payment removed', 'red', 'Payment of '.($money($m) ?? '—').(! empty($m['date']) ? ' dated '.\Illuminate\Support\Carbon::parse($m['date'])->format('d M Y') : '').' removed.'],
                    'billing.credit_note_created' => ['Credit note added', 'amber', 'A credit note was added against this invoice.'],
                    'billing.credit_note_updated' => ['Credit note updated', 'amber', 'A credit note on this invoice was changed.'],
                    default => [$log->describe()['title'], 'gray', $log->describe()['detail'] ?? '—'],
                };

                return (object) ['at' => $log->created_at, 'label' => $label, 'tone' => $tone, 'detail' => $detail, 'by' => $log->actor?->name ?? 'System'];
            });
    }
}
