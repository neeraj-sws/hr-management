<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class BillingCustomer extends Model
{
    use Concerns\HasCompany;

    /** No company = the main company (everything billed before companies is SWS's). */
    public function companyNullIsShared(): bool { return false; }

    protected $guarded = ['id'];
    protected $casts = ['is_active' => 'boolean'];
    public function invoices(): HasMany { return $this->hasMany(BillingInvoice::class, 'customer_id'); }
}
