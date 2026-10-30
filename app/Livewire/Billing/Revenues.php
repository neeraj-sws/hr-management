<?php

namespace App\Livewire\Billing;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\BillingCustomer;
use App\Models\BillingRevenue;
use App\Services\AuditLogger;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Revenue / income entries (money received, not necessarily tied to an invoice). */
class Revenues extends Component
{
    use InteractsWithHr, WithPagination;

    #[Url] public string $search = '';
    public bool $showForm = false;
    public ?int $editingId = null;
    public string $date = '';
    public string $amount = '0';
    public ?int $customerId = null;
    public string $category = '';
    public string $paymentMethod = '';
    public string $reference = '';
    public string $description = '';

    public function updatedSearch(): void { $this->resetPage(); }

    private function guard(): void { abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403); }

    public function create(): void
    {
        $this->guard();
        $this->reset('editingId', 'amount', 'customerId', 'category', 'paymentMethod', 'reference', 'description');
        $this->date = today()->toDateString();
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->guard();
        $r = BillingRevenue::findOrFail($id);
        $this->fill(['editingId' => $r->id, 'date' => $r->date->toDateString(), 'amount' => (string) (float) $r->amount,
            'customerId' => $r->customer_id, 'category' => (string) $r->category, 'paymentMethod' => (string) $r->payment_method,
            'reference' => (string) $r->reference, 'description' => (string) $r->description]);
        $this->showForm = true;
    }

    public function save(AuditLogger $audit): void
    {
        $this->guard();
        $data = $this->validate([
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'customerId' => ['nullable', 'exists:billing_customers,id'],
            'category' => ['nullable', 'string', 'max:100'],
            'paymentMethod' => ['nullable', 'string', 'max:50'],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
        $r = BillingRevenue::updateOrCreate(['id' => $this->editingId], [
            'date' => $data['date'], 'amount' => $data['amount'], 'customer_id' => $data['customerId'] ?: null,
            'category' => $data['category'] ?: null, 'payment_method' => $data['paymentMethod'] ?: null,
            'reference' => $data['reference'] ?: null, 'description' => $data['description'] ?: null,
            'created_by' => $this->editingId ? BillingRevenue::find($this->editingId)->created_by : auth()->id(),
        ]);
        $audit->record($this->editingId ? 'billing.revenue_updated' : 'billing.revenue_created', $r);
        $this->showForm = false;
        $this->toast('Revenue saved.');
    }

    public function closeModal(): void { $this->showForm = false; }

    public function render()
    {
        $this->guard();
        $term = trim($this->search);
        $revenues = BillingRevenue::with('customer')
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->where('category', 'like', "%{$term}%")
                ->orWhere('reference', 'like', "%{$term}%")
                ->orWhereHas('customer', fn ($q) => $q->where('name', 'like', "%{$term}%"))))
            ->orderByDesc('date')->paginate(20);

        return $this->page('livewire.billing.revenues', [
            'revenues' => $revenues,
            'total' => BillingRevenue::sum('amount'),
            'customers' => BillingCustomer::orderBy('name')->get(['id', 'name']),
        ], 'Revenue');
    }
}
