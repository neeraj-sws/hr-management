<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamPolicyOverride extends Model
{
    protected $fillable = ['team_id', 'rule_key', 'configuration', 'effective_from', 'effective_until', 'configured_by'];
    protected $casts = ['configuration' => 'array', 'effective_from' => 'date', 'effective_until' => 'date'];

    public function team(): BelongsTo { return $this->belongsTo(Team::class); }
}
