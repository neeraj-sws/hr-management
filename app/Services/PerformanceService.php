<?php

namespace App\Services;

use App\Models\AttendanceEmployee;
use App\Models\PerformanceFeedback;
use App\Models\PerformanceGoal;
use App\Models\PerformanceGoalUpdate;
use App\Models\PerformanceReview;
use App\Models\User;
use App\Notifications\ApprovalRequested;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Goals and the review cycle, the way most HR systems run it:
 *  1. goals (KRA / OKR) with a weight and a target; the employee updates progress;
 *  2. the manager starts a review: the employee writes a self-review and chosen
 *     colleagues give 360 feedback (rating + strengths + what to improve);
 *  3. the manager scores each goal and gives the final rating, then shares it;
 *  4. the employee acknowledges, with a comment if they want.
 */
class PerformanceService
{
    public function __construct(private readonly AuditLogger $audit, private readonly WorkService $work) {}

    /** Employees this person can set goals for and review: everyone for HR, else their direct reports. */
    public function manageableIds(User $user): Collection
    {
        if ($user->canAny(['hr.manage', 'employees.manage'])) return AttendanceEmployee::where('is_active', true)->pluck('id');
        if (! $user->canAny(['performance.review'])) return collect();

        return $user->employeeProfile?->directReports()->where('is_active', true)->pluck('id') ?? collect();
    }

    public function canManage(User $user, int $employeeId): bool
    {
        $own = $user->employeeProfile;

        return (! $own || $own->id !== $employeeId) && $this->manageableIds($user)->contains($employeeId);
    }

    // Goals ----------------------------------------------------------------------------------------

    /** A manager's goal is active at once; a goal the employee proposes waits for the manager. */
    public function saveGoal(User $actor, array $data, ?PerformanceGoal $goal = null): PerformanceGoal
    {
        $employeeId = (int) $data['employee_id'];
        $manager = $this->canManage($actor, $employeeId);
        $own = $actor->employeeProfile?->id === $employeeId;
        if (! $manager && ! $own) abort(403);
        if ($goal && ! $manager && ! ($own && $goal->status === 'proposed')) abort(403, 'Only your manager can change a goal after it is approved.');

        $values = [
            'employee_id' => $employeeId, 'kind' => $data['kind'], 'title' => $data['title'], 'description' => $data['description'] ?: null,
            'target' => $data['target'] ?: null, 'weight' => (int) $data['weight'], 'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'],
        ];
        if ($goal) {
            $goal->update($values);
            $this->audit->record('performance.goal_updated', $goal);

            return $goal;
        }
        $goal = PerformanceGoal::create($values + ['status' => $manager ? 'active' : 'proposed', 'created_by' => $actor->id, 'approved_by' => $manager ? $actor->id : null]);
        $this->audit->record($manager ? 'performance.goal_set' : 'performance.goal_proposed', $goal);
        if ($manager) {
            $goal->employee?->user?->notify(new ApprovalRequested('PerformanceGoal', $goal->id, "New goal for you: {$goal->title}", route('performance.goals', [], false)));
        } else {
            $goal->employee?->manager?->user?->notify(new ApprovalRequested('PerformanceGoal', $goal->id, "{$goal->employee->name} proposed a goal: {$goal->title}. Approve or change it.", route('performance.goals', ['tab' => 'team'], false)));
        }

