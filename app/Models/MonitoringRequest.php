<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "Please turn on screen capture for this person" — a manager's request, decided by HR in Approvals. */
class MonitoringRequest extends Model
{
    protected $fillable = ['employee_id', 'requested_by', 'reason', 'until', 'status', 'decided_by', 'decided_at', 'decision_note'];
    protected $casts = ['until' => 'date', 'decided_at' => 'datetime'];

    public function employee(): BelongsTo { return $this->belongsTo(AttendanceEmployee::class, 'employee_id'); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
}
