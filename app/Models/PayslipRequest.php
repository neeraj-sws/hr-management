<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** An employee's request for a month's payslip; sent as a system payslip, from the old salary sheet or as a file. */
class PayslipRequest extends Model
{
    protected $fillable = ['employee_id', 'month', 'note', 'status', 'payslip_id', 'legacy_record_id', 'file_path', 'requested_by', 'decided_by', 'decided_at', 'decision_note'];

    protected $casts = ['decided_at' => 'datetime'];

    public function employee(): BelongsTo { return $this->belongsTo(AttendanceEmployee::class); }
    public function payslip(): BelongsTo { return $this->belongsTo(Payslip::class); }
    public function legacyRecord(): BelongsTo { return $this->belongsTo(LegacySalaryRecord::class, 'legacy_record_id'); }
    public function decider(): BelongsTo { return $this->belongsTo(User::class, 'decided_by'); }

    public function monthLabel(): string
    {
        return Carbon::parse($this->month.'-01')->format('F Y');
    }

    public function hasDocument(): bool
    {
        return $this->status === 'sent' && ($this->payslip_id || $this->legacy_record_id || $this->file_path);
    }
}
