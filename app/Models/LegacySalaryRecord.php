<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One employee-month from the old Excel salary sheet, kept exactly as written (never recalculated). */
class LegacySalaryRecord extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['raw' => 'array', 'payment_date' => 'date'];

    public function employee(): BelongsTo { return $this->belongsTo(AttendanceEmployee::class, 'employee_id'); }

    public function importer(): BelongsTo { return $this->belongsTo(User::class, 'imported_by'); }
}
