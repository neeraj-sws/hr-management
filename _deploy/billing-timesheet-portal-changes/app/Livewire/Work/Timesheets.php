<?php

namespace App\Livewire\Work;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\Project;
use App\Models\TimesheetEntry;
use App\Models\TimesheetObjection;
use App\Services\AuditLogger;
use App\Services\TimesheetService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/** A project's monthly timesheet for the client: lines from task timers, billable minutes, objections, approval and invoice. */
class Timesheets extends Component
{
    use InteractsWithHr, WithFileUploads;

    #[Url] public ?int $project = null;
    #[Url] public string $month = '';

    public bool $showEntry = false;
    public ?int $entryId = null;
    public array $entry = [];
    public $file = null;
    public bool $showImport = false;
    public ?int $answering = null;
    public string $answer = '';
    public string $answerMinutes = '';
    public bool $showSettings = false;
    public string $rate = '';
    public bool $showDeveloper = true;
    public string $opening = '';

    private function guard(): void
    {
        abort_unless($this->user()->canAny(['projects.manage', 'hr.manage']), 403);
    }

    public function mount(): void
    {
        $this->guard();
        if (! preg_match('/^\d{4}-\d{2}$/', $this->month)) $this->month = today()->format('Y-m');
        $this->project ??= Project::whereNotNull('client_id')->orderBy('name')->value('id') ?? Project::orderBy('name')->value('id');
    }

    private function projectModel(): ?Project
    {
        return $this->project ? Project::with(['client.billingCustomer', 'manager'])->find($this->project) : null;
    }

    public function moveMonth(int $by): void
    {
        $this->month = Carbon::createFromFormat('Y-m', $this->month)->startOfMonth()->addMonths($by)->format('Y-m');
    }

    public function openEntry(?int $id = null): void
    {
        $this->guard();
        $e = $id ? TimesheetEntry::where('project_id', $this->project)->findOrFail($id) : null;
        $this->entryId = $e?->id;
        $this->entry = ['work_date' => $e?->work_date->toDateString() ?? today()->toDateString(), 'employee_id' => (string) ($e?->employee_id ?? ''), 'developer_name' => (string) $e?->developer_name,
            'start_time' => (string) ($e?->start_time ? substr($e->start_time, 0, 5) : ''), 'end_time' => (string) ($e?->end_time ? substr($e->end_time, 0, 5) : ''),
            'minutes' => (string) ($e?->minutes ?? ''), 'billable_minutes' => (string) ($e?->billable_minutes ?? ''), 'description' => (string) $e?->description,
            'status' => (string) ($e?->status ?? 'Done'), 'remarks' => (string) $e?->remarks, 'visible_to_client' => $e?->visible_to_client ?? true];
        $this->resetValidation();
        $this->showEntry = true;
    }

    public function saveEntry(TimesheetService $timesheets, AuditLogger $audit): void
    {
        $this->guard();
        $project = $this->projectModel();
        $data = $this->validate([
            'entry.work_date' => ['required', 'date'], 'entry.employee_id' => ['nullable', 'exists:attendance_employees,id'], 'entry.developer_name' => ['nullable', 'string', 'max:191'],
            'entry.start_time' => ['nullable', 'date_format:H:i'], 'entry.end_time' => ['nullable', 'date_format:H:i'],
            'entry.minutes' => ['required', 'integer', 'min:0', 'max:1440'], 'entry.billable_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'entry.description' => ['required', 'string', 'max:5000'], 'entry.status' => ['nullable', 'string', 'max:40'], 'entry.remarks' => ['nullable', 'string', 'max:2000'],
        ], [], ['entry.work_date' => 'date', 'entry.minutes' => 'minutes', 'entry.billable_minutes' => 'billable minutes', 'entry.description' => 'task'])['entry'];
        if ($timesheets->isLocked($project, Carbon::parse($data['work_date'])->format('Y-m'))) { $this->addError('entry.work_date', 'That month is already approved by the client.'); return; }
        $values = ['project_id' => $project->id, 'work_date' => $data['work_date'], 'employee_id' => $data['employee_id'] ?: null, 'developer_name' => $data['employee_id'] ? null : ($data['developer_name'] ?: null),
            'start_time' => $data['start_time'] ?: null, 'end_time' => $data['end_time'] ?: null, 'minutes' => (int) $data['minutes'],
            'billable_minutes' => $data['billable_minutes'] === '' || $data['billable_minutes'] === null ? (int) $data['minutes'] : (int) $data['billable_minutes'],
            'description' => $data['description'], 'status' => $data['status'] ?: null, 'remarks' => $data['remarks'] ?: null, 'visible_to_client' => (bool) $this->entry['visible_to_client']];
        $line = $this->entryId ? tap(TimesheetEntry::where('project_id', $project->id)->findOrFail($this->entryId))->update($values) : TimesheetEntry::create($values + ['source' => 'manual', 'created_by' => auth()->id()]);
        $audit->record($this->entryId ? 'timesheet.entry_updated' : 'timesheet.entry_added', $line, ['billable_minutes' => $values['billable_minutes']]);
        $this->showEntry = false;
        $this->toast('Timesheet line saved.');
    }

