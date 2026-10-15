<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveBalance extends Model
{
    public $timestamps = false;
    protected $fillable = ['employee_id', 'leave_type_id', 'year', 'entitled_days', 'used_days', 'carried_days', 'carried_expires_on', 'accrued_days', 'adjusted_days', 'last_accrual_month'];
    protected $casts = ['entitled_days' => 'decimal:2', 'used_days' => 'decimal:2', 'carried_days' => 'decimal:2', 'carried_expires_on' => 'date', 'accrued_days' => 'decimal:3', 'adjusted_days' => 'decimal:3', 'last_accrual_month' => 'integer'];
    public function employee(): BelongsTo { return $this->belongsTo(AttendanceEmployee::class); }
    public function leaveType(): BelongsTo { return $this->belongsTo(LeaveType::class); }

    /**
     * The balance row for an employee, leave type and leave year, created on first
     * use with the type's up-front allowance (recorded in the ledger as the opening entry).
     */
    public static function open(int $employeeId, LeaveType $type, int $year): self
    {
        $balance = self::firstOrCreate(
            ['employee_id' => $employeeId, 'leave_type_id' => $type->id, 'year' => $year],
            ['entitled_days' => (float) $type->monthly_accrual > 0 ? 0 : $type->annual_allowance]
        );
        if ($balance->wasRecentlyCreated) LeaveLedgerEntry::record($balance, 'opening', (float) $balance->entitled_days, $type->name.' allowance for '.\App\Support\LeaveYear::label($year));

        return $balance;
    }
}
