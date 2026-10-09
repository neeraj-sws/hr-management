<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payslip extends Model
{
    protected $fillable = ['payroll_item_id', 'employee_id', 'issued_at', 'reference'];
    protected $casts = ['issued_at' => 'datetime'];
    public function item(): BelongsTo { return $this->belongsTo(PayrollItem::class, 'payroll_item_id'); }
    public function employee(): BelongsTo { return $this->belongsTo(AttendanceEmployee::class); }
}
