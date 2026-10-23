<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceGoalUpdate extends Model
{
    protected $fillable = ['goal_id', 'user_id', 'progress', 'note'];
    protected $casts = ['progress' => 'integer'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
