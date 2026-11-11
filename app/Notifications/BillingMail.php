<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A billing e-mail to a customer / client at a stage of the workflow (proposal sent, payment received,
 * account statement, client portal login…). Sent from the address set in Billing settings when there is one.
 */
class BillingMail extends Notification
{
    use Queueable;

    /** @param string[] $lines */
    public function __construct(
        private readonly string $subject,
        private readonly string $greeting,
        private readonly array $lines,
        private readonly ?string $actionText = null,
        private readonly ?string $actionUrl = null,
        private readonly string $message = '',
        private readonly array $company = [],
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $from = ($this->company['name'] ?? '') ?: config('app.name');
        $mail = new MailMessage;
        if (filter_var($this->company['mail_from_address'] ?? '', FILTER_VALIDATE_EMAIL)) {
            $mail->from($this->company['mail_from_address'], ($this->company['mail_from_name'] ?? '') ?: $from);
        }
        $mail->subject($this->subject)->greeting($this->greeting);
        if (trim($this->message) !== '') $mail->line(trim($this->message));
        foreach ($this->lines as $line) $mail->line($line);
        if ($this->actionText && $this->actionUrl) $mail->action($this->actionText, $this->actionUrl);

        return $mail->salutation('Regards, '.$from);
    }
}
