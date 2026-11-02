<?php

namespace App\Livewire\Billing;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\BillingTax;
use App\Services\AuditLogger;
use Livewire\Component;

/** Tax rates (CGST/SGST/IGST/TDS…) used on invoice & estimate line items. */
class Taxes extends Component
{
    use InteractsWithHr;

    public bool $showForm = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $rate = '0';

    private function guard(): void { abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403); }

    public function create(): void
    {
        $this->guard();
        $this->reset('editingId', 'name', 'rate');
        $this->rate = '0';
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->guard();
        $t = BillingTax::findOrFail($id);
        $this->fill(['editingId' => $t->id, 'name' => $t->name, 'rate' => (string) (float) $t->rate]);
        $this->showForm = true;
    }

    public function save(AuditLogger $audit): void
    {
        $this->guard();
        $data = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);
        $t = BillingTax::updateOrCreate(['id' => $this->editingId], ['name' => $data['name'], 'rate' => $data['rate']]);
        $audit->record($this->editingId ? 'billing.tax_updated' : 'billing.tax_created', $t);
        $this->showForm = false;
        $this->toast('Tax saved.');
    }

    public function closeModal(): void { $this->showForm = false; }

    public function render()
    {
        $this->guard();

        return $this->page('livewire.billing.taxes', [
            'taxes' => BillingTax::orderBy('name')->get(),
        ], 'Taxes');
    }
}
