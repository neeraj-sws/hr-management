<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lowers what the company owes a vendor on a bill (returned goods, discount). */
class BillingDebitNote extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['date' => 'date', 'amount' => 'decimal:2'];

    public function bill(): BelongsTo { return $this->belongsTo(BillingBill::class, 'bill_id'); }
    public function vendor(): BelongsTo { return $this->belongsTo(BillingVendor::class, 'vendor_id'); }
}
