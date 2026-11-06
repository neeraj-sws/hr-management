<?php

namespace App\Services;

use App\Models\TrainingCourse;
use App\Models\TrainingEnrollment;
use App\Models\TrainingEnrollmentTopic;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Courses taught topic by topic, each by a mentor: the topics of a course with the mentors who can teach them,
 * each trainee's topics with the mentor chosen for them, and moving the trainee on when a topic is done
 * (the next mentor and the trainee are told).
 */
class TrainingMentorService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Saves a course's topics in the order given; topics left out are removed (trainees keep their copy).
     *
     * @param  list<array{id?: int|string|null, title: string, mentor_ids?: array}>  $rows
     */
    public function saveCourseTopics(TrainingCourse $course, array $rows): void
    {
        DB::transaction(function () use ($course, $rows) {
            $keep = [];
            foreach (array_values($rows) as $i => $row) {
                $title = trim((string) ($row['title'] ?? ''));
                if ($title === '') continue;
                $topic = ! empty($row['id']) ? $course->topics()->whereKey($row['id'])->first() : null;
                $topic ??= $course->topics()->make();
                $topic->fill(['title' => $title, 'sort' => $i + 1])->save();
                $topic->mentors()->sync(array_values(array_unique(array_map('intval', array_filter((array) ($row['mentor_ids'] ?? []))))));
                $keep[] = $topic->id;
            }
            $course->topics()->whereNotIn('id', $keep ?: [0])->delete();
        });
    }

    /**
     * Saves a trainee's topics (title, mentor, status) in the order given, then starts the first topic not done
     * when none is going on. The mentor of the topic going on is told about a new trainee.
     *
     * @param  list<array{id?: int|string|null, course_topic_id?: int|string|null, title: string, mentor_id?: int|string|null, status?: string}>  $rows
     */
    public function saveEnrollmentTopics(TrainingEnrollment $enrollment, array $rows, ?User $actor = null): void
    {
        $before = $enrollment->topics()->get()->keyBy('id');
        DB::transaction(function () use ($enrollment, $rows) {
            $keep = [];
            foreach (array_values($rows) as $i => $row) {
                $title = trim((string) ($row['title'] ?? ''));
                if ($title === '') continue;
                $topic = ! empty($row['id']) ? $enrollment->topics()->whereKey($row['id'])->first() : null;
                $topic ??= $enrollment->topics()->make();
                $status = isset(TrainingEnrollmentTopic::STATUSES[$row['status'] ?? '']) ? $row['status'] : ($topic->status ?? 'pending');
                $topic->fill([
                    'course_topic_id' => ($row['course_topic_id'] ?? null) ?: null, 'title' => $title, 'sort' => $i + 1,
                    'mentor_id' => ($row['mentor_id'] ?? null) ?: null, 'status' => $status,
                    'started_on' => $status === 'pending' ? null : ($topic->started_on ?? today()),
                    'completed_on' => $status === 'completed' ? ($topic->completed_on ?? today()) : null,
                ])->save();
                $keep[] = $topic->id;
            }
            $enrollment->topics()->whereNotIn('id', $keep ?: [0])->delete();
        });
        $this->startNextTopic($enrollment);

        // A mentor who now has this trainee on the topic going on (new, or changed) is told.
        foreach ($enrollment->topics()->with('mentor.user')->where('status', 'active')->whereNotNull('mentor_id')->get() as $topic) {
            $old = $before->get($topic->id);
            if ($old && $old->status === 'active' && (int) $old->mentor_id === (int) $topic->mentor_id) continue;
            $this->tellMentor($topic, $enrollment, $actor?->id);
        }
    }

    /** Builds a trainee's topics from the course: each topic with its only mentor, or none when several can teach it. */
    public static function topicsFromCourse(?TrainingCourse $course): array
    {
        if (! $course) return [];

        return $course->topics()->with('mentors:id,name')->get()->map(fn ($t) => [
            'id' => null, 'course_topic_id' => $t->id, 'title' => $t->title,
            'mentor_id' => $t->mentors->count() === 1 ? (string) $t->mentors->first()->id : '', 'status' => 'pending',
        ])->all();
    }

    /** The topic is done: the trainee moves on to the next topic (its mentor and the trainee are told). */
    public function completeTopic(TrainingEnrollmentTopic $topic, User $actor): ?TrainingEnrollmentTopic
    {
        $own = $actor->employeeProfile;
        abort_unless($actor->can('training.manage') || ($own && (int) $topic->mentor_id === (int) $own->id), 403, 'Only the mentor of this topic can mark it done.');
        if ($topic->status === 'completed') throw ValidationException::withMessages(['topic' => 'This topic is already done.']);

        $topic->update(['status' => 'completed', 'started_on' => $topic->started_on ?? today(), 'completed_on' => today(), 'completed_by' => $actor->id]);
        $enrollment = $topic->enrollment()->with('employee.user')->firstOrFail();
        $this->audit->record('training.topic_completed', $enrollment, ['topic' => $topic->title, 'trainee' => $enrollment->employee?->name, 'by' => $actor->name]);

        $next = $this->startNextTopic($enrollment);
        $trainee = $enrollment->employee;
        if ($next) {
            $this->tellMentor($next, $enrollment, $actor->id);
            Notifier::send($trainee?->user, 'TrainingTopic', $enrollment->id, "{$topic->title} is done. Next in {$enrollment->course_name}: {$next->title}".($next->mentor ? " with {$next->mentor->name}" : '').'.', route('training.my-fees', [], false), $actor->id);
        } elseif (! $enrollment->topics()->where('status', '!=', 'completed')->exists()) {
            Notifier::send($trainee?->user, 'TrainingTopic', $enrollment->id, "{$topic->title} is done. You have finished every topic of {$enrollment->course_name}. Well done!", route('training.my-fees', [], false), $actor->id);
            Notifier::send(Notifier::withPermission('training.manage'), 'TrainingTopic', $enrollment->id, ($trainee?->name ?? 'A trainee')." has finished every topic of {$enrollment->course_name}.", route('training.fees', [], false), $actor->id);
        }

        return $next;
    }

    /** Starts the first topic not done when none is going on (only while the trainee is studying). */
    private function startNextTopic(TrainingEnrollment $enrollment): ?TrainingEnrollmentTopic
    {
        if ($enrollment->status !== 'active') return null;
        $topics = $enrollment->topics()->with('mentor')->get();
        if ($topics->contains('status', 'active')) return null;
        $next = $topics->firstWhere('status', 'pending');
        $next?->update(['status' => 'active', 'started_on' => today()]);

        return $next;
    }

    private function tellMentor(TrainingEnrollmentTopic $topic, TrainingEnrollment $enrollment, ?int $exceptUserId): void
    {
        $topic->loadMissing('mentor.user');
        $trainee = $enrollment->employee()->first();
        Notifier::send($topic->mentor?->user, 'TrainingMentor', $topic->id, 'New trainee for you: '.($trainee?->name ?? 'a trainee')." for {$topic->title} ({$enrollment->course_name}).", route('training.mentoring', [], false), $exceptUserId);
    }
}
