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
    ];

    /** The Indian financial year of a date, e.g. 2026-09-30 -> "2026-27", 2027-02-10 -> "2026-27". */
    public function financialYear(Carbon|string $date): string
    {
        $d = $date instanceof Carbon ? $date : Carbon::parse($date);
        $startYear = $d->month >= 4 ? $d->year : $d->year - 1;

        return $startYear.'-'.substr((string) ($startYear + 1), 2);
    }

    /** Next invoice sequence for the financial year of $date (1-based, resets each year). */
    public function nextInvoiceNumber(Carbon|string $date): array
    {
        $fy = $this->financialYear($date);
        $next = (int) BillingInvoice::where('financial_year', $fy)->lockForUpdate()->max('number') + 1;

        return ['financial_year' => $fy, 'number' => $next];
    }

    public function nextEstimateNumber(Carbon|string $date): array
    {
        $fy = $this->financialYear($date);
        $next = (int) BillingEstimate::where('financial_year', $fy)->lockForUpdate()->max('number') + 1;

        return ['financial_year' => $fy, 'number' => $next];
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
