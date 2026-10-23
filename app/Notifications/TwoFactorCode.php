<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The 6-digit sign-in code. Sent straight away (not queued) so the person can sign in now. */
class TwoFactorCode extends Notification
{
    public function __construct(private readonly string $code, private readonly int $minutes) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->code.' is your '.config('app.name', 'SWS HR').' sign-in code')
            ->greeting('Hello '.($notifiable->name ?? '').',')
            ->line('Your sign-in code is:')
            ->line('**'.$this->code.'**')
            ->line("It works once, for {$this->minutes} minutes.")
            ->line('If you did not try to sign in just now, someone may know your password: change it from your profile and tell HR.');
    }
}
