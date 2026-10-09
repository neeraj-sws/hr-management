<?php

namespace App\Services;

use App\Models\AttendanceEmployee;
use App\Models\TrainingAssignment;
use App\Models\TrainingCourse;
use App\Notifications\ApprovalRequested;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Courses assigned to employees, who start and complete them. */
class TrainingService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Assigns a course to employees; anyone who already has it keeps their progress.
     *
     * @param  array<int>  $employeeIds
     */
    public function assign(TrainingCourse $course, array $employeeIds, ?string $dueOn, int $actor): int
    {
        if (! $course->is_active) throw ValidationException::withMessages(['course' => 'This course is inactive.']);
        if ($dueOn && $dueOn < today()->toDateString()) throw ValidationException::withMessages(['dueOn' => 'The due date cannot be in the past.']);

        return DB::transaction(function () use ($course, $employeeIds, $dueOn, $actor) {
            $created = 0;
            foreach (AttendanceEmployee::with('user')->whereIn('id', $employeeIds)->where('is_active', true)->get() as $employee) {
                $assignment = TrainingAssignment::firstOrCreate(
                    ['training_course_id' => $course->id, 'employee_id' => $employee->id],
                    ['assigned_by' => $actor, 'due_on' => $dueOn, 'status' => 'assigned']
                );
                if (! $assignment->wasRecentlyCreated) continue;
                $created++;
                $due = $dueOn ? ' (due '.now()->parse($dueOn)->format('d M').')' : '';
                $employee->user?->notify(new ApprovalRequested('TrainingAssignment', $assignment->id, "New training assigned: {$course->title}{$due}."));
            }
            $this->audit->record('training.assigned', $course, ['employees' => $created, 'due_on' => $dueOn]);

            return $created;
        });
    }

    public function start(TrainingAssignment $assignment, AttendanceEmployee $employee): TrainingAssignment
    {
        if ($assignment->employee_id !== $employee->id) abort(403);
        if ($assignment->status !== 'assigned') throw ValidationException::withMessages(['training' => 'This training has already been started.']);
        $assignment->update(['status' => 'in_progress', 'started_at' => now()]);
        $this->audit->record('training.started', $assignment);

        return $assignment;
    }

    public function complete(TrainingAssignment $assignment, AttendanceEmployee $employee, ?string $note): TrainingAssignment
    {
        if ($assignment->employee_id !== $employee->id) abort(403);
        if ($assignment->status === 'completed') throw ValidationException::withMessages(['training' => 'This training is already completed.']);
        $assignment->update(['status' => 'completed', 'started_at' => $assignment->started_at ?? now(), 'completed_at' => now(), 'completion_note' => $note ?: null]);
        $this->audit->record('training.completed', $assignment);
        $assignment->assigner?->notify(new ApprovalRequested('TrainingAssignment', $assignment->id, "{$employee->name} completed the training \"{$assignment->course?->title}\"."));

        return $assignment;
    }
}
