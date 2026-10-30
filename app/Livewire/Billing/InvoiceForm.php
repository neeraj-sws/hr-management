<?php

namespace App\Livewire\Billing;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\BillingCustomer;
use App\Models\BillingInvoice;
use App\Models\BillingInvoiceItem;
use App\Models\BillingProduct;
use App\Models\BillingTax;
use App\Services\AuditLogger;
use App\Services\BillingService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Create or edit an invoice with its line items (CMS invoice flow). */
class InvoiceForm extends Component
{
    use InteractsWithHr;

    #[Locked] public ?int $invoiceId = null;
    public ?int $customerId = null;
    public string $issueDate = '';
    public string $dueDate = '';
    public string $refNumber = '';
    public string $notes = '';
    /** @var array<int, array{product_id:?int,name:string,description:string,quantity:string,price:string,discount:string,tax_id:?int}> */
    public array $items = [];

    public function mount(?BillingInvoice $invoice = null): void
    {
        abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403);
        if ($invoice && $invoice->exists) {
            $invoice->load('items');
            $this->invoiceId = $invoice->id;
            $this->fill(['customerId' => $invoice->customer_id, 'issueDate' => $invoice->issue_date->toDateString(),
                'dueDate' => $invoice->due_date?->toDateString() ?? '', 'refNumber' => (string) $invoice->ref_number, 'notes' => (string) $invoice->notes]);
            $this->items = $invoice->items->map(fn ($i) => ['product_id' => $i->product_id, 'name' => $i->name, 'description' => (string) $i->description,
                'quantity' => (string) (float) $i->quantity, 'price' => (string) (float) $i->price, 'discount' => (string) (float) $i->discount, 'tax_id' => $i->tax_id])->all();
        } else {
            $this->issueDate = today()->toDateString();
            $this->dueDate = today()->addDays(7)->toDateString();
            $this->addItem();
        }
    }

    public function addItem(): void
    {
        $this->items[] = ['product_id' => null, 'name' => '', 'description' => '', 'quantity' => '1', 'price' => '0', 'discount' => '0', 'tax_id' => null];
    }

    public function removeItem(int $i): void
    {
        unset($this->items[$i]);
        $this->items = array_values($this->items);
        if (! $this->items) $this->addItem();
    }

    /** Fill name + price + tax from the chosen product. */
    public function updatedItems($value, $key): void
    {
        if (! str_ends_with($key, '.product_id')) return;
        $i = (int) explode('.', $key)[0];
        $product = $value ? BillingProduct::find($value) : null;
        if ($product) {
            $this->items[$i]['name'] = $this->items[$i]['name'] ?: $product->name;
            $this->items[$i]['price'] = (string) (float) $product->sale_price;
            $this->items[$i]['tax_id'] = $product->tax_id;
        }
    }

    public function lineTotal(array $it): float
    {
        $sub = (float) $it['quantity'] * (float) $it['price'] - (float) $it['discount'];
        $rate = $it['tax_id'] ? (float) (BillingTax::find($it['tax_id'])?->rate ?? 0) : 0;

        return round($sub + $sub * $rate / 100, 2);
    }

    public function save(AuditLogger $audit, BillingService $billing): void
    {
        abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403);
        $this->validate([
            'customerId' => ['required', 'exists:billing_customers,id'],
            'issueDate' => ['required', 'date'],
            'dueDate' => ['nullable', 'date', 'after_or_equal:issueDate'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.name' => ['required', 'string', 'max:191'],
            'items.*.quantity' => ['required', 'numeric', 'min:0'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
        ], [], ['customerId' => 'customer', 'issueDate' => 'issue date', 'dueDate' => 'due date']);

        DB::transaction(function () use ($audit, $billing) {
            if ($this->invoiceId) {
                $invoice = BillingInvoice::findOrFail($this->invoiceId);
                $invoice->update(['customer_id' => $this->customerId, 'issue_date' => $this->issueDate,
                    'due_date' => $this->dueDate ?: null, 'ref_number' => $this->refNumber ?: null, 'notes' => $this->notes ?: null]);
                $invoice->items()->delete();
            } else {
                $n = $billing->nextInvoiceNumber($this->issueDate);
                $invoice = BillingInvoice::create(['number' => $n['number'], 'financial_year' => $n['financial_year'],
                    'customer_id' => $this->customerId, 'issue_date' => $this->issueDate, 'due_date' => $this->dueDate ?: null,
                    'ref_number' => $this->refNumber ?: null, 'notes' => $this->notes ?: null, 'status' => 'Draft', 'created_by' => auth()->id()]);
            }
            foreach ($this->items as $it) {
                BillingInvoiceItem::create(['invoice_id' => $invoice->id, 'product_id' => $it['product_id'] ?: null,
                    'name' => $it['name'], 'description' => $it['description'] ?: null, 'quantity' => (float) $it['quantity'],
                    'price' => (float) $it['price'], 'discount' => (float) ($it['discount'] ?: 0), 'tax_id' => $it['tax_id'] ?: null]);
            }
            $invoice->load('items', 'payments', 'creditNotes');
            $invoice->refreshStatus();
            $audit->record($this->invoiceId ? 'billing.invoice_updated' : 'billing.invoice_created', $invoice);
            $this->invoiceId = $invoice->id;
        });

        $this->toast('Invoice saved.');
        $this->redirect(route('billing.invoices.show', $this->invoiceId));
    }

    public function render()
    {
        return $this->page('livewire.billing.invoice-form', [
            'customers' => BillingCustomer::orderBy('name')->get(['id', 'name']),
            'products' => BillingProduct::where('is_active', true)->orderBy('name')->get(['id', 'name', 'sale_price', 'tax_id']),
            'taxes' => BillingTax::orderBy('name')->get(['id', 'name', 'rate']),
            'subTotal' => round(collect($this->items)->sum(fn ($it) => (float) $it['quantity'] * (float) $it['price'] - (float) $it['discount']), 2),
            'grandTotal' => round(collect($this->items)->sum(fn ($it) => $this->lineTotal($it)), 2),
        ], $this->invoiceId ? 'Edit invoice' : 'New invoice');
    }
}
