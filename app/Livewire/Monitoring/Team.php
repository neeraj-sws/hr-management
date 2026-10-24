<?php

namespace App\Livewire\Monitoring;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\Department;
use App\Services\TeamMonitoringService;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Monitoring > Team monitoring: live work status of the people the viewer leads (HR / Admin: everyone).
 * Refreshes every 60 seconds. Opening the page is logged.
 */
class Team extends Component
{
    use InteractsWithHr;

    #[Url(as: 'q')] public string $search = '';
    #[Url] public string $status = '';
    #[Url] public ?int $department = null;
    #[Url] public ?int $manager = null;
    #[Url] public string $date = '';

    public function mount(TeamMonitoringService $monitoring): void
    {
        abort_unless($monitoring->canUse($this->user()), 403);
        if (! strtotime($this->date) || $this->date > today()->toDateString()) $this->date = today()->toDateString();
        $this->audit()->record('monitoring.team_viewed', null, ['date' => $this->date]);
    }

    private function audit(): \App\Services\AuditLogger
    {
        return app(\App\Services\AuditLogger::class);
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'status', 'department', 'manager');
        $this->date = today()->toDateString();
    }

    public function render(TeamMonitoringService $monitoring)
    {
        $user = $this->user();
        $scope = $monitoring->scopeIds($user);
        $orgWide = $scope === null;
        $term = trim($this->search);
        $people = AttendanceEmployee::with(['department', 'manager', 'user.roles'])->where('is_active', true)
            ->when(! $orgWide, fn ($q) => $q->whereIn('id', $scope->all() ?: [0]))
            ->when($orgWide && $this->department, fn ($q, $id) => $q->where('department_id', $this->department))
            ->when($orgWide && $this->manager, fn ($q) => $q->where('manager_id', $this->manager))
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('employee_code', 'like', "%{$term}%")))
            ->inCodeOrder()->limit(300)->get();
        $rows = $monitoring->rows($people, $this->date);
        $all = collect($rows);
        $counts = [
            'working' => $all->where('status', 'working')->count() + $all->where('status', 'meeting')->count(),
            'break' => $all->where('status', 'break')->count(),
            'idle' => $all->where('status', 'idle')->count(),
            'not_in' => $all->where('status', 'not_in')->count(),
            'capture' => $all->where('capture', true)->count(),
        ];
        $shown = $all->when($this->status !== '', fn ($c) => $c->filter(fn ($r) => $this->status === 'working' ? in_array($r['status'], ['working', 'meeting'], true) : $r['status'] === $this->status));

        return $this->page('livewire.monitoring.team', [
            'rows' => $shown->values(),
            'counts' => $counts,
            'orgWide' => $orgWide,
            'isToday' => $this->date === today()->toDateString(),
            'departments' => $orgWide ? Department::orderBy('name')->get(['id', 'name']) : collect(),
            'managers' => $orgWide ? AttendanceEmployee::whereIn('id', AttendanceEmployee::whereNotNull('manager_id')->distinct()->pluck('manager_id'))->orderBy('name')->get(['id', 'name']) : collect(),
        ], 'Team monitoring');
    }
}
