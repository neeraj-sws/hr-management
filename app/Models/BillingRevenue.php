<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class BillingRevenue extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['date' => 'date', 'amount' => 'decimal:2'];
    public function account(): BelongsTo { return $this->belongsTo(BillingBankAccount::class, 'account_id'); }
    public function categoryModel(): BelongsTo { return $this->belongsTo(BillingCategory::class, 'category_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(BillingCustomer::class, 'customer_id'); }
}
