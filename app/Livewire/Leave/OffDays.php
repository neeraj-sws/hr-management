<?php

namespace App\Livewire\Leave;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\ApprovalRequest;
use App\Models\OffDayRequest;
use App\Services\ApprovalInbox;
use App\Services\OffDayService;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Leave & Time > Off day requests: someone whose week-of-month offs (e.g. 2nd Saturday) count only when asked for
 * says "I am taking my off on this day"; HR approves or rejects it here (or in Approvals). Not asked for or not
 * approved, the day is a normal working day.
 */
class OffDays extends Component
{
    use InteractsWithHr, WithPagination;

    #[Url] public string $tab = 'mine';
    public bool $showForm = false;
    public string $offDate = '';
    public string $note = '';
    public ?int $decidingId = null;
    public bool $decideApprove = true;
    public string $decisionNote = '';

    public function mount(): void
    {
        if (! in_array($this->tab, ['mine', 'team'], true) || ($this->tab === 'team' && ! $this->canSeeTeam())) $this->tab = 'mine';
        if ($this->tab === 'mine' && ! $this->asks() && $this->canSeeTeam()) $this->tab = 'team';
    }

    private function canSeeTeam(): bool
    {
        return $this->user()->canAny(['hr.manage', 'approvals.manage']);
    }

    /** The signed-in employee's offs count only when asked for. */
    private function asks(): bool
    {
        $own = $this->ownEmployee();

        return $own && app(OffDayService::class)->needsRequests($own);
    }

    public function openForm(OffDayService $offs): void
    {
        $employee = $this->requireEmployee();
        $asked = OffDayRequest::where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved'])->get()->map(fn (OffDayRequest $r) => $r->off_date->toDateString())->all();
        $open = array_values(array_diff($offs->requestableDates($employee), $asked));
        $this->offDate = collect($open)->first(fn ($d) => $d >= today()->toDateString()) ?? ($open[0] ?? '');
        $this->note = '';
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(OffDayService $offs): void
    {
        $this->validate(['offDate' => ['required', 'date'], 'note' => ['nullable', 'string', 'max:1000']], [], ['offDate' => 'off day']);
        try {
            $offs->request($this->requireEmployee(), $this->offDate, $this->note);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw \Illuminate\Validation\ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => [['off_date' => 'offDate'][$k] ?? $k => $m])->all());
        }
        $this->reset('showForm', 'note');
        $this->toast($offs->autoApproves($this->requireEmployee()) ? 'Approved: the day is your off.' : 'Sent to HR. The day is your off once HR approves it.');
    }

    public function cancel(int $id, OffDayService $offs): void
    {
        $offs->cancel(OffDayRequest::findOrFail($id), $this->requireEmployee());
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
        $approval = ApprovalRequest::where('approvable_type', OffDayRequest::class)->where('approvable_id', $this->decidingId)->where('status', 'pending')->firstOrFail();
        abort_unless($inbox->canDecide($this->user(), $approval), 403);
        try {
            $inbox->decide($this->user(), $approval, $this->decideApprove, trim($this->decisionNote) ?: null);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw \Illuminate\Validation\ValidationException::withMessages(['decisionNote' => collect($e->errors())->flatten()->first()]);
        }
        $this->decidingId = null;
        $this->toast($this->decideApprove ? 'Off day approved.' : 'Off day not approved: it stays a working day.');
    }

    public function closeModal(): void
    {
        $this->decidingId = null;
        $this->showForm = false;
    }

    public function render(ApprovalInbox $inbox, OffDayService $offs)
    {
        $own = $this->ownEmployee();
        $team = $this->tab === 'team' && $this->canSeeTeam();
        $requests = OffDayRequest::with(['employee', 'decider'])
            ->when(! $team, fn ($q) => $q->where('employee_id', $own?->id ?? 0))
            ->orderByRaw("status = 'pending' desc")->latest('off_date')->latest('id')->paginate(15);
        $approvals = ApprovalRequest::where('approvable_type', OffDayRequest::class)->whereIn('approvable_id', $requests->pluck('id'))->where('status', 'pending')->with('approvable')->get();
        $asks = $this->asks();
        $asked = $own ? OffDayRequest::where('employee_id', $own->id)->whereIn('status', ['pending', 'approved'])->get()
            ->mapWithKeys(fn (OffDayRequest $r) => [$r->off_date->toDateString() => $r->status])->all() : [];

        return $this->page('livewire.leave.off-days', [
            'requests' => $requests,
            'team' => $team,
            'canSeeTeam' => $this->canSeeTeam(),
            'asks' => $asks,
            'decidable' => $approvals->filter(fn ($a) => $inbox->canDecide($this->user(), $a))->pluck('approvable_id')->all(),
            'dates' => $asks ? $offs->requestableDates($own) : [],
            'asked' => $asked,
        ], 'Off day requests');
    }
}
