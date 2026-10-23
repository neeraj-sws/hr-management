<?php

namespace App\Livewire\Performance;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\Department;
use App\Services\WorkService;
use Livewire\Attributes\Url;
use Livewire\Component;

class Productivity extends Component
{
    use InteractsWithHr, \Livewire\WithPagination;

    #[Url] public string $from = '';
    #[Url] public string $to = '';
    #[Url] public ?int $department = null;

    public function updated($property): void
    {
        if (in_array($property, ['from', 'to', 'department'], true)) $this->resetPage();
    }

    public function mount(): void
    {
        $this->from = strtotime($this->from) ? $this->from : now()->startOfMonth()->toDateString();
        $this->to = strtotime($this->to) ? $this->to : today()->toDateString();
    }

    public function render(WorkService $work)
    {
        if ($this->to < $this->from) $this->to = $this->from;
        $employees = AttendanceEmployee::where('is_active', true)
            ->when(! $this->user()->canAny(['hr.manage', 'employees.manage']), fn ($q) => $q->whereIn('id', $this->teamEmployeeIds()->all() ?: [0]))
            ->when($this->department, fn ($q, $id) => $q->where('department_id', $id))
            ->inCodeOrder()->get();
        $rows = $work->productivity($employees, $this->from, $this->to);

        return $this->page('livewire.performance.productivity', [
            'rows' => $this->paginateCollection($rows, 20),
            'departments' => Department::orderBy('name')->get(),
            'totals' => ['completed' => $rows->sum('completed_tasks'), 'late' => $rows->sum('completed_late'), 'overdue' => $rows->sum('overdue_tasks'), 'logged' => $rows->sum('logged_seconds'), 'active' => $rows->sum('active_seconds')],
        ], 'Productivity');
    }
}
