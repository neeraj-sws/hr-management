<?php

namespace App\Services;

use App\Jobs\SendTelegramNotification;
use App\Models\TelegramLink;
use App\Models\TelegramSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Telegram through the official Bot API (https://core.telegram.org/bots/api), free.
 *
 *  - Super Admin: administrative events go to one private chat (TELEGRAM_ADMIN_CHAT_ID in .env).
 *  - Employees: their own notifications go to the private chat they linked themselves (My profile > Connect Telegram),
 *    never to anybody else; not linked = no Telegram, e-mail and in-app go on as before.
 *
 * Off unless TELEGRAM_NOTIFICATIONS_ENABLED=true, a bot token is set, and System > Telegram is switched on. Messages
 * are queued and sent after the save is committed; the token never reaches logs, the browser or API responses.
 */
class TelegramNotificationService
{
    public const API = 'https://api.telegram.org';

    public const SETTINGS_KEY = 'notifications.telegram';

    /** Event => [label, to the Super Admin by default, to the employee's own chat by default (null = not offered)]. */
    public const EVENTS = [
        'check_in' => ['Attendance started (check-in, late shown)', true, true],
        'check_out' => ['Check-out', true, true],
        'break_start' => ['Break started', true, null],
        'break_end' => ['Break ended (with break length)', true, null],
        'absent' => ['Absent (not checked in by the alert time)', true, null],
        'leave_applied' => ['Leave request', true, null],
        'leave_decided' => ['Leave approved / rejected', true, null],
        'leave_cancelled' => ['Leave cancelled', true, null],
        'auto_rejected' => ['Leave / late reason rejected automatically (personal reason)', true, null],
        'overtime_requested' => ['Overtime requested', true, null],
        'expense_submitted' => ['Expense claim submitted', true, null],
        'night_work_requested' => ['Night work requested', true, null],
        'leave_pending' => ['Leave not decided (list every morning, after 24 hours, on its day)', true, null],
        'correction_requested' => ['Attendance correction requested', true, null],
        'daily_status' => ['Daily status submitted (an old day shows its reason)', true, null],
        'correction_decided' => ['Attendance correction approved / rejected', true, null],
        'task_assigned' => ['Task assigned', true, null],
        'task_completed' => ['Task completed', true, null],
        'payslip' => ['Salary slip generated', true, null],
        'payslip_request' => ['Payslip requested (old month)', true, null],
        'salary_payment' => ['Salary payment recorded', true, null],
        'advance_paid' => ['Advance payment recorded', true, null],
        'fine' => ['Fine made / removed', true, null],
        'delivery_error' => ['Telegram delivery problems', true, null],
    ];

    /** A value set in System > Telegram, else the one in .env (config). */
    private function setting(string $field): ?string
    {
        $value = trim((string) (TelegramSetting::current()?->{$field} ?? ''));
        if ($value === '') $value = trim((string) config('services.telegram.'.$field));

        return $value !== '' ? $value : null;
    }

    /** Bot token: saved in System > Telegram (encrypted) or in .env. Never logged or shown. */
    private function token(): ?string
    {
        return $this->setting('bot_token');
    }

    /** Where each part comes from, for the setup checks: "HRMS", ".env" or null (not set). */
    public function source(string $field): ?string
    {
        $saved = TelegramSetting::current();
        if ($field === 'enabled') return $saved?->enabled !== null ? 'HRMS' : (config('services.telegram.enabled') ? '.env' : null);
        if (trim((string) ($saved?->{$field} ?? '')) !== '') return 'HRMS';

        return trim((string) config('services.telegram.'.$field)) !== '' ? '.env' : null;
    }

    /**
     * Saves the bot from System > Telegram. A new token is checked with Telegram first (getMe) and its @username kept;
     * an empty token field keeps the saved one. Then the bot is pointed at this website.
     *
     * @return array{ok: bool, error: ?string, username: ?string}
     */
    public function saveBot(bool $enabled, ?string $token, ?string $adminChatId, int $actor, ?string $webhookUrl = null): array
    {
        $row = TelegramSetting::current() ?? new TelegramSetting;
        $token = trim((string) $token);
        $username = $row->bot_username;
        if ($token !== '') {
            try {
                $me = Http::timeout(10)->acceptJson()->get(self::API.'/bot'.$token.'/getMe');
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => 'Could not reach Telegram: '.Str::limit(str_replace($token, '***', $e->getMessage()), 100), 'username' => null];
            }
            if ($me->json('ok') !== true) return ['ok' => false, 'error' => 'Telegram did not accept this bot token ('.Str::limit((string) $me->json('description', 'HTTP '.$me->status()), 80).'). Copy it again from @BotFather.', 'username' => null];
            $row->bot_token = $token;
            $username = (string) $me->json('result.username');
        }
        $row->fill(['enabled' => $enabled, 'bot_username' => $username ?: null, 'admin_chat_id' => trim((string) $adminChatId) ?: null, 'updated_by' => $actor])->save();
        Cache::forget('telegram.bot_username');
        app(\App\Services\AuditLogger::class)->record('telegram.bot_saved', $row, ['token_changed' => $token !== '', 'enabled' => $enabled]);
        if ($token !== '' && $webhookUrl) $this->setWebhook($webhookUrl);

        return ['ok' => true, 'error' => null, 'username' => $username ?: null];
    }

    /** Forgets the token saved in the HRMS (.env is used again, if it has one). */
    public function removeSavedToken(int $actor): void
    {
        $row = TelegramSetting::current();
        if (! $row) return;
        $row->forceFill(['bot_token' => null, 'bot_username' => null, 'updated_by' => $actor])->save();
        Cache::forget('telegram.bot_username');
        app(\App\Services\AuditLogger::class)->record('telegram.bot_token_removed', $row);
    }

    public function configured(): bool
    {
        return $this->token() !== null;
    }

    /** Bot turned on (System > Telegram, else .env) + a token + the master switch for messages. */
    public function enabled(): bool
    {
        $saved = TelegramSetting::current()?->enabled;
        $on = $saved !== null ? (bool) $saved : (bool) config('services.telegram.enabled');

        return $on && $this->configured() && (bool) ($this->settings()['on'] ?? true);
    }

    public function adminChatId(): ?string
    {
        return $this->setting('admin_chat_id');
    }

    /** {on, employees, admin: {event: bool}, employee: {event: bool}} with defaults. */
    public function settings(): array
    {
        $saved = app(PolicyConfigurationService::class)->forEmployee(null, self::SETTINGS_KEY, today()->toDateString(), []);
        $admin = [];
        $employee = [];
        foreach (self::EVENTS as $event => [, $adminDefault, $employeeDefault]) {
            $admin[$event] = (bool) ($saved['admin'][$event] ?? $adminDefault);
            if ($employeeDefault !== null) $employee[$event] = (bool) ($saved['employee'][$event] ?? $employeeDefault);
        }

        return ['on' => (bool) ($saved['on'] ?? true), 'employees' => (bool) ($saved['employees'] ?? true), 'format' => ($saved['format'] ?? 'html') === 'text' ? 'text' : 'html',
            // People an administrator chose to get the Super Admin feed too (in their own linked chat).
            'extra_users' => array_values(array_map('intval', (array) ($saved['extra_users'] ?? []))), 'admin' => $admin, 'employee' => $employee];
    }

    public function saveSettings(array $settings, int $actor): void
    {
        $extra = array_values(array_unique(array_map('intval', (array) ($settings['extra_users'] ?? []))));
        // Only active people who connected Telegram themselves can be added.
        $extra = $extra ? TelegramLink::whereIn('user_id', $extra)->whereNotNull('chat_id')->whereHas('user', fn ($q) => $q->where('is_active', true))->pluck('user_id')->map(fn ($id) => (int) $id)->values()->all() : [];
        $clean = ['on' => (bool) ($settings['on'] ?? true), 'employees' => (bool) ($settings['employees'] ?? true), 'format' => ($settings['format'] ?? 'html') === 'text' ? 'text' : 'html', 'extra_users' => $extra, 'admin' => [], 'employee' => []];
        foreach (self::EVENTS as $event => [, , $employeeDefault]) {
            $clean['admin'][$event] = (bool) ($settings['admin'][$event] ?? false);
            if ($employeeDefault !== null) $clean['employee'][$event] = (bool) ($settings['employee'][$event] ?? false);
        }
        app(PeopleOperationsService::class)->setPolicy(self::SETTINGS_KEY, 'notifications', 'Telegram notifications', $clean, null, $actor, today()->toDateString());
    }

    public static function escape(?string $text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** "SWS HRMS Alert" header, the title line, then "Label: value" lines (all values escaped). */
    public static function format(string $title, array $lines = [], ?string $footer = null): string
    {
        $text = "<b>SWS HRMS Alert</b>\n<b>SWS HRMS | ".self::escape(Str::upper($title))."</b>\n";
        foreach ($lines as $label => $value) {
            if ($value === null || $value === '') continue;
            $text .= "\n".(is_int($label) ? self::escape((string) $value) : self::escape($label).': '.self::escape((string) $value));
        }

        return $footer ? $text."\n\n".self::escape($footer) : $text;
    }

    /** Administrative event to the Super Admin chat (if that event is on), and to anyone an administrator added. */
    public function toAdmin(string $event, string $key, string $text): void
    {
        $settings = $this->settings();
        if (! $this->enabled() || ! ($settings['admin'][$event] ?? false)) return;
        if ($this->adminChatId()) $this->queue('admin', null, "admin:{$key}", $event, $text);
        foreach ($settings['extra_users'] as $userId) {
            $chat = $this->chatFor($userId);
            if ($chat && $chat !== $this->adminChatId()) $this->queue('user', $userId, "admin:{$key}:u{$userId}", $event, $text);
        }
    }

    /** A person's own message to their linked chat. $event null = an in-app notification (their own preferences decide). */
    public function toUser(?User $user, ?string $event, string $key, string $text): void
    {
        if (! $user || ! ($user->is_active ?? true) || ! $this->enabled()) return;
        $settings = $this->settings();
        if (! $settings['employees'] || ($event !== null && ! ($settings['employee'][$event] ?? false))) return;
        if (! $this->chatFor($user->id)) return;
        $this->queue('user', $user->id, "user:{$user->id}:{$key}", $event, $text);
    }

    /** The linked private chat of a person (never a chat given by Telegram or a request). */
    public function chatFor(int $userId): ?string
    {
        return TelegramLink::where('user_id', $userId)->whereNotNull('chat_id')->value('chat_id');
    }

    private function queue(string $target, ?int $userId, string $key, ?string $event, string $text): void
    {
        $connection = (string) config('services.telegram.queue_connection', 'database');
        SendTelegramNotification::dispatch($target, $userId, mb_substr($key, 0, 191), $event, $text)->onConnection($connection)->onQueue('telegram')->afterCommit();
    }

    /**
     * Sends at once. Result: ok, permanent (a retry will not help: chat not found, bot blocked) and a short reason
     * that never contains the token.
     *
     * @return array{ok: bool, permanent: bool, error: ?string, blocked: bool}
     */
    public function send(string $chatId, string $text): array
    {
        $token = $this->token();
        if (! $token) return ['ok' => false, 'permanent' => true, 'error' => 'Bot token not set', 'blocked' => false];
        // System > Telegram > Message format: plain text drops the bold and shows links as "Open SWS HRMS: <address>".
        $plain = $this->settings()['format'] === 'text';
        $payload = ['chat_id' => $chatId, 'text' => mb_substr($plain ? self::plain($text) : $text, 0, 4000), 'disable_web_page_preview' => true];
        if (! $plain) $payload['parse_mode'] = 'HTML';
        try {
            $response = Http::timeout(10)->connectTimeout(5)->acceptJson()->asJson()
                ->post(self::API.'/bot'.$token.'/sendMessage', $payload);
        } catch (\Throwable $e) {
            return ['ok' => false, 'permanent' => false, 'error' => 'Network: '.Str::limit(str_replace($token, '***', $e->getMessage()), 120), 'blocked' => false];
        }
        if ($response->successful() && $response->json('ok') === true) return ['ok' => true, 'permanent' => false, 'error' => null, 'blocked' => false];
        $status = $response->status();
        $description = Str::limit(str_replace($token, '***', (string) $response->json('description', 'HTTP '.$status)), 160);

        return ['ok' => false, 'permanent' => in_array($status, [400, 401, 403, 404], true), 'error' => "{$status}: {$description}", 'blocked' => $status === 403];
    }

    /** The HTML message as plain text. */
    public static function plain(string $html): string
    {
        $text = preg_replace('/<a\s+href="([^"]*)"[^>]*>(.*?)<\/a>/is', '$2: $1', $html);

        return html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** The bot's @username (for the link); from .env or asked once from Telegram. */
    public function botUsername(): ?string
    {
        $name = ltrim((string) $this->setting('bot_username'), '@');
        if ($name !== '') return $name;
        if (! $this->configured()) return null;

        return Cache::remember('telegram.bot_username', 3600, function () {
            $me = rescue(fn () => Http::timeout(8)->acceptJson()->get(self::API.'/bot'.$this->token().'/getMe')->json('result.username'), null, false);

            return $me ?: null;
        });
    }

    /** Secret Telegram sends back on each webhook call, so nobody else can post to the webhook. */
    public function webhookSecret(): string
    {
        $secret = trim((string) config('services.telegram.webhook_secret'));

        return $secret !== '' ? $secret : substr(hash_hmac('sha256', 'telegram-webhook', (string) config('app.key')), 0, 40);
    }

    /** Points the bot at this site's webhook (System > Telegram > "Connect the bot to this website"). */
    public function setWebhook(string $url): array
    {
        if (! $this->configured()) return ['ok' => false, 'error' => 'Bot token not set'];
        try {
            $response = Http::timeout(10)->acceptJson()->asJson()->post(self::API.'/bot'.$this->token().'/setWebhook', ['url' => $url, 'secret_token' => $this->webhookSecret(), 'allowed_updates' => ['message', 'my_chat_member'], 'drop_pending_updates' => true]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => Str::limit(str_replace((string) $this->token(), '***', $e->getMessage()), 120)];
        }

        return ['ok' => $response->json('ok') === true, 'error' => $response->json('ok') === true ? null : (string) $response->json('description', 'HTTP '.$response->status())];
    }

    /**
     * Starts linking for the signed-in person: a random single-use token (only its hash is kept) valid for a few
     * minutes, and the bot link that carries it (https://t.me/<bot>?start=<token>).
     *
     * @return array{token: string, url: ?string, expires_at: \Illuminate\Support\Carbon}
     */
    public function startLink(User $user): array
    {
        $token = Str::random(40);
        $expires = now()->addMinutes(max(1, (int) config('services.telegram.link_minutes', 10)));
        TelegramLink::updateOrCreate(['user_id' => $user->id], ['token_hash' => hash('sha256', $token), 'token_expires_at' => $expires]);
        $bot = $this->botUsername();

        return ['token' => $token, 'url' => $bot ? "https://t.me/{$bot}?start={$token}" : null, 'expires_at' => $expires];
    }

    /**
     * /start <token> from a private chat: links that chat to the person who made the token. A token works once and
     * only until it expires; a chat already linked to someone else is refused.
     *
     * @return array{ok: bool, message: string, user: ?User}
     */
    public function completeLink(string $token, string $chatId, ?string $username): array
    {
        $link = preg_match('/^[A-Za-z0-9]{20,64}$/', $token) ? TelegramLink::with('user')->where('token_hash', hash('sha256', $token))->first() : null;
        if (! $link || ! $link->token_expires_at || $link->token_expires_at->isPast() || ! $link->user || ! ($link->user->is_active ?? true)) {
            return ['ok' => false, 'message' => 'This link has expired or was already used. Open SWS HRMS > My profile > Connect Telegram and try again.', 'user' => null];
        }
        $other = TelegramLink::where('chat_id', $chatId)->where('user_id', '!=', $link->user_id)->exists();
        if ($other) {
            $link->update(['token_hash' => null, 'token_expires_at' => null]);

            return ['ok' => false, 'message' => 'This Telegram account is already connected to another SWS HRMS account. Disconnect it there first.', 'user' => null];
        }
        $link->update(['chat_id' => $chatId, 'telegram_username' => $username ? mb_substr($username, 0, 100) : null, 'token_hash' => null, 'token_expires_at' => null, 'linked_at' => now()]);
        app(AuditLogger::class)->record('telegram.linked', $link->user, []);

        return ['ok' => true, 'message' => 'Connected. You will get your SWS HRMS notifications here, '.$link->user->name.'.', 'user' => $link->user];
    }

    public function unlink(User $user, string $why = 'disconnected'): void
    {
        $link = TelegramLink::where('user_id', $user->id)->first();
        if (! $link) return;
        $link->update(['chat_id' => null, 'telegram_username' => null, 'token_hash' => null, 'token_expires_at' => null, 'linked_at' => null]);
        app(AuditLogger::class)->record('telegram.unlinked', $user, ['why' => $why]);
    }
}
