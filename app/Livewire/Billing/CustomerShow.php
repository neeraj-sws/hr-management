<?php

namespace App\Livewire\Billing;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\BillingCustomer;
use Livewire\Component;

/** Customer detail: info cards, company stats (balance, overdue, totals) and their estimates + invoices. */
class CustomerShow extends Component
{
    use InteractsWithHr;

    public BillingCustomer $customer;

    public bool $emailOpen = false;
    public string $emailTo = '';
    public string $emailMessage = '';
    public string $statementFrom = '';
    public string $statementTo = '';

    public function openEmail(): void
    {
        abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403);
        $this->emailTo = (string) $this->customer->email;
        $this->emailMessage = '';
        $this->statementFrom = today()->subMonths(6)->startOfMonth()->toDateString();
        $this->statementTo = today()->toDateString();
        $this->resetValidation();
        $this->emailOpen = true;
    }

    /** Account statement e-mail (signed link to the statement for the period). */
    public function sendEmail(\App\Services\BillingMailer $mailer, \App\Services\BillingService $billing): void
    {
        abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403);
        $this->validate(['emailTo' => ['required', 'email:rfc', 'max:191'], 'emailMessage' => ['nullable', 'string', 'max:2000'],
            'statementFrom' => ['required', 'date'], 'statementTo' => ['required', 'date', 'after_or_equal:statementFrom']], [], ['emailTo' => 'e-mail', 'statementFrom' => 'from', 'statementTo' => 'to']);
        $data = \App\Http\Controllers\BillingPrintController::statementData($this->customer, $this->statementFrom, $this->statementTo, $billing);
        if ($error = $mailer->statement($this->customer, $data['from'], $data['to'], $this->emailTo, $data['closing'], $this->emailMessage)) { $this->addError('emailTo', $error); return; }
        $this->emailOpen = false;
        $this->toast('Statement e-mailed to '.$this->emailTo.'.');
    }

    public function mount(BillingCustomer $customer): void
    {
        abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403);
        $this->customer = $customer;
    }

    /** Delete the customer (only one with no invoices or proposals, so no billing history is lost). */
    public function deleteCustomer(\App\Services\AuditLogger $audit): void
    {
        abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403);
        if ($this->customer->invoices()->exists() || $this->customer->estimates()->exists()) {
            $this->toast('Is customer ki invoices / proposals hain — delete nahi ho sakta.', 'error');

            return;
        }
        $audit->record('billing.customer_deleted', $this->customer);
        $this->customer->delete();
        session()->flash('status', 'Customer deleted.');
        $this->redirect(route('billing.customers'));
    }

    public function duplicateEstimate(int $id, \App\Services\InvoiceActions $actions): void
    {
        abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403);
        $copy = $actions->duplicateEstimate($this->customer->estimates()->findOrFail($id), auth()->id());
        $this->redirect(route('billing.estimates.edit', $copy));
    }

    public function deleteEstimate(int $id, \App\Services\InvoiceActions $actions): void
    {
        abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403);
        try {
            $actions->deleteEstimate($this->customer->estimates()->findOrFail($id));
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->toast(collect($e->errors())->flatten()->first(), 'error');

            return;
        }
        $this->toast('Proposal deleted.');
    }

    public function duplicateInvoice(int $id, \App\Services\InvoiceActions $actions): void
    {
        abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403);
        $copy = $actions->duplicate($this->customer->invoices()->findOrFail($id), auth()->id());
        $this->redirect(route('billing.invoices.edit', $copy));
    }

    public function deleteInvoice(int $id, \App\Services\InvoiceActions $actions): void
    {
        abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403);
        $invoice = $this->customer->invoices()->findOrFail($id);
        try {
            $actions->delete($invoice);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->toast(collect($e->errors())->flatten()->first(), 'error');

            return;
        }
        $this->toast('Invoice '.$invoice->number().' deleted.');
    }

    public function render()
    {
        $this->customer->load(['invoices.items.tax', 'invoices.payments', 'invoices.creditNotes', 'estimates.items.tax']);
        $invoices = $this->customer->invoices->sortByDesc('issue_date')->values();
        $estimates = $this->customer->estimates->sortByDesc('issue_date')->values();

        $balance = $this->customer->owed(); // incl. dues carried over from the CMS
        $overdue = round($invoices->filter(fn ($i) => $i->due_date && $i->due_date->isPast() && $i->due() > 0)->sum(fn ($i) => $i->due()), 2);
        $totalInvoiced = round($invoices->sum(fn ($i) => $i->total()), 2);
        $count = $invoices->count();
        $average = $count ? round($totalInvoiced / $count, 2) : 0.0;

        return $this->page('livewire.billing.customer-show', [
            'invoices' => $invoices,
            'estimates' => $estimates,
            'balance' => $balance,
            'overdue' => $overdue,
            'totalInvoiced' => $totalInvoiced,
            'count' => $count,
            'average' => $average,
        ], $this->customer->name);
    }
}
