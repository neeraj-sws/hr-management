<?php

namespace App\Livewire\Company;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\Announcement;
use App\Models\Department;
use App\Models\Team;
use App\Services\CompanyService;
use Livewire\Component;
use Livewire\WithPagination;

class Announcements extends Component
{
    use InteractsWithHr, WithPagination;

    public bool $showForm = false;
    public ?int $editingId = null;
    public string $title = '';
    public string $body = '';
    public string $audience = 'all';
    public string $departmentId = '';
    public string $teamId = '';
    public bool $isPinned = false;
    public string $expiresOn = '';

    private function canManage(): bool
    {
        return $this->user()->canAny(['announcements.manage', 'hr.manage']);
    }

    public function edit(?int $id = null): void
    {
        abort_unless($this->canManage(), 403);
        $a = $id ? Announcement::findOrFail($id) : null;
        $this->fill([
            'editingId' => $a?->id, 'title' => $a?->title ?? '', 'body' => $a?->body ?? '', 'audience' => $a?->audience ?? 'all',
            'departmentId' => (string) ($a?->department_id ?? ''), 'teamId' => (string) ($a?->team_id ?? ''),
            'isPinned' => (bool) $a?->is_pinned, 'expiresOn' => (string) $a?->expires_on?->toDateString(),
        ]);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(CompanyService $company): void
    {
        abort_unless($this->canManage(), 403);
        $this->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:10000'],
            'audience' => ['required', 'in:all,department,team'],
            'departmentId' => ['required_if:audience,department', 'nullable', 'exists:departments,id'],
            'teamId' => ['required_if:audience,team', 'nullable', 'exists:teams,id'],
            'expiresOn' => ['nullable', 'date', 'after_or_equal:today'],
        ], ['departmentId.required_if' => 'Choose the department.', 'teamId.required_if' => 'Choose the team.'], ['expiresOn' => 'show until']);
        $company->saveAnnouncement([
            'title' => $this->title, 'body' => $this->body, 'audience' => $this->audience,
            'department_id' => $this->departmentId ?: null, 'team_id' => $this->teamId ?: null,
            'is_pinned' => $this->isPinned, 'expires_on' => $this->expiresOn ?: null,
        ], $this->user(), $this->editingId ? Announcement::findOrFail($this->editingId) : null);
        $this->showForm = false;
        $this->toast($this->editingId ? 'Announcement updated.' : 'Announcement published and sent to its audience.');
    }

    public function delete(int $id, CompanyService $company): void
    {
        abort_unless($this->canManage(), 403);
        $company->deleteAnnouncement(Announcement::findOrFail($id));
        $this->toast('Announcement deleted.');
    }

    public function closeModal(): void
    {
        $this->showForm = false;
    }

    public function render()
    {
        $query = Announcement::with(['author', 'department', 'team'])->orderByDesc('is_pinned')->latest('published_at');
        if (! $this->canManage()) $query->visibleTo($this->ownEmployee());

        return $this->page('livewire.company.announcements', [
            'announcements' => $query->paginate(10),
            'canManage' => $this->canManage(),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'teams' => Team::orderBy('name')->get(['id', 'name']),
        ], 'Announcements');
    }
}
