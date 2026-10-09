<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    protected $fillable = ['name', 'annual_allowance', 'carry_forward', 'carry_forward_limit', 'carry_forward_expiry_months', 'paid', 'is_active', 'monthly_accrual', 'allow_advance'];
    protected $casts = ['annual_allowance' => 'decimal:2', 'carry_forward_limit' => 'decimal:2', 'monthly_accrual' => 'decimal:3', 'carry_forward' => 'boolean', 'paid' => 'boolean', 'is_active' => 'boolean', 'allow_advance' => 'boolean'];
    public function requests(): HasMany { return $this->hasMany(LeaveRequest::class); }
}
