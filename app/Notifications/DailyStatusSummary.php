<?php

namespace App\Notifications;

use App\Models\DailyWorkStatus;
use App\Notifications\Channels\SafeMailChannel;
use App\Support\Duration;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * End-of-day e-mail of one person's daily status: plan, what was completed, blockers, each task with its
 * status and time, and the day's timing (in–out, worked, break, idle). Sent to the person and their manager.
 */
class DailyStatusSummary extends Notification
{
    use Queueable;

    public function __construct(private readonly DailyWorkStatus $status, private readonly array $timing) {}

    public function via(object $notifiable): array
    {
        return ! empty($notifiable->email) && SafeMailChannel::enabled() ? [SafeMailChannel::class] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $s = $this->status;
        $name = $s->employee?->name ?? 'Employee';
        $own = $s->employee?->user_id === $notifiable->getKey();
        $t = $this->timing;
        $mail = (new MailMessage)
            ->subject(config('app.name', 'SWS HR').': daily status of '.($own ? 'your day' : $name).' · '.$s->work_date->format('D, d M Y'))
            ->greeting('Hello '.($notifiable->name ?? '').',')
            ->line($own ? 'Here is the summary of your day.' : "Here is {$name}'s summary for the day.")
            ->line('**Timing:** check-in '.($t['check_in'] ?? '—').' · check-out '.($t['check_out'] ?? 'still in').' · worked '.Duration::hms($t['worked']).' · break '.Duration::hms($t['break']).' · idle '.Duration::hms($t['idle']).' · task time '.Duration::hms($t['task_seconds']));
        if ($s->plan) $mail->line('**Plan:** '.$s->plan);
        $mail->line('**Completed:** '.$s->summary);
        foreach ((array) ($s->project_updates ?? []) as $line) {
            $tasks = collect((array) ($line['tasks'] ?? []))->map(fn ($task) => '• '.$task['title'].' — '.str($task['status'] ?? '')->replace('_', ' ').(! empty($task['seconds']) ? ' · '.Duration::hms($task['seconds']) : '').(! empty($task['note']) ? ' · '.$task['note'] : ''))->join("\n");
            $mail->line('**'.($line['project'] ?? 'Other work').'**'.(! empty($line['seconds']) ? ' ('.Duration::hms($line['seconds']).')' : '').(! empty($line['update']) ? ': '.$line['update'] : '').($tasks !== '' ? "\n".$tasks : ''));
        }
        if ($s->pending) $mail->line('**Pending:** '.$s->pending);
        if ($s->tomorrow_plan) $mail->line('**Plan for tomorrow:** '.$s->tomorrow_plan);
        if ($s->blockers) $mail->line('**Blockers:** '.$s->blockers);

        return $mail->action('Open daily status', url(route('work.daily-status', ['employee' => $s->employee_id, 'date' => $s->work_date->toDateString()], false)))
            ->line('Sent at the end of each working day to the person and their manager.');
    }
}
