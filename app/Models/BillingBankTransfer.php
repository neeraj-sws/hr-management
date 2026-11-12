<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money moved from one bank / cash account to another. */
class BillingBankTransfer extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['date' => 'date', 'amount' => 'decimal:2'];

    public function from(): BelongsTo { return $this->belongsTo(BillingBankAccount::class, 'from_account_id'); }
    public function to(): BelongsTo { return $this->belongsTo(BillingBankAccount::class, 'to_account_id'); }
}
