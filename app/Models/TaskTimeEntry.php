<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskTimeEntry extends Model
{
    protected $fillable = ['task_id', 'employee_id', 'started_at', 'ended_at', 'duration_seconds', 'status'];
    protected $casts = ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    public function task(): BelongsTo { return $this->belongsTo(Task::class); }
    public function employee(): BelongsTo { return $this->belongsTo(AttendanceEmployee::class); }
}
