<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceReview extends Model
{
    /** self_review -> manager_review -> submitted (shared with the employee) -> acknowledged. */
    public const STATUSES = ['self_review' => 'Waiting for self-review', 'manager_review' => 'Waiting for manager', 'submitted' => 'Shared', 'acknowledged' => 'Acknowledged'];

    /** 360 comments are shown to the employee only when at least this many people answered, so no one is identifiable. */
    public const ANONYMOUS_MINIMUM = 2;

    protected $fillable = ['employee_id', 'reviewer_id', 'period_starts_on', 'period_ends_on', 'rating', 'self_rating', 'self_assessment', 'self_submitted_at', 'strengths', 'improvements', 'metrics', 'goal_scores', 'completed_at', 'status', 'employee_comment', 'acknowledged_at'];
    protected $casts = ['period_starts_on' => 'date', 'period_ends_on' => 'date', 'metrics' => 'array', 'goal_scores' => 'array', 'rating' => 'integer', 'self_rating' => 'integer', 'self_submitted_at' => 'datetime', 'completed_at' => 'datetime', 'acknowledged_at' => 'datetime'];

    public function employee(): BelongsTo { return $this->belongsTo(AttendanceEmployee::class); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewer_id'); }
    public function feedback(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(PerformanceFeedback::class, 'review_id'); }

    public function isShared(): bool
    {
        return in_array($this->status, ['submitted', 'acknowledged'], true);
    }

    /** The goals the review covers: the employee's goals overlapping the review period. */
    public function goals()
    {
        return PerformanceGoal::where('employee_id', $this->employee_id)->forPeriod($this->period_starts_on->toDateString(), $this->period_ends_on->toDateString())->orderByDesc('weight')->orderBy('id')->get();
    }

    /** Weighted goal score out of 5 from the manager's per-goal scores (goals without a weight count equally). */
    public function goalScore(): ?float
    {
        $scores = collect((array) $this->goal_scores)->filter(fn ($s) => is_numeric($s) && $s >= 1);
        if ($scores->isEmpty()) return null;
        $goals = PerformanceGoal::whereIn('id', $scores->keys())->get()->keyBy('id');
        $useWeights = $goals->sum('weight') > 0;
        $total = $weight = 0;
        foreach ($scores as $id => $score) {
            $w = $useWeights ? (int) ($goals[$id]->weight ?? 0) : 1;
            $total += $w * (float) $score;
            $weight += $w;
        }

        return $weight ? round($total / $weight, 1) : null;
    }
}