        return $goal;
    }

    public function approveGoal(User $actor, PerformanceGoal $goal): void
    {
        abort_unless($this->canManage($actor, $goal->employee_id), 403);
        if ($goal->status !== 'proposed') throw ValidationException::withMessages(['goal' => 'Only a proposed goal can be approved.']);
        $goal->update(['status' => 'active', 'approved_by' => $actor->id]);
        $this->audit->record('performance.goal_approved', $goal);
        $goal->employee?->user?->notify(new ApprovalRequested('PerformanceGoal', $goal->id, "Your goal was approved: {$goal->title}", route('performance.goals', [], false)));
    }

    public function setGoalStatus(User $actor, PerformanceGoal $goal, string $status): void
    {
        abort_unless($this->canManage($actor, $goal->employee_id), 403);
        abort_unless(in_array($status, ['active', 'completed', 'cancelled'], true), 422);
        $goal->update(['status' => $status] + ($status === 'completed' ? ['progress' => 100] : []));
        $this->audit->record('performance.goal_'.$status, $goal);
    }

    /** Progress 0-100 with a note; the employee or their manager. */
    public function updateProgress(User $actor, PerformanceGoal $goal, int $progress, ?string $note): PerformanceGoalUpdate
    {
        $own = $actor->employeeProfile?->id === $goal->employee_id;
        abort_unless($own || $this->canManage($actor, $goal->employee_id), 403);
        if ($goal->status !== 'active') throw ValidationException::withMessages(['progress' => 'Progress can be updated only on an active goal.']);
        $progress = max(0, min(100, $progress));

        return DB::transaction(function () use ($actor, $goal, $progress, $note) {
            $goal->update(['progress' => $progress]);
            $update = PerformanceGoalUpdate::create(['goal_id' => $goal->id, 'user_id' => $actor->id, 'progress' => $progress, 'note' => $note ?: null]);
            $this->audit->record('performance.goal_progress', $goal, ['progress' => $progress]);

            return $update;
        });
    }

    /** Total weight of an employee's goals in a period: should be 100 when weights are used. */
    public function weightFor(int $employeeId, string $from, string $to): int
    {
        return (int) PerformanceGoal::where('employee_id', $employeeId)->forPeriod($from, $to)->where('status', '!=', 'proposed')->sum('weight');
    }

    // Review cycle ---------------------------------------------------------------------------------

    /** Starts a review: the employee is asked for a self-review and the chosen colleagues for feedback. */
    public function startReview(User $manager, AttendanceEmployee $employee, string $from, string $to, array $feedbackFrom = [], bool $askSelfReview = true): PerformanceReview
    {
        abort_unless($this->canManage($manager, $employee->id), 403);
        $givers = User::whereIn('id', $feedbackFrom)->where('is_active', true)->where('id', '!=', $employee->user_id)->where('id', '!=', $manager->id)->get();

        return DB::transaction(function () use ($manager, $employee, $from, $to, $givers, $askSelfReview) {
            $review = PerformanceReview::create(['employee_id' => $employee->id, 'reviewer_id' => $manager->id, 'period_starts_on' => $from, 'period_ends_on' => $to, 'status' => $askSelfReview ? 'self_review' : 'manager_review']);
            foreach ($givers as $giver) {
                PerformanceFeedback::create(['review_id' => $review->id, 'giver_id' => $giver->id, 'relationship' => $this->relationship($giver, $employee)]);
                $giver->notify(new ApprovalRequested('PerformanceFeedback', $review->id, "{$manager->name} asked for your feedback on {$employee->name} ({$review->period_starts_on->format('d M')} – {$review->period_ends_on->format('d M Y')}).", route('performance.reviews', ['tab' => 'feedback'], false)));
            }
            $this->audit->record('performance.review_started', $review, ['feedback_from' => $givers->count()]);
            if ($askSelfReview) $employee->user?->notify(new ApprovalRequested('PerformanceReview', $review->id, 'Your performance review has started: please write your self-review.', route('performance.reviews', ['tab' => 'mine'], false)));

            return $review;
        });
    }

    public function submitSelfReview(User $employeeUser, PerformanceReview $review, int $rating, string $assessment): void
    {
        abort_unless($review->employee?->user_id === $employeeUser->id, 403);
        if ($review->status !== 'self_review') throw ValidationException::withMessages(['selfAssessment' => 'The self-review for this period is already done.']);
        $review->update(['self_rating' => $rating, 'self_assessment' => $assessment, 'self_submitted_at' => now(), 'status' => 'manager_review']);
        $this->audit->record('performance.self_review_submitted', $review, ['self_rating' => $rating]);
        $review->reviewer?->notify(new ApprovalRequested('PerformanceReview', $review->id, "{$review->employee->name} submitted their self-review. Complete the review.", route('performance.reviews', ['tab' => 'team'], false)));
    }

    public function giveFeedback(User $giver, PerformanceFeedback $feedback, ?int $rating, ?string $strengths, ?string $improvements, bool $decline = false): void
    {
        abort_unless($feedback->giver_id === $giver->id, 403);
        if ($feedback->status !== 'requested') throw ValidationException::withMessages(['feedbackStrengths' => 'This feedback is closed.']);
        if (! $decline && (! $rating || trim((string) $strengths) === '')) throw ValidationException::withMessages(['feedbackStrengths' => 'Give a rating and say what they do well.']);
        $feedback->update($decline ? ['status' => 'declined', 'submitted_at' => now()] : ['rating' => $rating, 'strengths' => $strengths, 'improvements' => $improvements ?: null, 'status' => 'submitted', 'submitted_at' => now()]);
        $this->audit->record($decline ? 'performance.feedback_declined' : 'performance.feedback_given', $feedback->review);
    }

    /** The manager's final rating and per-goal scores; shared with the employee. Unanswered feedback requests close. */
    public function completeReview(User $manager, PerformanceReview $review, array $data): void
    {
        abort_unless($review->reviewer_id === $manager->id || $this->canManage($manager, $review->employee_id), 403);
        if ($review->isShared()) throw ValidationException::withMessages(['rating' => 'This review is already shared.']);
        $goalIds = $review->goals()->pluck('id')->all();
        $scores = collect((array) ($data['goal_scores'] ?? []))->only($goalIds)->filter(fn ($s) => is_numeric($s) && $s >= 1 && $s <= 5)->map(fn ($s) => (int) $s)->all();
        $metrics = (array) $this->work->productivity(collect([$review->employee]), $review->period_starts_on->toDateString(), $review->period_ends_on->toDateString())->first();
        unset($metrics['employee']);

        DB::transaction(function () use ($manager, $review, $data, $scores, $metrics) {
            $review->update(['rating' => (int) $data['rating'], 'strengths' => $data['strengths'], 'improvements' => $data['improvements'] ?: null, 'goal_scores' => $scores ?: null, 'metrics' => $metrics, 'status' => 'submitted', 'completed_at' => now(), 'reviewer_id' => $review->reviewer_id ?? $manager->id]);
            $review->feedback()->where('status', 'requested')->update(['status' => 'closed']);
        });
        $this->audit->record('performance.review_submitted', $review, ['rating' => (int) $data['rating']]);
        $review->employee?->user?->notify(new ApprovalRequested('PerformanceReview', $review->id, 'A performance review has been shared with you.'));
    }

    /** What the employee may see of the 360 feedback: no names, and nothing unless enough people answered. */
    public function anonymousFeedback(PerformanceReview $review): ?array
    {
        $answers = $review->feedback()->where('status', 'submitted')->get();
        if ($answers->count() < PerformanceReview::ANONYMOUS_MINIMUM) return null;

        return ['count' => $answers->count(), 'average' => round((float) $answers->avg('rating'), 1),
            'strengths' => $answers->pluck('strengths')->filter()->shuffle()->values()->all(),
            'improvements' => $answers->pluck('improvements')->filter()->shuffle()->values()->all()];
    }

    private function relationship(User $giver, AttendanceEmployee $employee): string
    {
        $giverEmployee = $giver->employeeProfile;
        if (! $giverEmployee) return 'other';
        if ($giverEmployee->manager_id === $employee->id) return 'report';
        if ($employee->manager_id && $giverEmployee->manager_id === $employee->manager_id) return 'peer';
        if ($giverEmployee->directReports()->exists()) return 'manager';

        return 'peer';
    }
}
