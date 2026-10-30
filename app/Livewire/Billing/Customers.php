<?php

namespace App\Livewire\Billing;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\BillingCustomer;
use App\Services\AuditLogger;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Customers extends Component
{
    use InteractsWithHr, WithPagination;

    #[Url] public string $search = '';
    public bool $showForm = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $email = '';
    public string $contact = '';
    public string $taxNumber = '';
    public string $billingAddress = '';
    public string $billingCity = '';
    public string $billingState = '';

    public function updatedSearch(): void { $this->resetPage(); }

    private function guard(): void { abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403); }

    public function create(): void
    {
        $this->guard();
        $this->reset('editingId', 'name', 'email', 'contact', 'taxNumber', 'billingAddress', 'billingCity', 'billingState');
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->guard();
        $c = BillingCustomer::findOrFail($id);
        $this->fill(['editingId' => $c->id, 'name' => $c->name, 'email' => (string) $c->email, 'contact' => (string) $c->contact,
            'taxNumber' => (string) $c->tax_number, 'billingAddress' => (string) $c->billing_address, 'billingCity' => (string) $c->billing_city, 'billingState' => (string) $c->billing_state]);
        $this->showForm = true;
    }

    public function save(AuditLogger $audit): void
    {
        $this->guard();
        $data = $this->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:191'],
            'contact' => ['nullable', 'string', 'max:50'],
            'taxNumber' => ['nullable', 'string', 'max:50'],
            'billingAddress' => ['nullable', 'string', 'max:500'],
            'billingCity' => ['nullable', 'string', 'max:100'],
            'billingState' => ['nullable', 'string', 'max:100'],
        ]);
        $c = BillingCustomer::updateOrCreate(['id' => $this->editingId], [
            'name' => $data['name'], 'email' => $data['email'] ?: null, 'contact' => $data['contact'] ?: null,
            'tax_number' => $data['taxNumber'] ?: null, 'billing_name' => $data['name'],
            'billing_address' => $data['billingAddress'] ?: null, 'billing_city' => $data['billingCity'] ?: null, 'billing_state' => $data['billingState'] ?: null,
            'created_by' => $this->editingId ? BillingCustomer::find($this->editingId)->created_by : auth()->id(),
        ]);
        $audit->record($this->editingId ? 'billing.customer_updated' : 'billing.customer_created', $c);
        $this->showForm = false;
        $this->toast($this->editingId ? 'Customer updated.' : 'Customer added.');
    }

    public function closeModal(): void { $this->showForm = false; }

    public function render()
    {
        $this->guard();
        $term = trim($this->search);
        $customers = BillingCustomer::withCount('invoices')
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%")->orWhere('contact', 'like', "%{$term}%")))
            ->orderBy('name')->paginate(20);

        return $this->page('livewire.billing.customers', ['customers' => $customers], 'Customers');
    }
}
