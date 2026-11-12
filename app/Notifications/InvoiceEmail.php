<?php

namespace App\Notifications;

use App\Models\BillingInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The invoice e-mailed to the customer: "send" (new / resend) or "reminder" (payment due).
 * Carries the link the customer opens without signing in.
 */
class InvoiceEmail extends Notification
{
    use Queueable;

    public function __construct(
        private readonly BillingInvoice $invoice,
        private readonly string $kind,
        private readonly string $url,
        private readonly array $company,
        private readonly string $message = '',
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $i = $this->invoice;
        $from = $this->company['name'] ?: config('app.name');
        $money = fn ($v) => '₹'.number_format((float) $v, 2);
        $due = max(0, $i->due());
        $reminder = $this->kind === 'reminder';

        $mail = new MailMessage;
        if (filter_var($this->company['mail_from_address'] ?? '', FILTER_VALIDATE_EMAIL)) {
            $mail->from($this->company['mail_from_address'], ($this->company['mail_from_name'] ?? '') ?: $from);
        }
        $mail
            ->subject(($reminder ? 'Payment reminder: invoice ' : 'Invoice ').$i->number().' from '.$from)
            ->greeting('Hello '.($i->customer?->billing_name ?: $i->customer?->name ?: '').',');
        if (trim($this->message) !== '') $mail->line(trim($this->message));
        $mail->line($reminder
            ? 'This is a friendly reminder that invoice **'.$i->number().'** has **'.$money($due).'** due'.($i->due_date ? ' (due date '.$i->due_date->format('d M Y').')' : '').'.'
            : 'Please find invoice **'.$i->number().'** dated '.$i->issue_date->format('d M Y').' for **'.$money($i->total()).'**.');
        if (! $reminder && $i->due_date) $mail->line('Due date: '.$i->due_date->format('d M Y').($due < $i->total() ? ' · balance due '.$money($due) : ''));
        $mail->action('View invoice', $this->url)
            ->line('You can open, print or save the invoice as PDF from the link.')
            ->salutation('Regards, '.$from);

        return $mail;
    }
}
