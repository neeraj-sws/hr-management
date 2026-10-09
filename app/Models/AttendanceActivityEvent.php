<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceActivityEvent extends Model
{
    protected $fillable = [
        'attendance_session_id',
        'event_type',
        'event_time',
        'duration_seconds',
        'metadata',
    ];

    protected $casts = [
        'event_time' => 'datetime',
        'metadata' => 'array',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(AttendanceSession::class, 'attendance_session_id');
    }
}
