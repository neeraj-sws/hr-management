<?php

namespace App\Livewire\Monitoring;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\Screenshot;
use App\Services\ScreenCaptureService;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Screen captures: your own, your team's (managers) or everyone's (monitoring.view). */
class Screenshots extends Component
{
    use InteractsWithHr;

    #[Url] public ?int $employee = null;
    #[Url] public string $date = '';

    public function mount(): void
    {
        $this->date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) ? $this->date : today()->toDateString();
        $allowed = $this->viewable()->pluck('id');
        if (! $this->employee || ! $allowed->contains($this->employee)) $this->employee = $allowed->first();
    }

    /** People whose captures this user may see: never themselves; HR everyone else, a manager their team. */
    private function viewable()
    {
        $own = $this->ownEmployee();
        $query = AttendanceEmployee::where('is_active', true)->whereKeyNot($own?->id ?? 0)->inCodeOrder();
        if ($this->user()->can('monitoring.view')) return $query;
        $team = $this->user()->can('monitoring.team') ? app(\App\Services\TeamMonitoringService::class)->scopeIds($this->user()) : null;

        return $query->where(fn ($q) => $q->where('manager_id', $own?->id ?? 0)->when($team, fn ($q) => $q->orWhereIn('id', $team->all() ?: [0])));
    }

    public function render(ScreenCaptureService $captures)
    {
        $person = $this->employee ? $this->viewable()->find($this->employee) : null;
        $shots = $person ? Screenshot::with('employee')->where('employee_id', $person->id)->whereDate('captured_at', $this->date)->orderBy('captured_at')->get()
            ->filter(fn ($s) => $captures->canView($this->user(), $s) && ! $captures->isExpired($s))->values() : collect();

        return $this->page('livewire.monitoring.screenshots', [
            'people' => $this->viewable()->get(['id', 'name', 'employee_code']),
            'person' => $person,
            'shots' => $shots,
            'policy' => $captures->policy($person),
        ], 'Screenshots');
    }
}
