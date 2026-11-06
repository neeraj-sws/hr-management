<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class BillingTax extends Model
{
    use Concerns\HasCompany;

    protected $guarded = ['id'];
    protected $casts = ['rate' => 'decimal:2'];
}
