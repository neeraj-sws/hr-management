<?php

namespace App\Livewire\Client;

use App\Models\Project;
use App\Services\TimesheetService;
use Livewire\Component;

/** Client portal home: the client's projects with this month's hours and anything waiting for them. */
class Dashboard extends Component
{
    public function render(TimesheetService $timesheets)
    {
        $user = auth()->user();
        abort_unless($user?->client_id, 403);
        $month = today()->format('Y-m');
        $projects = Project::where('client_id', $user->client_id)->orderBy('name')->get()
            ->map(fn ($p) => ['project' => $p, 'now' => $timesheets->summary($p, $month, true), 'last' => $timesheets->summary($p, today()->subMonthNoOverflow()->format('Y-m'), true)]);

        return view('livewire.client.dashboard', ['projects' => $projects, 'month' => $month])->layout('layouts.client')->title('Projects');
    }
}
