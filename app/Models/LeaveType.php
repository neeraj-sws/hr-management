<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    protected $fillable = ['name', 'annual_allowance', 'carry_forward', 'carry_forward_limit', 'carry_forward_expiry_months', 'paid', 'is_active', 'monthly_accrual', 'allow_advance'];
    protected $casts = ['annual_allowance' => 'decimal:2', 'carry_forward_limit' => 'decimal:2', 'monthly_accrual' => 'decimal:3', 'carry_forward' => 'boolean', 'paid' => 'boolean', 'is_active' => 'boolean', 'allow_advance' => 'boolean'];
    public function requests(): HasMany { return $this->hasMany(LeaveRequest::class); }

    /**
     * Used = someone applied for it, or a person's balance was changed by hand / by a leave taken. A type only
     * granted automatically (opening, monthly accrual, expiry) and never used can be deleted; a used one is
     * made inactive instead, so leave history, salary and reports stay correct.
     */
    public function isUsed(): bool
    {
        return LeaveRequest::where('leave_type_id', $this->id)->exists()
            || LeaveLedgerEntry::where('leave_type_id', $this->id)->whereIn('entry_type', ['used', 'adjustment', 'reversal', 'carry_forward'])->exists();
    }

    /** Plain-language rules of this leave type, shown wherever leave is requested or configured. */
    public function describe(): string
    {
        $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 2), '0'), '.');
        $parts = [$this->paid ? 'Paid' : 'Unpaid (salary is deducted)'];
        if ($this->paid) {
            $parts[] = (float) $this->monthly_accrual > 0 ? $fmt($this->monthly_accrual).' day(s) earned each month' : $fmt($this->annual_allowance).' day(s) per year';
            if ($this->carry_forward) $parts[] = 'up to '.$fmt($this->carry_forward_limit).' unused day(s) carry forward'.($this->carry_forward_expiry_months ? ' for '.$this->carry_forward_expiry_months.' month(s)' : '');
            $parts[] = $this->allow_advance ? 'can go into advance (negative) balance within the company limit' : 'only up to the available balance';
        }

        return implode(' · ', $parts);
    }
}
