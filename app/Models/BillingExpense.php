<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money paid out directly (rent, salary advance, tea, hardware…) — the CMS "Payment" / "Expense". */
class BillingExpense extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['date' => 'date', 'amount' => 'decimal:2'];

    public function account(): BelongsTo { return $this->belongsTo(BillingBankAccount::class, 'account_id'); }
    public function vendor(): BelongsTo { return $this->belongsTo(BillingVendor::class, 'vendor_id'); }
    public function category(): BelongsTo { return $this->belongsTo(BillingCategory::class, 'category_id'); }
}
