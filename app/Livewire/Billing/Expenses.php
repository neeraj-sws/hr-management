<?php

namespace App\Livewire\Billing;

use App\Http\Controllers\BillingFileController;
use App\Livewire\Concerns\InteractsWithHr;
use App\Models\BillingBankAccount;
use App\Models\BillingCategory;
use App\Models\BillingVendor;
use App\Models\BillingExpense;
use App\Services\AuditLogger;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use App\Livewire\Concerns\WithPaginationNoUrl as WithPagination;

/** Purchases → Expenses / payments: money paid out directly (rent, tea, salary advance…), like the CMS "Payment". */
class Expenses extends Component
{
    use InteractsWithHr, WithFileUploads, WithPagination;

    #[Url] public string $search = '';
    #[Url] public string $from = '';
    #[Url] public string $to = '';
    #[Url] public string $account = '';
    #[Url] public string $vendor = '';
    #[Url] public string $categoryFilter = '';
    #[Url] public int $perPage = 10;

    public bool $showForm = false;
    public ?int $editingId = null;
    public string $date = '';
    public string $amount = '';
    public ?int $accountId = null;
    public ?int $vendorId = null;
    public ?int $categoryId = null;
    public string $paymentMethod = '';
    public string $reference = '';
    public string $description = '';
    public $receipt = null;
    public bool $removeReceipt = false;

    public function updated($property): void
    {
        if (in_array($property, ['search', 'from', 'to', 'account', 'vendor', 'categoryFilter', 'perPage'], true)) $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'from', 'to', 'account', 'vendor', 'categoryFilter');
        $this->resetPage();
    }

    private function guard(): void { abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403); }

    public function create(): void
    {
        $this->guard();
        $this->reset('editingId', 'amount', 'vendorId', 'categoryId', 'paymentMethod', 'reference', 'description', 'receipt', 'removeReceipt');
        $this->date = today()->toDateString();
        $this->accountId = BillingBankAccount::where('is_active', true)->orderBy('id')->value('id');
        $this->categoryId = BillingCategory::where('type', 'expense')->orderBy('name')->value('id');
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->guard();
        $r = BillingExpense::findOrFail($id);
        $this->reset('receipt', 'removeReceipt');
        $this->fill(['editingId' => $r->id, 'date' => $r->date->toDateString(), 'amount' => (string) (float) $r->amount, 'accountId' => $r->account_id,
            'vendorId' => $r->vendor_id, 'categoryId' => $r->category_id, 'paymentMethod' => (string) $r->payment_method,
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
            'vendorId' => ['nullable', 'exists:billing_vendors,id'],
            'categoryId' => ['nullable', 'exists:billing_categories,id'],
            'paymentMethod' => ['nullable', 'string', 'max:50'],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'receipt' => BillingFileController::RULES,
        ], ['receipt.mimes' => 'Receipt image (jpg / png) ya PDF honi chahiye.'], ['accountId' => 'account', 'categoryId' => 'category']);
        $existing = $this->editingId ? BillingExpense::findOrFail($this->editingId) : null;
        $path = $existing?->receipt_path;
        if ($this->receipt || $this->removeReceipt) { BillingFileController::forget($path); $path = null; }
        if ($this->receipt) $path = BillingFileController::store($this->receipt);
        $values = [
            'date' => $data['date'], 'amount' => $data['amount'], 'account_id' => $data['accountId'], 'vendor_id' => $data['vendorId'] ?: null,
            'category_id' => $data['categoryId'] ?: null,             'payment_method' => $data['paymentMethod'] ?: null, 'reference' => $data['reference'] ?: null, 'description' => $data['description'] ?: null, 'receipt_path' => $path,
        ];
        if ($existing) $existing->update($values);
        $r = $existing ?? BillingExpense::create($values + ['created_by' => auth()->id()]);
        $audit->record($existing ? 'billing.expense_updated' : 'billing.expense_created', $r, ['amount' => (float) $r->amount]);
        $this->reset('receipt', 'removeReceipt');
        $this->showForm = false;
        $this->toast('Expense saved.');
    }

    public function delete(int $id, AuditLogger $audit): void
    {
        $this->guard();
        $r = BillingExpense::findOrFail($id);
        $audit->record('billing.expense_deleted', $r, ['amount' => (float) $r->amount, 'date' => $r->date->toDateString()]);
        BillingFileController::forget($r->receipt_path);
        $r->delete();
        $this->toast('Expense deleted.');
    }

    public function closeModal(): void { $this->showForm = false; }

    public function render()
    {
        $this->guard();
        $term = trim($this->search);
        $date = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
        $query = BillingExpense::query()
            ->when($date($this->from), fn ($q, $d) => $q->whereDate('date', '>=', $d))
            ->when($date($this->to), fn ($q, $d) => $q->whereDate('date', '<=', $d))
            ->when($this->account, fn ($q, $a) => $q->where('account_id', (int) $a))
            ->when($this->vendor, fn ($q, $c) => $q->where('vendor_id', (int) $c))
            ->when($this->categoryFilter, fn ($q, $c) => $q->where('category_id', (int) $c))
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->where('reference', 'like', "%{$term}%")
                ->orWhere('description', 'like', "%{$term}%")->orWhereHas('vendor', fn ($q) => $q->where('name', 'like', "%{$term}%"))));
        $perPage = in_array($this->perPage, [10, 25, 50, 100], true) ? $this->perPage : 10;

        return $this->page('livewire.billing.expenses', [
            'expenses' => (clone $query)->with(['vendor', 'account', 'category'])->orderByDesc('date')->orderByDesc('id')->paginate($perPage),
            'total' => (float) (clone $query)->sum('amount'),
            'count' => (clone $query)->count(),
            'vendors' => BillingVendor::orderBy('name')->get(['id', 'name']),
            'accounts' => BillingBankAccount::orderByDesc('is_active')->orderBy('bank_name')->get(),
            'categories' => BillingCategory::where('type', 'expense')->orderBy('name')->get(['id', 'name']),
        ], 'Expenses');
    }
}
