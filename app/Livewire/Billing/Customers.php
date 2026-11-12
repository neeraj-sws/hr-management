<?php

namespace App\Livewire\Billing;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\BillingCustomer;
use App\Services\AuditLogger;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use App\Livewire\Concerns\WithPaginationNoUrl as WithPagination;

class Customers extends Component
{
    use InteractsWithHr, WithFileUploads, WithPagination;

    #[Url] public string $search = '';
    #[Url] public int $perPage = 10;
    public bool $showForm = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $email = '';
    public string $contact = '';
    public string $taxNumber = '';
    public string $billingAddress = '';
    public string $billingCity = '';
    public string $billingState = '';
    public string $billingName = '';
    public string $billingPhone = '';
    public string $billingZip = '';
    public string $billingCountry = 'India';
    public string $shippingName = '';
    public string $shippingPhone = '';
    public string $shippingAddress = '';
    public string $shippingCity = '';
    public string $shippingState = '';
    public string $shippingZip = '';
    public string $shippingCountry = '';
    public bool $isActive = true;
    // Import from Excel.
    public bool $showImport = false;
    public $importFile = null;

    /** Fields of the form, mapped to their columns. */
    private const FIELDS = [
        'name' => 'name', 'email' => 'email', 'contact' => 'contact', 'taxNumber' => 'tax_number',
        'billingName' => 'billing_name', 'billingPhone' => 'billing_phone', 'billingAddress' => 'billing_address', 'billingCity' => 'billing_city',
        'billingState' => 'billing_state', 'billingZip' => 'billing_zip', 'billingCountry' => 'billing_country',
        'shippingName' => 'shipping_name', 'shippingPhone' => 'shipping_phone', 'shippingAddress' => 'shipping_address', 'shippingCity' => 'shipping_city',
        'shippingState' => 'shipping_state', 'shippingZip' => 'shipping_zip', 'shippingCountry' => 'shipping_country',
    ];

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedPerPage(): void { $this->resetPage(); }

    public function mount(): void
    {
        if ($id = request('edit')) $this->edit((int) $id);
    }

