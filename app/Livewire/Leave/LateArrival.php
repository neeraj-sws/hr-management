<?php

namespace App\Livewire\Leave;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\ApprovalRequest;
use App\Models\LateArrivalRequest;
use App\Services\ApprovalInbox;
use App\Services\LateArrivalService;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Leave & Time > Late arrival: tell in advance that you will come late (expected check-in time and a valid reason);
 * the manager / HR approve or not here (or in Approvals). Approved and in by that time: no late fine, no late cut.
 */
class LateArrival extends Component
{
    use InteractsWithHr, WithPagination;

    #[Url] public string $tab = 'mine';
    public bool $showForm = false;
    public string $workDate = '';
    public string $expectedAt = '11:00';
    public string $reason = '';
    public ?int $decidingId = null;
    public bool $decideApprove = true;
    public string $decisionNote = '';

    public function mount(): void
    {
        $this->workDate = today()->toDateString();
        if (! in_array($this->tab, ['mine', 'team'], true) || ($this->tab === 'team' && ! $this->canSeeTeam())) $this->tab = $this->ownEmployee() ? 'mine' : 'team';
    }

    private function canSeeTeam(): bool
    {
        return $this->user()->canAny(['hr.manage', 'approvals.manage']) || ($this->ownEmployee()?->directReports()->exists() ?? false);
    }

    public function save(LateArrivalService $late): void
    {
        $this->validate(['workDate' => ['required', 'date'], 'expectedAt' => ['required', 'date_format:H:i'], 'reason' => ['required', 'string', 'min:10', 'max:2000']],
            ['reason.min' => 'Give a valid reason (at least 10 characters).'], ['workDate' => 'date', 'expectedAt' => 'expected check-in']);
        try {
            $request = $late->request($this->requireEmployee(), $this->workDate, $this->expectedAt, $this->reason);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw \Illuminate\Validation\ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => [['work_date' => 'workDate', 'expected_at' => 'expectedAt'][$k] ?? $k => $m])->all());
        }
        $this->reset('showForm', 'reason');
        $request->status === 'rejected' ? $this->toast((string) $request->decision_note, 'error') : $this->toast('Sent to your manager. It is not approved yet - you will be told.');
    }

    public function cancel(int $id, LateArrivalService $late): void
    {
        $late->cancel(LateArrivalRequest::findOrFail($id), $this->requireEmployee());
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
        $approval = ApprovalRequest::where('approvable_type', LateArrivalRequest::class)->where('approvable_id', $this->decidingId)->where('status', 'pending')->firstOrFail();
        abort_unless($inbox->canDecide($this->user(), $approval), 403);
        try {
            $inbox->decide($this->user(), $approval, $this->decideApprove, trim($this->decisionNote) ?: null);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw \Illuminate\Validation\ValidationException::withMessages(['decisionNote' => collect($e->errors())->flatten()->first()]);
        }
        $this->decidingId = null;
        $this->toast($this->decideApprove ? 'Late arrival approved.' : 'Late arrival not approved.');
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
        $requests = LateArrivalRequest::with(['employee', 'decider'])
            ->when(! $team, fn ($q) => $q->where('employee_id', $own?->id ?? 0))
            ->when($team && $teamIds !== null, fn ($q) => $q->whereIn('employee_id', $teamIds ?: [0]))
            ->orderByRaw("status = 'pending' desc")->latest('work_date')->latest('id')->paginate(15);
        $approvals = ApprovalRequest::where('approvable_type', LateArrivalRequest::class)->whereIn('approvable_id', $requests->pluck('id'))->where('status', 'pending')->with('approvable')->get();
        // Each approved request: did they come in by the time they gave?
        $checkIns = \App\Models\AttendanceSession::whereIn('employee_id', $requests->pluck('employee_id')->unique())->whereIn('work_date', $requests->pluck('work_date')->map->toDateString()->unique())->whereNotNull('check_in')->get()
            ->groupBy(fn ($s) => $s->employee_id.'|'.$s->work_date->toDateString())->map(fn ($g) => $g->min('check_in'));

        return $this->page('livewire.leave.late-arrival', [
            'requests' => $requests,
            'team' => $team,
            'canSeeTeam' => $this->canSeeTeam(),
            'decidable' => $approvals->filter(fn ($a) => $inbox->canDecide($this->user(), $a))->pluck('approvable_id')->all(),
            'checkIns' => $checkIns,
            'own' => $own,
        ], 'Late arrival');
    }
}
