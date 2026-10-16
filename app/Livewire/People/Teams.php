<?php

namespace App\Livewire\People;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\Department;
use App\Models\Team;
use App\Services\AuditLogger;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Teams extends Component
{
    use InteractsWithHr, \Livewire\WithPagination;

    public bool $showForm = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $description = '';
    public ?int $departmentId = null;
    public ?int $managerId = null;
    public array $memberIds = [];

    private function canManage(): bool
    {
        return $this->user()->canAny(['teams.manage', 'hr.manage']);
    }

    public function create(): void
    {
        abort_unless($this->canManage(), 403);
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        abort_unless($this->canManage(), 403);
        $team = Team::with('members')->findOrFail($id);
        $this->resetForm();
        $this->fill(['editingId' => $team->id, 'name' => $team->name, 'description' => (string) $team->description, 'departmentId' => $team->department_id, 'managerId' => $team->manager_employee_id, 'memberIds' => $team->members->pluck('id')->map(fn ($id) => (string) $id)->all(), 'showForm' => true]);
    }

    public function save(AuditLogger $audit): void
    {
        abort_unless($this->canManage(), 403);
        $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('teams', 'name')->ignore($this->editingId)],
            'description' => ['nullable', 'string', 'max:2000'],
            'departmentId' => ['nullable', 'exists:departments,id'],
            'managerId' => ['nullable', 'exists:attendance_employees,id'],
            'memberIds' => ['array'], 'memberIds.*' => ['integer', 'exists:attendance_employees,id'],
        ]);
        $team = Team::updateOrCreate(['id' => $this->editingId], ['name' => $this->name, 'description' => $this->description ?: null, 'department_id' => $this->departmentId ?: null, 'manager_employee_id' => $this->managerId ?: null, 'is_active' => true]);
        $members = collect($this->memberIds)->map(fn ($id) => (int) $id)->when($this->managerId, fn ($ids) => $ids->push((int) $this->managerId))->unique()->all();
        app(\App\Services\EmployeeService::class)->syncTeamMembers($team, $members, auth()->user());
        $audit->record($this->editingId ? 'team.updated' : 'team.created', $team, ['members' => count($members)]);
        $this->toast($this->editingId ? 'Team updated.' : 'Team created.');
        $this->closeModal();
    }

    public function archive(int $id, AuditLogger $audit): void
    {
        abort_unless($this->canManage(), 403);
        $team = Team::findOrFail($id);
        $team->update(['is_active' => ! $team->is_active]);
        $audit->record($team->is_active ? 'team.restored' : 'team.archived', $team);
    }

    /**
     * Removes a team created by mistake. A team that has (or had) members keeps
     * its membership history for team policies and reports: archive it instead.
     */
    public function remove(int $id, AuditLogger $audit): void
    {
        abort_unless($this->canManage(), 403);
        $team = Team::withCount('members')->findOrFail($id);
        $history = \App\Models\EmployeeTeamHistory::where('team_id', $team->id)->count();
        if ($team->members_count || $history) {
            throw \Illuminate\Validation\ValidationException::withMessages(['team' => "{$team->name} has ".($team->members_count ?: $history).' member record(s), so it cannot be removed. Archive it instead — its history stays for reports and team policies.']);
        }
        \App\Models\TeamPolicyOverride::where('team_id', $team->id)->delete();
        $audit->record('team.removed', null, ['id' => $team->id, 'name' => $team->name]);
        $team->delete();
        $this->toast("Team {$team->name} removed.");
    }

    public function closeModal(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'description', 'departmentId', 'managerId', 'memberIds');
        $this->resetValidation();
    }

    public function render()
    {
        $own = $this->ownEmployee();
        $teams = Team::with(['department', 'manager', 'members' => fn ($q) => $q->orderBy('name')])
            ->when(! $this->canManage(), fn ($q) => $q->where('is_active', true)->where(fn ($q) => $q->whereHas('members', fn ($m) => $m->whereKey($own?->id ?? 0))->orWhere('manager_employee_id', $own?->id ?? 0)))
            ->orderByDesc('is_active')->orderBy('name')->paginate(15);

        return $this->page('livewire.people.teams', [
            'teams' => $teams,
            'totals' => ['active' => Team::where('is_active', true)->count(), 'archived' => Team::where('is_active', false)->count(), 'members' => \Illuminate\Support\Facades\DB::table('employee_team')->distinct()->count('employee_id')],
            'canManage' => $this->canManage(),
            'departments' => Department::where('is_active', true)->orderBy('name')->get(),
            'employees' => $this->canManage() ? AttendanceEmployee::where('is_active', true)->orderBy('name')->get(['id', 'name', 'employee_code']) : collect(),
        ], 'Teams');
    }
}
