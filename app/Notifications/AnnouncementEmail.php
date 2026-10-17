<?php

namespace App\Notifications;

use App\Models\Announcement;
use App\Notifications\Channels\SafeMailChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The full announcement by e-mail, when its author ticked "Also send by e-mail". */
class AnnouncementEmail extends Notification
{
    use Queueable;

    public function __construct(private readonly Announcement $announcement)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ! empty($notifiable->email) && ($notifiable->is_active ?? true) && SafeMailChannel::enabled() ? [SafeMailChannel::class] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject(config('app.name', 'SWS HR').' announcement: '.$this->announcement->title)
            ->greeting($this->announcement->title);
        foreach (preg_split('/\R{2,}/', trim($this->announcement->body)) as $paragraph) $mail->line($paragraph);

        return $mail->action('Open announcements', url(route('company.announcements', [], false)));
    }
}
