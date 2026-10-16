<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A day without attendance as written in an imported sheet: off ("Third Saturday", "Weekly Off"), holiday or absent. */
class AttendanceDayMark extends Model
{
    protected $fillable = ['employee_id', 'work_date', 'status', 'label', 'source', 'created_by'];

    protected $casts = ['work_date' => 'date'];

    public function employee() { return $this->belongsTo(AttendanceEmployee::class, 'employee_id'); }
}
