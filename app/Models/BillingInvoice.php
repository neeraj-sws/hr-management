<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class BillingInvoice extends Model
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
    protected $casts = ['issue_date' => 'date', 'due_date' => 'date', 'send_date' => 'date'];

    public const STATUSES = ['Draft', 'Sent', 'Unpaid', 'Partially Paid', 'Paid'];

    public function customer(): BelongsTo { return $this->belongsTo(BillingCustomer::class, 'customer_id'); }
    public function category(): BelongsTo { return $this->belongsTo(BillingCategory::class, 'category_id'); }
    public function items(): HasMany { return $this->hasMany(BillingInvoiceItem::class, 'invoice_id'); }
    public function payments(): HasMany { return $this->hasMany(BillingInvoicePayment::class, 'invoice_id'); }
    public function creditNotes(): HasMany { return $this->hasMany(BillingCreditNote::class, 'invoice_id'); }
    public function project(): BelongsTo { return $this->belongsTo(Project::class, 'project_id'); }

    /** Display number, e.g. SWS/2026-27/00018. Prefix comes from settings (default SWS). */
    public function number(?string $prefix = null): string
    {
        $prefix ??= $this->companyPrefix() ?? \App\Services\BillingService::prefix('invoice');
        return $prefix.'/'.$this->financial_year.'/'.str_pad((string) $this->number, 5, '0', STR_PAD_LEFT);
    }

    public function subTotal(): float { return round($this->items->sum(fn ($i) => $i->lineSubtotal()), 2); }
    public function totalTax(): float { return round($this->items->sum(fn ($i) => $i->lineTax()), 2); }
    public function total(): float { return round($this->subTotal() + $this->totalTax(), 2); }
    public function paidAmount(): float { return round($this->payments->sum('amount'), 2); }
    public function creditTotal(): float { return round($this->creditNotes->sum('amount'), 2); }
    public function due(): float { return round($this->total() - $this->paidAmount() - $this->creditTotal(), 2); }

    /** Recompute the status from payments + credit notes. */
    public function refreshStatus(): void
    {
        $due = $this->due();
        $paid = $this->paidAmount();
        if ($this->status === 'Draft') return; // stays Draft until sent
        $this->status = $due <= 0 ? 'Paid' : ($paid > 0 ? 'Partially Paid' : ($this->status === 'Sent' ? 'Sent' : 'Unpaid'));
        $this->save();
    }
}
