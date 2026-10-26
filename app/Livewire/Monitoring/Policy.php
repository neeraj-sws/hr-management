<?php

namespace App\Livewire\Monitoring;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\Screenshot;
use App\Services\ScreenCaptureService;
use Livewire\Component;

/** The screen capture policy as it applies to the signed-in person: frequency, purpose, access, retention. */
class Policy extends Component
{
    use InteractsWithHr;

    public function acknowledge(ScreenCaptureService $captures): void
    {
        $captures->acknowledge($this->user());
        $this->toast('Thank you — your confirmation is recorded.');
    }

    public function render(ScreenCaptureService $captures)
    {
        $own = $this->ownEmployee();
        $policy = $captures->policy($own);

        return $this->page('livewire.monitoring.policy', [
            'own' => $own,
            'policy' => $policy,
            'terms' => $captures->describe($policy),
            'acknowledged' => $captures->isAcknowledged($this->user(), $policy),
            'acknowledgedAt' => $this->user()->screen_capture_acknowledged_at,
            'myShots' => $own ? Screenshot::where('employee_id', $own->id)->count() : 0, // shown as a number only
            // Since when, why and who — and who has looked at your screenshots / monitoring data.
            'capture' => $own ? app(\App\Services\TeamMonitoringService::class)->captureState($own) : null,
            'views' => $own ? app(\App\Services\TeamMonitoringService::class)->viewsOf($own) : collect(),
        ], 'Monitoring Policy');
    }
}
