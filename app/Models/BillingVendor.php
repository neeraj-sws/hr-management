<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A supplier the company buys from (bills, expenses). */
class BillingVendor extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['is_active' => 'boolean'];

    public function bills(): HasMany { return $this->hasMany(BillingBill::class, 'vendor_id'); }
    public function expenses(): HasMany { return $this->hasMany(BillingExpense::class, 'vendor_id'); }
}
