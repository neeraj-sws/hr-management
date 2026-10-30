<?php

namespace App\Services;

use App\Models\BillingEstimate;
use App\Models\BillingInvoice;
use Illuminate\Support\Carbon;

/** Financial-year invoice/estimate numbering (India: Apr–Mar; number resets each year). */
class BillingService
{
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
        $next = (int) BillingInvoice::where('financial_year', $fy)->max('number') + 1;

        return ['financial_year' => $fy, 'number' => $next];
    }

    public function nextEstimateNumber(Carbon|string $date): array
    {
        $fy = $this->financialYear($date);
        $next = (int) BillingEstimate::where('financial_year', $fy)->max('number') + 1;

        return ['financial_year' => $fy, 'number' => $next];
    }
}
