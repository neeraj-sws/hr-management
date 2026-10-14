<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    protected $fillable = ['project_id', 'assignee_id', 'created_by', 'title', 'description', 'priority', 'status', 'estimated_hours', 'due_on', 'started_at', 'completed_at', 'server_version', 'work_date', 'parent_id', 'overdue_notified_at', 'progress'];
    protected $casts = ['estimated_hours' => 'decimal:2', 'due_on' => 'date', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'work_date' => 'date'];
    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function assignee(): BelongsTo { return $this->belongsTo(AttendanceEmployee::class, 'assignee_id'); }
    public function timeEntries(): HasMany { return $this->hasMany(TaskTimeEntry::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    protected static function booted(): void
    {
        static::saving(function (Task $task) {
            if ($task->isDirty('due_on') && $task->exists) $task->overdue_notified_at = null;
        });
    }

    /**
     * Progress in %: 100 when completed; otherwise the % set on the task; otherwise
     * done subtasks out of all subtasks; otherwise logged time against the estimate
     * (capped at 95 until it is completed); otherwise 0.
     */
    public function progressPercent(?int $loggedSeconds = null): int
    {
        if ($this->status === 'completed') return 100;
        if ($this->progress !== null) return max(0, min(100, (int) $this->progress));
        $subtasks = $this->subtasks_count ?? null;
        if ($subtasks) return (int) round(((int) ($this->subtasks_done_count ?? 0)) / $subtasks * 100);
        if ($loggedSeconds !== null && (float) $this->estimated_hours > 0) return (int) min(95, round($loggedSeconds / ((float) $this->estimated_hours * 3600) * 100));

        return 0;
    }

    public function isOverdue(): bool
    {
        return $this->due_on !== null && $this->status !== 'completed' && $this->due_on->lt(today());
    }

    /** Completed after the end of its due date. */
    public function wasCompletedLate(): bool
    {
        return $this->due_on !== null && $this->completed_at !== null && $this->completed_at->gt($this->due_on->copy()->endOfDay());
    }
    public function parent(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(self::class, 'parent_id'); }
    public function subtasks(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(self::class, 'parent_id'); }
    public function comments(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(TaskComment::class)->latest(); }
}
