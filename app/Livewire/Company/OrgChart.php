<?php

namespace App\Livewire\Company;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\Department;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Reporting lines built from each employee's manager. */
class OrgChart extends Component
{
    use InteractsWithHr;

    #[Url] public string $department = '';
    #[Url(as: 'q')] public string $search = '';

    public function render()
    {
        $employees = AttendanceEmployee::with('department')->where('is_active', true)->inCodeOrder()->get();
        $byManager = $employees->groupBy(fn ($e) => $employees->contains('id', $e->manager_id) ? $e->manager_id : 0);
        $roots = $byManager->get(0, collect());
        if ($this->department) {
            // Show each person in the department with their reports, starting from the top-most person in it.
            $inDepartment = $employees->where('department_id', (int) $this->department);
            $roots = $inDepartment->filter(fn ($e) => ! $inDepartment->contains('id', $e->manager_id))->values();
        }
        $term = trim(mb_strtolower($this->search));
        $matches = $term === '' ? collect() : $employees->filter(fn ($e) => str_contains(mb_strtolower($e->name.' '.$e->designation.' '.$e->employee_code), $term))->pluck('id');

        return $this->page('livewire.company.org-chart', [
            'roots' => $roots,
            'children' => $byManager,
            'matches' => $matches,
            'own' => $this->ownEmployee(),
            'total' => $employees->count(),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'countReports' => fn (int $id) => $this->countReports($byManager, $id),
        ], 'Org Chart');
    }

    private function countReports(Collection $byManager, int $id, int $depth = 0): int
    {
        if ($depth > 50) return 0;
        $direct = $byManager->get($id, collect());

        return $direct->count() + $direct->sum(fn ($e) => $this->countReports($byManager, $e->id, $depth + 1));
    }
}
