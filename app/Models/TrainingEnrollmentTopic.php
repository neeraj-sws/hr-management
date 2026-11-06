<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A trainee's topic in their course: who mentors them in it and how far they got (not started, going on, done). */
class TrainingEnrollmentTopic extends Model
{
    public const STATUSES = ['pending' => ['Not started', 'gray'], 'active' => ['Going on', 'blue'], 'completed' => ['Done', 'green']];

    protected $fillable = ['enrollment_id', 'course_topic_id', 'title', 'sort', 'mentor_id', 'status', 'started_on', 'completed_on', 'completed_by'];
    protected $casts = ['started_on' => 'date', 'completed_on' => 'date'];

    public function enrollment(): BelongsTo { return $this->belongsTo(TrainingEnrollment::class, 'enrollment_id'); }

    public function mentor(): BelongsTo { return $this->belongsTo(AttendanceEmployee::class, 'mentor_id'); }

    public function courseTopic(): BelongsTo { return $this->belongsTo(TrainingCourseTopic::class, 'course_topic_id'); }
}
