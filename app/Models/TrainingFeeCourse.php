<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A course offered to trainees, with its fee (Training > Course fees). */
class TrainingFeeCourse extends Model
{
    protected $fillable = ['name', 'duration', 'fee', 'description', 'is_active'];
    protected $casts = ['fee' => 'decimal:2', 'is_active' => 'boolean'];

    public function enrollments()
    {
        return $this->hasMany(TrainingEnrollment::class, 'fee_course_id');
    }
}
