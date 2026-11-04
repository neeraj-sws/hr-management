<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A customer company whose people sign in to the client portal to review project timesheets. */
class Client extends Model
{
    protected $fillable = ['name', 'contact_name', 'email', 'phone', 'billing_customer_id', 'is_active', 'notes'];

    protected $casts = ['is_active' => 'boolean'];

    public function projects(): HasMany { return $this->hasMany(Project::class); }
    public function users(): HasMany { return $this->hasMany(User::class); }
    public function billingCustomer(): BelongsTo { return $this->belongsTo(BillingCustomer::class, 'billing_customer_id'); }
}
