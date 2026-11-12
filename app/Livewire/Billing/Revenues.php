<?php

namespace App\Livewire\Billing;

use App\Http\Controllers\BillingFileController;
use App\Livewire\Concerns\InteractsWithHr;
use App\Models\BillingBankAccount;
use App\Models\BillingCategory;
use App\Models\BillingCustomer;
use App\Models\BillingRevenue;
use App\Services\AuditLogger;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use App\Livewire\Concerns\WithPaginationNoUrl as WithPagination;

/** Revenue / income entries (money received, not necessarily tied to an invoice), like the old CMS. */
class Revenues extends Component
{
    use InteractsWithHr, WithFileUploads, WithPagination;

    #[Url] public string $search = '';
    #[Url] public string $from = '';
    #[Url] public string $to = '';
    #[Url] public string $account = '';
    #[Url] public string $customer = '';
    #[Url] public string $categoryFilter = '';
    #[Url] public int $perPage = 10;

    public bool $showForm = false;
    public ?int $editingId = null;
    public string $date = '';
    public string $amount = '';
    public ?int $accountId = null;
    public ?int $customerId = null;
    public ?int $categoryId = null;
    public string $paymentMethod = '';
    public string $reference = '';
    public string $description = '';
    public $receipt = null;
    public bool $removeReceipt = false;

    public function updated($property): void
    {
        if (in_array($property, ['search', 'from', 'to', 'account', 'customer', 'categoryFilter', 'perPage'], true)) $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'from', 'to', 'account', 'customer', 'categoryFilter');
        $this->resetPage();
    }

    private function guard(): void { abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403); }

    public function create(): void
    {
        $this->guard();
        $this->reset('editingId', 'amount', 'customerId', 'categoryId', 'paymentMethod', 'reference', 'description', 'receipt', 'removeReceipt');
        $this->date = today()->toDateString();
        $this->accountId = BillingBankAccount::where('is_active', true)->orderBy('id')->value('id');
        $this->categoryId = BillingCategory::where('type', 'income')->orderBy('name')->value('id');
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->guard();
        $r = BillingRevenue::findOrFail($id);
        $this->reset('receipt', 'removeReceipt');
        $this->fill(['editingId' => $r->id, 'date' => $r->date->toDateString(), 'amount' => (string) (float) $r->amount, 'accountId' => $r->account_id,
            'customerId' => $r->customer_id, 'categoryId' => $r->category_id, 'paymentMethod' => (string) $r->payment_method,
            'reference' => (string) $r->reference, 'description' => (string) $r->description]);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(AuditLogger $audit): void
    {
        $this->guard();
        $data = $this->validate([
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'accountId' => ['required', 'exists:billing_bank_accounts,id'],
            'customerId' => ['nullable', 'exists:billing_customers,id'],
            'categoryId' => ['nullable', 'exists:billing_categories,id'],
            'paymentMethod' => ['nullable', 'string', 'max:50'],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'receipt' => BillingFileController::RULES,
        ], ['receipt.mimes' => 'The receipt must be an image (jpg / png) or a PDF.'], ['accountId' => 'account', 'categoryId' => 'category']);
        $existing = $this->editingId ? BillingRevenue::findOrFail($this->editingId) : null;
        $path = $existing?->receipt_path;
        if ($this->receipt || $this->removeReceipt) { BillingFileController::forget($path); $path = null; }
        if ($this->receipt) $path = BillingFileController::store($this->receipt);
        $values = [
            'date' => $data['date'], 'amount' => $data['amount'], 'account_id' => $data['accountId'], 'customer_id' => $data['customerId'] ?: null,
            'category_id' => $data['categoryId'] ?: null, 'category' => $data['categoryId'] ? BillingCategory::find($data['categoryId'])?->name : null,
            'payment_method' => $data['paymentMethod'] ?: null, 'reference' => $data['reference'] ?: null, 'description' => $data['description'] ?: null, 'receipt_path' => $path,
        ];
        if ($existing) $existing->update($values);
        $r = $existing ?? BillingRevenue::create($values + ['created_by' => auth()->id()]);
        $audit->record($existing ? 'billing.revenue_updated' : 'billing.revenue_created', $r, ['amount' => (float) $r->amount]);
        $this->reset('receipt', 'removeReceipt');
        $this->showForm = false;
        $this->toast('Revenue saved.');
    }

    public function delete(int $id, AuditLogger $audit): void
    {
        $this->guard();
        $r = BillingRevenue::findOrFail($id);
        $audit->record('billing.revenue_deleted', $r, ['amount' => (float) $r->amount, 'date' => $r->date->toDateString()]);
        BillingFileController::forget($r->receipt_path);
        $r->delete();
        $this->toast('Revenue deleted.');
    }

    public function closeModal(): void { $this->showForm = false; }

    public function render()
    {
        $this->guard();
        $term = trim($this->search);
        $date = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
        $query = BillingRevenue::query()
            ->when($date($this->from), fn ($q, $d) => $q->whereDate('date', '>=', $d))
            ->when($date($this->to), fn ($q, $d) => $q->whereDate('date', '<=', $d))
            ->when($this->account, fn ($q, $a) => $q->where('account_id', (int) $a))
            ->when($this->customer, fn ($q, $c) => $q->where('customer_id', (int) $c))
            ->when($this->categoryFilter, fn ($q, $c) => $q->where('category_id', (int) $c))
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->where('category', 'like', "%{$term}%")->orWhere('reference', 'like', "%{$term}%")
                ->orWhere('description', 'like', "%{$term}%")->orWhereHas('customer', fn ($q) => $q->where('name', 'like', "%{$term}%"))));
        $perPage = in_array($this->perPage, [10, 25, 50, 100], true) ? $this->perPage : 10;

        return $this->page('livewire.billing.revenues', [
            'revenues' => (clone $query)->with(['customer', 'account', 'categoryModel'])->orderByDesc('date')->orderByDesc('id')->paginate($perPage),
            'total' => (float) (clone $query)->sum('amount'),
            'count' => (clone $query)->count(),
            'customers' => BillingCustomer::orderBy('name')->get(['id', 'name']),
            'accounts' => BillingBankAccount::orderByDesc('is_active')->orderBy('bank_name')->get(),
            'categories' => BillingCategory::where('type', 'income')->orderBy('name')->get(['id', 'name']),
        ], 'Revenue');
    }
}
