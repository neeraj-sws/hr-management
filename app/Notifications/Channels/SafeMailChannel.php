<?php

namespace App\Notifications\Channels;

use App\Services\AuditLogger;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Sends the e-mail copy of a notification without ever breaking the action
 * that caused it. With QUEUE_CONNECTION=sync a bad SMTP setting used to throw
 * after the record was already saved (e.g. "500 Server Error" right after an
 * employee was created). Failures are logged, written to the audit log and
 * kept as the "last mail error" shown under Settings > Email.
 */
class SafeMailChannel
{
    public const LAST_ERROR_KEY = 'sws.mail.last_error';

    public function __construct(private readonly MailChannel $mail) {}

    /** Company switch under Settings > Email (on unless an administrator turned it off). */
    public static function enabled(): bool
    {
        $policy = rescue(fn () => app(\App\Services\PolicyConfigurationService::class)->forEmployee(null, 'notifications.email', today()->toDateString(), ['enabled' => true]), ['enabled' => true], false);

        return (bool) ($policy['enabled'] ?? true);
    }

    public function send(object $notifiable, Notification $notification): void
    {
        if (empty($notifiable->email)) return;

        try {
            $this->mail->send($notifiable, $notification);
        } catch (\Throwable $error) {
            self::recordFailure($error, (string) $notifiable->email, class_basename($notification));
        }
    }

    public static function recordFailure(\Throwable $error, string $recipient, string $what): void
    {
        $message = \Illuminate\Support\Str::limit($error->getMessage(), 500);
        Log::error('E-mail delivery failed', ['to' => $recipient, 'notification' => $what, 'error' => $message]);
        rescue(fn () => Cache::put(self::LAST_ERROR_KEY, ['at' => now()->toDateTimeString(), 'to' => $recipient, 'error' => $message], now()->addDays(7)), null, false);
        rescue(fn () => app(AuditLogger::class)->record('notification.email_failed', null, ['to' => $recipient, 'notification' => $what, 'error' => $message]), null, false);
    }
}
