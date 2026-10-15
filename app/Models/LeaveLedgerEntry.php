<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One change to a leave balance. Append-only: entries are never edited or
 * deleted, so the ledger is the audit trail of how a balance came to be.
 */
class LeaveLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    public const TYPES = [
        'opening' => 'Opening allowance',
        'accrual' => 'Monthly accrual',
        'carry_forward' => 'Carried forward',
        'expiry' => 'Carry-forward expired',
        'adjustment' => 'Manual adjustment',
        'used' => 'Leave taken',
        'reversal' => 'Leave reversed',
    ];

    protected $fillable = ['employee_id', 'leave_type_id', 'year', 'entry_type', 'days', 'reason', 'reference_type', 'reference_id', 'created_by'];
    protected $casts = ['days' => 'decimal:3', 'year' => 'integer', 'created_at' => 'datetime'];

    public function employee(): BelongsTo { return $this->belongsTo(AttendanceEmployee::class); }
    public function leaveType(): BelongsTo { return $this->belongsTo(LeaveType::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function reference(): MorphTo { return $this->morphTo(); }

    public static function record(LeaveBalance $balance, string $type, float $days, ?string $reason = null, ?Model $reference = null, ?int $actor = null): ?self
    {
        if (abs($days) < 0.0005) return null;

        return self::create([
            'employee_id' => $balance->employee_id, 'leave_type_id' => $balance->leave_type_id, 'year' => $balance->year,
            'entry_type' => $type, 'days' => round($days, 3), 'reason' => $reason,
            'reference_type' => $reference?->getMorphClass(), 'reference_id' => $reference?->getKey(),
            'created_by' => $actor ?? auth()->id(),
        ]);
    }

    public function label(): string
    {
        return self::TYPES[$this->entry_type] ?? $this->entry_type;
    }
}