    /** Quick change of the minutes charged to the client, right in the table. */
    public function setBillable(int $id, string $minutes, TimesheetService $timesheets, AuditLogger $audit): void
    {
        $this->guard();
        $line = TimesheetEntry::where('project_id', $this->project)->findOrFail($id);
        if ($timesheets->isLocked($line->project, $line->work_date->format('Y-m'))) { $this->toast('This month is approved; lines are locked.'); return; }
        $value = max(0, min(1440, (int) $minutes));
        $line->update(['billable_minutes' => $value]);
        $audit->record('timesheet.billable_changed', $line, ['billable_minutes' => $value]);
    }

    public function toggleVisible(int $id): void
    {
        $this->guard();
        $line = TimesheetEntry::where('project_id', $this->project)->findOrFail($id);
        $line->update(['visible_to_client' => ! $line->visible_to_client]);
    }

    public function deleteEntry(int $id, TimesheetService $timesheets, AuditLogger $audit): void
    {
        $this->guard();
        $line = TimesheetEntry::where('project_id', $this->project)->findOrFail($id);
        if ($timesheets->isLocked($line->project, $line->work_date->format('Y-m'))) { $this->toast('This month is approved; lines are locked.'); return; }
        $audit->record('timesheet.entry_deleted', $line, ['minutes' => $line->minutes, 'description' => $line->description]);
        $line->delete();
        $this->toast('Line removed.');
    }

    public function import(TimesheetService $timesheets): void
    {
        $this->guard();
        $this->validate(['file' => ['required', 'file', 'max:5120', 'extensions:xlsx']], ['file.extensions' => 'Upload the Excel timesheet (.xlsx).']);
        $result = $timesheets->import($this->projectModel(), $this->file, auth()->id());
        $this->reset('file');
        $this->showImport = false;
        if ($result['months']) $this->month = end($result['months']);
        session()->flash('timesheet_import', $result);
        $this->toast("{$result['created']} lines imported".($result['kept'] ? ", {$result['kept']} were already there" : '').'.');
    }

    public function openAnswer(int $objectionId): void
    {
        $o = TimesheetObjection::with('entry')->findOrFail($objectionId);
        $this->answering = $o->id;
        $this->answer = '';
        $this->answerMinutes = (string) ($o->proposed_minutes ?? $o->entry->billable_minutes);
        $this->resetValidation();
    }

    public function respond(bool $accept, TimesheetService $timesheets): void
    {
        $this->guard();
        $this->validate(['answer' => ['required', 'string', 'min:2', 'max:2000'], 'answerMinutes' => [$accept ? 'required' : 'nullable', 'nullable', 'integer', 'min:0', 'max:1440']], [], ['answer' => 'answer', 'answerMinutes' => 'billable minutes']);
        $timesheets->respond(TimesheetObjection::findOrFail($this->answering), $this->user(), $accept, $this->answer, $accept ? (int) $this->answerMinutes : null);
        $this->answering = null;
        $this->toast($accept ? 'Objection accepted — the client is told.' : 'Objection answered — the client is told.');
    }

    public function openSettings(TimesheetService $timesheets): void
    {
        $this->guard();
        $p = $this->projectModel();
        $this->rate = (string) ($p->hourly_rate !== null ? (float) $p->hourly_rate : '');
        $this->showDeveloper = (bool) $p->client_sees_developer;
        $this->opening = (string) $timesheets->month($p, $this->month)->opening_remaining_minutes;
        $this->showSettings = true;
    }

    public function saveSettings(TimesheetService $timesheets, AuditLogger $audit): void
    {
        $this->guard();
        $this->validate(['rate' => ['nullable', 'numeric', 'min:0', 'max:1000000'], 'opening' => ['nullable', 'integer', 'min:-100000', 'max:100000']], [], ['rate' => 'hourly rate', 'opening' => 'carried-in minutes']);
        $p = $this->projectModel();
        $p->update(['hourly_rate' => $this->rate === '' ? null : $this->rate, 'client_sees_developer' => $this->showDeveloper]);
        if (! $timesheets->isLocked($p, $this->month)) $timesheets->month($p, $this->month)->update(['opening_remaining_minutes' => (int) $this->opening]);
        $audit->record('timesheet.settings_saved', $p, ['hourly_rate' => $this->rate, 'month' => $this->month, 'opening_minutes' => (int) $this->opening]);
        $this->showSettings = false;
        $this->toast('Project billing settings saved.');
    }

    public function createInvoice(TimesheetService $timesheets): void
    {
        $this->guard();
        abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403);
        $invoice = $timesheets->createInvoice($this->projectModel(), $this->month, $this->user());
        $this->redirect(route('billing.invoices.show', $invoice));
    }

    public function closeModal(): void
    {
        $this->showEntry = $this->showImport = $this->showSettings = false;
        $this->answering = null;
    }

    public function render(TimesheetService $timesheets)
    {
        $this->guard();
        $project = $this->projectModel();
        if ($project) $timesheets->syncFromTimers($project, $this->month);

        return $this->page('livewire.work.timesheets', [
            'projects' => Project::with('client:id,name')->orderByRaw('client_id IS NULL')->orderBy('name')->get(['id', 'name', 'client_id']),
            'projectModel' => $project,
            'entries' => $project ? $timesheets->entries($project, $this->month) : collect(),
            'summary' => $project ? $timesheets->summary($project, $this->month) : null,
            'employees' => AttendanceEmployee::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'answeringObjection' => $this->answering ? TimesheetObjection::with(['entry', 'raiser'])->find($this->answering) : null,
            'canInvoice' => $this->user()->canAny(['hr.manage', 'payroll.manage']),
        ], 'Timesheets');
    }
}
