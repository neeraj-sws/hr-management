<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class BillingCreditNote extends Model
{
    use Concerns\HasCompany;

    /** No company = the main company (everything billed before companies is SWS's). */
    public function companyNullIsShared(): bool { return false; }

    protected $guarded = ['id'];
    protected $casts = ['date' => 'date', 'amount' => 'decimal:2'];
    public function invoice(): BelongsTo { return $this->belongsTo(BillingInvoice::class, 'invoice_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(BillingCustomer::class, 'customer_id'); }
}
