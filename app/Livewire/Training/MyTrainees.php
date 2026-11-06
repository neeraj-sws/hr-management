<?php

namespace App\Livewire\Training;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\TrainingEnrollmentTopic;
use App\Services\TrainingMentorService;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A mentor's trainees: who they are teaching now (and which topic), who comes to them next, and whom they have
 * taught. "Mark done" moves the trainee on to the next topic and tells its mentor. HR can look at any mentor.
 * Fees are not shown here.
 */
class MyTrainees extends Component
{
    use InteractsWithHr;

    #[Url] public string $show = 'now';
    #[Url] public string $mentor = '';

    private function isHr(): bool
    {
        return $this->user()->can('training.manage');
    }

    /** The mentor whose trainees are shown: yourself, or (for HR) the one picked; null = every mentor. */
    private function mentorId(): ?int
    {
        if ($this->isHr()) return $this->mentor !== '' ? (int) $this->mentor : null;

        return $this->ownEmployee()?->id ?? 0;
    }

    public function markDone(int $id, TrainingMentorService $mentors): void
    {
        $topic = TrainingEnrollmentTopic::findOrFail($id);
        $next = $mentors->completeTopic($topic, $this->user());
        $this->toast("{$topic->title} done.".($next ? " Next: {$next->title}".($next->mentor ? " with {$next->mentor->name}" : '').'.' : ''));
    }

    public function render()
    {
        $mentorId = $this->mentorId();
        // Going on and coming next: only trainees still studying; done: everyone taught.
        $base = fn (string $status) => TrainingEnrollmentTopic::query()->where('status', $status)
            ->when($mentorId !== null, fn ($q) => $q->where('mentor_id', $mentorId), fn ($q) => $q->whereNotNull('mentor_id'))
            ->when($status !== 'completed', fn ($q) => $q->whereHas('enrollment', fn ($e) => $e->where('status', 'active')));
        $counts = ['now' => $base('active')->count(), 'next' => $base('pending')->count(), 'done' => $base('completed')->count()];
        $status = ['now' => 'active', 'next' => 'pending', 'done' => 'completed'][$this->show] ?? 'active';
        $rows = $base($status)->with(['mentor:id,name', 'enrollment.employee:id,name,employee_code', 'enrollment.topics.mentor:id,name'])
            ->when($status === 'completed', fn ($q) => $q->orderByDesc('completed_on'), fn ($q) => $q->orderBy('started_on'))->orderBy('id')->get();

        return $this->page('livewire.training.my-trainees', [
            'rows' => $rows,
            'counts' => $counts,
            'isHr' => $this->isHr(),
            'mentors' => $this->isHr() ? AttendanceEmployee::whereIn('id', TrainingEnrollmentTopic::whereNotNull('mentor_id')->distinct()->pluck('mentor_id'))->orderBy('name')->get(['id', 'name']) : collect(),
            'ownId' => $this->ownEmployee()?->id,
        ], 'My trainees');
    }
}
