<?php

namespace App\Livewire\Attendance;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\ApprovalRequest;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceSession;
use App\Services\ApprovalInbox;
use App\Services\AttendanceCorrectionService;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Corrections extends Component
{
    use InteractsWithHr, WithPagination;

    #[Url] public string $tab = '';
    #[Url(as: 'date')] public string $prefillDate = '';
    public bool $showForm = false;
    public string $workDate = '';
    public string $checkIn = '';
    public string $checkOut = '';
    public string $breakMinutes = '';
    public string $reason = '';
    public ?int $decidingId = null;
    public string $decisionNote = '';

    public function mount(): void
    {
        $canApprove = $this->canApprove();
        // Approvers land on what is waiting for them; everyone else on their own requests.
        if (! in_array($this->tab, ['mine', 'approvals', 'all'], true)) $this->tab = ! $this->ownEmployee() || ($canApprove && app(ApprovalInbox::class)->pending($this->user(), AttendanceCorrection::class)->isNotEmpty()) ? 'approvals' : 'mine';
        if ($this->tab !== 'mine' && ! $canApprove) $this->tab = 'mine';
        if ($this->ownEmployee() && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->prefillDate)) $this->create($this->prefillDate);
        // Open the form from a link once; refreshing the page must not open it again.
        $this->prefillDate = '';
    }

    private function canApprove(): bool
    {
        return $this->user()->canAny(['attendance.correct', 'approvals.manage']) || ApprovalInbox::isTeamLead($this->user());
    }

    public function create(?string $date = null): void
    {
        $employee = $this->requireEmployee();
        $this->reset('checkIn', 'checkOut', 'reason', 'breakMinutes');
        $this->workDate = $date ?: today()->toDateString();
        $session = AttendanceSession::where('employee_id', $employee->id)->whereDate('work_date', $this->workDate)->first();
        $this->checkIn = (string) $session?->check_in?->format('H:i');
        $this->checkOut = (string) $session?->check_out?->format('H:i');
        $this->resetValidation();
        $this->showForm = true;
    }

    public function updatedWorkDate(): void
    {
        $session = AttendanceSession::where('employee_id', $this->requireEmployee()->id)->whereDate('work_date', $this->workDate)->first();
        $this->checkIn = (string) $session?->check_in?->format('H:i');
        $this->checkOut = (string) $session?->check_out?->format('H:i');
    }

    public function submit(AttendanceCorrectionService $corrections): void
    {
        $this->validate([
            'workDate' => ['required', 'date', 'before_or_equal:today'],
            'checkIn' => ['nullable', 'date_format:H:i'],
            'checkOut' => ['nullable', 'date_format:H:i'],
            'breakMinutes' => ['nullable', 'integer', 'min:0', 'max:720'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ], [], ['workDate' => 'date', 'checkIn' => 'check-in time', 'checkOut' => 'check-out time', 'breakMinutes' => 'break minutes']);
        $corrections->request($this->requireEmployee(), $this->workDate, $this->checkIn ?: null, $this->checkOut ?: null, $this->reason, $this->breakMinutes === '' ? null : (int) $this->breakMinutes);
        $this->showForm = false;
        $this->toast('Correction sent for approval to your manager and the attendance administrators.');
    }

    public function cancel(int $id, AttendanceCorrectionService $corrections): void
    {
        $corrections->cancel(AttendanceCorrection::findOrFail($id), $this->requireEmployee());
        $this->toast('Correction request cancelled.');
    }

    public function openDecision(int $id): void
    {
        $this->fill(['decidingId' => $id, 'decisionNote' => '']);
        $this->resetValidation();
    }

    public function decide(bool $approve, ApprovalInbox $inbox): void
    {
        $this->validate(['decisionNote' => [$approve ? 'nullable' : 'required', 'string', 'max:1000']], ['decisionNote.required' => 'Add a reason when rejecting.']);
        $approval = ApprovalRequest::where('approvable_type', AttendanceCorrection::class)->where('approvable_id', $this->decidingId)->where('status', 'pending')->firstOrFail();
        $inbox->decide($this->user(), $approval, $approve, $this->decisionNote ?: null);
        $this->decidingId = null;
        $this->toast($approve ? 'Correction approved — attendance updated.' : 'Correction rejected.');
    }

    public function closeModal(): void
    {
        $this->showForm = false;
        $this->decidingId = null;
    }

    public function render(ApprovalInbox $inbox)
    {
        $user = $this->user();
        $own = $this->ownEmployee();
        $query = AttendanceCorrection::with(['employee', 'decider', 'session'])->latest('id');
        if ($this->tab === 'mine') $query->where('employee_id', $own?->id ?? 0);
        elseif ($this->tab === 'approvals') $query->whereIn('id', $inbox->pending($user, AttendanceCorrection::class)->pluck('approvable_id')->all() ?: [0]);
        else $query->when(! $user->canAny(['attendance.correct', 'hr.manage']), fn ($q) => $q->whereIn('employee_id', $this->teamEmployeeIds()->all() ?: [0]));
        $deciding = $this->decidingId ? AttendanceCorrection::with(['employee', 'session'])->find($this->decidingId) : null;
        $attendance = app(\App\Services\AttendanceService::class);
        // The form shows what is recorded for the chosen day, including the actual working time.
        $recorded = null;
        $payrollNotice = null;
        if ($this->showForm && $own && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->workDate)) {
            $session = AttendanceSession::where('employee_id', $own->id)->whereDate('work_date', $this->workDate)->latest('id')->first();
            $recorded = ['session' => $session, 'worked' => $session ? $attendance->workedSeconds($session) : null, 'required' => (int) round($attendance->dailyHours($own, $this->workDate) * 3600)];
            $payrollNotice = app(\App\Services\PayrollLockGuard::class)->closedNotice($own->id, $this->workDate);
        }

        return $this->page('livewire.attendance.corrections', [
            'corrections' => $query->paginate(15),
            'own' => $own,
            'canApprove' => $this->canApprove(),
            'pendingCount' => $this->canApprove() ? $inbox->pending($user, AttendanceCorrection::class)->count() : 0,
            'deciding' => $deciding,
            'decidingWorked' => $deciding?->session ? $attendance->workedSeconds($deciding->session) : null,
            'decidingAfter' => $deciding ? $this->workedAfter($deciding) : null,
            'decidingNotice' => $deciding && $deciding->status === 'pending' ? app(\App\Services\PayrollLockGuard::class)->closedNotice($deciding->employee_id, $deciding->work_date->toDateString()) : null,
            'recorded' => $recorded,
            'payrollNotice' => $payrollNotice,
        ], 'Attendance Corrections');
    }

    /** Working time the day would have once the correction is applied (same rule as the approval: span − break − idle). */
    private function workedAfter(AttendanceCorrection $correction): ?int
    {
        $session = $correction->session;
        $in = $correction->requested_check_in ?? $session?->check_in;
        $out = $correction->requested_check_out ?? $session?->check_out;
        if (! $in || ! $out || $out->lte($in)) return null;
        $break = $correction->requested_break_minutes !== null ? $correction->requested_break_minutes * 60 : (int) $session?->break_seconds;

        return (int) max($in->diffInSeconds($out) - $break - (int) $session?->idle_seconds, 0);
    }
}
