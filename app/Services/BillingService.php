<?php

namespace App\Services;

use App\Models\BillingEstimate;
use App\Models\BillingInvoice;
use Illuminate\Support\Carbon;

/** Financial-year invoice/estimate numbering (India: Apr–Mar; number resets each year), company details and GST split. */
class BillingService
{
    /** Company details printed on every invoice (Billing → Settings). */
    public const COMPANY_FIELDS = [
        'name' => 'Company / legal name', 'address' => 'Address', 'city' => 'City', 'state' => 'State', 'state_code' => 'State code (e.g. 23)',
        'pin' => 'PIN code', 'gstin' => 'GSTIN', 'pan' => 'PAN', 'email' => 'E-mail', 'phone' => 'Phone', 'website' => 'Website',
        'bank_name' => 'Bank name', 'account_name' => 'Account name', 'account_number' => 'Account number', 'ifsc' => 'IFSC', 'upi' => 'UPI ID',
        'terms' => 'Terms & conditions', 'signatory' => 'Authorised signatory', 'due_days' => 'Default due in (days)',
        // Look & numbering (like the old CMS settings)
        'logo' => 'Logo', 'invoice_color' => 'Invoice colour', 'estimate_color' => 'Estimate colour', 'show_qr' => 'QR code on invoice',
        'invoice_prefix' => 'Invoice prefix', 'estimate_prefix' => 'Estimate prefix', 'customer_prefix' => 'Customer prefix', 'vendor_prefix' => 'Vendor prefix', 'bill_prefix' => 'Bill prefix',
        'date_format' => 'Date format', 'mail_from_address' => 'Send invoices from (e-mail)', 'mail_from_name' => 'Sender name',
        // Printed under the invoice, like the CMS: footer title + notes (bank details, may hold simple formatting), signature / seal image.
        'footer_title' => 'Footer title', 'footer_notes' => 'Footer notes (bank details)', 'signature' => 'Signature / seal image',
    ];

    /** Footer notes keep simple formatting (bold, line breaks) but never scripts, links or event handlers. */
    public static function safeHtml(?string $html): string
    {
        $html = strip_tags((string) $html, '<p><br><b><strong><i><em><u><span><div><small>');
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
        $html = preg_replace('/(style\s*=\s*"[^"]*)(expression|url\s*\(|javascript:)[^"]*"/i', '$1"', $html);

        return trim($html);
    }

    /** Date formats offered in Billing settings (PHP format => example). */
    public const DATE_FORMATS = ['M j, Y' => 'May 1, 2026', 'd M Y' => '01 May 2026', 'd-m-Y' => '01-05-2026', 'd/m/Y' => '01/05/2026'];

    /** Billing settings for this request (read once; saved settings clear it). */
    public static function settings(): array
    {
        if (! app()->bound('billing.settings')) app()->instance('billing.settings', app(self::class)->company());

        return app('billing.settings');
    }

    public static function forgetSettings(): void
    {
        app()->forgetInstance('billing.settings');
    }

    /** Number prefix for a document: invoice "SWS", estimate "EST", customer "#CUST", vendor "#VEND", bill "BILL". */
    public static function prefix(string $kind): string
    {
        $defaults = ['invoice' => config('billing.prefix', 'SWS'), 'estimate' => '#PROP', 'customer' => '#CUST', 'vendor' => '#VEND', 'bill' => 'BILL'];
        $saved = trim((string) (self::settings()[$kind.'_prefix'] ?? ''));

        return $saved !== '' ? $saved : ($defaults[$kind] ?? '');
    }

    /** e.g. #CUST00024. */
    public static function code(string $kind, int $id): string
    {
        return self::prefix($kind).str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }

    /** A billing date in the chosen format; null stays null so "?? '—'" works. */
    public static function date($date): ?string
    {
        if ($date === null || $date === '') return null;
        $format = self::settings()['date_format'] ?? '';

        return Carbon::parse($date)->format(isset(self::DATE_FORMATS[$format]) ? $format : 'M j, Y'); // CMS style by default
    }

