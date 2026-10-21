<?php

namespace App\Livewire\Leave;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\LeaveBalance;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\AuditLogger;
use App\Services\LeaveAccrualService;
use App\Support\LeaveYear;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Balances extends Component
{
    use InteractsWithHr, WithPagination;

    #[Url] public int $year = 0;
    #[Url] public string $search = '';

    public ?int $adjustEmployeeId = null;
    public ?int $adjustTypeId = null;
    public string $adjustDays = '';
    public string $adjustReason = '';

    /** Employee whose leave ledger is open (HR view). */
    public ?int $ledgerEmployeeId = null;

    public function mount(): void
    {
        $this->year = $this->year >= 2000 && $this->year <= 2100 ? $this->year : LeaveYear::current();
    }

    private function canManage(): bool
    {
        return $this->user()->can('leave.manage');
    }

    public function openAdjust(int $employeeId, int $typeId): void
    {
        abort_unless($this->canManage(), 403);
        $this->fill(['adjustEmployeeId' => $employeeId, 'adjustTypeId' => $typeId, 'adjustDays' => '', 'adjustReason' => '']);
        $this->resetValidation();
    }

    public function saveAdjustment(AuditLogger $audit): void
    {
        abort_unless($this->canManage(), 403);
        $this->validate(['adjustDays' => ['required', 'numeric', 'not_in:0', 'between:-365,365'], 'adjustReason' => ['required', 'string', 'max:500']], [], ['adjustDays' => 'days', 'adjustReason' => 'reason']);
        $employee = AttendanceEmployee::findOrFail($this->adjustEmployeeId);
        $type = LeaveType::findOrFail($this->adjustTypeId);
        DB::transaction(function () use ($employee, $type, $audit) {
            $balance = LeaveBalance::open($employee->id, $type, $this->year);
            $balance = LeaveBalance::whereKey($balance->id)->lockForUpdate()->first();
            $balance->update(['adjusted_days' => (float) $balance->adjusted_days + (float) $this->adjustDays]);
            LeaveLedgerEntry::record($balance, 'adjustment', (float) $this->adjustDays, $this->adjustReason);
            $audit->record('leave.balance_adjusted', $balance, ['days' => (float) $this->adjustDays, 'reason' => $this->adjustReason, 'employee_id' => $employee->id, 'leave_type' => $type->name]);
        });
        $days = (float) $this->adjustDays;
        \App\Services\Notifier::send($employee->user, 'LeaveBalance', $employee->id, $this->user()->name.' '.($days > 0 ? 'added' : 'deducted').' '.abs($days)." day(s) of {$type->name} ".($days > 0 ? 'to' : 'from')." your leave balance. Reason: {$this->adjustReason}", route('leave.balances', [], false), auth()->id());
        $this->closeModal();
        $this->toast('Leave balance adjusted. The employee was notified.');
    }

    public function runAccrual(LeaveAccrualService $accrual): void
    {
        abort_unless($this->user()->can('policies.manage'), 403);
        $count = $accrual->accrueThrough((int) today()->year, (int) today()->month, auth()->id());
        $this->toast("Monthly accrual processed for {$count} balances.");
    }

    public function openLedger(int $employeeId): void
    {
        abort_unless($this->canManage(), 403);
        $this->ledgerEmployeeId = $employeeId;
    }

    public function closeModal(): void
    {
        $this->reset('adjustEmployeeId', 'adjustTypeId', 'adjustDays', 'adjustReason', 'ledgerEmployeeId');
    }

    /** Ledger entries for one employee and leave year, oldest first, with a running total per leave type. */
    private function ledger(int $employeeId)
    {
        $running = [];

        return LeaveLedgerEntry::with(['leaveType', 'creator'])->where('employee_id', $employeeId)->where('year', $this->year)->orderBy('id')->get()
            ->each(function (LeaveLedgerEntry $entry) use (&$running) {
                $running[$entry->leave_type_id] = round(($running[$entry->leave_type_id] ?? 0) + (float) $entry->days, 3);
                $entry->setAttribute('running', $running[$entry->leave_type_id]);
            });
    }

    public function render(LeaveAccrualService $accrual)
    {
        $types = LeaveType::where('is_active', true)->orderBy('name')->get();
        $own = $this->ownEmployee();
        $mine = $own ? $types->map(fn ($t) => ['type' => $t, ...$accrual->balance($own, $t, $this->year)]) : collect();
        $history = $own ? LeaveRequest::with('leaveType')->where('employee_id', $own->id)->whereBetween('starts_on', [LeaveYear::start($this->year)->toDateString(), LeaveYear::end($this->year)->toDateString()])->latest('starts_on')->get() : collect();

        $team = null;
        if ($this->canManage()) {
            $team = AttendanceEmployee::where('is_active', true)
                ->when(trim($this->search) !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.trim($this->search).'%')->orWhere('employee_code', 'like', '%'.trim($this->search).'%')))
                ->inCodeOrder()->paginate(15);
            $team->getCollection()->transform(function ($employee) use ($types, $accrual) {
                $employee->setAttribute('balances', $types->mapWithKeys(fn ($t) => [$t->id => $accrual->balance($employee, $t, $this->year)]));

                return $employee;
            });
        }

        return $this->page('livewire.leave.balances', [
            'types' => $types, 'mine' => $mine, 'history' => $history, 'team' => $team, 'own' => $own,
            'adjusting' => $this->adjustEmployeeId ? AttendanceEmployee::find($this->adjustEmployeeId) : null,
            'adjustType' => $this->adjustTypeId ? LeaveType::find($this->adjustTypeId) : null,
            'myLedger' => $own ? $this->ledger($own->id) : collect(),
            'ledgerEmployee' => $this->ledgerEmployeeId && $this->canManage() ? AttendanceEmployee::find($this->ledgerEmployeeId) : null,
            'ledgerEntries' => $this->ledgerEmployeeId && $this->canManage() ? $this->ledger($this->ledgerEmployeeId) : collect(),
            'yearOptions' => LeaveYear::options(), 'yearLabel' => LeaveYear::label($this->year),
            'yearRange' => LeaveYear::start($this->year)->format('d M Y').' – '.LeaveYear::end($this->year)->format('d M Y'),
        ], 'Leave Balance');
    }
}
