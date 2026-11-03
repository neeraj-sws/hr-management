<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A Help & Support category. Restricted ones (Salary & Payroll) are seen only by people with support.payroll. */
class SupportCategory extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'is_restricted', 'is_active', 'sort_order'];

    protected $casts = ['is_restricted' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer'];

    public function tickets(): HasMany { return $this->hasMany(SupportTicket::class, 'category_id'); }
}
