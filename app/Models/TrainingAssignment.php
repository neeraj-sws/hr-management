<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingAssignment extends Model
{
    protected $fillable = ['training_course_id', 'employee_id', 'assigned_by', 'due_on', 'status', 'started_at', 'completed_at', 'completion_note'];
    protected $casts = ['due_on' => 'date', 'started_at' => 'datetime', 'completed_at' => 'datetime'];

    public function course(): BelongsTo { return $this->belongsTo(TrainingCourse::class, 'training_course_id'); }
    public function employee(): BelongsTo { return $this->belongsTo(AttendanceEmployee::class, 'employee_id'); }
    public function assigner(): BelongsTo { return $this->belongsTo(User::class, 'assigned_by'); }

    public function isOverdue(): bool
    {
        return $this->status !== 'completed' && $this->due_on && $this->due_on->lt(today());
    }

    public function displayStatus(): string
    {
        return $this->isOverdue() ? 'overdue' : $this->status;
    }
}
