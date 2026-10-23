<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One fee payment by a trainee, with its receipt number, how it was paid and who received it. */
class TrainingFeePayment extends Model
{
    public const METHODS = ['cash' => 'Cash', 'upi' => 'UPI', 'bank_transfer' => 'Bank transfer', 'card' => 'Card', 'cheque' => 'Cheque', 'other' => 'Other'];

    protected $fillable = ['enrollment_id', 'employee_id', 'receipt_no', 'amount', 'paid_on', 'method', 'reference', 'installment_no', 'note', 'received_by'];
    protected $casts = ['amount' => 'decimal:2', 'paid_on' => 'date'];

    public function enrollment()
    {
        return $this->belongsTo(TrainingEnrollment::class, 'enrollment_id');
    }

    public function employee()
    {
        return $this->belongsTo(AttendanceEmployee::class);
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
