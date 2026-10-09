<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceReview extends Model
{
    protected $fillable = ['employee_id', 'reviewer_id', 'period_starts_on', 'period_ends_on', 'rating', 'strengths', 'improvements', 'metrics', 'status', 'employee_comment', 'acknowledged_at'];
    protected $casts = ['period_starts_on' => 'date', 'period_ends_on' => 'date', 'metrics' => 'array', 'rating' => 'integer', 'acknowledged_at' => 'datetime'];

    public function employee(): BelongsTo { return $this->belongsTo(AttendanceEmployee::class); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewer_id'); }
}
