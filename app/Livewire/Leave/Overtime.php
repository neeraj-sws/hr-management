<?php

namespace App\Livewire\Leave;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\ApprovalRequest;
use App\Models\OvertimeRequest;
use App\Services\ApprovalInbox;
use App\Services\PeopleOperationsService;
use App\Services\PolicyConfigurationService;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Overtime extends Component
{
    use InteractsWithHr, WithPagination;

    #[Url] public string $tab = '';
    public bool $showForm = false;
    public string $workDate = '';
    public string $minutes = '';
    public string $reason = '';
    public ?int $decidingId = null;
    public string $approvedMinutes = '';
    public string $decisionNote = '';

    public function mount(): void
    {
        // Overtime switched off in Settings > Payroll: the page is gone too (not only its menu link).
        abort_unless(app(\App\Services\PeopleOperationsService::class)->overtimeEnabled(), 404);
        $canApprove = $this->user()->can('overtime.manage');
        if (! in_array($this->tab, ['mine', 'approvals', 'all'], true)) $this->tab = $this->ownEmployee() ? 'mine' : 'approvals';
        if ($this->tab !== 'mine' && ! $canApprove) $this->tab = 'mine';
        // "Request overtime" from My Attendance: open the form for that day and the extra minutes worked.
        $date = (string) request()->query('date', '');
        if ($this->ownEmployee() && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->create();
            $this->workDate = $date;
            $this->minutes = (string) max(0, (int) request()->query('minutes', 0)) ?: '';
            $this->reason = 'Worked beyond my shift on '.\Illuminate\Support\Carbon::parse($date)->format('d M Y');
        }
    }

    public function create(): void
    {
        $this->requireEmployee();
        $this->reset('minutes', 'reason');
        $this->workDate = today()->toDateString();
        $this->resetValidation();
        $this->showForm = true;
    }

    public function submit(PeopleOperationsService $people): void
    {
        $this->validate(['workDate' => ['required', 'date', 'before_or_equal:today'], 'minutes' => ['required', 'integer', 'min:15', 'max:720'], 'reason' => ['required', 'string', 'max:1000']], [], ['workDate' => 'work date']);
        $employee = $this->requireEmployee();
        if (OvertimeRequest::where('employee_id', $employee->id)->whereDate('work_date', $this->workDate)->whereIn('status', ['pending', 'approved'])->exists()) {
            $this->addError('workDate', 'You already have a pending or approved overtime request for this date.');

            return;
        }
        $people->requestOvertime($employee, $this->workDate, (int) $this->minutes, $this->reason);
        $this->showForm = false;
        $this->toast('Overtime request submitted for approval.');
    }

    public function openDecision(int $id): void
    {
        $request = OvertimeRequest::findOrFail($id);
        $this->fill(['decidingId' => $id, 'approvedMinutes' => (string) $request->requested_minutes, 'decisionNote' => '']);
        $this->resetValidation();
    }

    public function decide(bool $approve, ApprovalInbox $inbox, PeopleOperationsService $people): void
    {
        $request = OvertimeRequest::findOrFail($this->decidingId);
        $this->validate(['approvedMinutes' => [$approve ? 'required' : 'nullable', 'integer', 'min:1', 'max:'.$request->requested_minutes], 'decisionNote' => [$approve ? 'nullable' : 'required', 'string', 'max:1000']], ['decisionNote.required' => 'Add a reason when rejecting.'], ['approvedMinutes' => 'approved minutes']);
        $approval = ApprovalRequest::where('approvable_type', OvertimeRequest::class)->where('approvable_id', $request->id)->where('status', 'pending')->firstOrFail();
        abort_unless($inbox->canDecide($this->user(), $approval), 403);
        $people->decideOvertime($request, auth()->id(), $approve, $approve ? (int) $this->approvedMinutes : null, $this->decisionNote ?: null);
        $this->decidingId = null;
        $this->toast($approve ? 'Overtime approved.' : 'Overtime rejected.');
    }

    public function closeModal(): void
    {
        $this->showForm = false;
        $this->decidingId = null;
    }

    public function render(ApprovalInbox $inbox, PolicyConfigurationService $policies)
    {
        $user = $this->user();
        $own = $this->ownEmployee();
        $query = OvertimeRequest::with(['employee', 'decider'])->latest('id'); // newest request first
        if ($this->tab === 'mine') $query->where('employee_id', $own?->id ?? 0);
        elseif ($this->tab === 'approvals') $query->whereIn('id', $inbox->pending($user, OvertimeRequest::class)->pluck('approvable_id')->all() ?: [0]);
        else $query->when(! $user->canAny(['hr.manage', 'employees.manage']), fn ($q) => $q->whereIn('employee_id', $this->teamEmployeeIds()->all() ?: [0]));

        $mine = $own ? OvertimeRequest::where('employee_id', $own->id)->whereYear('work_date', today()->year)->whereMonth('work_date', today()->month) : null;

        return $this->page('livewire.leave.overtime', [
            'requests' => $query->paginate(15),
            'canApprove' => $user->can('overtime.manage'),
            // Counts on the status cards: my own, and everyone I may see (the same scope as the list).
            'tabCounts' => ['mine' => $own ? \App\Models\OvertimeRequest::where('employee_id', $own->id)->count() : 0,
                'all' => $user->can('overtime.manage') ? \App\Models\OvertimeRequest::query()->when(! $user->canAny(['hr.manage', 'employees.manage']), fn ($q) => $q->whereIn('employee_id', $this->teamEmployeeIds()->all() ?: [0]))->count() : 0],
            'pendingCount' => $user->can('overtime.manage') ? $inbox->pending($user, OvertimeRequest::class)->count() : 0,
            'own' => $own,
            'monthApproved' => $mine ? (int) (clone $mine)->where('status', 'approved')->sum('approved_minutes') : 0,
            'monthPending' => $mine ? (int) (clone $mine)->where('status', 'pending')->sum('requested_minutes') : 0,
            'policy' => $policies->forEmployee($own, 'overtime.multiplier', today()->toDateString(), []),
            'deciding' => $deciding = ($this->decidingId ? OvertimeRequest::with('employee')->find($this->decidingId) : null),
            'evidence' => $deciding ? app(PeopleOperationsService::class)->overtimeEvidence($deciding) : null,
        ], 'Overtime');
    }
}
