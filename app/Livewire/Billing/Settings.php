<?php

namespace App\Livewire\Billing;

use App\Livewire\Concerns\InteractsWithHr;
use App\Services\BillingService;
use App\Services\PeopleOperationsService;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Company details printed on every invoice and estimate: address, GSTIN, PAN, bank, UPI, terms, signatory. */
class Settings extends Component
{
    use InteractsWithHr, WithFileUploads;

    public array $company = [];
    public $logoFile = null;
    /** Master Admin: the old CMS database dump (.sql) to import invoices, customers… from (no server terminal needed). */
    public $cmsDump = null;
    public string $cmsImportLog = '';
    /** The logo stays out of the form data (it is a few hundred KB); only whether there is one. */
    public bool $hasLogo = false;
    public bool $dropLogo = false;
    public $signatureFile = null;
    public bool $hasSignature = false;
    public bool $dropSignature = false;

    public function mount(BillingService $billing): void
    {
        abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403);
        $this->company = $billing->company();
        $this->hasLogo = ($this->company['logo'] ?? '') !== '';
        $this->hasSignature = ($this->company['signature'] ?? '') !== '';
        unset($this->company['logo'], $this->company['signature']);
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
            'company.invoice_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'], 'company.estimate_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'company.invoice_prefix' => ['nullable', 'regex:/^[A-Za-z0-9#\-]{1,12}$/'], 'company.estimate_prefix' => ['nullable', 'regex:/^[A-Za-z0-9#\-]{1,12}$/'],
            'company.customer_prefix' => ['nullable', 'regex:/^[A-Za-z0-9#\-]{1,12}$/'], 'company.vendor_prefix' => ['nullable', 'regex:/^[A-Za-z0-9#\-]{1,12}$/'], 'company.bill_prefix' => ['nullable', 'regex:/^[A-Za-z0-9#\-]{1,12}$/'],
            'company.date_format' => ['nullable', \Illuminate\Validation\Rule::in(array_keys(BillingService::DATE_FORMATS))],
            'company.mail_from_address' => ['nullable', 'email'], 'company.mail_from_name' => ['nullable', 'string', 'max:100'],
            'logoFile' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:300'],
            'signatureFile' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:300'],
            'company.footer_notes' => ['nullable', 'string', 'max:10000'],
        ] + collect(array_keys(BillingService::COMPANY_FIELDS))->reject(fn ($k) => in_array($k, ['logo', 'signature', 'footer_notes'], true))
            ->mapWithKeys(fn ($k) => ['company.'.$k => ['nullable', 'string', 'max:2000']])->all(), ['company.gstin.regex' => 'GSTIN should look like 23ABCDE1234F1Z5.', 'company.pan.regex' => 'PAN should look like ABCDE1234F.', 'company.ifsc.regex' => 'IFSC should look like SBIN0001234.'],
            ['company.name' => 'company name', 'logoFile' => 'logo', 'signatureFile' => 'signature', 'company.invoice_prefix' => 'invoice prefix', 'company.estimate_prefix' => 'estimate prefix']);
        // The logo is kept inside the settings as a small image, so it shows on prints, the customer link and e-mails alike.
        $logo = $this->dropLogo ? '' : (string) (BillingService::settings()['logo'] ?? '');
        if ($this->logoFile) $logo = 'data:'.$this->logoFile->getMimeType().';base64,'.base64_encode(file_get_contents($this->logoFile->getRealPath()));
        $values = array_intersect_key(array_map(fn ($v) => trim((string) $v), $this->company), BillingService::COMPANY_FIELDS);
        $values['logo'] = $logo;
        $sign = $this->dropSignature ? '' : (string) (BillingService::settings()['signature'] ?? '');
        if ($this->signatureFile) $sign = 'data:'.$this->signatureFile->getMimeType().';base64,'.base64_encode(file_get_contents($this->signatureFile->getRealPath()));
        $values['signature'] = $sign;
        $values['footer_notes'] = BillingService::safeHtml($values['footer_notes'] ?? '');
        $people->setPolicy('billing.company', 'billing', 'Billing company details', $values, null, auth()->id(), today()->toDateString());
        $this->logoFile = null;
        $this->dropLogo = false;
        $this->hasLogo = $logo !== '';
        $this->signatureFile = null;
        $this->dropSignature = false;
        $this->hasSignature = $sign !== '';
        $this->company['footer_notes'] = $values['footer_notes'];
        BillingService::forgetSettings();
        $this->toast('Billing settings saved. They appear on every invoice from now on.');
    }

    /**
     * Runs the CMS billing import (php artisan billing:import-cms) on an uploaded dump. Safe to run again: invoices already
     * in HRMS are matched (CMS number, customer, date) and only fixed, never doubled; HRMS payments stay.
     */
    public function importCms(): void
    {
        abort_unless(\App\Support\Access::isAdmin($this->user()), 403);
        $this->validate(['cmsDump' => ['required', 'file', 'max:51200']], [], ['cmsDump' => 'CMS dump']);
        if (strtolower($this->cmsDump->getClientOriginalExtension()) !== 'sql') { $this->addError('cmsDump', 'CMS ka database dump (.sql file) chuno.'); return; }
        @set_time_limit(600);
        $code = \Illuminate\Support\Facades\Artisan::call('billing:import-cms', ['file' => $this->cmsDump->getRealPath()]);
        $this->cmsImportLog = trim(\Illuminate\Support\Facades\Artisan::output());
        $this->cmsDump = null;
        app(\App\Services\AuditLogger::class)->record('billing.cms_imported', null, ['ok' => $code === 0]);
        $this->toast($code === 0 ? 'CMS data imported.' : 'CMS import failed — see the log below.', $code === 0 ? 'success' : 'error');
    }

    public function removeLogo(): void
    {
        abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403);
        $this->dropLogo = true;
        $this->logoFile = null;
    }

    public function removeSignature(): void
    {
        abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403);
        $this->dropSignature = true;
        $this->signatureFile = null;
    }

    public function render()
    {
        return $this->page('livewire.billing.settings', ['fields' => BillingService::COMPANY_FIELDS], 'Billing settings');
    }
}
