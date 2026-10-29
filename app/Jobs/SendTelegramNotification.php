<?php

namespace App\Jobs;

use App\Models\TelegramMessage;
use App\Models\User;
use App\Services\TelegramNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Sends one Telegram message (to the Super Admin chat or one person's own linked chat), once: its key is recorded, so
 * a retried job never sends it twice. Network trouble / Telegram busy = tried again later; chat not found / bot
 * blocked = given up (a blocked bot disconnects that person). Queued after the save is committed.
 */
class SendTelegramNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 4;

    public int $timeout = 30;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function __construct(public string $target, public ?int $userId, public string $key, public ?string $event, public string $text) {}

    public function handle(TelegramNotificationService $telegram): void
    {
        if (! $telegram->enabled()) return;
        $record = TelegramMessage::firstOrCreate(['key' => $this->key], ['target' => $this->target, 'user_id' => $this->userId, 'event' => $this->event, 'status' => 'pending']);
        if ($record->status === 'sent') return;
        // Resolved now, from the configuration or the verified link (never from the event).
        $chatId = $this->target === 'admin' ? $telegram->adminChatId() : ($this->userId ? $telegram->chatFor($this->userId) : null);
        if (! $chatId) {
            $record->update(['status' => 'skipped', 'error' => 'No chat linked']);

            return;
        }
        $result = $telegram->send($chatId, $this->text);
        $record->increment('attempts');
        if ($result['ok']) {
            $record->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);

            return;
        }
        $record->update(['status' => $result['permanent'] ? 'failed' : 'retrying', 'error' => $result['error']]);
        Log::warning('Telegram message not sent', ['key' => $this->key, 'target' => $this->target, 'error' => $result['error']]);
        if ($result['permanent']) {
            if ($result['blocked'] && $this->target === 'user' && $this->userId && ($user = User::find($this->userId))) $telegram->unlink($user, 'bot blocked in Telegram');
            $this->reportToAdmin($telegram, (string) $result['error']);

            return;
        }
        $this->release($this->backoff()[min($this->attempts() - 1, 2)] ?? 300);
    }

    public function failed(?\Throwable $e): void
    {
        TelegramMessage::where('key', $this->key)->update(['status' => 'failed']);
        $this->reportToAdmin(app(TelegramNotificationService::class), 'gave up after retries');
    }

    /** One alert an hour at most to the Super Admin chat (never about its own failures, never repeated per message). */
    private function reportToAdmin(TelegramNotificationService $telegram, string $error): void
    {
        if ($this->target === 'admin' || ! Cache::add('telegram.error_alert', true, 3600)) return;
        $telegram->toAdmin('delivery_error', 'error:'.now()->format('YmdH'), TelegramNotificationService::format('Telegram delivery problem', ['A message could not be delivered', 'Reason' => $error], 'Check System > Telegram. This alert is sent at most once an hour.'));
    }
}
