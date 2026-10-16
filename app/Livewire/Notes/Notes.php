<?php

namespace App\Livewire\Notes;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\EmployeeNote;
use App\Models\Team;
use App\Services\AuditLogger;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Visibility: personal (author only), manager (manager ↔ one direct report),
 * team (members of a team). "My Notes" and "Team Notes" share this page.
 */
class Notes extends Component
{
    use InteractsWithHr, \Livewire\WithPagination;

    #[Locked] public bool $teamMode = false;
    #[Url] public string $search = '';

    public bool $showForm = false;
    public ?int $editingId = null;
    public string $visibility = 'personal';
    public ?int $employeeId = null;
    public ?int $teamId = null;
    public string $title = '';
    public string $body = '';

    public function mount(): void
    {
        $this->teamMode = request()->routeIs('notes.team');
    }

    public function create(): void
    {
        $this->resetForm();
        $this->visibility = $this->teamMode ? 'team' : 'personal';
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $note = EmployeeNote::findOrFail($id);
        abort_unless($this->user()->can('update', $note), 403);
        $this->resetForm();
        $this->fill(['editingId' => $note->id, 'visibility' => $note->visibility, 'employeeId' => $note->employee_id, 'teamId' => $note->team_id, 'title' => $note->title, 'body' => $note->body, 'showForm' => true]);
    }

    public function save(AuditLogger $audit): void
    {
        $this->validate([
            'visibility' => ['required', 'in:personal,manager,team'],
            'employeeId' => ['nullable', 'required_if:visibility,manager', 'exists:attendance_employees,id'],
            'teamId' => ['nullable', 'required_if:visibility,team', 'exists:teams,id'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
        ], ['employeeId.required_if' => 'Choose the team member this note is about.', 'teamId.required_if' => 'Choose a team.']);
        $own = $this->ownEmployee();
        if ($this->visibility === 'team') {
            abort_unless($this->user()->can('hr.manage') || ($own && $own->teams()->whereKey($this->teamId)->exists()), 403);
        }
        if ($this->visibility === 'manager') {
            $target = AttendanceEmployee::findOrFail($this->employeeId);
            abort_unless($this->user()->can('hr.manage') || $target->manager?->user_id === auth()->id(), 403);
        }
        $values = ['employee_id' => $this->visibility === 'manager' ? $this->employeeId : null, 'team_id' => $this->visibility === 'team' ? $this->teamId : null, 'visibility' => $this->visibility, 'title' => $this->title, 'body' => $this->body];
        if ($this->editingId) {
            $note = EmployeeNote::findOrFail($this->editingId);
            abort_unless($this->user()->can('update', $note), 403);
            $note->update($values);
        } else {
            $note = EmployeeNote::create([...$values, 'author_id' => auth()->id()]);
            // Shared notes reach the people they are shared with (private notes notify nobody).
            $recipients = match ($note->visibility) {
                'manager' => collect([AttendanceEmployee::with('user')->find($note->employee_id)?->user]),
                'team' => Team::find($note->team_id)?->members()->with('user')->get()->pluck('user') ?? collect(),
                default => collect(),
            };
            \App\Services\Notifier::send($recipients->filter(), 'EmployeeNote', $note->id, $this->user()->name.' shared a note'.($note->visibility === 'team' ? ' with '.(Team::find($note->team_id)?->name ?? 'your team') : ' with you').': "'.$note->title.'"', route($note->visibility === 'team' ? 'notes.team' : 'notes.my', [], false), auth()->id());
        }
        $audit->record($this->editingId ? 'note.updated' : 'note.created', $note, ['visibility' => $note->visibility]);
        $this->closeModal();
        $this->toast('Note saved.');
    }

    public function delete(int $id, AuditLogger $audit): void
    {
        $note = EmployeeNote::findOrFail($id);
        abort_unless($this->user()->can('delete', $note), 403);
        $audit->record('note.deleted', $note);
        $note->delete();
        $this->toast('Note deleted.');
    }

    public function closeModal(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'visibility', 'employeeId', 'teamId', 'title', 'body');
        $this->resetValidation();
    }

    public function render()
    {
        $user = $this->user();
        $own = $this->ownEmployee();
        $teamIds = $own ? $own->teams()->pluck('teams.id') : collect();
        $reportIds = $own ? $own->directReports()->pluck('id') : collect();
        $term = trim($this->search);

        $notes = EmployeeNote::with(['author', 'employee', 'team'])
            // My Notes: my own private / manager notes, and notes my manager wrote about me — team notes live under Team Notes.
            ->when(! $this->teamMode, fn ($q) => $q->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('author_id', $user->id)->where('visibility', '!=', 'team'))
                ->orWhere(fn ($q) => $q->where('visibility', 'manager')->where('employee_id', $own?->id ?? 0))))
            ->when($this->teamMode, fn ($q) => $q->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('visibility', 'team')->whereIn('team_id', $teamIds->all() ?: [0]))
                ->orWhere(fn ($q) => $q->where('visibility', 'manager')->whereIn('employee_id', $reportIds->all() ?: [0]))
                ->orWhere(fn ($q) => $q->where('visibility', 'team')->where('author_id', $user->id))
                ->when($user->can('hr.manage'), fn ($q) => $q->orWhereIn('visibility', ['team', 'manager']))))
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->where('title', 'like', "%{$term}%")->orWhere('body', 'like', "%{$term}%")))
            ->latest('updated_at')->paginate(15);

        return $this->page('livewire.notes.notes', [
            'notes' => $notes,
            'teams' => $user->can('hr.manage') ? Team::where('is_active', true)->orderBy('name')->get() : ($own ? $own->teams()->orderBy('name')->get() : collect()),
            'reports' => $user->can('hr.manage') ? AttendanceEmployee::where('is_active', true)->orderBy('name')->get(['id', 'name']) : ($own ? $own->directReports()->orderBy('name')->get(['id', 'name']) : collect()),
        ], $this->teamMode ? 'Team Notes' : 'My Notes');
    }
}
