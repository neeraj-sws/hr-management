<?php

namespace App\Livewire\System;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class Roles extends Component
{
    use InteractsWithHr, WithPagination;

    public const LOCKED_ROLE = 'Master Admin';

    #[Url] public string $tab = 'matrix';
    #[Url] public string $search = '';
    public array $matrix = [];
    public ?int $editingUserId = null;
    public array $userRoles = [];

    public function mount(): void
    {
        $this->loadMatrix();
    }

    private function loadMatrix(): void
    {
        $this->matrix = Role::with('permissions')->get()->mapWithKeys(fn ($role) => [$role->id => $role->permissions->pluck('name')->values()->all()])->all();
    }

    public function saveRole(int $roleId, AuditLogger $audit): void
    {
        abort_unless($this->user()->can('roles.manage'), 403);
        $role = Role::findOrFail($roleId);
        abort_if($role->name === self::LOCKED_ROLE, 422, 'The Master Admin role always has every permission.');
        $valid = Permission::whereIn('name', $this->matrix[$roleId] ?? [])->pluck('name')->all();
        $before = $role->permissions->pluck('name')->all();
        $role->syncPermissions($valid);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $audit->record('role.permissions_updated', $role, ['added' => array_values(array_diff($valid, $before)), 'removed' => array_values(array_diff($before, $valid))]);
        $this->loadMatrix();
        $this->toast("{$role->name} permissions saved.");
    }

    public function editUser(int $id): void
    {
        $user = User::findOrFail($id);
        $this->editingUserId = $user->id;
        $this->userRoles = $user->getRoleNames()->all();
    }

    public function saveUserRoles(AuditLogger $audit): void
    {
        abort_unless($this->user()->can('roles.manage'), 403);
        $user = User::findOrFail($this->editingUserId);
        $roles = Role::whereIn('name', $this->userRoles)->pluck('name')->all();
        if ($user->id === auth()->id() && ! in_array(self::LOCKED_ROLE, $roles, true) && $user->hasRole(self::LOCKED_ROLE)) {
            throw ValidationException::withMessages(['userRoles' => 'You cannot remove your own Master Admin role.']);
        }
        $before = $user->getRoleNames()->all();
        $user->syncRoles($roles);
        $audit->record('user.roles_updated', $user, ['before' => $before, 'after' => $roles]);
        $this->editingUserId = null;
        $this->toast('Roles updated for '.$user->name.'.');
    }

    public function closeModal(): void
    {
        $this->editingUserId = null;
    }

    public function render()
    {
        $term = trim($this->search);

        return $this->page('livewire.system.roles', [
            'roles' => Role::withCount('users')->orderByRaw("CASE name WHEN 'Master Admin' THEN 0 WHEN 'HR' THEN 1 WHEN 'Accounts' THEN 2 WHEN 'Manager' THEN 3 ELSE 4 END")->get(),
            'permissions' => Permission::orderBy('name')->pluck('name')
                // Overtime switched off in Settings > Payroll: its permission is not shown.
                ->reject(fn ($name) => $name === 'overtime.manage' && ! app(\App\Services\PeopleOperationsService::class)->overtimeEnabled())
                ->groupBy(fn ($name) => str($name)->before('.')->toString()),
            'users' => $this->tab === 'users' ? User::with('roles')->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%")))->latest('id')->paginate(20) : null,
            'editing' => $this->editingUserId ? User::find($this->editingUserId) : null,
            'locked' => self::LOCKED_ROLE,
        ], 'Roles & Permissions');
    }
}
