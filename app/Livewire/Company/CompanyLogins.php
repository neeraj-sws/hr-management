<?php

namespace App\Livewire\Company;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Spatie\Permission\Models\Role;

/**
 * Master Admin only: view, create and deactivate logins (users) that belong to a company.
 * This is how a Company Admin, HR, Accounts or Manager login is created for a company
 * without touching phpMyAdmin.
 */
class CompanyLogins extends Component
{
    use InteractsWithHr;

    public Company $company;

    public bool $showForm = false;
    public bool $showPasswordForm = false;
    public bool $showEditForm = false;
    public int $editingUserId = 0;
    public int $resetUserId = 0;
    public string $newPassword = '';
    public string $loginName = '';
    public string $loginEmail = '';
    public string $loginPassword = '';
    public string $loginRole = '';

    public function mount(Company $company): void
    {
        abort_if(\App\Support\CompanyAccess::isLimited($this->user()), 403, 'Only the Super Admin can manage company logins.');
        $this->company = Company::withoutGlobalScopes()->findOrFail($company->id);
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function save(): void
    {
        abort_if(\App\Support\CompanyAccess::isLimited($this->user()), 403);
        $this->validate([
            'loginName'     => ['required', 'string', 'max:120'],
            'loginEmail'    => ['required', 'email', 'max:120', Rule::unique('users', 'email')],
            'loginPassword' => ['required', 'string', 'min:8'],
            'loginRole'     => ['required', Rule::in($this->allowedRoles())],
        ], [], [
            'loginName' => 'name', 'loginEmail' => 'email', 'loginPassword' => 'password', 'loginRole' => 'role',
        ]);

        $user = User::create([
            'name'       => trim($this->loginName),
            'email'      => trim($this->loginEmail),
            'password'   => Hash::make($this->loginPassword),
            'company_id' => $this->company->id,
        ]);
        $user->assignRole($this->loginRole);

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->toast("Login created for {$user->name} ({$this->loginRole}).");
        $this->showForm = false;
        $this->resetForm();
    }

    public function deactivate(int $userId): void
    {
        abort_if(\App\Support\CompanyAccess::isLimited($this->user()), 403);
        $user = User::withoutGlobalScopes()->where('company_id', $this->company->id)->findOrFail($userId);
        abort_if($user->id === 1, 403, 'The main admin cannot be deactivated.');
        $user->update(['is_active' => false]);
        $this->toast("{$user->name} deactivated.");
    }

    public function reactivate(int $userId): void
    {
        abort_if(\App\Support\CompanyAccess::isLimited($this->user()), 403);
        $user = User::withoutGlobalScopes()->where('company_id', $this->company->id)->findOrFail($userId);
        $user->update(['is_active' => true]);
        $this->toast("{$user->name} reactivated.");
    }

    public function openPasswordReset(int $userId): void
    {
        $this->resetUserId = $userId;
        $this->newPassword = '';
        $this->resetValidation();
        $this->showPasswordForm = true;
        $this->showForm = false;
    }

    public function savePassword(): void
    {
        abort_if(\App\Support\CompanyAccess::isLimited($this->user()), 403);
        $this->validate(['newPassword' => ['required', 'string', 'min:8']], [], ['newPassword' => 'new password']);
        $user = User::withoutGlobalScopes()->where('company_id', $this->company->id)->findOrFail($this->resetUserId);
        $user->update(['password' => Hash::make($this->newPassword)]);
        $this->toast("Password updated for {$user->name}.");
        $this->showPasswordForm = false;
        $this->resetUserId = 0;
        $this->newPassword = '';
    }

    public function cancelPassword(): void
    {
        $this->showPasswordForm = false;
        $this->resetUserId = 0;
        $this->newPassword = '';
        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    public function edit(int $userId): void
    {
        abort_if(\App\Support\CompanyAccess::isLimited($this->user()), 403);
        $user = User::withoutGlobalScopes()->where('company_id', $this->company->id)->findOrFail($userId);
        $this->editingUserId = $userId;
        $this->loginName = $user->name;
        $this->loginEmail = $user->email;
        $this->loginRole = $user->roles->first()?->name ?? '';
        $this->showEditForm = true;
        $this->showForm = false;
        $this->showPasswordForm = false;
        $this->resetValidation();
    }

    public function saveEdit(): void
    {
        abort_if(\App\Support\CompanyAccess::isLimited($this->user()), 403);
        $this->validate([
            'loginName'  => ['required', 'string', 'max:120'],
            'loginEmail' => ['required', 'email', 'max:120', Rule::unique('users', 'email')->ignore($this->editingUserId)],
            'loginRole'  => ['required', Rule::in($this->allowedRoles())],
        ], [], ['loginName' => 'name', 'loginEmail' => 'email', 'loginRole' => 'role']);

        $user = User::withoutGlobalScopes()->where('company_id', $this->company->id)->findOrFail($this->editingUserId);
        $user->update(['name' => trim($this->loginName), 'email' => trim($this->loginEmail)]);
        $user->syncRoles([$this->loginRole]);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->toast('Login updated for '.$user->name.'.');
        $this->showEditForm = false;
        $this->editingUserId = 0;
        $this->resetForm();
    }

    public function cancelEdit(): void
    {
        $this->showEditForm = false;
        $this->editingUserId = 0;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset('loginName', 'loginEmail', 'loginPassword', 'loginRole');
        $this->resetValidation();
    }

    /** Roles a company login may have (never Master Admin or a role that manages other companies). */
    private function allowedRoles(): array
    {
        return Role::whereNotIn('name', ['Master Admin', 'Super Admin'])->pluck('name')->toArray();
    }

    public function render()
    {
        $logins = User::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->with('roles')
            ->orderBy('name')
            ->get();

        return $this->page('livewire.company.company-logins', [
            'logins'       => $logins,
            'allowedRoles' => $this->allowedRoles(),
        ], $this->company->name.' – Logins');
    }
}
