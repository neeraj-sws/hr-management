<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HolidayOverride extends Model
{
    protected $fillable = ['scope_type', 'scope_id', 'original_date', 'action', 'new_date', 'name', 'reason', 'created_by'];

    protected $casts = ['original_date' => 'date', 'new_date' => 'date'];

    public const ACTIONS = ['shift' => 'Shift to another day', 'cancel' => 'Cancel (make it a working day)', 'add' => 'Add a holiday'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
