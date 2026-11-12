<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Income / expense category for revenue (like the old CMS "Product & Service categories"). */
class BillingCategory extends Model
{
    public const TYPES = ['income' => 'Income', 'expense' => 'Expense', 'product' => 'Product & service'];

    protected $guarded = ['id'];
}