    /** Accent colour of invoices / estimates (hex). */
    public static function color(string $kind = 'invoice'): string
    {
        $c = (string) (self::settings()[$kind.'_color'] ?? '');

        return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : '#1f3a5f';
    }

    /** The Indian financial year of a date, e.g. 2026-09-30 -> "2026-27", 2027-02-10 -> "2026-27". */
    public function financialYear(Carbon|string $date): string
    {
        $d = $date instanceof Carbon ? $date : Carbon::parse($date);
        $startYear = $d->month >= 4 ? $d->year : $d->year - 1;

        return $startYear.'-'.substr((string) ($startYear + 1), 2);
    }

    /** Next invoice sequence for the financial year of $date (1-based, resets each year). */
    public function nextInvoiceNumber(Carbon|string $date, ?int $companyId = null): array
    {
        $fy = $this->financialYear($date);
        $company = $this->documentCompany($companyId);
        $next = (int) BillingInvoice::withoutGlobalScopes()->where('company_id', $company)->where('financial_year', $fy)->lockForUpdate()->max('number') + 1;

        return ['financial_year' => $fy, 'number' => $next, 'company_id' => $company];
    }

    public function nextEstimateNumber(Carbon|string $date, ?int $companyId = null): array
    {
        // One running sequence like the CMS (#PROP00001, #PROP00002 …); the year is only kept for filtering.
        $fy = $this->financialYear($date);
        $company = $this->documentCompany($companyId);
        $next = (int) BillingEstimate::withoutGlobalScopes()->where('company_id', $company)->lockForUpdate()->max('number') + 1;

        return ['financial_year' => $fy, 'number' => $next, 'company_id' => $company];
    }

    /** Whose numbers: the given company, else the signed-in company owner's, else the main company. */
    private function documentCompany(?int $companyId): ?int
    {
        return \App\Support\CompanyAccess::limitedCompanyId() ?? $companyId ?? \App\Models\Company::main()?->id;
    }

    /** The company details printed on a document of this company (its own when it has them). */
    public function companyFor(?int $companyId): array
    {
        return \App\Support\CompanyAccess::withRulesCompany($companyId, fn () => $this->company());
    }

    /** Company details for invoices, with blanks for anything not set yet. */
    public function company(): array
    {
        $saved = app(PolicyConfigurationService::class)->forEmployee(null, 'billing.company', today()->toDateString(), []);

        return array_merge(array_fill_keys(array_keys(self::COMPANY_FIELDS), ''), ['name' => config('app.name'), 'due_days' => '7'], array_filter($saved, fn ($v) => $v !== null && $v !== ''));
    }

    /** Indian states / UTs: GST state code => [name, short forms]. "DL", "Delhi", "07" and "New Delhi" are the same state. */
    public const STATES = [
        '01' => ['Jammu and Kashmir', 'JK'], '02' => ['Himachal Pradesh', 'HP'], '03' => ['Punjab', 'PB'], '04' => ['Chandigarh', 'CH'],
        '05' => ['Uttarakhand', 'UK', 'UT', 'Uttaranchal'], '06' => ['Haryana', 'HR'], '07' => ['Delhi', 'DL', 'New Delhi', 'NCT of Delhi'],
        '08' => ['Rajasthan', 'RJ'], '09' => ['Uttar Pradesh', 'UP'], '10' => ['Bihar', 'BR'], '11' => ['Sikkim', 'SK'],
        '12' => ['Arunachal Pradesh', 'AR'], '13' => ['Nagaland', 'NL'], '14' => ['Manipur', 'MN'], '15' => ['Mizoram', 'MZ'],
        '16' => ['Tripura', 'TR'], '17' => ['Meghalaya', 'ML'], '18' => ['Assam', 'AS'], '19' => ['West Bengal', 'WB'],
        '20' => ['Jharkhand', 'JH'], '21' => ['Odisha', 'OD', 'OR', 'Orissa'], '22' => ['Chhattisgarh', 'CG', 'Chattisgarh'],
        '23' => ['Madhya Pradesh', 'MP'], '24' => ['Gujarat', 'GJ'], '26' => ['Dadra and Nagar Haveli and Daman and Diu', 'DN', 'DD'],
        '27' => ['Maharashtra', 'MH'], '29' => ['Karnataka', 'KA'], '30' => ['Goa', 'GA'], '31' => ['Lakshadweep', 'LD'],
        '32' => ['Kerala', 'KL'], '33' => ['Tamil Nadu', 'TN'], '34' => ['Puducherry', 'PY', 'Pondicherry'], '35' => ['Andaman and Nicobar Islands', 'AN'],
        '36' => ['Telangana', 'TS', 'TG'], '37' => ['Andhra Pradesh', 'AP'], '38' => ['Ladakh', 'LA'],
    ];

