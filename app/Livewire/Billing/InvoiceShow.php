<?php

namespace App\Livewire\Billing;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AuditLog;
use App\Models\BillingInvoice;
use App\Models\BillingInvoicePayment;
use App\Services\AuditLogger;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

class InvoiceShow extends Component
{
    use InteractsWithHr, WithFileUploads;

    #[Locked] public int $invoiceId;
    public bool $showPayment = false;
    public string $payAmount = '';
    public string $payDate = '';
    public string $payMethod = 'Bank';
    public string $payReference = '';
    public ?int $payAccountId = null;
    public string $payNote = '';
    public $payReceipt = null;
    // E-mail to the customer: send / resend the invoice, or a payment reminder.
    public ?string $emailKind = null;
    /** For a payment-receipt e-mail: which payment. */
    public ?int $emailPaymentId = null;
    /** Record payment: also e-mail the receipt to the customer. */
    public bool $payEmail = true;
    public string $emailTo = '';
    public string $emailMessage = '';
    // Credit note against this invoice.
    public bool $showCredit = false;
    public ?int $creditId = null;
    public string $creditAmount = '';
    public string $creditDate = '';
    public string $creditDescription = '';

    public function mount(BillingInvoice $invoice): void
    {
        abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403);
        $this->invoiceId = $invoice->id;
    }

    private function invoice(): BillingInvoice
    {
        return BillingInvoice::with(['customer', 'items.tax', 'payments.recorder', 'payments.account', 'creditNotes', 'project'])->findOrFail($this->invoiceId);
    }

    public function markSent(AuditLogger $audit): void
    {
        $inv = $this->invoice();
        if ($inv->status === 'Draft') { $inv->update(['status' => 'Sent', 'send_date' => now()->toDateString()]); $audit->record('billing.invoice_sent', $inv); }
        $this->toast('Invoice marked as sent.');
    }

    private function guard(): void
    {
        abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403);
    }

    public function openEmail(string $kind, ?int $paymentId = null): void
    {
        $this->guard();
        abort_unless(in_array($kind, ['send', 'reminder', 'payment'], true), 404);
        if ($kind === 'payment') abort_unless($paymentId && BillingInvoicePayment::where('invoice_id', $this->invoiceId)->whereKey($paymentId)->exists(), 404);
        $this->emailPaymentId = $kind === 'payment' ? $paymentId : null;
        $this->emailKind = $kind;
        $this->emailTo = (string) $this->invoice()->customer?->email;
        $this->emailMessage = '';
        $this->resetValidation();
    }

    /** E-mails the invoice link (sending a Draft marks it Sent) or a payment reminder; failures are shown, not hidden. */
    public function sendEmail(AuditLogger $audit, \App\Services\InvoiceActions $actions, \App\Services\BillingService $billing): void
    {
        $this->guard();
        $this->validate(['emailTo' => ['required', 'email:rfc', 'max:191'], 'emailMessage' => ['nullable', 'string', 'max:2000']], [], ['emailTo' => 'e-mail', 'emailMessage' => 'message']);
        $inv = $this->invoice();
        if ($this->emailKind === 'payment') {
            $error = app(\App\Services\BillingMailer::class)->paymentReceipt(BillingInvoicePayment::where('invoice_id', $inv->id)->findOrFail($this->emailPaymentId), $this->emailTo, $this->emailMessage);
            if ($error) { $this->addError('emailTo', $error); return; }
            $this->emailKind = null;
            $this->toast('Payment receipt e-mailed to '.$this->emailTo.'.');

            return;
        }
        $kind = $this->emailKind === 'reminder' ? 'reminder' : 'send';
        if ($kind === 'reminder' && $inv->due() <= 0) { $this->addError('emailTo', 'Is invoice par kuch due nahi hai.'); return; }
        if ($inv->status === 'Draft') {
            $inv->update(['status' => 'Sent', 'send_date' => now()->toDateString()]);
            $audit->record('billing.invoice_sent', $inv);
        }
        try {
            (new \App\Support\MailRecipient($this->emailTo, (string) $inv->customer?->name))
                ->notifyNow(new \App\Notifications\InvoiceEmail($inv, $kind, $actions->publicUrl($inv), $billing->company(), $this->emailMessage));
        } catch (\Throwable $e) {
            report($e);
            $this->addError('emailTo', 'E-mail nahi gaya: '.\Illuminate\Support\Str::limit($e->getMessage(), 160).' (Settings → Email check karo).');

            return;
        }
        $audit->record('billing.invoice_emailed', $inv, ['to' => $this->emailTo, 'kind' => $kind]);
        $this->emailKind = null;
        $this->toast($kind === 'reminder' ? 'Payment reminder sent to '.$this->emailTo.'.' : 'Invoice e-mailed to '.$this->emailTo.'.');
    }

    public function openCredit(?int $id = null): void
    {
        $this->guard();
        $note = $id ? ($this->invoice()->creditNotes->firstWhere('id', $id) ?? abort(404)) : null;
        $this->creditId = $note?->id;
        $this->creditAmount = $note ? (string) (float) $note->amount : (string) max(0, $this->invoice()->due());
        $this->creditDate = $note ? \Illuminate\Support\Carbon::parse($note->date)->toDateString() : today()->toDateString();
        $this->creditDescription = (string) $note?->description;
        $this->resetValidation();
        $this->showCredit = true;
    }

    /** A credit note lowers what the customer owes on this invoice (never below zero). */
    public function saveCredit(AuditLogger $audit): void
    {
        $this->guard();
        $inv = $this->invoice();
        $existing = $this->creditId ? ($inv->creditNotes->firstWhere('id', $this->creditId) ?? abort(404)) : null;
        $max = round(max(0, $inv->due()) + (float) $existing?->amount, 2);
        $this->validate([
            'creditAmount' => ['required', 'numeric', 'min:0.01', 'max:'.max(0.01, $max)],
            'creditDate' => ['required', 'date'], 'creditDescription' => ['nullable', 'string', 'max:500'],
        ], ['creditAmount.max' => 'Credit note due amount (₹'.number_format($max, 2).') se zyada nahi ho sakta.'], ['creditAmount' => 'amount', 'creditDate' => 'date']);
        $values = ['invoice_id' => $inv->id, 'customer_id' => $inv->customer_id, 'amount' => (float) $this->creditAmount, 'date' => $this->creditDate,
            'description' => trim($this->creditDescription) ?: null];
        if ($existing) $existing->update($values);
        $note = $existing ?? \App\Models\BillingCreditNote::create($values + ['created_by' => auth()->id()]);
        $this->invoice()->refreshStatus();
        $audit->record($existing ? 'billing.credit_note_updated' : 'billing.credit_note_created', $note, ['amount' => (float) $this->creditAmount]);
        $this->showCredit = false;
        $this->toast('Credit note saved.');
    }

    public function deleteCredit(int $id, AuditLogger $audit): void
    {
        $this->guard();
        $note = \App\Models\BillingCreditNote::where('invoice_id', $this->invoiceId)->findOrFail($id);
        $audit->record('billing.credit_note_deleted', $this->invoice(), ['amount' => (float) $note->amount]);
        $note->delete();
        $inv = $this->invoice();
        if ($inv->status === 'Paid') $inv->status = $inv->paidAmount() > 0 ? 'Partially Paid' : 'Unpaid';
        $inv->refreshStatus();
        $this->toast('Credit note removed.');
    }

    public function duplicate(\App\Services\InvoiceActions $actions): void
    {
        abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403);
        $copy = $actions->duplicate($this->invoice(), auth()->id());
        $this->redirect(route('billing.invoices.edit', $copy));
    }

    public function delete(\App\Services\InvoiceActions $actions): void
    {
        abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403);
        $invoice = $this->invoice();
        try {
            $actions->delete($invoice);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->toast(collect($e->errors())->flatten()->first(), 'error');

            return;
        }
        session()->flash('status', 'Invoice '.$invoice->number().' deleted.');
        $this->redirect(route('billing.invoices'));
    }

    public function openPayment(): void
    {
        $inv = $this->invoice();
        $this->payAmount = (string) max(0, $inv->due());
        $this->payDate = now()->toDateString();
        $this->payMethod = 'Manually'; // the CMS shows payments added by hand as "Manually"
        $this->payReference = '';
        $this->payNote = '';
        $this->payReceipt = null;
        $this->payEmail = filter_var((string) $inv->customer?->email, FILTER_VALIDATE_EMAIL) !== false;
        $this->payAccountId = \App\Models\BillingBankAccount::where('is_active', true)->where('bank_name', '!=', 'Cash')->orderBy('id')->value('id')
            ?? \App\Models\BillingBankAccount::where('is_active', true)->orderBy('id')->value('id');
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
            'payAccountId' => ['nullable', 'exists:billing_bank_accounts,id'],
            'payNote' => ['nullable', 'string', 'max:500'],
            'payReceipt' => \App\Http\Controllers\BillingFileController::RULES,
        ], ['payReceipt.mimes' => 'Receipt image (jpg / png) ya PDF honi chahiye.'], ['payAmount' => 'amount', 'payDate' => 'date', 'payMethod' => 'method', 'payAccountId' => 'account', 'payNote' => 'description']);
        $payment = BillingInvoicePayment::create(['invoice_id' => $inv->id, 'date' => $this->payDate, 'amount' => (float) $this->payAmount,
            'method' => $this->payMethod, 'reference' => $this->payReference ?: null, 'account_id' => $this->payAccountId ?: null, 'note' => trim($this->payNote) ?: null,
            'receipt_path' => $this->payReceipt ? \App\Http\Controllers\BillingFileController::store($this->payReceipt) : null, 'recorded_by' => auth()->id()]);
        $this->payReceipt = null;
        $inv->load('payments', 'creditNotes', 'items');
        if ($inv->status === 'Draft') $inv->status = 'Sent';
        $inv->refreshStatus();
        $audit->record('billing.payment_recorded', $inv, ['amount' => (float) $this->payAmount]);
        $this->showPayment = false;
        // "New Invoice Payment" e-mail: the receipt goes to the customer when asked.
        $to = (string) $this->invoice()->customer?->email;
        if ($this->payEmail && filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $error = app(\App\Services\BillingMailer::class)->paymentReceipt($payment, $to);
            $this->toast($error ? 'Payment recorded, but the receipt e-mail failed: '.$error : 'Payment recorded and the receipt e-mailed to '.$to.'.', $error ? 'error' : 'success');

            return;
        }
        $this->toast('Payment recorded.');
    }

    /** Removes a payment entered by mistake; the invoice's status and due amount follow. */
    public function deletePayment(int $paymentId, AuditLogger $audit): void
    {
        abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403);
        $payment = BillingInvoicePayment::where('invoice_id', $this->invoiceId)->findOrFail($paymentId);
        $audit->record('billing.payment_deleted', $payment->invoice, ['amount' => (float) $payment->amount, 'date' => $payment->date?->toDateString(), 'reference' => $payment->reference]);
        \App\Http\Controllers\BillingFileController::forget($payment->receipt_path);
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
            'accounts' => \App\Models\BillingBankAccount::where('is_active', true)->orderBy('bank_name')->get(),
            'publicUrl' => $invoice->status === 'Draft' ? null : app(\App\Services\InvoiceActions::class)->publicUrl($invoice),
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
                    'billing.invoice_created' => ['Invoice created', 'blue', ! empty($m['copied_from']) ? 'Copied from invoice '.$m['copied_from'].'.' : 'Invoice created.'],
                    'billing.invoice_updated' => ['Invoice updated', 'violet', 'Invoice details changed.'],
                    'billing.invoice_sent' => ['Marked as sent', 'blue', 'Invoice marked as sent.'],
                    'billing.payment_recorded' => ['Payment added', 'green', 'Payment of '.($money($m) ?? '—').' recorded.'],
                    'billing.payment_deleted' => ['Payment removed', 'red', 'Payment of '.($money($m) ?? '—').(! empty($m['date']) ? ' dated '.\Illuminate\Support\Carbon::parse($m['date'])->format('d M Y') : '').' removed.'],
                    'billing.credit_note_created' => ['Credit note added', 'amber', 'A credit note was added against this invoice.'],
                    'billing.credit_note_updated' => ['Credit note updated', 'amber', 'A credit note on this invoice was changed.'],
                    'billing.credit_note_deleted' => ['Credit note removed', 'red', 'Credit note of '.($money($m) ?? '—').' removed.'],
                    'billing.email_sent' => [($m['kind'] ?? '') === 'payment_receipt' ? 'Receipt e-mailed' : 'E-mail sent', 'blue', (($m['kind'] ?? '') === 'payment_receipt' ? 'Payment receipt' : 'E-mail').' sent to '.($m['to'] ?? '—').'.'],
                    'billing.invoice_emailed' => [($m['kind'] ?? '') === 'reminder' ? 'Reminder e-mailed' : 'Invoice e-mailed', 'blue', (($m['kind'] ?? '') === 'reminder' ? 'Payment reminder' : 'Invoice').' e-mailed to '.($m['to'] ?? '—').'.'],
                    default => [$log->describe()['title'], 'gray', $log->describe()['detail'] ?? '—'],
                };

                return (object) ['at' => $log->created_at, 'label' => $label, 'tone' => $tone, 'detail' => $detail, 'by' => $log->actor?->name ?? 'System'];
            });
    }
}
