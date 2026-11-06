<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** One topic of a course (e.g. JavaScript), taught by one of its mentors (e.g. Fahad or Khushboo). */
class TrainingCourseTopic extends Model
{
    protected $fillable = ['training_course_id', 'title', 'sort'];

    public function course(): BelongsTo { return $this->belongsTo(TrainingCourse::class, 'training_course_id'); }

    /** The employees who can teach this topic. */
    public function mentors(): BelongsToMany
    {
        return $this->belongsToMany(AttendanceEmployee::class, 'training_course_topic_mentors', 'topic_id', 'employee_id')->orderBy('name');
    }
}
