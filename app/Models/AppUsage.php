<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppUsage extends Model
{
    protected $table = 'app_usage_daily';
    protected $fillable = ['employee_id', 'work_date', 'app_name', 'seconds'];
    protected $casts = ['work_date' => 'date', 'seconds' => 'integer'];

    public function employee(): BelongsTo { return $this->belongsTo(AttendanceEmployee::class, 'employee_id'); }
}
