<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyWorkStatus extends Model
{
    protected $fillable = ['employee_id', 'work_date', 'summary', 'submitted_by', 'server_version', 'yesterday', 'plan', 'blockers', 'notes', 'submitted_at', 'project_updates', 'pending', 'tomorrow_plan'];
    /** project_updates: [{project_id, project, update, tasks: [{title, status}], seconds}] — one line per project worked on. */
    protected $casts = ['work_date' => 'date', 'server_version' => 'integer', 'submitted_at' => 'datetime', 'project_updates' => 'array'];

    public function employee(): BelongsTo { return $this->belongsTo(AttendanceEmployee::class, 'employee_id'); }
    public function submitter(): BelongsTo { return $this->belongsTo(User::class, 'submitted_by'); }
}
