<?php

namespace App\Livewire\Payroll;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\EmployeePenalty;
use App\Services\DayWiseSalaryService;
use App\Services\PeopleOperationsService;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Payroll > Day-wise salary: one employee's month, day by day (status, time at work, the day's pay, extra working,
 * overtime, fines, net), adding up to what payroll pays. Administrators, HR and payroll see everyone; a manager sees
 * the people who report to them. Admin, HR and the person's manager can take back a fine from here, with a reason.
 */
class DayWise extends Component
{
    use InteractsWithHr;

    #[Url] public ?int $employee = null;
    #[Url] public string $month = '';

    /** Taking back one fine (or every fine on the page): the fine ids and the reason. */
    public array $removing = [];
    public string $removeNote = '';

    public function mount(): void
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $this->month)) $this->month = today()->format('Y-m');
        if ($this->employee && ! $this->canSee($this->employee)) $this->employee = null;
        $this->employee ??= $this->people()->first()?->id;
    }

    private function orgWide(): bool
    {
        return $this->user()->canAny(['payroll.manage', 'payroll.approve', 'hr.manage', 'employees.manage']) || \App\Support\Access::isAdmin($this->user());
    }

    /** Who this person may look at: everyone on payroll (org-wide) or their direct reports (a manager). */
    private function people()
    {
        $own = $this->ownEmployee();

        return AttendanceEmployee::query()->onPayroll()->where(fn ($q) => $q->where('is_active', true)->orWhereNotNull('exit_date'))
            ->when(! $this->orgWide(), fn ($q) => $q->where('manager_id', $own?->id ?? 0))
            ->orderByRaw('LENGTH(employee_code)')->orderBy('employee_code')->get(['id', 'name', 'employee_code', 'is_active', 'manager_id']);
    }

    private function canSee(int $employeeId): bool
    {
        if ($this->orgWide()) return true;
        $own = $this->ownEmployee();

        return $own && AttendanceEmployee::whereKey($employeeId)->where('manager_id', $own->id)->exists();
    }

    /** Admin, HR / fines managers, or the person's own manager. */
    private function canRemoveFines(?AttendanceEmployee $employee): bool
    {
        if (! $employee) return false;
        $user = $this->user();
        if (\App\Support\Access::isAdmin($user) || $user->canAny(['hr.manage', 'penalties.manage'])) return true;
        $own = $this->ownEmployee();

        return $own !== null && $employee->manager_id === $own->id;
    }

    public function updatedEmployee(): void
    {
        if ($this->employee && ! $this->canSee((int) $this->employee)) $this->employee = null;
    }

    public function shiftMonth(int $by): void
    {
        $this->month = \Illuminate\Support\Carbon::parse($this->month.'-01')->addMonthsNoOverflow($by)->format('Y-m');
    }

    /** $ids: one fine, or every fine still on the page ("Take back all"). */
    public function openRemove(array $ids): void
    {
        $employee = AttendanceEmployee::find($this->employee);
        abort_unless($this->canRemoveFines($employee), 403);
        $this->removing = EmployeePenalty::whereIn('id', array_map('intval', $ids))->where('employee_id', $employee->id)->get()->filter->canBeRemoved()->pluck('id')->all();
        $this->removeNote = '';
        $this->resetValidation();
    }

    public function saveRemove(PeopleOperationsService $people): void
    {
        $employee = AttendanceEmployee::find($this->employee);
        abort_unless($this->canRemoveFines($employee), 403);
        $this->validate(['removeNote' => ['required', 'string', 'min:5', 'max:500']], ['removeNote.required' => 'Write why the fine is taken back.', 'removeNote.min' => 'Write why the fine is taken back (at least 5 characters).'], ['removeNote' => 'reason']);
        // Only this employee's fines (nobody can send other ids from the page).
        $ids = EmployeePenalty::whereIn('id', $this->removing)->where('employee_id', $employee->id)->pluck('id')->all();
        $done = $people->removeFines($ids, $this->removeNote, $this->user()->id);
        $this->removing = [];
        $this->toast(($done['removed'] + $done['paid_back']).' fine(s) taken back'.($done['paid_back'] ? ' ('.$done['paid_back'].' paid back in the next salary)' : '').'.');
    }

    public function closeModal(): void
    {
        $this->removing = [];
    }

    public function render(DayWiseSalaryService $days)
    {
        $person = $this->employee && $this->canSee($this->employee) ? AttendanceEmployee::with(['department', 'manager'])->find($this->employee) : null;

        return $this->page('livewire.payroll.day-wise', [
            'people' => $this->people(),
            'person' => $person,
            'data' => $person ? $days->month($person, $this->month) : null,
            'canRemove' => $this->canRemoveFines($person),
            'removingTotal' => $this->removing ? (float) EmployeePenalty::whereIn('id', $this->removing)->sum('amount') : 0.0,
        ], 'Day-wise salary');
    }
}
