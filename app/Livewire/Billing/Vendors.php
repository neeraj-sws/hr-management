<?php

namespace App\Livewire\Billing;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\BillingVendor;
use App\Services\AuditLogger;
use Livewire\Attributes\Url;
use Livewire\Component;
use App\Livewire\Concerns\WithPaginationNoUrl as WithPagination;

/** Purchases → Suppliers (vendors): who the company buys from; bills and payments to them. */
class Vendors extends Component
{
    use InteractsWithHr, WithPagination;

    #[Url] public string $search = '';
    #[Url] public int $perPage = 10;
    public bool $showForm = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $email = '';
    public string $contact = '';
    public string $taxNumber = '';
    public string $billingName = '';
    public string $billingPhone = '';
    public string $billingAddress = '';
    public string $billingCity = '';
    public string $billingState = '';
    public string $billingZip = '';
    public string $billingCountry = 'India';
    public bool $isActive = true;

    private const FIELDS = ['name' => 'name', 'email' => 'email', 'contact' => 'contact', 'taxNumber' => 'tax_number', 'billingName' => 'billing_name', 'billingPhone' => 'billing_phone',
        'billingAddress' => 'billing_address', 'billingCity' => 'billing_city', 'billingState' => 'billing_state', 'billingZip' => 'billing_zip', 'billingCountry' => 'billing_country'];

    public function updated($property): void { if (in_array($property, ['search', 'perPage'], true)) $this->resetPage(); }

    private function guard(): void { abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403); }

    public function create(): void
    {
        $this->guard();
        $this->reset(array_merge(['editingId', 'isActive'], array_keys(self::FIELDS)));
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->guard();
        $v = BillingVendor::findOrFail($id);
        $this->fill(['editingId' => $v->id, 'isActive' => (bool) $v->is_active] + collect(self::FIELDS)->map(fn ($c) => (string) $v->{$c})->all());
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(AuditLogger $audit): void
    {
        $this->guard();
        $short = ['nullable', 'string', 'max:100'];
        $data = $this->validate([
            'name' => ['required', 'string', 'max:191'], 'email' => ['nullable', 'email', 'max:191'], 'contact' => ['nullable', 'string', 'max:50'], 'taxNumber' => ['nullable', 'string', 'max:50'],
            'billingName' => ['nullable', 'string', 'max:191'], 'billingPhone' => ['nullable', 'string', 'max:50'], 'billingAddress' => ['nullable', 'string', 'max:500'],
            'billingCity' => $short, 'billingState' => $short, 'billingZip' => ['nullable', 'string', 'max:20'], 'billingCountry' => $short,
        ]);
        $values = collect(self::FIELDS)->mapWithKeys(fn ($c, $f) => [$c => trim((string) $data[$f]) ?: null])->all();
        $values['billing_name'] ??= $data['name'];
        $v = BillingVendor::updateOrCreate(['id' => $this->editingId], $values + ['is_active' => $this->isActive] + ($this->editingId ? [] : ['created_by' => auth()->id()]));
        $audit->record($this->editingId ? 'billing.vendor_updated' : 'billing.vendor_created', $v);
        $this->showForm = false;
        $this->toast($this->editingId ? 'Vendor updated.' : 'Vendor added.');
    }

    public function delete(int $id, AuditLogger $audit): void
    {
        $this->guard();
        $v = BillingVendor::withCount(['bills', 'expenses'])->findOrFail($id);
        if ($v->bills_count || $v->expenses_count) { $this->toast('This supplier has bills or expenses, so it cannot be deleted (mark it Inactive instead).', 'error'); return; }
        $audit->record('billing.vendor_deleted', $v);
        $v->delete();
        $this->toast('Vendor deleted.');
    }

    public function closeModal(): void { $this->showForm = false; }

    public function render()
    {
        $this->guard();
        $term = trim($this->search);
        $vendors = BillingVendor::withCount('bills')->with(['bills.items.tax', 'bills.payments', 'bills.debitNotes'])->withSum('expenses', 'amount')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%")->orWhere('contact', 'like', "%{$term}%")))
            ->orderBy('name')->paginate(in_array($this->perPage, [10, 25, 50, 100], true) ? $this->perPage : 10);
        $vendors->getCollection()->transform(function ($v) {
            $v->balance_due = round($v->bills->where('status', '!=', 'Draft')->sum(fn ($b) => max(0, $b->due())), 2);

            return $v;
        });

        return $this->page('livewire.billing.vendors', ['vendors' => $vendors], 'Suppliers');
    }
}
