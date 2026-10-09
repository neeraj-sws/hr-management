<?php

namespace App\Livewire\Leave;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\AuditLogger;
use App\Services\LeaveAccrualService;
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

    public function mount(): void
    {
        $this->year = $this->year >= 2000 && $this->year <= 2100 ? $this->year : (int) today()->year;
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
            $balance = LeaveBalance::firstOrCreate(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $this->year], ['entitled_days' => (float) $type->monthly_accrual > 0 ? 0 : $type->annual_allowance]);
            $balance = LeaveBalance::whereKey($balance->id)->lockForUpdate()->first();
            $balance->update(['adjusted_days' => (float) $balance->adjusted_days + (float) $this->adjustDays]);
            $audit->record('leave.balance_adjusted', $balance, ['days' => (float) $this->adjustDays, 'reason' => $this->adjustReason, 'employee_id' => $employee->id, 'leave_type' => $type->name]);
        });
        $this->closeModal();
        $this->toast('Leave balance adjusted.');
    }

    public function runAccrual(LeaveAccrualService $accrual): void
    {
        abort_unless($this->user()->can('policies.manage'), 403);
        $count = $accrual->accrueThrough((int) today()->year, (int) today()->month, auth()->id());
        $this->toast("Monthly accrual processed for {$count} balances.");
    }

    public function closeModal(): void
    {
        $this->reset('adjustEmployeeId', 'adjustTypeId', 'adjustDays', 'adjustReason');
    }

    public function render(LeaveAccrualService $accrual)
    {
        $types = LeaveType::where('is_active', true)->orderBy('name')->get();
        $own = $this->ownEmployee();
        $mine = $own ? $types->map(fn ($t) => ['type' => $t, ...$accrual->balance($own, $t, $this->year)]) : collect();
        $history = $own ? LeaveRequest::with('leaveType')->where('employee_id', $own->id)->whereYear('starts_on', $this->year)->latest('starts_on')->get() : collect();

        $team = null;
        if ($this->canManage()) {
            $team = AttendanceEmployee::where('is_active', true)
                ->when(trim($this->search) !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.trim($this->search).'%')->orWhere('employee_code', 'like', '%'.trim($this->search).'%')))
                ->orderBy('name')->paginate(15);
            $team->getCollection()->transform(function ($employee) use ($types, $accrual) {
                $employee->setAttribute('balances', $types->mapWithKeys(fn ($t) => [$t->id => $accrual->balance($employee, $t, $this->year)]));

                return $employee;
            });
        }

        return $this->page('livewire.leave.balances', [
            'types' => $types, 'mine' => $mine, 'history' => $history, 'team' => $team, 'own' => $own,
            'adjusting' => $this->adjustEmployeeId ? AttendanceEmployee::find($this->adjustEmployeeId) : null,
            'adjustType' => $this->adjustTypeId ? LeaveType::find($this->adjustTypeId) : null,
        ], 'Leave Balance');
    }
}
