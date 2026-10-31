<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A client's objection to a timesheet entry and the manager's answer. */
class TimesheetObjection extends Model
{
    protected $fillable = ['timesheet_entry_id', 'raised_by', 'reason', 'proposed_minutes', 'status', 'response', 'responded_by', 'responded_at'];

    protected $casts = ['responded_at' => 'datetime', 'proposed_minutes' => 'integer'];

    public function entry(): BelongsTo { return $this->belongsTo(TimesheetEntry::class, 'timesheet_entry_id'); }
    public function raiser(): BelongsTo { return $this->belongsTo(User::class, 'raised_by'); }
    public function responder(): BelongsTo { return $this->belongsTo(User::class, 'responded_by'); }
}
