<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class BillingInvoicePayment extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['date' => 'date', 'amount' => 'decimal:2'];
}
