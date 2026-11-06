<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceGoalUpdate extends Model
{
    use Concerns\BelongsToEmployeeCompany;

    /** A login limited to one company sees this only when its PerformanceGoal is of that company. */
    public function companyOwner(): array { return ['goal_id', PerformanceGoal::class]; }

    protected $fillable = ['goal_id', 'user_id', 'progress', 'note'];
    protected $casts = ['progress' => 'integer'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
