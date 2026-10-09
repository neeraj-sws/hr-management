<?php

namespace App\Services;

use App\Models\AttendanceEmployee;
use App\Models\Candidate;
use App\Models\Interview;
use App\Models\User;
use App\Notifications\ApprovalRequested;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Hiring pipeline: candidates move through stages, interviewers give feedback, and a hire becomes an employee. */
class RecruitmentService
{
    public const RESUME_RULES = ['nullable', 'file', 'max:5120', 'mimes:pdf,doc,docx'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EmployeeService $employees,
        private readonly PeopleOperationsService $people,
    ) {}

    public function addCandidate(array $data, ?UploadedFile $resume, int $actor): Candidate
    {
        $path = $resume?->store('resumes', 'local');
        try {
            $candidate = Candidate::create([...$data, 'stage' => 'applied', 'resume_path' => $path, 'resume_name' => $resume?->getClientOriginalName(), 'created_by' => $actor]);
        } catch (\Throwable $error) {
            if ($path) Storage::disk('local')->delete($path);
            throw $error;
        }
        $this->audit->record('candidate.added', $candidate, ['job_opening_id' => $candidate->job_opening_id]);

        return $candidate;
    }

    public function moveStage(Candidate $candidate, string $stage): Candidate
    {
        if (! array_key_exists($stage, Candidate::STAGES) || $stage === 'hired') throw ValidationException::withMessages(['stage' => 'Use "Hire" to hire a candidate.']);
        if ($candidate->stage === 'hired') throw ValidationException::withMessages(['stage' => 'This candidate has already been hired.']);
        $from = $candidate->stage;
        $candidate->update(['stage' => $stage]);
        $this->audit->record('candidate.stage_changed', $candidate, ['from' => $from, 'to' => $stage]);

        return $candidate;
    }

    public function scheduleInterview(Candidate $candidate, array $data): Interview
    {
        if (in_array($candidate->stage, ['hired', 'rejected'], true)) throw ValidationException::withMessages(['candidate' => 'This candidate is no longer in the pipeline.']);
        if (now()->parse($data['scheduled_at'])->lt(now()->subMinutes(5))) throw ValidationException::withMessages(['scheduledAt' => 'Choose a time in the future.']);

        return DB::transaction(function () use ($candidate, $data) {
            $interview = $candidate->interviews()->create([...$data, 'status' => 'scheduled']);
            if (in_array($candidate->stage, ['applied', 'screening'], true)) $candidate->update(['stage' => 'interview']);
            $when = $interview->scheduled_at->format('d M, h:i A');
            User::find($interview->interviewer_id)?->notify(new ApprovalRequested('Interview', $interview->id, "You have an interview with {$candidate->name} ({$candidate->jobOpening?->title}) on {$when}."));
            $this->audit->record('interview.scheduled', $interview, ['candidate_id' => $candidate->id]);

            return $interview;
        });
    }

    public function submitFeedback(Interview $interview, User $user, array $data): Interview
    {
        if ($interview->interviewer_id !== $user->id && ! $user->can('recruitment.manage')) abort(403);
        if ($interview->status === 'cancelled') throw ValidationException::withMessages(['interview' => 'This interview was cancelled.']);
        $interview->update([...$data, 'status' => 'completed']);
        $this->audit->record('interview.feedback_submitted', $interview, ['rating' => $interview->rating, 'recommendation' => $interview->recommendation]);

        return $interview;
    }

    public function cancelInterview(Interview $interview): Interview
    {
        if ($interview->status !== 'scheduled') throw ValidationException::withMessages(['interview' => 'Only scheduled interviews can be cancelled.']);
        $interview->update(['status' => 'cancelled']);
        $this->audit->record('interview.cancelled', $interview);

        return $interview;
    }

    /**
     * Creates the employee + login (same rules as People > Employees), marks the
     * candidate hired and opens an onboarding case.
     */
    public function hire(Candidate $candidate, array $employeeData, User $actor): AttendanceEmployee
    {
        if ($candidate->stage === 'hired') throw ValidationException::withMessages(['candidate' => 'This candidate has already been hired.']);
        if ($candidate->stage === 'rejected') throw ValidationException::withMessages(['candidate' => 'Move the candidate back into the pipeline before hiring.']);

        return DB::transaction(function () use ($candidate, $employeeData, $actor) {
            $employee = $this->employees->create([...$employeeData, 'name' => $candidate->name, 'email' => $candidate->email, 'phone' => $candidate->phone], $actor);
            $candidate->update(['stage' => 'hired', 'hired_employee_id' => $employee->id]);
            $this->people->openLifecycle($employee, 'onboarding', $employeeData['joined_at'] ?? null, "Hired for {$candidate->jobOpening?->title}", $actor->id);
            $this->audit->record('candidate.hired', $candidate, ['employee_id' => $employee->id]);

            return $employee;
        });
    }
}
