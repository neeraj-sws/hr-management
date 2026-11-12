<?php

namespace App\Livewire\Billing;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\BillingBankAccount;
use App\Services\AuditLogger;
use Livewire\Component;

/** Banking: the bank / cash accounts payments and revenue are received into, with their balance. */
class BankAccounts extends Component
{
    use InteractsWithHr;

    public bool $showForm = false;
    public ?int $editingId = null;
    public string $holderName = '';
    public string $bankName = '';
    public string $accountNumber = '';
    public string $ifsc = '';
    public string $openingBalance = '0';
    public string $contact = '';
    public string $address = '';
    public bool $isActive = true;

    private function guard(): void { abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403); }

    public function create(): void
    {
        $this->guard();
        $this->reset('editingId', 'holderName', 'bankName', 'accountNumber', 'ifsc', 'openingBalance', 'contact', 'address', 'isActive');
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->guard();
        $a = BillingBankAccount::findOrFail($id);
        $this->fill(['editingId' => $a->id, 'holderName' => $a->holder_name, 'bankName' => $a->bank_name, 'accountNumber' => (string) $a->account_number,
            'ifsc' => (string) $a->ifsc, 'openingBalance' => (string) (float) $a->opening_balance, 'contact' => (string) $a->contact, 'address' => (string) $a->address, 'isActive' => (bool) $a->is_active]);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(AuditLogger $audit): void
    {
        $this->guard();
        $d = $this->validate([
            'holderName' => ['required', 'string', 'max:191'], 'bankName' => ['required', 'string', 'max:191'], 'accountNumber' => ['nullable', 'string', 'max:50'],
            'ifsc' => ['nullable', 'string', 'max:20'], 'openingBalance' => ['required', 'numeric', 'min:-100000000', 'max:100000000'],
            'contact' => ['nullable', 'string', 'max:50'], 'address' => ['nullable', 'string', 'max:500'],
        ], [], ['holderName' => 'account holder', 'bankName' => 'bank name', 'openingBalance' => 'opening balance']);
        $a = BillingBankAccount::updateOrCreate(['id' => $this->editingId], [
            'holder_name' => $d['holderName'], 'bank_name' => $d['bankName'], 'account_number' => $d['accountNumber'] ?: null, 'ifsc' => strtoupper((string) $d['ifsc']) ?: null,
            'opening_balance' => (float) $d['openingBalance'], 'contact' => $d['contact'] ?: null, 'address' => $d['address'] ?: null, 'is_active' => $this->isActive,
        ] + ($this->editingId ? [] : ['created_by' => auth()->id()]));
        $audit->record($this->editingId ? 'billing.account_updated' : 'billing.account_created', $a);
        $this->showForm = false;
        $this->toast('Account saved.');
    }

    public function delete(int $id, AuditLogger $audit): void
    {
        $this->guard();
        $a = BillingBankAccount::findOrFail($id);
        if ($a->inUse()) { $this->toast('This account has entries, so it cannot be deleted (mark it Inactive instead).', 'error'); return; }
        $audit->record('billing.account_deleted', $a);
        $a->delete();
        $this->toast('Account deleted.');
    }

    public function closeModal(): void { $this->showForm = false; }

    public function render()
    {
        $this->guard();
        $accounts = BillingBankAccount::orderByDesc('is_active')->orderBy('bank_name')->get()
            ->each(function ($a) { $a->money_in = $a->moneyIn(); $a->money_out = $a->moneyOut(); });

        return $this->page('livewire.billing.bank-accounts', ['accounts' => $accounts], 'Bank accounts');
    }
}
