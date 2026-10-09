<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRun extends Model
{
    protected $fillable = ['period_starts_on', 'period_ends_on', 'status', 'created_by', 'locked_by', 'locked_at', 'needs_recalculation'];
    protected $casts = ['period_starts_on' => 'date', 'period_ends_on' => 'date', 'locked_at' => 'datetime', 'needs_recalculation' => 'boolean'];
    public function items(): HasMany { return $this->hasMany(PayrollItem::class); }
}
