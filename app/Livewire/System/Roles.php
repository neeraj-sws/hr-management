<?php

namespace App\Livewire\System;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\Company;
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
    public const OWNER_ROLE = 'Company Owner';

    #[Url] public string $tab = 'matrix';
    #[Url] public string $search = '';
    public array $matrix = [];
    public ?int $editingUserId = null;
    public array $userRoles = [];
    /** The company the login is limited to ('' = all companies). Set by the Super Admin only. */
    public string $userCompanyId = '';

    public function mount(): void
    {
        // A company owner (logins.manage, no roles.manage) only gives roles to his own company's people.
        if (! $this->managesAll()) $this->tab = 'users';
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

    /** True for the Super Admin (roles.manage); false for a company owner managing his company's logins. */
    private function managesAll(): bool
    {
        return $this->user()->can('roles.manage');
    }

    /**
     * The roles this person may give: the Super Admin any; a company owner the ordinary ones (HR, Accounts, Manager,
     * Employee …), never Master Admin, Company Owner or a role that can manage roles or logins.
     */
    private function grantableRoles()
    {
        $roles = Role::with('permissions')->get();
        if ($this->managesAll()) return $roles;

        return $roles->reject(fn ($role) => in_array($role->name, [self::LOCKED_ROLE, self::OWNER_ROLE], true)
            || $role->permissions->pluck('name')->intersect(['roles.manage', 'logins.manage'])->isNotEmpty())->values();
    }

    /** Logins this person may change: everyone for the Super Admin; for an owner, his own company's people but himself. */
    private function usersQuery()
    {
        $query = User::query();
        if ($this->managesAll()) return $query;

        return $query->whereKeyNot($this->user()->id)
            // whereHas runs the employee query, which keeps only his company's employees.
            ->where(fn ($q) => $q->where('company_id', $this->user()->company_id)->orWhere(fn ($q) => $q->whereNull('company_id')->whereHas('employeeProfile')))
            ->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', [self::LOCKED_ROLE, self::OWNER_ROLE]));
    }

    public function editUser(int $id): void
    {
        abort_unless($this->user()->canAny(['roles.manage', 'logins.manage']), 403);
        $user = $this->usersQuery()->findOrFail($id);
        $this->editingUserId = $user->id;
        $this->userRoles = $user->getRoleNames()->all();
        $this->userCompanyId = (string) ($user->company_id ?? '');
        $this->resetValidation();
    }

    public function saveUserRoles(AuditLogger $audit): void
    {
        abort_unless($this->user()->canAny(['roles.manage', 'logins.manage']), 403);
        $user = $this->usersQuery()->findOrFail($this->editingUserId);
        $before = $user->getRoleNames()->all();
        $beforeCompany = $user->company_id;

        if ($this->managesAll()) {
            $roles = Role::whereIn('name', $this->userRoles)->pluck('name')->all();
            if ($user->id === auth()->id() && ! in_array(self::LOCKED_ROLE, $roles, true) && $user->hasRole(self::LOCKED_ROLE)) {
                throw ValidationException::withMessages(['userRoles' => 'You cannot remove your own Master Admin role.']);
            }
            $companyId = $this->userCompanyId !== '' ? Company::find((int) $this->userCompanyId)?->id : null;
            if (in_array(self::OWNER_ROLE, $roles, true) && ! $companyId) {
                throw ValidationException::withMessages(['userCompanyId' => 'Choose the company this owner runs.']);
            }
            if ($companyId && in_array(self::LOCKED_ROLE, $roles, true)) {
                throw ValidationException::withMessages(['userCompanyId' => 'A Master Admin sees all companies: leave the company empty.']);
            }
        } else {
            // An owner changes only the roles he may give; any other role the person has stays.
            $grantable = $this->grantableRoles()->pluck('name');
            $roles = collect($before)->diff($grantable)->merge($grantable->intersect($this->userRoles))->unique()->values()->all();
            // The logins he gives roles to see his company only.
            $companyId = $this->user()->company_id;
        }

        $user->syncRoles($roles);
        $user->forceFill(['company_id' => $companyId])->save();
        $audit->record('user.roles_updated', $user, ['before' => $before, 'after' => $roles, 'company_before' => $beforeCompany, 'company_after' => $companyId]);
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
        $managesAll = $this->managesAll();
        if (! $managesAll) $this->tab = 'users';

        return $this->page('livewire.system.roles', [
            'roles' => Role::withCount('users')->orderByRaw("CASE name WHEN 'Master Admin' THEN 0 WHEN 'Company Owner' THEN 1 WHEN 'HR' THEN 2 WHEN 'Accounts' THEN 3 WHEN 'Manager' THEN 4 ELSE 5 END")->get(),
            'grantable' => $this->grantableRoles()->pluck('name'),
            'permissions' => Permission::orderBy('name')->pluck('name')
                // Overtime switched off in Settings > Payroll: its permission is not shown.
                ->reject(fn ($name) => $name === 'overtime.manage' && ! app(\App\Services\PeopleOperationsService::class)->overtimeEnabled())
                ->groupBy(fn ($name) => str($name)->before('.')->toString()),
            'users' => $this->tab === 'users' ? $this->usersQuery()->with(['roles', 'company'])->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%")))->latest('id')->paginate(20) : null,
            'editing' => $this->editingUserId ? User::find($this->editingUserId) : null,
            'locked' => self::LOCKED_ROLE,
            'managesAll' => $managesAll,
            // The company of a login is chosen by the Super Admin only.
            'companies' => $managesAll ? Company::orderByDesc('is_main')->orderBy('name')->get() : collect(),
        ], $managesAll ? 'Roles & Permissions' : 'Logins & roles');
    }
}
