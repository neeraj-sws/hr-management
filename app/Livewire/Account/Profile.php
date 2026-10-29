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
        \App\Services\ImpersonationService::block();
        $this->validate(['photo' => \App\Services\EmployeePhotoService::RULES], ['photo.mimes' => 'Choose a JPG, PNG or WebP picture.', 'photo.max' => 'The picture must be 5 MB or smaller.']);
        $photos->save($this->requireEmployee(), $this->photo, $this->user());
        $this->reloadWith('Photo updated.');
    }

    public function removePhoto(\App\Services\EmployeePhotoService $photos): void
    {
        \App\Services\ImpersonationService::block();
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
    public string $address = '';
    public string $emergencyName = '';
    public string $emergencyRelation = '';
    public string $emergencyPhone = '';
    public array $prefs = [];
    /** Website: play a short sound when a new notification arrives. */
    public bool $webSound = true;

    public function mount(): void
    {
        $this->phone = (string) ($this->ownEmployee()?->phone ?? '');
        $this->dateOfBirth = (string) $this->ownEmployee()?->date_of_birth?->toDateString();
        $this->shareBirthday = (bool) ($this->ownEmployee()?->share_birthday ?? true);
        $this->address = (string) ($this->ownEmployee()?->address ?? '');
        $this->emergencyName = (string) ($this->ownEmployee()?->emergency_contact_name ?? '');
        $this->emergencyRelation = (string) ($this->ownEmployee()?->emergency_contact_relation ?? '');
        $this->emergencyPhone = (string) ($this->ownEmployee()?->emergency_contact_phone ?? '');
        $saved = (array) ($this->user()->notification_preferences ?? []);
        $this->webSound = (bool) ($saved['web_sound'] ?? true);
        foreach (\App\Notifications\ApprovalRequested::GROUPS as $group => $definition) {
            $this->prefs[$group] = ['in_app' => \App\Notifications\ApprovalRequested::preference($saved, $group, 'in_app'), 'email' => \App\Notifications\ApprovalRequested::preference($saved, $group, 'email'), 'push' => \App\Notifications\ApprovalRequested::preference($saved, $group, 'push'), 'telegram' => \App\Notifications\ApprovalRequested::preference($saved, $group, 'telegram')];
        }
    }

    public function saveContact(AuditLogger $audit): void
    {
        \App\Services\ImpersonationService::block();
        $employee = $this->requireEmployee();
        // The same rules and save as the app's Settings screen.
        $r = \App\Services\OwnProfileService::rules();
        $data = $this->validate(['phone' => $r['phone'], 'dateOfBirth' => $r['date_of_birth'], 'address' => $r['address'], 'emergencyName' => $r['emergency_contact_name'], 'emergencyRelation' => $r['emergency_contact_relation'], 'emergencyPhone' => $r['emergency_contact_phone']], [], ['dateOfBirth' => 'date of birth', 'emergencyName' => 'emergency contact name', 'emergencyRelation' => 'relation', 'emergencyPhone' => 'emergency contact phone']);
        app(\App\Services\OwnProfileService::class)->save($employee, ['phone' => $data['phone'], 'date_of_birth' => $data['dateOfBirth'], 'share_birthday' => $this->shareBirthday, 'address' => $data['address'], 'emergency_contact_name' => $data['emergencyName'], 'emergency_contact_relation' => $data['emergencyRelation'], 'emergency_contact_phone' => $data['emergencyPhone']], $this->user());
        $this->toast('Contact details saved.');
    }

    public function savePreferences(AuditLogger $audit): void
    {
        \App\Services\ImpersonationService::block();
        $clean = [];
        foreach (\App\Notifications\ApprovalRequested::GROUPS as $group => [, $mutable]) {
            $clean[$group] = ['in_app' => $mutable ? (bool) ($this->prefs[$group]['in_app'] ?? true) : true, 'email' => (bool) ($this->prefs[$group]['email'] ?? false), 'push' => (bool) ($this->prefs[$group]['push'] ?? true), 'telegram' => (bool) ($this->prefs[$group]['telegram'] ?? false)];
        }
        $this->user()->forceFill(['notification_preferences' => [...$clean, 'web_sound' => $this->webSound]])->save();
        $this->prefs = $clean;
        $audit->record('profile.notification_preferences_updated', $this->user(), $clean);
        $this->toast('Notification preferences saved.');
    }

    /** Telegram link being made: the bot link (with its single-use token) shown until it is used or expires. */
    public ?string $telegramUrl = null;
    public ?string $telegramExpires = null;

    public function connectTelegram(\App\Services\TelegramNotificationService $telegram): void
    {
        \App\Services\ImpersonationService::block();
        abort_unless($telegram->enabled(), 403);
        $link = $telegram->startLink($this->user());
        if (! $link['url']) {
            $this->addError('telegram', 'The Telegram bot is not set up yet. Ask the administrator.');

            return;
        }
        $this->telegramUrl = $link['url'];
        $this->telegramExpires = $link['expires_at']->format('h:i A');
    }

    public function disconnectTelegram(\App\Services\TelegramNotificationService $telegram): void
    {
        \App\Services\ImpersonationService::block();
        $telegram->unlink($this->user());
        $this->telegramUrl = null;
        $this->toast('Telegram disconnected.');
    }

    /** "I have tapped Start": shows whether the link worked. */
    public function checkTelegram(): void
    {
        if (\App\Notifications\Channels\TelegramChannel::linked($this->user()->id)) {
            $this->telegramUrl = null;
            $this->toast('Telegram connected.');
        } else {
            $this->addError('telegram', 'Not connected yet: open the link, tap Start in Telegram, then check again.');
        }
    }

    public string $currentPassword = '';
    public string $newPassword = '';
    public string $newPassword_confirmation = '';

    /** Admins and HR change their own password here (current password needed). Other people: HR resets it. */
    public static function canChangeOwnPassword(\App\Models\User $user): bool
    {
        return \App\Support\Access::isAdmin($user) || $user->hasRole('HR') || $user->can('hr.manage');
    }

    public function changePassword(AuditLogger $audit): void
    {
        \App\Services\ImpersonationService::block();
        $user = $this->user();
        abort_unless(self::canChangeOwnPassword($user), 403);
        $this->validate([
            'currentPassword' => ['required', 'string'],
            'newPassword' => ['required', 'string', 'confirmed', 'different:currentPassword', \Illuminate\Validation\Rules\Password::min(12)->letters()->numbers()],
        ], ['newPassword.different' => 'The new password must be different from the current one.'], ['currentPassword' => 'current password', 'newPassword' => 'new password']);
        if (! \Illuminate\Support\Facades\Hash::check($this->currentPassword, $user->password)) {
            $this->addError('currentPassword', 'The current password is not correct.');

            return;
        }
        $user->forceFill(['password' => \Illuminate\Support\Facades\Hash::make($this->newPassword)])->save();
        // The mobile and desktop apps sign in again with the new password; this browser stays signed in.
        $user->tokens()->delete();
        $audit->record('user.password_changed_self', $user);
        \App\Services\Notifier::send($user, 'PasswordChanged', $user->id, 'You changed your login password on '.now()->format('d M Y, h:i A').'. The mobile and desktop apps will ask you to sign in again. If this was not you, contact the administrator.', route('account.profile', [], false));
        $this->reset('currentPassword', 'newPassword', 'newPassword_confirmation');
        $this->toast('Password changed. Use the new password next time you sign in (also in the mobile and desktop apps).');
    }

    /** Turns the e-mailed sign-in code on or off for this person's own account. */
    public function toggleTwoFactor(AuditLogger $audit, \App\Services\TwoFactorService $twoFactor): void
    {
        \App\Services\ImpersonationService::block();
        $user = $this->user();
        $on = ! $user->two_factor_enabled;
        if ($on && ! \App\Notifications\Channels\SafeMailChannel::enabled()) {
            $this->toast('Sign-in codes are sent by e-mail, and the company has e-mail turned off. Ask HR.', 'error');

            return;
        }
        $user->forceFill(['two_factor_enabled' => $on])->save();
        $audit->record($on ? 'profile.two_factor_on' : 'profile.two_factor_off', $user);
        $this->toast($on ? 'From your next sign-in, a code will be e-mailed to '.$twoFactor->maskedEmail($user).'.' : '2-step sign-in turned off for your account.');
    }

    public function render()
    {
        $employee = $this->ownEmployee()?->load(['department', 'manager', 'teams', 'salaryProfile']);

        $twoFactor = app(\App\Services\TwoFactorService::class);

        return $this->page('livewire.account.profile', ['employee' => $employee, 'user' => $this->user(), 'twoFactorRequired' => $twoFactor->requiredFor($this->user()) && ! $this->user()->two_factor_enabled, 'maskedEmail' => $twoFactor->maskedEmail($this->user()),
            'telegramOn' => app(\App\Services\TelegramNotificationService::class)->enabled(),
            'telegramLink' => \App\Models\TelegramLink::where('user_id', $this->user()->id)->whereNotNull('chat_id')->first()], 'My profile');
    }
}
