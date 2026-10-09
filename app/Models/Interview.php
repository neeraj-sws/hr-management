<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Interview extends Model
{
    public const MODES = ['in_person' => 'In person', 'video' => 'Video call', 'phone' => 'Phone'];
    public const RECOMMENDATIONS = ['hire' => 'Hire', 'hold' => 'Hold', 'reject' => 'Reject'];

    protected $fillable = ['candidate_id', 'scheduled_at', 'duration_minutes', 'mode', 'location', 'interviewer_id', 'status', 'rating', 'recommendation', 'feedback'];
    protected $casts = ['scheduled_at' => 'datetime', 'rating' => 'integer', 'duration_minutes' => 'integer'];

    public function candidate(): BelongsTo { return $this->belongsTo(Candidate::class); }
    public function interviewer(): BelongsTo { return $this->belongsTo(User::class, 'interviewer_id'); }
}
