<?php

namespace App\Livewire\Work;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\Project;
use App\Models\TimesheetEntry;
use App\Models\TimesheetObjection;
use App\Services\AuditLogger;
use App\Services\PolicyConfigurationService;
use App\Services\TimesheetService;
use App\Support\WorkCalendar;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/** A project's monthly timesheet for the client: lines from task timers, billable minutes, objections, approval and invoice. */
class Timesheets extends Component
{
    use InteractsWithHr, WithFileUploads;

    /** "My Timesheet": an employee adds and edits only their own lines on the projects they work on. */
    #[Locked] public bool $mine = false;
    #[Url] public ?int $project = null;
    #[Url] public string $month = '';
    /** My Timesheet: "mine" (own lines) or "team" (a manager sees their team's lines, read-only). */
    #[Url] public string $scope = 'mine';
    #[Url] public ?int $member = null;

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

    /** Who may open the page: managers/HR, or (My Timesheet) any employee. */
    private function guard(): void
    {
        if ($this->mine) { abort_unless($this->ownEmployee(), 403); return; }
        abort_unless($this->user()->canAny(['projects.manage', 'hr.manage']), 403);
    }

    /** Rates, billable minutes, client visibility, import, objections and invoices stay with managers/HR. */
    private function managerGuard(): void
    {
        abort_if($this->mine, 403);
        $this->guard();
    }

    public function mount(bool $mine = false): void
    {
        $this->mine = $mine || request()->routeIs('work.my-timesheet');
        $this->guard();
        if (! preg_match('/^\d{4}-\d{2}$/', $this->month)) $this->month = today()->format('Y-m');
        if ($this->mine) {
            if (! $this->teamView()) $this->scope = 'mine';
            if ($this->project && ! $this->myProjects()->whereKey($this->project)->exists()) $this->project = null;
            $this->project ??= $this->myProjects()->orderByRaw('client_id IS NULL')->orderBy('name')->value('id');

            return;
        }
        $this->project ??= Project::whereNotNull('client_id')->orderBy('name')->value('id') ?? Project::orderBy('name')->value('id');
    }

    /** Projects the employee works on (manager, member, or has a task on it); in the team view, also their team's. */
    private function myProjects()
    {
        $own = $this->ownEmployee();
        if (! $own) return Project::query()->whereRaw('1 = 0');

        return $this->teamView()
            ? app(TimesheetService::class)->projectsOf($this->team()->pluck('id')->push($own->id)->all())
            : app(TimesheetService::class)->employeeProjects($own);
    }

    private ?\Illuminate\Support\Collection $teamCache = null;

    /** The manager's team (people reporting to them and members of the teams they lead). */
    private function team(): \Illuminate\Support\Collection
    {
        return $this->teamCache ??= $this->mine && $this->ownEmployee() ? app(TimesheetService::class)->teamOf($this->ownEmployee()) : collect();
    }

    private function teamView(): bool
    {
        return $this->mine && $this->scope === 'team' && $this->team()->isNotEmpty();
    }

    public function updatedScope(): void
    {
        if (! in_array($this->scope, ['mine', 'team'], true) || ! $this->teamView()) $this->scope = 'mine';
        $this->member = null;
        if (! $this->project || ! $this->myProjects()->whereKey($this->project)->exists()) {
            $this->project = $this->myProjects()->orderByRaw('client_id IS NULL')->orderBy('name')->value('id');
        }
    }

    private function projectModel(): ?Project
    {
        if (! $this->project) return null;
        $query = $this->mine ? $this->myProjects() : Project::query();

        return $query->with(['client.billingCustomer', 'manager'])->find($this->project);
    }

    /** A line on the current project; in My Timesheet only the employee's own lines they added themselves. */
    private function line(int $id): TimesheetEntry
    {
        abort_unless($this->projectModel(), 404);
        $line = TimesheetEntry::where('project_id', $this->project)->findOrFail($id);
        if ($this->mine) abort_unless($line->employee_id === $this->ownEmployee()->id && $line->source === 'manual', 403);

        return $line;
    }

