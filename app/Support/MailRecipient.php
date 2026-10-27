<?php

namespace App\Support;

use Illuminate\Notifications\Notifiable;

/** An e-mail address that is not a user (e.g. a team mailbox) that a notification can be sent to. */
class MailRecipient
{
    use Notifiable;

    public function __construct(public string $email, public string $name = 'Team') {}

    public function getKey(): ?int
    {
        return null;
    }
}
