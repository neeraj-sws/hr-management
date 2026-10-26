<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingCourse extends Model
{
    protected $fillable = ['title', 'description', 'category', 'provider', 'duration_hours', 'fee', 'one_time_discount', 'duration_label', 'material_url', 'is_mandatory', 'is_active', 'created_by'];
    protected $casts = ['is_mandatory' => 'boolean', 'is_active' => 'boolean', 'duration_hours' => 'decimal:2', 'fee' => 'decimal:2', 'one_time_discount' => 'decimal:2'];

    /** Trainees enrolled in this course (Course fees). */
    public function enrollments(): HasMany { return $this->hasMany(TrainingEnrollment::class, 'training_course_id'); }

    public function assignments(): HasMany { return $this->hasMany(TrainingAssignment::class); }
}