    private function guard(): void { abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403); }

    public function deleteCustomer(int $id, AuditLogger $audit): void
    {
        $this->guard();
        $c = BillingCustomer::withCount('invoices')->findOrFail($id);
        if ($c->invoices_count) { $this->toast('Customer has invoices — cannot delete.'); return; }
        $audit->record('billing.customer_deleted', $c);
        $c->delete();
        $this->toast('Customer deleted.');
    }

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
        $c = BillingCustomer::findOrFail($id);
        $this->fill(['editingId' => $c->id, 'isActive' => (bool) $c->is_active] + collect(self::FIELDS)->map(fn ($column) => (string) $c->{$column})->all());
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(AuditLogger $audit): void
    {
        $this->guard();
        $short = ['nullable', 'string', 'max:100'];
        $data = $this->validate([
            'name' => ['required', 'string', 'max:191'], 'email' => ['nullable', 'email', 'max:191'], 'contact' => ['nullable', 'string', 'max:50'],
            'taxNumber' => ['nullable', 'string', 'max:50'], 'billingName' => ['nullable', 'string', 'max:191'], 'billingPhone' => ['nullable', 'string', 'max:50'],
            'billingAddress' => ['nullable', 'string', 'max:500'], 'billingCity' => $short, 'billingState' => $short, 'billingZip' => ['nullable', 'string', 'max:20'], 'billingCountry' => $short,
            'shippingName' => ['nullable', 'string', 'max:191'], 'shippingPhone' => ['nullable', 'string', 'max:50'], 'shippingAddress' => ['nullable', 'string', 'max:500'],
            'shippingCity' => $short, 'shippingState' => $short, 'shippingZip' => ['nullable', 'string', 'max:20'], 'shippingCountry' => $short,
        ]);
        $values = collect(self::FIELDS)->mapWithKeys(fn ($column, $field) => [$column => trim((string) $data[$field]) ?: null])->all();
        $values['billing_name'] ??= $data['name'];
        $c = BillingCustomer::updateOrCreate(['id' => $this->editingId], $values + [
            'is_active' => $this->isActive,
            'created_by' => $this->editingId ? BillingCustomer::find($this->editingId)->created_by : auth()->id(),
        ]);
        $audit->record($this->editingId ? 'billing.customer_updated' : 'billing.customer_created', $c);
        $this->showForm = false;
        $this->toast($this->editingId ? 'Customer updated.' : 'Customer added.');
    }

    /** Copies the billing address into the shipping address. */
    public function copyBilling(): void
    {
        foreach (['Name', 'Phone', 'Address', 'City', 'State', 'Zip', 'Country'] as $f) $this->{'shipping'.$f} = $this->{'billing'.$f};
        if ($this->shippingName === '') $this->shippingName = $this->name;
    }

    /**
     * Imports customers from an Excel sheet (the Export file works as the template): a header row with
     * Name and any of Email, Contact, GSTIN, Billing …, Shipping …. A name that already exists is skipped.
     */
    public function import(AuditLogger $audit): void
    {
        $this->guard();
        $this->validate(['importFile' => ['required', 'file', 'max:5120', 'extensions:xlsx']], ['importFile.extensions' => 'Upload an Excel file (.xlsx).'], ['importFile' => 'file']);
        $rows = \App\Support\XlsxReader::rows($this->importFile->getRealPath());
        $aliases = ['name' => 'name', 'customer' => 'name', 'customer_name' => 'name', 'email' => 'email', 'contact' => 'contact', 'phone' => 'contact', 'mobile' => 'contact',
            'gstin' => 'tax_number', 'gst' => 'tax_number', 'gst_number' => 'tax_number', 'tax_number' => 'tax_number',
            'billing_name' => 'billing_name', 'billing_phone' => 'billing_phone', 'billing_address' => 'billing_address', 'address' => 'billing_address',
            'city' => 'billing_city', 'billing_city' => 'billing_city', 'state' => 'billing_state', 'billing_state' => 'billing_state',
            'zip' => 'billing_zip', 'zip_code' => 'billing_zip', 'billing_zip' => 'billing_zip', 'pin' => 'billing_zip', 'country' => 'billing_country', 'billing_country' => 'billing_country',
            'shipping_name' => 'shipping_name', 'shipping_phone' => 'shipping_phone', 'shipping_address' => 'shipping_address', 'shipping_city' => 'shipping_city',
            'shipping_state' => 'shipping_state', 'shipping_zip' => 'shipping_zip', 'shipping_country' => 'shipping_country'];
        $slug = fn ($t) => trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(trim((string) $t))), '_');
        $cols = null;
        $created = $skipped = 0;
        foreach ($rows as $cells) {
            if ($cols === null) {
                $map = [];
                foreach ($cells as $i => $text) if (isset($aliases[$slug($text)])) $map[$aliases[$slug($text)]] ??= $i;
                if (isset($map['name'])) $cols = $map;
                continue;
            }
            $values = collect($cols)->map(fn ($i) => trim((string) ($cells[$i] ?? '')))->filter(fn ($v) => $v !== '')->all();
            if (empty($values['name'])) continue;
            if (isset($values['email']) && ! filter_var($values['email'], FILTER_VALIDATE_EMAIL)) unset($values['email']);
            if (BillingCustomer::whereRaw('LOWER(name) = ?', [mb_strtolower($values['name'])])->exists()) { $skipped++; continue; }
            $values['billing_name'] ??= $values['name'];
            BillingCustomer::create(array_map(fn ($v) => mb_substr($v, 0, 191), $values) + ['is_active' => true, 'created_by' => auth()->id()]);
            $created++;
        }
        if ($cols === null) { $this->addError('importFile', 'The sheet has no header row with a "Name" column.'); return; }
        $audit->record('billing.customers_imported', null, ['created' => $created, 'skipped' => $skipped]);
        $this->reset('importFile');
        $this->showImport = false;
        $this->toast("{$created} customers imported".($skipped ? ", {$skipped} already existed (skipped)" : '').'.');
    }

    public function closeModal(): void { $this->showForm = false; $this->showImport = false; }

    public function render()
    {
        $this->guard();
        $term = trim($this->search);
        $customers = BillingCustomer::withCount('invoices')
            ->with(['invoices.items.tax', 'invoices.payments', 'invoices.creditNotes'])
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%")->orWhere('contact', 'like', "%{$term}%")
                ->orWhere('customer_number', ltrim(preg_replace('/\D/', '', $term), '0') === '' ? -1 : (int) ltrim(preg_replace('/\D/', '', $term), '0'))))
            ->orderByRaw('customer_number IS NULL')->orderBy('customer_number')->orderBy('id')
            ->paginate(in_array($this->perPage, [10, 25, 50, 100], true) ? $this->perPage : 10);
        // Outstanding balance = sum of every invoice's due (total − paid − credit notes).
        $customers->getCollection()->transform(function ($c) {
            $c->balance_due = $c->owed(); // what is owed, like the CMS balance (shown negative there)

            return $c;
        });

        return $this->page('livewire.billing.customers', ['customers' => $customers], 'Customers');
    }
}
