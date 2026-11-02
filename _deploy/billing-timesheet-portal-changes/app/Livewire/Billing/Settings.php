<?php

namespace App\Livewire\Billing;

use App\Livewire\Concerns\InteractsWithHr;
use App\Services\BillingService;
use App\Services\PeopleOperationsService;
use Livewire\Component;

/** Company details printed on every invoice and estimate: address, GSTIN, PAN, bank, UPI, terms, signatory. */
class Settings extends Component
{
    use InteractsWithHr;

    public array $company = [];

    public function mount(BillingService $billing): void
    {
        abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403);
        $this->company = $billing->company();
    }

    public function save(PeopleOperationsService $people): void
    {
        abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403);
        foreach (['gstin', 'pan', 'ifsc'] as $upper) $this->company[$upper] = strtoupper(trim((string) ($this->company[$upper] ?? '')));
        $this->validate([
            'company.name' => ['required', 'string', 'max:191'],
            'company.gstin' => ['nullable', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/'],
            'company.pan' => ['nullable', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
            'company.ifsc' => ['nullable', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
            'company.email' => ['nullable', 'email'],
            'company.state_code' => ['nullable', 'digits:2'],
            'company.due_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'company.*' => ['nullable', 'string', 'max:2000'],
        ], ['company.gstin.regex' => 'GSTIN should look like 23ABCDE1234F1Z5.', 'company.pan.regex' => 'PAN should look like ABCDE1234F.', 'company.ifsc.regex' => 'IFSC should look like SBIN0001234.'],
            ['company.name' => 'company name']);
        $people->setPolicy('billing.company', 'billing', 'Billing company details', array_intersect_key(array_map(fn ($v) => trim((string) $v), $this->company), BillingService::COMPANY_FIELDS), null, auth()->id(), today()->toDateString());
        $this->toast('Billing settings saved. They appear on every invoice from now on.');
    }

    public function render()
    {
        return $this->page('livewire.billing.settings', ['fields' => BillingService::COMPANY_FIELDS], 'Billing settings');
    }
}
