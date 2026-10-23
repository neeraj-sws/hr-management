<?php

namespace App\Livewire\Performance;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\PerformanceFeedback;
use App\Models\PerformanceReview;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PerformanceService;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Reviews: the manager starts one (self-review and 360 feedback first, or writes it straight away),
 * the employee writes a self-review, colleagues give feedback, the manager scores the goals and
 * gives the final rating, and the employee acknowledges.
 */
class Reviews extends Component
{
    use InteractsWithHr, \Livewire\WithPagination;

    #[Url] public string $tab = '';
    // New review
    public bool $showForm = false;
    public ?int $employeeId = null;
    public string $periodStart = '';
    public string $periodEnd = '';
    public string $mode = 'cycle';
    public array $feedbackFrom = [];
    // Self-review
    public ?int $selfId = null;
    public int $selfRating = 3;
    public string $selfAssessment = '';
    // 360 feedback
    public ?int $feedbackId = null;
    public int $feedbackRating = 3;
    public string $feedbackStrengths = '';
    public string $feedbackImprovements = '';
    // Manager's final review
    public ?int $completeId = null;
    public int $rating = 3;
    public string $strengths = '';
    public string $improvements = '';
    public array $goalScores = [];
    // Acknowledge
    public ?int $acknowledgingId = null;
    public string $employeeComment = '';

    private function canReview(): bool
    {
        return $this->user()->canAny(['performance.review', 'hr.manage']);
    }

    public function mount(): void
    {
        if (! in_array($this->tab, ['mine', 'team', 'feedback'], true)) $this->tab = $this->ownEmployee() ? 'mine' : 'team';
        if ($this->tab === 'team' && ! $this->canReview()) $this->tab = 'mine';
    }

    private function reviewableIds()
    {
        return app(PerformanceService::class)->manageableIds($this->user());
    }

