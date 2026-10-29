<?php

namespace App\Notifications\Channels;

use App\Models\TelegramLink;
use App\Services\TelegramNotificationService;
use Illuminate\Notifications\Notification;

/**
 * A person's own notification (leave decided, payslip ready, task assigned, fine, correction, …) to the Telegram chat
 * they linked themselves. Only queues the message (sent after the save is committed); not linked = nothing.
 */
class TelegramChannel
{
    public static function enabled(): bool
    {
        return app(TelegramNotificationService::class)->enabled();
    }

    public static function linked(int $userId): bool
    {
        return TelegramLink::where('user_id', $userId)->whereNotNull('chat_id')->exists();
    }

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toTelegram')) return;
        $telegram = app(TelegramNotificationService::class);
        // The Super Admin's own chat already gets the administrative feed: no second copy there.
        $chat = $telegram->chatFor((int) $notifiable->getKey());
        if (! $chat || $chat === $telegram->adminChatId()) return;
        $telegram->toUser($notifiable, null, 'n:'.($notification->id ?? md5(serialize($notification))), $notification->toTelegram($notifiable));
    }
}
