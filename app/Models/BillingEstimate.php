<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class BillingEstimate extends Model
{
    use Concerns\HasCompany;

    /** No company = the main company (everything billed before companies is SWS's). */
    public function companyNullIsShared(): bool { return false; }

    /** Numbers run per company and financial year, so the company is always set (the main one by default). */
    protected static function booted(): void
    {
        static::creating(function (self $doc) {
            $doc->company_id ??= Company::main()?->id;
        });
    }

    /** "SWS" for the main company's documents; another company's start with its name, e.g. "ITWEB". */
    public function companyPrefix(): ?string
    {
        $company = $this->company_id ? Company::withoutGlobalScopes()->find($this->company_id) : null;

        return $company && ! $company->is_main ? strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $company->name), 0, 8)) : null;
    }

    protected $guarded = ['id'];
    protected $casts = ['issue_date' => 'date', 'expiry_date' => 'date'];
    public const STATUSES = ['Draft', 'Sent', 'Accepted', 'Declined', 'Converted'];
    public function customer(): BelongsTo { return $this->belongsTo(BillingCustomer::class, 'customer_id'); }
    public function items(): HasMany { return $this->hasMany(BillingEstimateItem::class, 'estimate_id'); }
    public function number(?string $prefix = null): string
    {
        $prefix ??= ($this->companyPrefix() ? $this->companyPrefix().'-' : '').config('billing.estimate_prefix', 'EST');
        return $prefix.'/'.$this->financial_year.'/'.str_pad((string) $this->number, 5, '0', STR_PAD_LEFT);
    }
    public function subTotal(): float { return round($this->items->sum(fn ($i) => $i->lineSubtotal()), 2); }
    public function totalTax(): float { return round($this->items->sum(fn ($i) => $i->lineTax()), 2); }
    public function total(): float { return round($this->subTotal() + $this->totalTax(), 2); }
}
