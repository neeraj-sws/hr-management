<?php

namespace App\Livewire\Billing;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\BillingProduct;
use App\Models\BillingTax;
use App\Services\AuditLogger;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Products & services that can be added to invoices/estimates. */
class Products extends Component
{
    use InteractsWithHr, WithPagination;

    #[Url] public string $search = '';
    public bool $showForm = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $type = 'service';
    public string $sku = '';
    public string $hsnSac = '';
    public string $salePrice = '0';
    public string $purchasePrice = '0';
    public ?int $taxId = null;
    public string $description = '';
    public bool $isActive = true;

    public function updatedSearch(): void { $this->resetPage(); }

    private function guard(): void { abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403); }

    public function create(): void
    {
        $this->guard();
        $this->reset('editingId', 'name', 'sku', 'hsnSac', 'salePrice', 'purchasePrice', 'taxId', 'description');
        $this->type = 'service';
        $this->isActive = true;
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->guard();
        $p = BillingProduct::findOrFail($id);
        $this->fill(['editingId' => $p->id, 'name' => $p->name, 'type' => $p->type, 'sku' => (string) $p->sku, 'hsnSac' => (string) $p->hsn_sac,
            'salePrice' => (string) (float) $p->sale_price, 'purchasePrice' => (string) (float) $p->purchase_price,
            'taxId' => $p->tax_id, 'description' => (string) $p->description, 'isActive' => (bool) $p->is_active]);
        $this->showForm = true;
    }

    public function save(AuditLogger $audit): void
    {
        $this->guard();
        $data = $this->validate([
            'name' => ['required', 'string', 'max:191'],
            'type' => ['required', 'in:product,service'],
            'sku' => ['nullable', 'string', 'max:100'],
            'hsnSac' => ['nullable', 'string', 'max:20'],
            'salePrice' => ['required', 'numeric', 'min:0'],
            'purchasePrice' => ['nullable', 'numeric', 'min:0'],
            'taxId' => ['nullable', 'exists:billing_taxes,id'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);
        $p = BillingProduct::updateOrCreate(['id' => $this->editingId], [
            'name' => $data['name'], 'type' => $data['type'], 'sku' => $data['sku'] ?: null, 'hsn_sac' => $data['hsnSac'] ?: null,
            'sale_price' => $data['salePrice'], 'purchase_price' => $data['purchasePrice'] ?: 0,
            'tax_id' => $data['taxId'] ?: null, 'description' => $data['description'] ?: null,
            'is_active' => $this->isActive,
            'created_by' => $this->editingId ? BillingProduct::find($this->editingId)->created_by : auth()->id(),
        ]);
        $audit->record($this->editingId ? 'billing.product_updated' : 'billing.product_created', $p);
        $this->showForm = false;
        $this->toast($this->editingId ? 'Product updated.' : 'Product added.');
    }

    public function closeModal(): void { $this->showForm = false; }

    public function render()
    {
        $this->guard();
        $term = trim($this->search);
        $products = BillingProduct::with('tax')
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('sku', 'like', "%{$term}%")))
            ->orderBy('name')->paginate(20);

        return $this->page('livewire.billing.products', [
            'products' => $products,
            'taxes' => BillingTax::orderBy('name')->get(['id', 'name', 'rate']),
        ], 'Products & services');
    }
}
