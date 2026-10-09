<?php

namespace App\Livewire\Performance;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\PerformanceReview;
use App\Notifications\ApprovalRequested;
use App\Services\AuditLogger;
use App\Services\WorkService;
use Livewire\Attributes\Url;
use Livewire\Component;

class Reviews extends Component
{
    use InteractsWithHr;

    #[Url] public string $tab = '';
    public bool $showForm = false;
    public ?int $employeeId = null;
    public string $periodStart = '';
    public string $periodEnd = '';
    public int $rating = 3;
    public string $strengths = '';
    public string $improvements = '';
    public ?int $acknowledgingId = null;
    public string $employeeComment = '';

    private function canReview(): bool
    {
        return $this->user()->canAny(['performance.review', 'hr.manage']);
    }

    public function mount(): void
    {
        if (! in_array($this->tab, ['mine', 'team'], true)) $this->tab = $this->ownEmployee() ? 'mine' : 'team';
        if ($this->tab === 'team' && ! $this->canReview()) $this->tab = 'mine';
    }

    private function reviewableIds()
    {
        if ($this->user()->canAny(['hr.manage', 'employees.manage'])) return AttendanceEmployee::where('is_active', true)->pluck('id');

        return $this->ownEmployee()?->directReports()->where('is_active', true)->pluck('id') ?? collect();
    }

    public function create(): void
    {
        abort_unless($this->canReview(), 403);
        $this->reset('employeeId', 'strengths', 'improvements');
        $this->rating = 3;
        $this->periodStart = now()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $this->periodEnd = now()->subMonthNoOverflow()->endOfMonth()->toDateString();
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(WorkService $work, AuditLogger $audit): void
    {
        abort_unless($this->canReview(), 403);
        $this->validate(['employeeId' => ['required', 'integer'], 'periodStart' => ['required', 'date'], 'periodEnd' => ['required', 'date', 'after_or_equal:periodStart'], 'rating' => ['required', 'integer', 'between:1,5'], 'strengths' => ['required', 'string', 'max:3000'], 'improvements' => ['nullable', 'string', 'max:3000']], [], ['employeeId' => 'employee']);
        abort_unless($this->reviewableIds()->contains($this->employeeId), 403);
        $employee = AttendanceEmployee::findOrFail($this->employeeId);
        abort_if($employee->user_id === auth()->id(), 422, 'You cannot review yourself.');
        $metrics = (array) $work->productivity(collect([$employee]), $this->periodStart, $this->periodEnd)->first();
        unset($metrics['employee']);
        $review = PerformanceReview::create(['employee_id' => $employee->id, 'reviewer_id' => auth()->id(), 'period_starts_on' => $this->periodStart, 'period_ends_on' => $this->periodEnd, 'rating' => $this->rating, 'strengths' => $this->strengths, 'improvements' => $this->improvements ?: null, 'metrics' => $metrics, 'status' => 'submitted']);
        $audit->record('performance.review_submitted', $review, ['rating' => $this->rating]);
        $employee->user?->notify(new ApprovalRequested('PerformanceReview', $review->id, 'A performance review has been shared with you.'));
        $this->showForm = false;
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
        $this->acknowledgingId = null;
    }

    public function render()
    {
        $own = $this->ownEmployee();
        $reviews = PerformanceReview::with(['employee', 'reviewer'])
            ->when($this->tab === 'mine', fn ($q) => $q->where('employee_id', $own?->id ?? 0))
            ->when($this->tab === 'team', fn ($q) => $q->where(fn ($q) => $q->whereIn('employee_id', $this->reviewableIds()->all() ?: [0])->orWhere('reviewer_id', auth()->id())))
            ->latest('period_ends_on')->latest('id')->get();

        return $this->page('livewire.performance.reviews', [
            'reviews' => $reviews,
            'own' => $own,
            'canReview' => $this->canReview(),
            'people' => $this->canReview() ? AttendanceEmployee::whereIn('id', $this->reviewableIds()->all() ?: [0])->where('user_id', '!=', auth()->id())->orderBy('name')->get(['id', 'name']) : collect(),
            'average' => $reviews->avg('rating'),
        ], 'Performance');
    }
}
