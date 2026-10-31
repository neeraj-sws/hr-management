<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A project's month on the timesheet: minutes carried in ("old remaining"), the client's approval and its invoice. */
class TimesheetMonth extends Model
{
    protected $fillable = ['project_id', 'month', 'opening_remaining_minutes', 'approved_at', 'approved_by', 'invoice_id'];

    protected $casts = ['approved_at' => 'datetime', 'opening_remaining_minutes' => 'integer'];

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function invoice(): BelongsTo { return $this->belongsTo(BillingInvoice::class, 'invoice_id'); }
}
