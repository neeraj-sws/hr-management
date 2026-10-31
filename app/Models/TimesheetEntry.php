<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One line of a project's timesheet: who worked on what, when and for how long (actual and billable minutes). */
class TimesheetEntry extends Model
{
    protected $fillable = ['project_id', 'task_id', 'employee_id', 'developer_name', 'work_date', 'start_time', 'end_time', 'minutes', 'billable_minutes',
        'description', 'status', 'remarks', 'source', 'task_time_entry_id', 'import_key', 'visible_to_client', 'created_by'];

    protected $casts = ['work_date' => 'date', 'visible_to_client' => 'boolean', 'minutes' => 'integer', 'billable_minutes' => 'integer'];

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function task(): BelongsTo { return $this->belongsTo(Task::class); }
    public function employee(): BelongsTo { return $this->belongsTo(AttendanceEmployee::class, 'employee_id'); }
    public function objections(): HasMany { return $this->hasMany(TimesheetObjection::class)->latest('id'); }

    public function developer(): string { return $this->employee?->name ?? $this->developer_name ?? '—'; }
}