    /** GST state code ("07") for a state written any way ("DL", "Delhi", "07", "new delhi"); null when unknown. */
    public static function stateCode(?string $state): ?string
    {
        $key = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $state));
        if ($key === '') return null;
        if (preg_match('/^\d{1,2}$/', $key)) return isset(self::STATES[str_pad($key, 2, '0', STR_PAD_LEFT)]) ? str_pad($key, 2, '0', STR_PAD_LEFT) : null;
        foreach (self::STATES as $code => $names) {
            foreach ($names as $name) if (strtolower(preg_replace('/[^a-z0-9]/i', '', $name)) === $key) return (string) $code;
        }

        return null;
    }

    /** "Delhi (07)" for any spelling of a state; the text as written when unknown. */
    public static function stateLabel(?string $state): string
    {
        $code = self::stateCode($state);

        return $code ? self::STATES[$code][0].' ('.$code.')' : (string) $state;
    }

    /**
     * Same state (CGST + SGST) unless the place of supply is another state (IGST). States are compared by GST
     * state code, so "MP" and "Madhya Pradesh" match; the company's GSTIN gives its state when none is set.
     * Unknown states count as the same state.
     */
    public function isInterState(?string $placeOfSupply): bool
    {
        $company = $this->company();
        $ours = self::stateCode($company['state_code'] ?: $company['state']) ?? (preg_match('/^\d{2}/', (string) $company['gstin'], $m) ? self::stateCode($m[0]) : null);
        $theirs = self::stateCode($placeOfSupply);
        if ($ours && $theirs) return $ours !== $theirs;
        $norm = fn (?string $s) => strtolower(preg_replace('/[^a-z]/i', '', (string) $s));

        return $norm($company['state']) !== '' && $norm($placeOfSupply) !== '' && $norm($company['state']) !== $norm($placeOfSupply);
    }

    /** A customer GSTIN without a leading "GSTIN:" label (as some imported records have). */
    public static function cleanGstin(?string $value): string
    {
        return trim((string) preg_replace('/^\s*GSTIN\s*(no\.?|number)?\s*[:\-]?\s*/i', '', (string) $value));
    }

    /**
     * Tax summary of an invoice / estimate grouped by rate: [rate => [taxable, cgst, sgst, igst, total_tax]].
     *
     * @param  BillingInvoice|BillingEstimate  $document
     */
    public function taxSplit($document): array
    {
        $inter = $this->isInterState($document->place_of_supply ?: $document->customer?->billing_state);
        $rows = [];
        foreach ($document->items as $item) {
            $rate = (float) ($item->tax?->rate ?? 0);
            $key = (string) $rate;
            $rows[$key] ??= ['rate' => $rate, 'taxable' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'igst' => 0.0, 'total_tax' => 0.0];
            $rows[$key]['taxable'] += $item->lineSubtotal();
            $tax = $item->lineTax();
            $rows[$key]['total_tax'] += $tax;
            if ($inter) {
                $rows[$key]['igst'] += $tax;
            } else {
                $half = round($tax / 2, 2);
                $rows[$key]['cgst'] += $half;
                $rows[$key]['sgst'] += round($tax - $half, 2);
            }
        }
        ksort($rows, SORT_NUMERIC);

        return array_values(array_map(fn ($r) => array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $r), $rows));
    }
}