    public function moveMonth(int $by): void
    {
        $this->month = Carbon::createFromFormat('Y-m', $this->month)->startOfMonth()->addMonths($by)->format('Y-m');
    }

    public function openEntry(?int $id = null): void
    {
        $this->guard();
        abort_unless($this->projectModel(), 404);
        $e = $id ? $this->line($id) : null;
        $this->entryId = $e?->id;
        $this->entry = ['work_date' => $e?->work_date->toDateString() ?? today()->toDateString(), 'employee_id' => (string) ($e?->employee_id ?? ($this->mine ? $this->ownEmployee()->id : '')), 'developer_name' => (string) $e?->developer_name,
            'start_time' => (string) ($e?->start_time ? substr($e->start_time, 0, 5) : ''), 'end_time' => (string) ($e?->end_time ? substr($e->end_time, 0, 5) : ''),
            'minutes' => (string) ($e?->minutes ?? ''), 'billable_minutes' => (string) ($e?->billable_minutes ?? ''), 'description' => (string) $e?->description,
            'status' => (string) ($e ? $e->status : 'Done'), 'stage' => (string) $e?->stage, 'remarks' => (string) $e?->remarks, 'visible_to_client' => $e?->visible_to_client ?? true];
        $this->resetValidation();
        $this->showEntry = true;
    }

    /** The working-days calendar for a date: the entry's employee if set, otherwise the company default. */
    private function workCalendar(string $date): array
    {
        $employee = ! empty($this->entry['employee_id']) ? AttendanceEmployee::find($this->entry['employee_id']) : null;

        return app(PolicyConfigurationService::class)->forEmployee($employee, 'calendar.working_days', Carbon::parse($date)->toDateString(), ['days' => [1, 2, 3, 4, 5], 'holidays' => []]);
    }

    /** Live validation/aid as the entry form changes. */
    public function updatedEntry($value, $key): void
    {
        // Non-working days (weekly off / holiday, per the company calendar) are not allowed — flag immediately.
        if ($key === 'work_date') {
            $this->resetValidation('entry.work_date');
            if (! empty($this->entry['work_date'])) {
                $cal = $this->workCalendar($this->entry['work_date']);
                if (! WorkCalendar::isWorkday($this->entry['work_date'], $cal)) {
                    $this->addError('entry.work_date', 'Ye din off hai ('.WorkCalendar::offDayReason($this->entry['work_date'], $cal).') — timesheet line add nahi kar sakte.');
                }
            }

            return;
        }
        // A typed name that is exactly an HRMS employee picks that developer too (when none is chosen yet).
        if ($key === 'developer_name') {
            $name = trim((string) $value);
            if ($name !== '' && empty($this->entry['employee_id'])) {
                $match = AttendanceEmployee::where('is_active', true)->where('name', $name)->value('id');
                if ($match) $this->entry['employee_id'] = (string) $match;
            }

            return;
        }
        // Fill worked-minutes from start/end as soon as both are set (overnight shifts wrap past midnight).
        if ($key !== 'start_time' && $key !== 'end_time') return;
        $start = $this->entry['start_time'] ?? '';
        $end = $this->entry['end_time'] ?? '';
        if (! preg_match('/^\d{2}:\d{2}$/', $start) || ! preg_match('/^\d{2}:\d{2}$/', $end)) return;
        $s = Carbon::createFromFormat('H:i', $start);
        $e = Carbon::createFromFormat('H:i', $end);
        if ($e->lessThanOrEqualTo($s)) $e->addDay();
        $this->entry['minutes'] = (string) $s->diffInMinutes($e);
    }

