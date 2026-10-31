<?php

namespace App\Livewire\Leave;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\ApprovalRequest;
use App\Models\AttendanceEmployee;
use App\Models\NightWorkRequest;
use App\Models\Task;
use App\Services\ApprovalInbox;
use App\Services\NightWorkService;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Leave & Time > Night work: ask for work done at night (time, tasks, what was done) and see the decision; the
 * manager / HR approve or reject the team's requests here (or in Approvals). Approved time is paid by the hour.
 */
class NightWork extends Component
{
    use InteractsWithHr, WithPagination;

    #[Url] public string $tab = 'mine';
    public bool $showForm = false;
    public string $workDate = '';
    public string $startsAt = '22:00';
    public string $endsAt = '';
    public array $taskIds = [];
    public string $workDone = '';
    public ?int $decidingId = null;
    public bool $decideApprove = true;
    public string $decisionNote = '';

    public function mount(): void
    {
        $this->workDate = today()->subDay()->toDateString();
        if (! in_array($this->tab, ['mine', 'team'], true) || ($this->tab === 'team' && ! $this->canSeeTeam())) $this->tab = $this->ownEmployee() ? 'mine' : 'team';
    }

    private function canSeeTeam(): bool
    {
        return $this->user()->canAny(['hr.manage', 'approvals.manage']) || ($this->ownEmployee()?->directReports()->exists() ?? false);
    }

    public function save(NightWorkService $nights): void
    {
        $this->validate([
            'workDate' => ['required', 'date'], 'startsAt' => ['required', 'date_format:H:i'], 'endsAt' => ['required', 'date_format:H:i'],
            'taskIds' => ['array'], 'taskIds.*' => ['integer'], 'workDone' => ['required', 'string', 'min:10', 'max:3000'],
        ], ['workDone.min' => 'Write what you did (at least 10 characters).'], ['workDate' => 'date', 'startsAt' => 'from', 'endsAt' => 'to', 'workDone' => 'work done']);
        try {
            $nights->request($this->requireEmployee(), $this->workDate, $this->startsAt, $this->endsAt, $this->taskIds, $this->workDone);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw \Illuminate\Validation\ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => [['work_date' => 'workDate', 'starts_at' => 'startsAt', 'ends_at' => 'endsAt', 'task_ids' => 'taskIds', 'work_done' => 'workDone'][$k] ?? $k => $m])->all());
        }
        $this->reset('showForm', 'taskIds', 'workDone', 'endsAt');
        $this->toast('Night work sent to your manager for approval.');
    }

    public function cancel(int $id, NightWorkService $nights): void
    {
        $nights->cancel(NightWorkRequest::findOrFail($id), $this->requireEmployee());
        $this->toast('Request cancelled.');
    }

    public function openDecide(int $id, bool $approve): void
    {
        $this->decidingId = $id;
        $this->decideApprove = $approve;
        $this->decisionNote = '';
        $this->resetValidation();
    }

    public function decide(ApprovalInbox $inbox): void
    {
        $approval = ApprovalRequest::where('approvable_type', NightWorkRequest::class)->where('approvable_id', $this->decidingId)->where('status', 'pending')->firstOrFail();
        abort_unless($inbox->canDecide($this->user(), $approval), 403);
        try {
            $inbox->decide($this->user(), $approval, $this->decideApprove, trim($this->decisionNote) ?: null);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw \Illuminate\Validation\ValidationException::withMessages(['decisionNote' => collect($e->errors())->flatten()->first()]);
        }
        $this->decidingId = null;
        $this->toast($this->decideApprove ? 'Night work approved: it is paid with the salary.' : 'Night work not approved.');
    }

    public function closeModal(): void
    {
        $this->decidingId = null;
        $this->showForm = false;
    }

    public function render(ApprovalInbox $inbox)
    {
        $own = $this->ownEmployee();
        $team = $this->tab === 'team' && $this->canSeeTeam();
        $teamIds = $this->user()->canAny(['hr.manage', 'approvals.manage']) ? null : ($own ? $own->directReports()->pluck('id')->all() : []);
        $requests = NightWorkRequest::with(['employee', 'decider'])
            ->when(! $team, fn ($q) => $q->where('employee_id', $own?->id ?? 0))
            ->when($team && $teamIds !== null, fn ($q) => $q->whereIn('employee_id', $teamIds ?: [0]))
            ->orderByRaw("status = 'pending' desc")->latest('work_date')->latest('id')->paginate(15);
        $approvals = ApprovalRequest::where('approvable_type', NightWorkRequest::class)->whereIn('approvable_id', $requests->pluck('id'))->where('status', 'pending')->with('approvable')->get();

        return $this->page('livewire.leave.night-work', [
            'requests' => $requests,
            'team' => $team,
            'canSeeTeam' => $this->canSeeTeam(),
            'decidable' => $approvals->filter(fn ($a) => $inbox->canDecide($this->user(), $a))->pluck('approvable_id')->all(),
            'myTasks' => $own ? Task::with('project')->where('assignee_id', $own->id)->where(fn ($q) => $q->where('status', '!=', 'completed')->orWhereDate('completed_at', '>=', today()->subDays(NightWorkService::DAYS_BACK)))->latest('id')->limit(60)->get() : collect(),
            'own' => $own,
        ], 'Night work');
    }
}
