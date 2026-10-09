<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeManagerHistory extends Model
{
    protected $table = 'employee_manager_history';
    protected $fillable = ['employee_id', 'manager_id', 'starts_on', 'ends_on', 'changed_by'];
    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date'];

    public function employee(): BelongsTo { return $this->belongsTo(AttendanceEmployee::class, 'employee_id'); }
    public function manager(): BelongsTo { return $this->belongsTo(AttendanceEmployee::class, 'manager_id'); }
}
