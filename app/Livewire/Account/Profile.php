<?php

namespace App\Livewire\Account;

use App\Livewire\Concerns\InteractsWithHr;
use App\Services\AuditLogger;
use Livewire\Component;

class Profile extends Component
{
    use InteractsWithHr, \Livewire\WithFileUploads;

    /** New photo being uploaded (saved as soon as it is chosen). */
    public $photo = null;

    public function updatedPhoto(\App\Services\EmployeePhotoService $photos): void
    {
        $this->validate(['photo' => \App\Services\EmployeePhotoService::RULES], ['photo.mimes' => 'Choose a JPG, PNG or WebP picture.', 'photo.max' => 'The picture must be 3 MB or smaller.']);
        $photos->save($this->requireEmployee(), $this->photo, $this->user());
        $this->reloadWith('Photo updated.');
    }

    public function removePhoto(\App\Services\EmployeePhotoService $photos): void
    {
        $photos->remove($this->requireEmployee(), $this->user());
        $this->reloadWith('Photo removed.');
    }

    /** Reloads the page so the new photo shows everywhere at once (header and top bar). */
    private function reloadWith(string $message): void
    {
        session()->flash('toast', ['message' => $message, 'type' => 'success']);
        $this->redirect(route('account.profile'));
    }

    public string $phone = '';
    public string $dateOfBirth = '';
    public bool $shareBirthday = true;
    public array $prefs = [];

    public function mount(): void
    {
        $this->phone = (string) ($this->ownEmployee()?->phone ?? '');
        $this->dateOfBirth = (string) $this->ownEmployee()?->date_of_birth?->toDateString();
        $this->shareBirthday = (bool) ($this->ownEmployee()?->share_birthday ?? true);
        $saved = (array) ($this->user()->notification_preferences ?? []);
        foreach (\App\Notifications\ApprovalRequested::GROUPS as $group => $definition) {
            $this->prefs[$group] = ['in_app' => \App\Notifications\ApprovalRequested::preference($saved, $group, 'in_app'), 'email' => \App\Notifications\ApprovalRequested::preference($saved, $group, 'email'), 'push' => \App\Notifications\ApprovalRequested::preference($saved, $group, 'push')];
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

    public function render()
    {
        $employee = $this->ownEmployee()?->load(['department', 'manager', 'teams', 'salaryProfile']);

        return $this->page('livewire.account.profile', ['employee' => $employee, 'user' => $this->user()], 'My profile');
    }
}
