<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompOffCredit extends Model
{
    protected $fillable = ['employee_id', 'earned_on', 'days', 'status', 'expires_on', 'used_leave_id', 'note', 'created_by'];

    protected $casts = ['earned_on' => 'date', 'expires_on' => 'date', 'days' => 'decimal:2'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(AttendanceEmployee::class, 'employee_id');
    }
}