    public function saveEntry(TimesheetService $timesheets, AuditLogger $audit): void
    {
        $this->guard();
        $project = $this->projectModel();
        abort_unless($project, 404);
        if ($this->mine) {
            $existing = $this->entryId ? $this->line($this->entryId) : null;
            $this->entry['employee_id'] = (string) $this->ownEmployee()->id;
            $this->entry['developer_name'] = '';
            $this->entry['visible_to_client'] = $existing?->visible_to_client ?? true;
            // Billable follows the worked minutes unless a manager already changed it.
            $this->entry['billable_minutes'] = $existing && $existing->billable_minutes !== $existing->minutes ? (string) $existing->billable_minutes : '';
        }
        $data = $this->validate([
            'entry.work_date' => ['required', 'date'], 'entry.employee_id' => ['nullable', 'exists:attendance_employees,id'], 'entry.developer_name' => ['nullable', 'string', 'max:191'],
            'entry.start_time' => ['nullable', 'date_format:H:i'], 'entry.end_time' => ['nullable', 'date_format:H:i'],
            'entry.minutes' => ['required', 'integer', 'min:0', 'max:1440'], 'entry.billable_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'entry.description' => ['required', 'string', 'max:5000'], 'entry.status' => ['nullable', 'string', 'max:40'], 'entry.stage' => ['nullable', 'string', 'max:40'], 'entry.remarks' => ['nullable', 'string', 'max:2000'],
        ], [], ['entry.work_date' => 'date', 'entry.minutes' => 'minutes', 'entry.billable_minutes' => 'billable minutes', 'entry.description' => 'task'])['entry'];
        $cal = $this->workCalendar($data['work_date']);
        if (! WorkCalendar::isWorkday($data['work_date'], $cal)) { $this->addError('entry.work_date', 'Ye din off hai ('.WorkCalendar::offDayReason($data['work_date'], $cal).') — timesheet line add nahi kar sakte.'); return; }
        if ($this->mine && Carbon::parse($data['work_date'])->isAfter(today())) { $this->addError('entry.work_date', 'Aage ki date ka timesheet nahi bhar sakte.'); return; }
        if ($timesheets->isLocked($project, Carbon::parse($data['work_date'])->format('Y-m'))) { $this->addError('entry.work_date', 'That month is already approved by the client.'); return; }
        $values = ['project_id' => $project->id, 'work_date' => $data['work_date'], 'employee_id' => $data['employee_id'] ?: null, 'developer_name' => trim((string) $data['developer_name']) ?: null,
            'start_time' => $data['start_time'] ?: null, 'end_time' => $data['end_time'] ?: null, 'minutes' => (int) $data['minutes'],
            'billable_minutes' => $data['billable_minutes'] === '' || $data['billable_minutes'] === null ? (int) $data['minutes'] : (int) $data['billable_minutes'],
            'description' => $data['description'], 'status' => $data['status'] ?: null, 'stage' => ($data['stage'] ?? null) ?: null, 'remarks' => $data['remarks'] ?: null, 'visible_to_client' => (bool) $this->entry['visible_to_client']];
        $line = $this->entryId ? tap($this->line($this->entryId))->update($values) : TimesheetEntry::create($values + ['source' => 'manual', 'created_by' => auth()->id()]);
        $audit->record($this->entryId ? 'timesheet.entry_updated' : 'timesheet.entry_added', $line, ['billable_minutes' => $values['billable_minutes']]);
        $this->showEntry = false;
        $this->toast('Timesheet line saved.');
    }

    /** Quick change of the minutes charged to the client, right in the table. */
    public function setBillable(int $id, string $minutes, TimesheetService $timesheets, AuditLogger $audit): void
    {
        $this->managerGuard();
        $line = TimesheetEntry::where('project_id', $this->project)->findOrFail($id);
        if ($timesheets->isLocked($line->project, $line->work_date->format('Y-m'))) { $this->toast('This month is approved; lines are locked.'); return; }
        $value = max(0, min(1440, (int) $minutes));
        $line->update(['billable_minutes' => $value]);
        $audit->record('timesheet.billable_changed', $line, ['billable_minutes' => $value]);
    }

