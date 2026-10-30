<?php

namespace App\Livewire\System;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\TelegramLink;
use App\Models\TelegramMessage;
use App\Services\TelegramNotificationService;
use Livewire\Component;

/**
 * System > Telegram (administrators only): setup checks, connecting the bot to this website, a test message to the
 * Super Admin chat, which events go where, who has connected, and the latest messages. The bot (on/off, token,
 * Super Admin chat ID) is set here and saved in the HRMS - .env is only a fallback. The token is stored encrypted and
 * never shown again, not even in part.
 */
class Telegram extends Component
{
    use InteractsWithHr, \Livewire\WithPagination;

    /** Latest messages filters: to (admin / user), status, day. */
    public string $msgTarget = '';
    public string $msgStatus = '';
    public string $msgDay = '';

    public array $form = [];

    /** Bot connection form. The token field always starts empty (empty on save = keep the saved token). */
    public bool $botEnabled = true;
    public string $botToken = '';
    public string $adminChatId = '';

    public function mount(TelegramNotificationService $telegram): void
    {
        $this->form = $telegram->settings();
        $saved = \App\Models\TelegramSetting::current();
        $this->botEnabled = $saved?->enabled ?? ((bool) config('services.telegram.enabled') || ! $telegram->configured());
        $this->adminChatId = (string) $telegram->adminChatId();
    }

    public function saveBot(TelegramNotificationService $telegram): void
    {
        abort_unless(\App\Support\Access::isAdmin($this->user()), 403);
        $this->validate([
            'botToken' => ['nullable', 'string', 'max:100', 'regex:/^\d{5,15}:[A-Za-z0-9_-]{30,60}$/'],
            'adminChatId' => ['nullable', 'string', 'regex:/^-?\d{4,20}$/'],
        ], ['botToken.regex' => 'This does not look like a bot token (it looks like 123456789:ABC...). Copy it again from @BotFather.', 'adminChatId.regex' => 'The chat ID is a number (e.g. 1234567890; a group starts with -).'], ['botToken' => 'bot token', 'adminChatId' => 'Super Admin chat ID']);
        if (trim($this->botToken) === '' && ! $telegram->configured()) {
            $this->addError('botToken', 'Paste the bot token from @BotFather.');

            return;
        }
        $result = $telegram->saveBot($this->botEnabled, $this->botToken, $this->adminChatId, $this->user()->id, route('api.telegram.webhook'));
        $this->botToken = '';
        if (! $result['ok']) {
            $this->addError('botToken', $result['error']);

            return;
        }
        $this->adminChatId = (string) $telegram->adminChatId();
        $this->toast('Telegram bot saved'.($result['username'] ? ' (@'.$result['username'].')' : '').'.');
    }

    /** The signed-in administrator's own connected Telegram becomes the Super Admin chat (no typing of IDs). */
    public function useMyChat(): void
    {
        abort_unless(\App\Support\Access::isAdmin($this->user()), 403);
        $chat = TelegramLink::where('user_id', $this->user()->id)->whereNotNull('chat_id')->value('chat_id');
        if (! $chat) {
            $this->addError('adminChatId', 'Connect your own Telegram first (My profile > Telegram > Connect), then press this again.');

            return;
        }
        $this->adminChatId = (string) $chat;
        $this->toast('Your Telegram chat is filled in. Press "Save bot" to keep it.');
    }

    public function removeToken(TelegramNotificationService $telegram): void
    {
        abort_unless(\App\Support\Access::isAdmin($this->user()), 403);
        $telegram->removeSavedToken($this->user()->id);
        $this->toast('The saved bot token was removed.');
    }

    /** Tick or untick every event in one column ("admin" or "employee") - saved with Save. */
    public function setAll(string $column, bool $on): void
    {
        abort_unless(in_array($column, ['admin', 'employee'], true), 404);
        foreach (TelegramNotificationService::EVENTS as $event => [, , $employeeDefault]) {
            if ($column === 'employee' && $employeeDefault === null) continue;
            $this->form[$column][$event] = $on;
        }
    }

    public function updated($property): void
    {
        if (in_array($property, ['msgTarget', 'msgStatus', 'msgDay'], true)) $this->resetPage('msgs');
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
            $this->addError('telegram', 'Fill in the Super Admin chat ID under "Bot connection" first (or press "Use my Telegram").');

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
        $from = fn (string $field) => ($s = $telegram->source($field)) ? " · from {$s}" : '';
        $saved = \App\Models\TelegramSetting::current();

        return $this->page('livewire.system.telegram', [
            'checks' => [
                ['Bot turned on'.$from('enabled'), $saved?->enabled !== null ? (bool) $saved->enabled : (bool) config('services.telegram.enabled')],
                ['Bot token saved'.$from('bot_token'), $telegram->configured()],
                ['Super Admin chat ID'.($admin !== '' ? ' (…'.substr($admin, -4).')' : '').$from('admin_chat_id'), $admin !== ''],
                ['Bot username'.($telegram->configured() && $telegram->botUsername() ? ': @'.$telegram->botUsername() : ''), (bool) ($telegram->configured() && $telegram->botUsername())],
            ],
            'tokenSource' => $telegram->source('bot_token'),
            'savedBot' => $saved?->loadMissing('updater'),
            'myChatLinked' => TelegramLink::where('user_id', $this->user()->id)->whereNotNull('chat_id')->exists(),
            'events' => TelegramNotificationService::EVENTS,
            'links' => TelegramLink::with('user')->whereNotNull('chat_id')->latest('linked_at')->get(),
            // Latest messages: filtered, 20 a page, grouped by day on the page; who and what in words.
            'messages' => TelegramMessage::with('user')
                ->when($this->msgTarget !== '', fn ($q) => $q->where('target', $this->msgTarget))
                ->when($this->msgStatus !== '', fn ($q) => $q->where('status', $this->msgStatus))
                ->when(preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->msgDay), fn ($q) => $q->whereDate('created_at', $this->msgDay))
                ->latest('id')->paginate(20, ['*'], 'msgs'),
            'messageCounts' => TelegramMessage::whereDate('created_at', today())->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all(),
            'webhookUrl' => route('api.telegram.webhook'),
            'queueConnection' => (string) config('services.telegram.queue_connection', 'database'),
        ], 'Telegram');
    }
}
