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
    /** Audience "employees": the people chosen by name (one or several). */
    public array $employeeIds = [];
    /** Departments / teams left out (e.g. everyone except IT Web Info). */
    public array $excludeDepartments = [];
    public array $excludeTeams = [];
    public bool $isPinned = false;
    public bool $sendEmail = false;
    public bool $sendTelegram = false;
    public string $expiresOn = '';
    /** '' = all companies; a login limited to one company always posts to it. */
    public string $companyId = '';

    private function canManage(): bool
    {
        return $this->user()->canAny(['announcements.manage', 'hr.manage']);
    }

    /** A login limited to one company changes only its own company's announcements, not those for all companies. */
    public function canChange(Announcement $announcement): bool
    {
        return $this->canManage() && (! \App\Support\CompanyAccess::isLimited($this->user()) || $announcement->company_id !== null);
    }

    public function edit(?int $id = null): void
    {
        abort_unless($this->canManage(), 403);
        $a = $id ? Announcement::findOrFail($id) : null;
        abort_if($a && ! $this->canChange($a), 403);
        $this->fill([
            'editingId' => $a?->id, 'title' => $a?->title ?? '', 'body' => $a?->body ?? '', 'audience' => $a?->audience ?? 'all',
            'departmentId' => (string) ($a?->department_id ?? ''), 'teamId' => (string) ($a?->team_id ?? ''),
            'employeeIds' => array_map('strval', (array) ($a?->employee_ids ?? [])),
            'excludeDepartments' => array_map('strval', (array) ($a?->excluded_department_ids ?? [])), 'excludeTeams' => array_map('strval', (array) ($a?->excluded_team_ids ?? [])),
            'isPinned' => (bool) $a?->is_pinned, 'expiresOn' => (string) $a?->expires_on?->toDateString(), 'sendEmail' => false, 'sendTelegram' => false,
            'companyId' => (string) ($a?->company_id ?? ''),
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
            'audience' => ['required', 'in:all,department,team,employees'],
            'departmentId' => ['required_if:audience,department', 'nullable', 'exists:departments,id'],
            'teamId' => ['required_if:audience,team', 'nullable', 'exists:teams,id'],
            'employeeIds' => ['array', 'required_if:audience,employees'], 'employeeIds.*' => ['integer', 'exists:attendance_employees,id'],
            'expiresOn' => ['nullable', 'date', 'after_or_equal:today'],
            'excludeDepartments' => ['array'], 'excludeDepartments.*' => ['integer', 'exists:departments,id'],
            'excludeTeams' => ['array'], 'excludeTeams.*' => ['integer', 'exists:teams,id'],
        ], ['departmentId.required_if' => 'Choose the department.', 'teamId.required_if' => 'Choose the team.', 'employeeIds.required_if' => 'Choose at least one employee.'], ['expiresOn' => 'show until']);
        $company->saveAnnouncement([
            'title' => $this->title, 'body' => $this->body, 'audience' => $this->audience,
            'department_id' => $this->departmentId ?: null, 'team_id' => $this->teamId ?: null, 'employee_ids' => $this->employeeIds,
            'excluded_department_ids' => array_values(array_map('intval', $this->excludeDepartments)) ?: null,
            'excluded_team_ids' => array_values(array_map('intval', $this->excludeTeams)) ?: null,
            'is_pinned' => $this->isPinned, 'expires_on' => $this->expiresOn ?: null,
            'company_id' => \App\Support\CompanyAccess::limitedCompanyId() ?? ($this->companyId !== '' ? \App\Models\Company::find((int) $this->companyId)?->id : null),
        ], $this->user(), $this->editingId ? tap(Announcement::findOrFail($this->editingId), fn ($a) => abort_unless($this->canChange($a), 403)) : null, ! $this->editingId && $this->sendEmail, ! $this->editingId && $this->sendTelegram);
        $this->showForm = false;
        $this->toast($this->editingId ? 'Announcement updated.' : 'Announcement published and sent in the app'.($this->sendEmail ? ', by e-mail' : '').($this->sendTelegram ? ', on Telegram' : '').' to its audience.');
    }

    public function delete(int $id, CompanyService $company): void
    {
        abort_unless($this->canManage(), 403);
        $announcement = Announcement::findOrFail($id);
        abort_unless($this->canChange($announcement), 403);
        $company->deleteAnnouncement($announcement);
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
            // Company choice for logins of all companies, when there is more than one company.
            'companies' => \App\Support\CompanyAccess::isLimited($this->user()) ? collect() : \App\Models\Company::orderByDesc('is_main')->orderBy('name')->get(['id', 'name']),
            'employees' => $this->showForm && $this->canManage() ? \App\Models\AttendanceEmployee::where('is_active', true)->orderBy('name')->get(['id', 'name', 'employee_code']) : collect(),
        ], 'Announcements');
    }
}
