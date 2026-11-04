<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class BillingCustomer extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['is_active' => 'boolean'];
    public function invoices(): HasMany { return $this->hasMany(BillingInvoice::class, 'customer_id'); }
}
