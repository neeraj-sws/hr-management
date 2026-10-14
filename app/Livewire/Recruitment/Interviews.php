<?php

namespace App\Livewire\Recruitment;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\Interview;
use App\Services\RecruitmentService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Interviews assigned to the signed-in user (recruiters can switch to all). */
class Interviews extends Component
{
    use InteractsWithHr, \Livewire\WithPagination;

    #[Url] public string $show = 'upcoming';
    #[Url] public bool $all = false;
    public ?int $feedbackId = null;
    public string $rating = '';
    public string $recommendation = '';
    public string $feedback = '';

    public function openFeedback(int $id): void
    {
        $interview = $this->scoped()->findOrFail($id);
        $this->fill(['feedbackId' => $interview->id, 'rating' => (string) ($interview->rating ?? ''), 'recommendation' => (string) $interview->recommendation, 'feedback' => (string) $interview->feedback]);
        $this->resetValidation();
    }

    public function saveFeedback(RecruitmentService $recruitment): void
    {
        $this->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'recommendation' => ['required', Rule::in(array_keys(Interview::RECOMMENDATIONS))],
            'feedback' => ['required', 'string', 'min:10', 'max:5000'],
        ], ['feedback.min' => 'Write a few lines so the hiring team understands your rating.']);
        $recruitment->submitFeedback($this->scoped()->findOrFail($this->feedbackId), $this->user(), ['rating' => (int) $this->rating, 'recommendation' => $this->recommendation, 'feedback' => $this->feedback]);
        $this->feedbackId = null;
        $this->toast('Feedback saved. Thank you!');
    }

    public function closeModal(): void
    {
        $this->feedbackId = null;
    }

    private function scoped()
    {
        $query = Interview::with(['candidate.jobOpening', 'interviewer']);

        return $this->all && $this->user()->can('recruitment.manage') ? $query : $query->where('interviewer_id', auth()->id());
    }

    public function render()
    {
        $query = $this->scoped();
        if ($this->show === 'upcoming') $query->where('status', 'scheduled')->orderBy('scheduled_at');
        else $query->where('status', '!=', 'scheduled')->orderByDesc('scheduled_at');

        return $this->page('livewire.recruitment.interviews', [
            'interviews' => $query->paginate(15),
            'canSeeAll' => $this->user()->can('recruitment.manage'),
            'giving' => $this->feedbackId ? Interview::with('candidate.jobOpening')->find($this->feedbackId) : null,
        ], 'Interviews');
    }
}
