<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money paid to a vendor against a bill. */
class BillingBillPayment extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['date' => 'date', 'amount' => 'decimal:2'];

    public function bill(): BelongsTo { return $this->belongsTo(BillingBill::class, 'bill_id'); }
    public function account(): BelongsTo { return $this->belongsTo(BillingBankAccount::class, 'account_id'); }
    public function recorder(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }
}
