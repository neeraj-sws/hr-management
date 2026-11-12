<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A bank or cash account that invoice payments and revenue are received into and bills / expenses are paid from. */
class BillingBankAccount extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['opening_balance' => 'decimal:2', 'is_active' => 'boolean'];

    public function payments(): HasMany { return $this->hasMany(BillingInvoicePayment::class, 'account_id'); }
    public function revenues(): HasMany { return $this->hasMany(BillingRevenue::class, 'account_id'); }
    public function billPayments(): HasMany { return $this->hasMany(BillingBillPayment::class, 'account_id'); }
    public function expenses(): HasMany { return $this->hasMany(BillingExpense::class, 'account_id'); }
    public function transfersOut(): HasMany { return $this->hasMany(BillingBankTransfer::class, 'from_account_id'); }
    public function transfersIn(): HasMany { return $this->hasMany(BillingBankTransfer::class, 'to_account_id'); }

    /** e.g. "HDFC Bank · Soham Web Solution" or "Cash". */
    public function label(): string
    {
        // Like the CMS: "HDFC Bank Soham Web Solution", "Cash cash".
        return trim($this->bank_name.' '.$this->holder_name);
    }

    /** Money in: invoice payments, revenue, transfers in. */
    public function moneyIn(): float
    {
        return round((float) $this->payments()->sum('amount') + (float) $this->revenues()->sum('amount') + (float) $this->transfersIn()->sum('amount'), 2);
    }

    /** Money out: bill payments, expenses, transfers out. */
    public function moneyOut(): float
    {
        return round((float) $this->billPayments()->sum('amount') + (float) $this->expenses()->sum('amount') + (float) $this->transfersOut()->sum('amount'), 2);
    }

    public function balance(): float
    {
        return round((float) $this->opening_balance + $this->moneyIn() - $this->moneyOut(), 2);
    }

    /** Whether anything is recorded against the account (then it cannot be deleted). */
    public function inUse(): bool
    {
        return $this->payments()->exists() || $this->revenues()->exists() || $this->billPayments()->exists() || $this->expenses()->exists()
            || $this->transfersOut()->exists() || $this->transfersIn()->exists();
    }
}
