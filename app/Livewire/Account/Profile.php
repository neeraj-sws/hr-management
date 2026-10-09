<?php

namespace App\Livewire\Account;

use App\Livewire\Concerns\InteractsWithHr;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;

class Profile extends Component
{
    use InteractsWithHr;

    public string $phone = '';
    public string $dateOfBirth = '';
    public bool $shareBirthday = true;
    public array $prefs = [];
    public string $currentPassword = '';
    public string $newPassword = '';
    public string $newPassword_confirmation = '';

    public function mount(): void
    {
        $this->phone = (string) ($this->ownEmployee()?->phone ?? '');
        $this->dateOfBirth = (string) $this->ownEmployee()?->date_of_birth?->toDateString();
        $this->shareBirthday = (bool) ($this->ownEmployee()?->share_birthday ?? true);
        $saved = (array) ($this->user()->notification_preferences ?? []);
        foreach (\App\Notifications\ApprovalRequested::GROUPS as $group => $definition) {
            $this->prefs[$group] = ['in_app' => (bool) ($saved[$group]['in_app'] ?? true), 'email' => (bool) ($saved[$group]['email'] ?? false), 'push' => (bool) ($saved[$group]['push'] ?? true)];
        }
    }

    public function saveContact(AuditLogger $audit): void
    {
        $employee = $this->requireEmployee();
        $data = $this->validate(['phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+\-\s()]*$/'], 'dateOfBirth' => ['nullable', 'date', 'before:today', 'after:1900-01-01']], [], ['dateOfBirth' => 'date of birth']);
        $employee->update(['phone' => $data['phone'] ?: null, 'date_of_birth' => $data['dateOfBirth'] ?: null, 'share_birthday' => $this->shareBirthday]);
        $audit->record('profile.contact_updated', $employee);
        $this->toast('Contact details saved.');
    }

    public function savePreferences(AuditLogger $audit): void
    {
        $clean = [];
        foreach (\App\Notifications\ApprovalRequested::GROUPS as $group => [, $mutable]) {
            $clean[$group] = ['in_app' => $mutable ? (bool) ($this->prefs[$group]['in_app'] ?? true) : true, 'email' => (bool) ($this->prefs[$group]['email'] ?? false), 'push' => (bool) ($this->prefs[$group]['push'] ?? true)];
        }
        $this->user()->forceFill(['notification_preferences' => $clean])->save();
        $this->prefs = $clean;
        $audit->record('profile.notification_preferences_updated', $this->user(), $clean);
        $this->toast('Notification preferences saved.');
    }

    public function changePassword(AuditLogger $audit): void
    {
        $this->validate([
            'currentPassword' => ['required', 'current_password:web'],
            'newPassword' => ['required', 'confirmed', Password::min(12)->letters()->numbers(), 'different:currentPassword'],
        ], [], ['newPassword' => 'new password', 'currentPassword' => 'current password']);
        $user = $this->user();
        $user->update(['password' => Hash::make($this->newPassword)]);
        $user->tokens()->delete();
        $audit->record('profile.password_changed', $user);
        $this->reset('currentPassword', 'newPassword', 'newPassword_confirmation');
        $this->toast('Password changed. API tokens were revoked.');
    }

    public function render()
    {
        $employee = $this->ownEmployee()?->load(['department', 'manager', 'teams', 'salaryProfile']);

        return $this->page('livewire.account.profile', ['employee' => $employee, 'user' => $this->user()], 'My profile');
    }
}
