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

        // Clear Spatie permission cache so the new role takes effect immediately.
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

    public function cancel(): void
    {
        $this->showForm = false;
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
