<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingCustomer extends Model
{
    use Concerns\HasCompany;

    /** No company = the main company (everything billed before companies is SWS's). */
    public function companyNullIsShared(): bool { return false; }

    protected $guarded = ['id'];
    protected $casts = ['is_active' => 'boolean', 'opening_balance' => 'decimal:2'];

    public function invoices(): HasMany { return $this->hasMany(BillingInvoice::class, 'customer_id'); }
    public function estimates(): HasMany { return $this->hasMany(BillingEstimate::class, 'customer_id'); }

    /** New customers continue the CMS numbering. */
    protected static function booted(): void
    {
        static::creating(function (self $c) {
            $c->customer_number ??= (int) static::max('customer_number') + 1;
        });
    }

    /** e.g. #CUST00001 (the CMS customer number; falls back to the HRMS id). */
    public function code(): string
    {
        return \App\Services\BillingService::code('customer', (int) ($this->customer_number ?: $this->id));
    }

    /** What the customer owes: dues carried over from the CMS + every sent invoice's due (total − paid − credit notes). */
    public function owed(): float
    {
        $invoices = $this->relationLoaded('invoices') ? $this->invoices : $this->invoices()->with(['items.tax', 'payments', 'creditNotes'])->get();

        return round((float) $this->opening_balance + $invoices->where('status', '!=', 'Draft')->sum(fn ($i) => $i->due()), 2);
    }

    /** The balance as the CMS shows it: what is owed, as a negative number (paid in advance shows positive). */
    public function cmsBalance(): float
    {
        return round(-$this->owed(), 2) + 0.0;
    }
}
