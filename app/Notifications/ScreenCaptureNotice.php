<?php

namespace App\Notifications;

use App\Notifications\Channels\SafeMailChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The screen capture notice: shown in the app and e-mailed (whatever the
 * person's e-mail preferences — it is a policy notice) when capture is turned
 * on for them, when they join while it is on, or when its terms change.
 */
class ScreenCaptureNotice extends Notification
{
    use Queueable;

    /** @param array{frequency: string, when: string, purpose: string, viewers: string, retention: string, never: string} $terms */
    public function __construct(private readonly int $employeeId, private readonly array $terms)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if (! empty($notifiable->email) && SafeMailChannel::enabled()) $channels[] = SafeMailChannel::class;

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject(config('app.name', 'SWS HR').': screen capture is on for your work time')
            ->greeting('Hello '.($notifiable->name ?? '').',')
            ->line('Your employer has turned on screen capture in the SWS HR desktop app for you. This is what it means:')
            ->line('• How often: '.$this->terms['frequency'])
            ->line('• When: '.$this->terms['when'])
            ->line('• Why: '.$this->terms['purpose'])
            ->line('• Who can see the screenshots: '.$this->terms['viewers'])
            ->line('• How long they are kept: '.$this->terms['retention'])
            ->line('• '.$this->terms['never'])
            ->action('Read the monitoring policy', url(route('monitoring.policy', [], false)))
            ->line('The desktop app shows "Screen capture ON" while it is active. Please read the policy and confirm in the app.');
    }

    public function toDatabase(object $notifiable): array
    {
        return ['kind' => 'ScreenCapture', 'record_id' => $this->employeeId, 'message' => 'Screen capture is on for you: '.$this->terms['frequency'].' Only while you are checked in and working. Tap to read the monitoring policy.', 'url' => route('monitoring.policy', [], false)];
    }
}
