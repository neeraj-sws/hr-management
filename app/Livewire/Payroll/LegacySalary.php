<?php

namespace App\Livewire\Payroll;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\LegacySalaryRecord;
use App\Services\LegacySalaryImport;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Month-wise salary history imported from the old Excel salary sheet (read-only, stored as written). */
class LegacySalary extends Component
{
    use InteractsWithHr, WithPagination;

    #[Url] public string $month = '';
    #[Url] public string $search = '';
    #[Url] public string $status = '';
    #[Url] public ?int $employee = null;
    /** '' any | taken (any leave) | without_application | short | paid | none */
    #[Url] public string $leave = '';
    public ?int $openId = null;

    public function updated($property): void
    {
        if (in_array($property, ['month', 'search', 'status', 'employee', 'leave'], true)) $this->resetPage();
    }

    public function open(int $id): void
    {
        $this->openId = $id;
    }

    public function closeModal(): void
    {
        $this->openId = null;
    }

    public function render()
    {
        abort_unless($this->user()->canAny(['payroll.manage', 'hr.manage']), 403);
        $term = trim($this->search);
        $query = LegacySalaryRecord::query()
            ->when($this->month, fn ($q, $m) => $q->where('payroll_month', $m))
            ->when($this->status, fn ($q, $s) => $q->where('payment_status', $s))
            ->when($this->employee, fn ($q, $id) => $q->where('employee_id', $id))
            ->when($this->leave === 'taken', fn ($q) => $q->where(fn ($q) => $q->where('leave_days', '>', 0)->orWhere('paid_leave', '>', 0)->orWhere('without_application_leave', '>', 0)->orWhere('without_application_short_leave', '>', 0)))
            ->when($this->leave === 'without_application', fn ($q) => $q->where('without_application_leave', '>', 0))
            ->when($this->leave === 'short', fn ($q) => $q->where(fn ($q) => $q->where('without_application_short_leave', '>', 0)->orWhere('short_leave_minutes', '>', 0)))
            ->when($this->leave === 'paid', fn ($q) => $q->where('paid_leave', '>', 0))
            ->when($this->leave === 'none', fn ($q) => $q->where(fn ($q) => $q->whereNull('leave_days')->orWhere('leave_days', 0))->where(fn ($q) => $q->whereNull('without_application_leave')->orWhere('without_application_leave', 0)))
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->where('employee_code', 'like', "%{$term}%")->orWhere('name', 'like', "%{$term}%")));

        return $this->page('livewire.payroll.legacy-salary', [
            'records' => (clone $query)->with('employee')->orderByDesc('payroll_month')->orderBy('source_row')->orderBy('id')->paginate(25),
            'totals' => ['leave_days' => (float) (clone $query)->sum('leave_days'), 'without_application' => (float) (clone $query)->sum('without_application_leave'), 'rows' => (clone $query)->count(), 'paid' => (float) (clone $query)->sum('salary_paid'), 'remains' => (float) (clone $query)->sum('salary_remains'), 'unlinked' => (clone $query)->whereNull('employee_id')->count()],
            'months' => LegacySalaryRecord::distinct()->orderByDesc('payroll_month')->pluck('payroll_month'),
            'statuses' => LegacySalaryRecord::whereNotNull('payment_status')->distinct()->orderBy('payment_status')->pluck('payment_status'),
            'people' => AttendanceEmployee::whereIn('id', LegacySalaryRecord::whereNotNull('employee_id')->distinct()->pluck('employee_id'))->orderBy('name')->get(['id', 'name', 'employee_code']),
            'columns' => LegacySalaryImport::COLUMNS,
            'opened' => $this->openId ? LegacySalaryRecord::with(['employee', 'importer'])->find($this->openId) : null,
        ], 'Legacy Salary');
    }
}