    public function create(): void
    {
        abort_unless($this->canReview(), 403);
        $this->reset('employeeId', 'feedbackFrom');
        $this->mode = 'cycle';
        [$this->periodStart, $this->periodEnd] = Goals::periodRange(Goals::periodKey(today()->subMonthsNoOverflow(3)));
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(PerformanceService $performance): void
    {
        abort_unless($this->canReview(), 403);
        $this->validate(['employeeId' => ['required', 'integer'], 'periodStart' => ['required', 'date'], 'periodEnd' => ['required', 'date', 'after_or_equal:periodStart'], 'mode' => ['required', 'in:cycle,direct'], 'feedbackFrom' => ['array', 'max:10'], 'feedbackFrom.*' => ['integer', 'exists:users,id']], [], ['employeeId' => 'employee', 'feedbackFrom' => 'feedback givers']);
        abort_unless($this->reviewableIds()->contains($this->employeeId), 403);
        $employee = AttendanceEmployee::findOrFail($this->employeeId);
        abort_if($employee->user_id === auth()->id(), 422, 'You cannot review yourself.');
        $review = $performance->startReview($this->user(), $employee, $this->periodStart, $this->periodEnd, $this->mode === 'cycle' ? $this->feedbackFrom : [], $this->mode === 'cycle');
        $this->showForm = false;
        if ($this->mode === 'direct') {
            $this->openComplete($review->id);

            return;
        }
        $this->tab = 'team';
        $this->toast('Review started: '.$employee->name.' is asked for a self-review'.(count($this->feedbackFrom) ? ' and '.count($this->feedbackFrom).' '.str('colleague')->plural(count($this->feedbackFrom)).' for feedback.' : '.'));
    }

    public function openSelf(int $id): void
    {
        $review = PerformanceReview::where('employee_id', $this->requireEmployee()->id)->findOrFail($id);
        $this->selfId = $review->id;
        $this->selfRating = 3;
        $this->selfAssessment = '';
        $this->resetValidation();
    }

    public function submitSelf(PerformanceService $performance): void
    {
        $this->validate(['selfRating' => ['required', 'integer', 'between:1,5'], 'selfAssessment' => ['required', 'string', 'min:10', 'max:5000']], [], ['selfAssessment' => 'self-review']);
        $performance->submitSelfReview($this->user(), PerformanceReview::findOrFail($this->selfId), $this->selfRating, $this->selfAssessment);
        $this->selfId = null;
        $this->toast('Self-review sent to your manager.');
    }

    public function openFeedback(int $id): void
    {
        PerformanceFeedback::where('giver_id', auth()->id())->findOrFail($id);
        $this->feedbackId = $id;
        $this->feedbackRating = 3;
        $this->feedbackStrengths = $this->feedbackImprovements = '';
        $this->resetValidation();
    }

    public function submitFeedback(PerformanceService $performance, bool $decline = false): void
    {
        if (! $decline) $this->validate(['feedbackRating' => ['required', 'integer', 'between:1,5'], 'feedbackStrengths' => ['required', 'string', 'max:3000'], 'feedbackImprovements' => ['nullable', 'string', 'max:3000']], [], ['feedbackStrengths' => 'what they do well', 'feedbackImprovements' => 'what to improve']);
        $performance->giveFeedback($this->user(), PerformanceFeedback::findOrFail($this->feedbackId), $this->feedbackRating, $this->feedbackStrengths, $this->feedbackImprovements, $decline);
        $this->feedbackId = null;
        $this->toast($decline ? 'Feedback request declined.' : 'Thank you, your feedback is sent. The employee sees it without your name.');
    }

    public function openComplete(int $id): void
    {
        $review = PerformanceReview::findOrFail($id);
        abort_unless($review->reviewer_id === auth()->id() || $this->reviewableIds()->contains($review->employee_id), 403);
        $this->completeId = $id;
        $this->rating = $review->self_rating ?: 3;
        $this->strengths = $this->improvements = '';
        $this->goalScores = $review->goals()->mapWithKeys(fn ($g) => [$g->id => ''])->all();
        $this->resetValidation();
    }

    public function complete(PerformanceService $performance): void
    {
        $this->validate(['rating' => ['required', 'integer', 'between:1,5'], 'strengths' => ['required', 'string', 'max:3000'], 'improvements' => ['nullable', 'string', 'max:3000'], 'goalScores.*' => ['nullable', 'integer', 'between:1,5']], [], ['goalScores.*' => 'goal score']);
        $performance->completeReview($this->user(), PerformanceReview::findOrFail($this->completeId), ['rating' => $this->rating, 'strengths' => $this->strengths, 'improvements' => $this->improvements, 'goal_scores' => $this->goalScores]);
        $this->completeId = null;
        $this->toast('Review shared with the employee.');
    }

    public function openAcknowledge(int $id): void
    {
        $this->acknowledgingId = $id;
        $this->employeeComment = '';
    }

    public function acknowledge(AuditLogger $audit): void
    {
        $review = PerformanceReview::where('employee_id', $this->requireEmployee()->id)->where('status', 'submitted')->findOrFail($this->acknowledgingId);
        $this->validate(['employeeComment' => ['nullable', 'string', 'max:2000']]);
        $review->update(['status' => 'acknowledged', 'acknowledged_at' => now(), 'employee_comment' => $this->employeeComment ?: null]);
        $audit->record('performance.review_acknowledged', $review);
        $this->acknowledgingId = null;
        $this->toast('Review acknowledged.');
    }

    public function closeModal(): void
    {
        $this->showForm = false;
        $this->acknowledgingId = $this->selfId = $this->feedbackId = $this->completeId = null;
    }

    public function render(PerformanceService $performance)
    {
        $own = $this->ownEmployee();
        $query = PerformanceReview::with(['employee', 'reviewer', 'feedback.giver'])
            ->when($this->tab === 'mine', fn ($q) => $q->where('employee_id', $own?->id ?? 0))
            ->when($this->tab === 'team', fn ($q) => $q->where(fn ($q) => $q->whereIn('employee_id', $this->reviewableIds()->all() ?: [0])->orWhere('reviewer_id', auth()->id()))->where('employee_id', '!=', $own?->id ?? 0))
            ->when($this->tab === 'feedback', fn ($q) => $q->whereRaw('1 = 0'))
            ->latest('id');
        $reviews = (clone $query)->paginate(15);
        $requests = PerformanceFeedback::with('review.employee')->where('giver_id', auth()->id())->orderByRaw("status = 'requested' desc")->latest('id')->limit(30)->get();

        return $this->page('livewire.performance.reviews', [
            'reviews' => $reviews,
            'own' => $own,
            'canReview' => $this->canReview(),
            'people' => $this->canReview() ? AttendanceEmployee::whereIn('id', $this->reviewableIds()->all() ?: [0])->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', auth()->id()))->orderBy('name')->get(['id', 'name']) : collect(),
            'colleagues' => $this->showForm ? User::where('is_active', true)->where('id', '!=', auth()->id())->whereHas('employeeProfile', fn ($q) => $q->where('is_active', true)->when($this->employeeId, fn ($q) => $q->where('id', '!=', $this->employeeId)))->orderBy('name')->get(['id', 'name']) : collect(),
            'average' => (clone $query)->reorder()->whereNotNull('rating')->avg('rating'),
            'requests' => $requests,
            'openRequests' => $requests->where('status', 'requested')->count(),
            'performance' => $performance,
            'selfReview' => $this->selfId ? PerformanceReview::find($this->selfId) : null,
            'feedbackFor' => $this->feedbackId ? PerformanceFeedback::with('review.employee')->find($this->feedbackId) : null,
            'completing' => $this->completeId ? PerformanceReview::with(['employee', 'feedback.giver'])->find($this->completeId) : null,
        ], 'Performance');
    }
}
