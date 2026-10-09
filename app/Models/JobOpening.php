<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobOpening extends Model
{
    public const TYPES = ['full_time' => 'Full time', 'part_time' => 'Part time', 'contract' => 'Contract', 'intern' => 'Internship'];
    public const STATUSES = ['open' => 'Open', 'on_hold' => 'On hold', 'closed' => 'Closed'];

    protected $fillable = ['title', 'department_id', 'location', 'employment_type', 'openings', 'description', 'status', 'hiring_manager_id', 'closes_on', 'created_by'];
    protected $casts = ['closes_on' => 'date', 'openings' => 'integer'];

    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function hiringManager(): BelongsTo { return $this->belongsTo(User::class, 'hiring_manager_id'); }
    public function candidates(): HasMany { return $this->hasMany(Candidate::class); }
}
