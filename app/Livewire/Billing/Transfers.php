<?php

namespace App\Livewire\Billing;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\BillingBankAccount;
use App\Models\BillingBankTransfer;
use App\Services\AuditLogger;
use Livewire\Attributes\Url;
use Livewire\Component;
use App\Livewire\Concerns\WithPaginationNoUrl as WithPagination;

/** Banking → Transfer: money moved between bank / cash accounts (e.g. cash deposited in HDFC). */
class Transfers extends Component
{
    use InteractsWithHr, WithPagination;

    #[Url] public string $from = '';
    #[Url] public string $to = '';
    #[Url] public string $account = '';
    #[Url] public int $perPage = 10;

    public bool $showForm = false;
    public ?int $editingId = null;
    public ?int $fromAccountId = null;
    public ?int $toAccountId = null;
    public string $amount = '';
    public string $date = '';
    public string $reference = '';
    public string $description = '';

    private function guard(): void { abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403); }

    public function updated($property): void
    {
        if (in_array($property, ['from', 'to', 'account', 'perPage'], true)) $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('from', 'to', 'account');
        $this->resetPage();
    }

    public function create(): void
    {
        $this->guard();
        $this->reset('editingId', 'fromAccountId', 'toAccountId', 'amount', 'reference', 'description');
        $this->date = today()->toDateString();
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->guard();
        $t = BillingBankTransfer::findOrFail($id);
        $this->fill(['editingId' => $t->id, 'fromAccountId' => $t->from_account_id, 'toAccountId' => $t->to_account_id, 'amount' => (string) (float) $t->amount,
            'date' => $t->date->toDateString(), 'reference' => (string) $t->reference, 'description' => (string) $t->description]);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(AuditLogger $audit): void
    {
        $this->guard();
        $d = $this->validate([
            'fromAccountId' => ['required', 'exists:billing_bank_accounts,id'], 'toAccountId' => ['required', 'different:fromAccountId', 'exists:billing_bank_accounts,id'],
            'amount' => ['required', 'numeric', 'min:0.01'], 'date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:500'],
        ], ['toAccountId.different' => 'From aur To account alag hone chahiye.'], ['fromAccountId' => 'from account', 'toAccountId' => 'to account']);
        $t = BillingBankTransfer::updateOrCreate(['id' => $this->editingId], [
            'from_account_id' => $d['fromAccountId'], 'to_account_id' => $d['toAccountId'], 'amount' => (float) $d['amount'], 'date' => $d['date'],
            'reference' => $d['reference'] ?: null, 'description' => $d['description'] ?: null,
        ] + ($this->editingId ? [] : ['created_by' => auth()->id()]));
        $audit->record($this->editingId ? 'billing.transfer_updated' : 'billing.transfer_created', $t, ['amount' => (float) $t->amount]);
        $this->showForm = false;
        $this->toast('Transfer saved.');
    }

    public function delete(int $id, AuditLogger $audit): void
    {
        $this->guard();
        $t = BillingBankTransfer::findOrFail($id);
        $audit->record('billing.transfer_deleted', $t, ['amount' => (float) $t->amount]);
        $t->delete();
        $this->toast('Transfer deleted.');
    }

    public function closeModal(): void { $this->showForm = false; }

    public function render()
    {
        $this->guard();
        $date = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
        $transfers = BillingBankTransfer::with('from', 'to')
            ->when($date($this->from), fn ($q, $d) => $q->whereDate('date', '>=', $d))
            ->when($date($this->to), fn ($q, $d) => $q->whereDate('date', '<=', $d))
            ->when($this->account, fn ($q, $a) => $q->where(fn ($w) => $w->where('from_account_id', (int) $a)->orWhere('to_account_id', (int) $a)))
            ->orderByDesc('date')->orderByDesc('id')->paginate(in_array($this->perPage, [10, 25, 50, 100], true) ? $this->perPage : 10);

        return $this->page('livewire.billing.transfers', [
            'transfers' => $transfers,
            'accounts' => BillingBankAccount::orderByDesc('is_active')->orderBy('bank_name')->get(),
        ], 'Transfers');
    }
}
