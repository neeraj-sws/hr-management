<?php

namespace App\Livewire\Client;

use App\Models\Project;
use App\Models\TimesheetEntry;
use App\Services\TimesheetService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;

/** The client's view of a project's monthly timesheet: object to a line, see the answer, approve the month. */
class Timesheet extends Component
{
    #[Url] public ?int $project = null;
    #[Url] public string $month = '';

    public ?int $objecting = null;
    public string $reason = '';
    public string $proposed = '';

    private function projects()
    {
        return Project::where('client_id', auth()->user()->client_id)->orderBy('name');
    }

    public function mount(): void
    {
        abort_unless(auth()->user()?->client_id, 403);
        if (! preg_match('/^\d{4}-\d{2}$/', $this->month)) $this->month = today()->format('Y-m');
        if (! $this->project || ! $this->projects()->whereKey($this->project)->exists()) $this->project = $this->projects()->value('id');
    }

    private function projectModel(): ?Project
    {
        return $this->project ? $this->projects()->find($this->project) : null;
    }

    public function moveMonth(int $by): void
    {
        $this->month = Carbon::createFromFormat('Y-m', $this->month)->startOfMonth()->addMonths($by)->format('Y-m');
    }

    public function openObjection(int $entryId): void
    {
        $entry = TimesheetEntry::findOrFail($entryId);
        app(TimesheetService::class)->assertClientCanSee(auth()->user(), $entry);
        $this->objecting = $entry->id;
        $this->reason = '';
        $this->proposed = '';
        $this->resetValidation();
    }

    public function object(TimesheetService $timesheets): void
    {
        $this->validate(['reason' => ['required', 'string', 'min:5', 'max:2000'], 'proposed' => ['nullable', 'integer', 'min:0', 'max:1440']],
            ['reason.required' => 'Say what is wrong with this line.'], ['proposed' => 'minutes']);
        $timesheets->object(TimesheetEntry::findOrFail($this->objecting), auth()->user(), $this->reason, $this->proposed === '' ? null : (int) $this->proposed);
        $this->objecting = null;
        $this->dispatch('toast', message: 'Objection sent. You will see the answer here.', type: 'success');
    }

    public function approve(TimesheetService $timesheets): void
    {
        $timesheets->approveMonth($this->projectModel(), $this->month, auth()->user());
        $this->dispatch('toast', message: 'Thank you — the month is approved.', type: 'success');
    }

    public function render(TimesheetService $timesheets)
    {
        $project = $this->projectModel();

        return view('livewire.client.timesheet', [
            'projects' => $this->projects()->get(['id', 'name']),
            'projectModel' => $project,
            'entries' => $project ? $timesheets->entries($project, $this->month, true) : collect(),
            'summary' => $project ? $timesheets->summary($project, $this->month, true) : null,
            'objectingEntry' => $this->objecting ? TimesheetEntry::find($this->objecting) : null,
        ])->layout('layouts.client')->title('Timesheet');
    }
}
