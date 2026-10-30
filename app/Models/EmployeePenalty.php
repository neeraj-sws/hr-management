<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class EmployeePenalty extends Model
{
    protected $fillable = ['employee_id','policy_rule_id','effective_on','amount','status','reason','requested_by','decided_by','decided_at','decision_note','payroll_item_id','source_key'];
    protected $casts = ['effective_on'=>'date','amount'=>'decimal:2','decided_at'=>'datetime'];
    public function employee(){return $this->belongsTo(AttendanceEmployee::class);}
    public function policyRule(){return $this->belongsTo(PolicyRule::class);}
    public function payrollItem(){return $this->belongsTo(PayrollItem::class);}
    public function decider(){return $this->belongsTo(User::class, 'decided_by');}
    /** The employee's reason for a fine (one per fine). */
    public function appeal(){return $this->hasOne(PenaltyAppeal::class, 'employee_penalty_id');}
    /** A late-arrival fine: the employee can give a reason for it. */
    public function isLateFine(): bool{return str_starts_with((string) $this->source_key, 'late:');}
    /** Start of the note on a fine taken back after its salary was paid: it stays in that payroll and is paid back. */
    public const PAID_BACK = 'Paid back in the next salary';
    public function isPaidBack(): bool{return str_starts_with((string) $this->decision_note, self::PAID_BACK);}
    /** HR / admin can still take it back: not removed yet and not already paid back. */
    public function canBeRemoved(): bool{return in_array($this->status, ['pending', 'approved'], true) && ! $this->isPaidBack();}
    /** The employee can still give a reason: any fine (late, absence, daily status, task …) not removed and with no reason yet. */
    public function canAppeal(): bool{return in_array($this->status, ['pending', 'approved'], true) && ! $this->isPaidBack() && ! $this->appeal;}
}
