<?php

namespace App\Livewire\Billing;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\BillingEstimate;
use App\Models\BillingInvoice;
use App\Models\BillingInvoiceItem;
use App\Services\AuditLogger;
use App\Services\BillingService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

class EstimateShow extends Component
{
    use InteractsWithHr;

    #[Locked] public int $estimateId;

    public function mount(BillingEstimate $estimate): void
    {
        abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403);
        $this->estimateId = $estimate->id;
    }

    private function estimate(): BillingEstimate
    {
        return BillingEstimate::with(['customer', 'items.tax'])->findOrFail($this->estimateId);
    }

    public function setStatus(string $status, AuditLogger $audit): void
    {
        if (! in_array($status, ['Sent', 'Accepted', 'Declined'], true)) return;
        $est = $this->estimate();
        if ($est->status === 'Converted') { $this->toast('Already converted.'); return; }
        $est->update(['status' => $status]);
        $audit->record('billing.estimate_status', $est, ['status' => $status]);
        $this->toast('Estimate marked as '.$status.'.');
    }

    /** Create an invoice from this estimate's items and link it. */
    public function convert(AuditLogger $audit, BillingService $billing): void
    {
        $est = $this->estimate();
        if ($est->converted_invoice_id) {
            $this->redirect(route('billing.invoices.show', $est->converted_invoice_id));
            return;
        }

        $invoice = DB::transaction(function () use ($est, $audit, $billing) {
            $n = $billing->nextInvoiceNumber(today());
            $invoice = BillingInvoice::create([
                'number' => $n['number'], 'financial_year' => $n['financial_year'],
                'customer_id' => $est->customer_id, 'issue_date' => today()->toDateString(),
                'due_date' => today()->addDays(7)->toDateString(), 'notes' => $est->notes,
                'status' => 'Draft', 'created_by' => auth()->id(),
            ]);
            foreach ($est->items as $it) {
                BillingInvoiceItem::create(['invoice_id' => $invoice->id, 'product_id' => $it->product_id,
                    'name' => $it->name, 'description' => $it->description, 'quantity' => $it->quantity,
                    'price' => $it->price, 'discount' => $it->discount, 'tax_id' => $it->tax_id]);
            }
            $est->update(['status' => 'Converted', 'converted_invoice_id' => $invoice->id]);
            $audit->record('billing.estimate_converted', $est, ['invoice_id' => $invoice->id]);

            return $invoice;
        });

        $this->toast('Estimate converted to invoice.');
        $this->redirect(route('billing.invoices.show', $invoice->id));
    }

    public function render()
    {
        return $this->page('livewire.billing.estimate-show', ['estimate' => $this->estimate()], 'Estimate');
    }
}
