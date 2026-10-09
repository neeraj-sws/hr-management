<?php

namespace App\Livewire;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\Project;
use App\Models\Task;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Global search across records the user is allowed to see. */
class Search extends Component
{
    use InteractsWithHr;

    #[Url]
    public string $q = '';

    public function render()
    {
        $user = $this->user();
        $term = trim($this->q);
        $orgWide = $user->canAny(['hr.manage', 'employees.manage']);
        $scope = $this->teamEmployeeIds();
        $employees = $tasks = $projects = collect();

        if (mb_strlen($term) >= 2) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
            $employees = AttendanceEmployee::with('department')
                ->when(! $orgWide, fn ($q) => $q->whereIn('id', $scope->all() ?: [0]))
                ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('employee_code', 'like', $like)->orWhere('email', 'like', $like)->orWhere('designation', 'like', $like))
                ->orderBy('name')->limit(20)->get();
            $tasks = Task::with('assignee')
                ->when(! $orgWide, fn ($q) => $q->whereIn('assignee_id', $scope->all() ?: [0]))
                ->where('title', 'like', $like)->latest()->limit(20)->get();
            $projects = $user->canAny(['projects.manage', 'hr.manage']) ? Project::where('name', 'like', $like)->limit(10)->get() : collect();
        }

        return $this->page('livewire.search', compact('employees', 'tasks', 'projects', 'term', 'orgWide'), 'Search');
    }
}
