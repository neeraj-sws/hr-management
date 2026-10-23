<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 360 feedback: one colleague's view (peer, junior, another manager) for a review. */
class PerformanceFeedback extends Model
{
    public const RELATIONSHIPS = ['peer' => 'Peer', 'report' => 'Reports to them', 'manager' => 'Another manager', 'other' => 'Other'];

    protected $table = 'performance_feedback';
    protected $fillable = ['review_id', 'giver_id', 'relationship', 'rating', 'strengths', 'improvements', 'status', 'submitted_at'];
    protected $casts = ['rating' => 'integer', 'submitted_at' => 'datetime'];

    public function review(): BelongsTo { return $this->belongsTo(PerformanceReview::class, 'review_id'); }
    public function giver(): BelongsTo { return $this->belongsTo(User::class, 'giver_id'); }
}
