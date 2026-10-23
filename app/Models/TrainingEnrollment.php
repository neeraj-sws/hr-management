<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A trainee enrolled in a course: the fee they owe, in how many installments, and what they have paid. */
class TrainingEnrollment extends Model
{
    public const STATUSES = ['active' => 'Studying', 'completed' => 'Completed', 'dropped' => 'Left the course'];

    protected $fillable = ['employee_id', 'fee_course_id', 'course_name', 'total_fee', 'discount', 'installments', 'starts_on', 'ends_on', 'status', 'notes', 'created_by'];
    protected $casts = ['total_fee' => 'decimal:2', 'discount' => 'decimal:2', 'starts_on' => 'date', 'ends_on' => 'date'];

    public function employee()
    {
        return $this->belongsTo(AttendanceEmployee::class);
    }

    public function course()
    {
        return $this->belongsTo(TrainingFeeCourse::class, 'fee_course_id');
    }

    public function payments()
    {
        return $this->hasMany(TrainingFeePayment::class, 'enrollment_id')->orderBy('paid_on')->orderBy('id');
    }

    /** What the trainee has to pay: the fee less the discount. */
    public function payable(): float
    {
        return round(max(0, (float) $this->total_fee - (float) $this->discount), 2);
    }

    public function paid(): float
    {
        return round((float) ($this->relationLoaded('payments') ? $this->payments->sum('amount') : $this->payments()->sum('amount')), 2);
    }

    public function balance(): float
    {
        return round(max(0, $this->payable() - $this->paid()), 2);
    }

    /** The next installment: what is left spread over the installments not yet paid. */
    public function nextInstallment(): float
    {
        $count = $this->relationLoaded('payments') ? $this->payments->count() : $this->payments()->count();
        $left = max(1, (int) $this->installments - $count);

        return round($this->balance() / $left, 2);
    }
}
