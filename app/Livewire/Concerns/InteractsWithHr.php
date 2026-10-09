<?php

namespace App\Livewire\Concerns;

use App\Models\AttendanceEmployee;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/** Shared helpers for SWS HR Livewire pages. */
trait InteractsWithHr
{
    private ?AttendanceEmployee $cachedEmployee = null;
    private bool $employeeResolved = false;

    protected function user(): User
    {
        return auth()->user();
    }

    protected function ownEmployee(): ?AttendanceEmployee
    {
        if (! $this->employeeResolved) {
            $this->cachedEmployee = AttendanceEmployee::where('user_id', auth()->id())->first();
            $this->employeeResolved = true;
        }

        return $this->cachedEmployee;
    }

    protected function requireEmployee(): AttendanceEmployee
    {
        return $this->ownEmployee() ?? abort(403, 'An employee profile is required.');
    }

    /** Own employee id plus direct reports (a manager's team). */
    protected function teamEmployeeIds(): Collection
    {
        $employee = $this->ownEmployee();

        return $employee ? $employee->directReports()->pluck('id')->push($employee->id) : collect();
    }

    /**
     * For a manager: the date each direct report joined their team (from the
     * reporting history), so records from before they managed someone stay
     * with the previous manager. Empty for org-wide users.
     *
     * @return array<int, string> employee id => Y-m-d
     */
    protected function teamSince(): array
    {
        $own = $this->ownEmployee();
        if (! $own || $this->user()->canAny(['hr.manage', 'employees.manage'])) return [];
        $history = \App\Models\EmployeeManagerHistory::where('manager_id', $own->id)->whereNull('ends_on')->pluck('starts_on', 'employee_id');

        return $own->directReports()->pluck('id')->mapWithKeys(fn ($id) => [$id => isset($history[$id]) ? \Illuminate\Support\Carbon::parse($history[$id])->toDateString() : '1970-01-01'])->all();
    }

    protected function canManageEmployee(AttendanceEmployee $employee): bool
    {
        $own = $this->ownEmployee();

        return $this->user()->canAny(['hr.manage', 'employees.manage']) || ($own && $employee->manager_id === $own->id);
    }

    protected function toast(string $message, string $type = 'success'): void
    {
        $this->dispatch('toast', message: $message, type: $type);
    }

    public function paginationView(): string
    {
        return 'partials.pagination';
    }

    protected function page(string $view, array $data, string $title): View
    {
        return view($view, $data)->layout('layouts.app')->title($title);
    }
}
