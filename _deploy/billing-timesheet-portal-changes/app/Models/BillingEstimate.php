<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class BillingEstimate extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['issue_date' => 'date', 'expiry_date' => 'date'];
    public const STATUSES = ['Draft', 'Sent', 'Accepted', 'Declined', 'Converted'];
    public function customer(): BelongsTo { return $this->belongsTo(BillingCustomer::class, 'customer_id'); }
    public function items(): HasMany { return $this->hasMany(BillingEstimateItem::class, 'estimate_id'); }
    public function number(?string $prefix = null): string
    {
        $prefix ??= config('billing.estimate_prefix', 'EST');
        return $prefix.'/'.$this->financial_year.'/'.str_pad((string) $this->number, 5, '0', STR_PAD_LEFT);
    }
    public function subTotal(): float { return round($this->items->sum(fn ($i) => $i->lineSubtotal()), 2); }
    public function totalTax(): float { return round($this->items->sum(fn ($i) => $i->lineTax()), 2); }
    public function total(): float { return round($this->subTotal() + $this->totalTax(), 2); }
}
