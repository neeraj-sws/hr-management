<?php

namespace App\Livewire\Billing;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\BillingBill;
use App\Models\BillingBillItem;
use App\Models\BillingCategory;
use App\Models\BillingProduct;
use App\Models\BillingTax;
use App\Models\BillingVendor;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Create or edit a purchase bill from a supplier, with its line items (like the CMS bill). */
class BillForm extends Component
{
    use InteractsWithHr;

    #[Locked] public ?int $billId = null;
    public ?int $vendorId = null;
    public ?int $categoryId = null;
    public string $billDate = '';
    public string $dueDate = '';
    public string $refNumber = '';
    public string $notes = '';
    public array $items = [];

    public function mount(?BillingBill $bill = null): void
    {
        abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403);
        if ($bill && $bill->exists) {
            $bill->load('items');
            $this->billId = $bill->id;
            $this->fill(['vendorId' => $bill->vendor_id, 'categoryId' => $bill->category_id, 'billDate' => $bill->bill_date->toDateString(),
                'dueDate' => $bill->due_date?->toDateString() ?? '', 'refNumber' => (string) $bill->ref_number, 'notes' => (string) $bill->notes]);
            $this->items = $bill->items->map(fn ($i) => ['product_id' => $i->product_id, 'name' => $i->name, 'description' => (string) $i->description,
                'quantity' => (string) (float) $i->quantity, 'price' => (string) (float) $i->price, 'discount' => (string) (float) $i->discount, 'tax_id' => $i->tax_id])->all();
        } else {
            $this->billDate = today()->toDateString();
            $this->dueDate = today()->addDays(7)->toDateString();
            if (($vid = (int) request('vendor')) && BillingVendor::whereKey($vid)->exists()) $this->vendorId = $vid;
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

    /** Picking a product fills the name, purchase price and tax. */
    public function updatedItems($value, $key): void
    {
        if (! str_ends_with($key, '.product_id')) return;
        $i = (int) explode('.', $key)[0];
        if ($product = $value ? BillingProduct::find($value) : null) {
            $this->items[$i]['name'] = $this->items[$i]['name'] ?: $product->name;
            $this->items[$i]['price'] = (string) (float) ($product->purchase_price ?: $product->sale_price);
            $this->items[$i]['tax_id'] = $product->tax_id;
        }
    }

    public function lineTotal(array $it): float
    {
        $sub = (float) $it['quantity'] * (float) $it['price'] - (float) $it['discount'];
        $rate = $it['tax_id'] ? (float) (BillingTax::find($it['tax_id'])?->rate ?? 0) : 0;

        return round($sub + $sub * $rate / 100, 2);
    }

    public function save(AuditLogger $audit): void
    {
        abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403);
        $this->validate([
            'vendorId' => ['required', 'exists:billing_vendors,id'], 'categoryId' => ['nullable', 'exists:billing_categories,id'],
            'billDate' => ['required', 'date'], 'dueDate' => ['nullable', 'date', 'after_or_equal:billDate'], 'refNumber' => ['nullable', 'string', 'max:100'],
            'items' => ['required', 'array', 'min:1'], 'items.*.name' => ['required', 'string', 'max:191'],
            'items.*.quantity' => ['required', 'numeric', 'min:0'], 'items.*.price' => ['required', 'numeric', 'min:0'], 'items.*.discount' => ['nullable', 'numeric', 'min:0'],
        ], [], ['vendorId' => 'supplier', 'billDate' => 'bill date', 'dueDate' => 'due date', 'categoryId' => 'category']);
        if ($this->billId) {
            $existing = BillingBill::with('payments', 'debitNotes')->findOrFail($this->billId);
            $newTotal = round(collect($this->items)->sum(fn ($it) => $this->lineTotal($it)), 2);
            if ($newTotal + 0.005 < $existing->paidAmount() + $existing->debitTotal()) {
                $this->addError('items', 'Naya total ('.number_format($newTotal, 2).') already paid amount se kam hai. Pehle payment hatao ya debit note lagao.');

                return;
            }
        }
        DB::transaction(function () use ($audit) {
            $values = ['vendor_id' => $this->vendorId, 'category_id' => $this->categoryId ?: null, 'bill_date' => $this->billDate, 'due_date' => $this->dueDate ?: null,
                'ref_number' => $this->refNumber ?: null, 'notes' => $this->notes ?: null];
            if ($this->billId) {
                $bill = BillingBill::findOrFail($this->billId);
                $bill->update($values);
                $bill->items()->delete();
            } else {
                $bill = BillingBill::create($values + ['number' => (int) BillingBill::lockForUpdate()->max('number') + 1, 'status' => 'Draft', 'created_by' => auth()->id()]);
            }
            foreach ($this->items as $it) {
                BillingBillItem::create(['bill_id' => $bill->id, 'product_id' => $it['product_id'] ?: null, 'name' => $it['name'], 'description' => $it['description'] ?: null,
                    'quantity' => (float) $it['quantity'], 'price' => (float) $it['price'], 'discount' => (float) ($it['discount'] ?: 0), 'tax_id' => $it['tax_id'] ?: null]);
            }
            $bill->load('items.tax', 'payments', 'debitNotes')->refreshStatus();
            $audit->record($this->billId ? 'billing.bill_updated' : 'billing.bill_created', $bill);
            $this->billId = $bill->id;
        });
        $this->toast('Bill saved.');
        $this->redirect(route('billing.bills.show', $this->billId));
    }

    public function render()
    {
        $taxes = BillingTax::orderBy('name')->get(['id', 'name', 'rate']);
        $sub = round(collect($this->items)->sum(fn ($it) => (float) $it['quantity'] * (float) $it['price'] - (float) $it['discount']), 2);
        $grand = round(collect($this->items)->sum(fn ($it) => $this->lineTotal($it)), 2);

        return $this->page('livewire.billing.bill-form', [
            'vendors' => BillingVendor::where('is_active', true)->orWhere('id', $this->vendorId)->orderBy('name')->get(['id', 'name']),
            'categories' => BillingCategory::where('type', 'expense')->orderBy('name')->get(['id', 'name']),
            'products' => BillingProduct::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'taxes' => $taxes, 'subTotal' => $sub, 'grandTotal' => $grand,
            'discountTotal' => round(collect($this->items)->sum(fn ($it) => (float) $it['discount']), 2),
        ], $this->billId ? 'Edit bill' : 'New bill');
    }
}
