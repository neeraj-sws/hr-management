<?php

namespace App\Livewire\Billing;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\BillingCustomer;
use App\Models\BillingEstimate;
use App\Models\BillingEstimateItem;
use App\Models\BillingProduct;
use App\Models\BillingTax;
use App\Services\AuditLogger;
use App\Services\BillingService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Create or edit an estimate (quote) with its line items. */
class EstimateForm extends Component
{
    use InteractsWithHr;

    #[Locked] public ?int $estimateId = null;
    public ?int $customerId = null;
    public string $issueDate = '';
    public string $expiryDate = '';
    public string $notes = '';
    /** @var array<int, array{product_id:?int,name:string,description:string,quantity:string,price:string,discount:string,tax_id:?int}> */
    public array $items = [];

    public function mount(?BillingEstimate $estimate = null): void
    {
        abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403);
        if ($estimate && $estimate->exists) {
            $estimate->load('items');
            $this->estimateId = $estimate->id;
            $this->fill(['customerId' => $estimate->customer_id, 'issueDate' => $estimate->issue_date->toDateString(),
                'expiryDate' => $estimate->expiry_date?->toDateString() ?? '', 'notes' => (string) $estimate->notes]);
            $this->items = $estimate->items->map(fn ($i) => ['product_id' => $i->product_id, 'name' => $i->name, 'description' => (string) $i->description,
                'quantity' => (string) (float) $i->quantity, 'price' => (string) (float) $i->price, 'discount' => (string) (float) $i->discount, 'tax_id' => $i->tax_id])->all();
        } else {
            $this->issueDate = today()->toDateString();
            $this->expiryDate = today()->addDays(14)->toDateString();
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
            'expiryDate' => ['nullable', 'date', 'after_or_equal:issueDate'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.name' => ['required', 'string', 'max:191'],
            'items.*.quantity' => ['required', 'numeric', 'min:0'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
        ], [], ['customerId' => 'customer', 'issueDate' => 'issue date', 'expiryDate' => 'expiry date']);

        DB::transaction(function () use ($audit, $billing) {
            if ($this->estimateId) {
                $estimate = BillingEstimate::findOrFail($this->estimateId);
                $estimate->update(['customer_id' => $this->customerId, 'issue_date' => $this->issueDate,
                    'expiry_date' => $this->expiryDate ?: null, 'notes' => $this->notes ?: null]);
                $estimate->items()->delete();
            } else {
                $n = $billing->nextEstimateNumber($this->issueDate);
                $estimate = BillingEstimate::create(['number' => $n['number'], 'financial_year' => $n['financial_year'],
                    'customer_id' => $this->customerId, 'issue_date' => $this->issueDate, 'expiry_date' => $this->expiryDate ?: null,
                    'notes' => $this->notes ?: null, 'status' => 'Draft', 'created_by' => auth()->id()]);
            }
            foreach ($this->items as $it) {
                BillingEstimateItem::create(['estimate_id' => $estimate->id, 'product_id' => $it['product_id'] ?: null,
                    'name' => $it['name'], 'description' => $it['description'] ?: null, 'quantity' => (float) $it['quantity'],
                    'price' => (float) $it['price'], 'discount' => (float) ($it['discount'] ?: 0), 'tax_id' => $it['tax_id'] ?: null]);
            }
            $audit->record($this->estimateId ? 'billing.estimate_updated' : 'billing.estimate_created', $estimate);
            $this->estimateId = $estimate->id;
        });

        $this->toast('Estimate saved.');
        $this->redirect(route('billing.estimates.show', $this->estimateId));
    }

    public function render()
    {
        return $this->page('livewire.billing.estimate-form', [
            'customers' => BillingCustomer::orderBy('name')->get(['id', 'name']),
            'products' => BillingProduct::where('is_active', true)->orderBy('name')->get(['id', 'name', 'sale_price', 'tax_id']),
            'taxes' => BillingTax::orderBy('name')->get(['id', 'name', 'rate']),
            'subTotal' => round(collect($this->items)->sum(fn ($it) => (float) $it['quantity'] * (float) $it['price'] - (float) $it['discount']), 2),
            'grandTotal' => round(collect($this->items)->sum(fn ($it) => $this->lineTotal($it)), 2),
        ], $this->estimateId ? 'Edit estimate' : 'New estimate');
    }
}
