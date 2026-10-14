<?php

namespace App\Livewire\Security;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\AuditLog;
use App\Models\CredentialVaultEntry;
use App\Models\Team;
use App\Services\AuditLogger;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Secrets are stored with Laravel's `encrypted` cast (APP_KEY, AES-256) and are
 * never listed — only revealed one at a time after a policy check, and every
 * reveal is written to the audit log.
 */
class Vault extends Component
{
    use InteractsWithHr, \Livewire\WithPagination;

    public const CATEGORIES = ['database' => 'Database', 'login' => 'Login', 'service' => 'API / service'];

    #[Url] public string $search = '';
    #[Url] public string $category = '';
    public bool $showForm = false;
    public ?int $editingId = null;
    public string $vaultCategory = 'login';
    public string $label = '';
    public string $username = '';
    public string $secret = '';
    public string $url = '';
    public string $visibility = 'private';
    public ?int $ownerId = null;
    public ?int $teamId = null;
    public ?int $revealedId = null;
    public string $revealedSecret = '';

    private function canManage(): bool
    {
        return $this->user()->can('credentials.manage');
    }

    public function create(): void
    {
        abort_unless($this->canManage(), 403);
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $entry = CredentialVaultEntry::findOrFail($id);
        abort_unless($this->canManage() && $this->user()->can('view', $entry), 403);
        $this->resetForm();
        $this->fill(['editingId' => $entry->id, 'vaultCategory' => $entry->category, 'label' => $entry->label, 'username' => (string) $entry->username, 'url' => (string) $entry->url, 'visibility' => $entry->visibility, 'ownerId' => $entry->owner_employee_id, 'teamId' => $entry->team_id, 'showForm' => true]);
    }

    public function save(AuditLogger $audit): void
    {
        abort_unless($this->canManage(), 403);
        $this->validate([
            'vaultCategory' => ['required', 'in:'.implode(',', array_keys(self::CATEGORIES))],
            'label' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:255'],
            'secret' => [$this->editingId ? 'nullable' : 'required', 'string', 'max:10000'],
            'url' => ['nullable', 'url', 'max:2000'],
            'visibility' => ['required', 'in:private,hr,team,employee'],
            'ownerId' => ['nullable', 'required_if:visibility,employee', 'exists:attendance_employees,id'],
            'teamId' => ['nullable', 'required_if:visibility,team', 'exists:teams,id'],
        ], ['ownerId.required_if' => 'Choose the employee who may see this credential.', 'teamId.required_if' => 'Choose the team that may see this credential.'], ['vaultCategory' => 'category']);
        $values = ['category' => $this->vaultCategory, 'label' => $this->label, 'username' => $this->username ?: null, 'url' => $this->url ?: null, 'visibility' => $this->visibility, 'owner_employee_id' => $this->visibility === 'employee' ? $this->ownerId : null, 'team_id' => $this->visibility === 'team' ? $this->teamId : null];
        if ($this->secret !== '') $values['secret_value'] = $this->secret;
        if ($this->editingId) {
            $entry = CredentialVaultEntry::findOrFail($this->editingId);
            abort_unless($this->user()->can('view', $entry), 403);
            $entry->update($values);
            $audit->record('credential.updated', $entry, ['category' => $entry->category, 'secret_rotated' => $this->secret !== '']);
        } else {
            $entry = CredentialVaultEntry::create([...$values, 'created_by' => auth()->id()]);
            $audit->record('credential.created', $entry, ['category' => $entry->category, 'visibility' => $entry->visibility]);
        }
        $this->showForm = false;
        $this->resetForm();
        $this->toast('Credential saved (encrypted).');
    }

    public function reveal(int $id, AuditLogger $audit): void
    {
        $entry = CredentialVaultEntry::findOrFail($id);
        if (! $this->user()->can('view', $entry)) {
            $audit->record('credential.access_denied', $entry, ['category' => $entry->category]);
            abort(403);
        }
        $this->revealedId = $entry->id;
        $this->revealedSecret = $entry->secret_value;
        $audit->record('credential.accessed', $entry, ['category' => $entry->category]);
    }

    public function hide(): void
    {
        $this->reset('revealedId', 'revealedSecret');
    }

    public function delete(int $id, AuditLogger $audit): void
    {
        $entry = CredentialVaultEntry::findOrFail($id);
        abort_unless($this->user()->can('delete', $entry), 403);
        $audit->record('credential.deleted', $entry, ['category' => $entry->category, 'label' => $entry->label]);
        if ($this->revealedId === $entry->id) $this->hide();
        $entry->delete();
        $this->toast('Credential deleted.');
    }

    public function closeModal(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'vaultCategory', 'label', 'username', 'secret', 'url', 'visibility', 'ownerId', 'teamId');
        $this->resetValidation();
    }

    public function render()
    {
        $user = $this->user();
        $own = $this->ownEmployee();
        $teamIds = $own ? $own->teams()->pluck('teams.id')->all() : [];
        $term = trim($this->search);
        $entries = CredentialVaultEntry::with(['creator', 'owner', 'team'])
            ->when(! $this->canManage(), fn ($q) => $q->where(fn ($q) => $q
                ->where('created_by', $user->id)
                ->orWhere(fn ($q) => $q->where('visibility', 'employee')->where('owner_employee_id', $own?->id ?? 0))
                ->orWhere(fn ($q) => $q->where('visibility', 'team')->whereIn('team_id', $teamIds ?: [0]))
                ->when($user->can('hr.manage'), fn ($q) => $q->orWhere('visibility', 'hr'))))
            ->when($this->category, fn ($q, $c) => $q->where('category', $c))
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->where('label', 'like', "%{$term}%")->orWhere('username', 'like', "%{$term}%")->orWhere('url', 'like', "%{$term}%")))
            ->orderBy('label')->paginate(15);

        return $this->page('livewire.security.vault', [
            'entries' => $entries,
            'canManage' => $this->canManage(),
            'categories' => self::CATEGORIES,
            'employees' => $this->canManage() ? AttendanceEmployee::where('is_active', true)->orderBy('name')->get(['id', 'name']) : collect(),
            'teams' => $this->canManage() ? Team::where('is_active', true)->orderBy('name')->get(['id', 'name']) : collect(),
            'accessLog' => $this->canManage() ? AuditLog::with('actor')->whereIn('action', ['credential.accessed', 'credential.access_denied', 'credential.created', 'credential.updated', 'credential.deleted'])->latest('id')->limit(12)->get() : collect(),
        ], 'Credential Vault');
    }
}