    public function toggleVisible(int $id): void
    {
        $this->managerGuard();
        $line = TimesheetEntry::where('project_id', $this->project)->findOrFail($id);
        $line->update(['visible_to_client' => ! $line->visible_to_client]);
    }

    public function deleteEntry(int $id, TimesheetService $timesheets, AuditLogger $audit): void
    {
        $this->guard();
        $line = $this->line($id);
        if ($timesheets->isLocked($line->project, $line->work_date->format('Y-m'))) { $this->toast('This month is approved; lines are locked.'); return; }
        $audit->record('timesheet.entry_deleted', $line, ['minutes' => $line->minutes, 'description' => $line->description]);
        $line->delete();
        $this->toast('Line removed.');
    }

    public function import(TimesheetService $timesheets): void
    {
        $this->managerGuard();
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
        $this->managerGuard();
        $o = TimesheetObjection::with('entry')->findOrFail($objectionId);
        $this->answering = $o->id;
        $this->answer = '';
        $this->answerMinutes = (string) ($o->proposed_minutes ?? $o->entry->billable_minutes);
        $this->resetValidation();
    }

    public function respond(bool $accept, TimesheetService $timesheets): void
    {
        $this->managerGuard();
        $this->validate(['answer' => ['required', 'string', 'min:2', 'max:2000'], 'answerMinutes' => [$accept ? 'required' : 'nullable', 'nullable', 'integer', 'min:0', 'max:1440']], [], ['answer' => 'answer', 'answerMinutes' => 'billable minutes']);
        $timesheets->respond(TimesheetObjection::findOrFail($this->answering), $this->user(), $accept, $this->answer, $accept ? (int) $this->answerMinutes : null);
        $this->answering = null;
        $this->toast($accept ? 'Objection accepted — the client is told.' : 'Objection answered — the client is told.');
    }

    public function openSettings(TimesheetService $timesheets): void
    {
        $this->managerGuard();
        $p = $this->projectModel();
        $this->rate = (string) ($p->hourly_rate !== null ? (float) $p->hourly_rate : '');
        $this->showDeveloper = (bool) $p->client_sees_developer;
        $this->opening = (string) $timesheets->month($p, $this->month)->opening_remaining_minutes;
        $this->showSettings = true;
    }

    public function saveSettings(TimesheetService $timesheets, AuditLogger $audit): void
    {
        $this->managerGuard();
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
        $this->managerGuard();
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
        $entries = $project ? $timesheets->entries($project, $this->month) : collect();
        $team = $this->teamView() ? $this->team() : collect();
        if ($team->isNotEmpty()) {
            $people = $this->member ? $team->where('id', $this->member) : $team->concat([$this->ownEmployee()]);
            $entries = $entries->filter(fn ($e) => $timesheets->lineOfAny($e, $people))->values();
        } elseif ($this->mine) {
            $entries = $entries->where('employee_id', $this->ownEmployee()->id)->values();
        }

        return $this->page('livewire.work.timesheets', [
            'projects' => ($this->mine ? $this->myProjects() : Project::query())->with('client:id,name')->orderByRaw('client_id IS NULL')->orderBy('name')->get(['id', 'name', 'client_id']),
            'projectModel' => $project,
            'entries' => $entries,
            'summary' => $project ? $timesheets->summary($project, $this->month) : null,
            'employees' => AttendanceEmployee::with('teams:id,name')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'answeringObjection' => $this->answering ? TimesheetObjection::with(['entry', 'raiser'])->find($this->answering) : null,
            'canInvoice' => ! $this->mine && $this->user()->canAny(['hr.manage', 'payroll.manage']),
            'ownId' => $this->mine ? $this->ownEmployee()->id : null,
            'team' => $this->team(),
            'teamView' => $team->isNotEmpty(),
        ], $this->mine ? 'My Timesheet' : 'Timesheets');
    }
}
