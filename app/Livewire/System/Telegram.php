<?php

namespace App\Livewire\System;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\TelegramLink;
use App\Models\TelegramMessage;
use App\Services\TelegramNotificationService;
use Livewire\Component;

/**
 * System > Telegram (administrators only): setup checks, connecting the bot to this website, a test message to the
 * Super Admin chat, which events go where, who has connected, and the latest messages. The bot token and the Super
 * Admin chat ID live in .env only and are never shown here in full.
 */
class Telegram extends Component
{
    use InteractsWithHr;

    public array $form = [];

    public function mount(TelegramNotificationService $telegram): void
    {
        $this->form = $telegram->settings();
    }

    public function save(TelegramNotificationService $telegram): void
    {
        abort_unless(\App\Support\Access::isAdmin($this->user()), 403);
        $this->validate(['form.format' => ['required', 'in:html,text'], 'form.extra_users' => ['array'], 'form.extra_users.*' => ['integer', 'exists:users,id']], [], ['form.extra_users' => 'people']);
        $telegram->saveSettings($this->form, $this->user()->id);
        $this->form = $telegram->settings();
        $this->toast('Telegram settings saved.');
    }

    public function connectWebhook(TelegramNotificationService $telegram): void
    {
        abort_unless(\App\Support\Access::isAdmin($this->user()), 403);
        $result = $telegram->setWebhook(route('api.telegram.webhook'));
        $result['ok'] ? $this->toast('The bot is connected to this website.') : $this->addError('telegram', 'Telegram said: '.$result['error']);
    }

    /** Sent at once (not queued) so the result shows here. */
    public function sendTest(TelegramNotificationService $telegram): void
    {
        abort_unless(\App\Support\Access::isAdmin($this->user()), 403);
        $chat = $telegram->adminChatId();
        if (! $chat) {
            $this->addError('telegram', 'Set TELEGRAM_ADMIN_CHAT_ID in .env first (send /start to the bot to see your chat ID).');

            return;
        }
        $result = $telegram->send($chat, TelegramNotificationService::format('Test message', ['Telegram notifications are working.', 'Sent by' => $this->user()->name, 'Time' => now()->format('d F Y, h:i A')]));
        $result['ok'] ? $this->toast('Test message sent to the Super Admin chat.') : $this->addError('telegram', 'Not sent - '.$result['error']);
    }

    public function disconnect(int $userId, TelegramNotificationService $telegram): void
    {
        abort_unless(\App\Support\Access::isAdmin($this->user()), 403);
        $user = \App\Models\User::findOrFail($userId);
        $telegram->unlink($user, 'disconnected by an administrator');
        $this->toast("Telegram disconnected for {$user->name}.");
    }

    public function render(TelegramNotificationService $telegram)
    {
        $admin = (string) $telegram->adminChatId();

        return $this->page('livewire.system.telegram', [
            'checks' => [
                ['TELEGRAM_NOTIFICATIONS_ENABLED=true in .env', (bool) config('services.telegram.enabled')],
                ['TELEGRAM_BOT_TOKEN set in .env', $telegram->configured()],
                ['TELEGRAM_ADMIN_CHAT_ID set in .env'.($admin !== '' ? ' (…'.substr($admin, -4).')' : ''), $admin !== ''],
                ['Bot username'.($telegram->configured() && $telegram->botUsername() ? ': @'.$telegram->botUsername() : ''), (bool) ($telegram->configured() && $telegram->botUsername())],
            ],
            'events' => TelegramNotificationService::EVENTS,
            'links' => TelegramLink::with('user')->whereNotNull('chat_id')->latest('linked_at')->get(),
            'messages' => TelegramMessage::with([])->latest('id')->limit(25)->get(),
            'webhookUrl' => route('api.telegram.webhook'),
            'queueConnection' => (string) config('services.telegram.queue_connection', 'database'),
        ], 'Telegram');
    }
}
