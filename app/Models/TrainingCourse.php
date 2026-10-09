<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingCourse extends Model
{
    protected $fillable = ['title', 'description', 'category', 'provider', 'duration_hours', 'material_url', 'is_mandatory', 'is_active', 'created_by'];
    protected $casts = ['is_mandatory' => 'boolean', 'is_active' => 'boolean', 'duration_hours' => 'decimal:2'];

    public function assignments(): HasMany { return $this->hasMany(TrainingAssignment::class); }
}
