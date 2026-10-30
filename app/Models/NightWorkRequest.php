<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Work done at night, asked for with the tasks worked on and what was done; paid by the hour once approved. */
class NightWorkRequest extends Model
{
    protected $fillable = ['employee_id', 'work_date', 'starts_at', 'ends_at', 'minutes', 'task_ids', 'work_done', 'status', 'decided_by', 'decided_at', 'decision_note'];

    protected $casts = ['work_date' => 'date', 'task_ids' => 'array', 'decided_at' => 'datetime', 'minutes' => 'integer'];

    public function employee(): BelongsTo { return $this->belongsTo(AttendanceEmployee::class, 'employee_id'); }
    public function decider(): BelongsTo { return $this->belongsTo(User::class, 'decided_by'); }

    /** The tasks it was for, in the order chosen. */
    public function tasks()
    {
        $ids = array_map('intval', (array) $this->task_ids);

        return Task::with('project')->whereIn('id', $ids ?: [0])->get()->sortBy(fn ($t) => array_search($t->id, $ids, true))->values();
    }

    /** "10:00 PM – 01:30 AM" */
    public function timeRange(): string
    {
        return \Illuminate\Support\Carbon::parse($this->starts_at)->format('h:i A').' – '.\Illuminate\Support\Carbon::parse($this->ends_at)->format('h:i A');
    }
}
