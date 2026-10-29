<?php

namespace App\Http\Controllers;

use App\Models\TelegramLink;
use App\Services\TelegramNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Telegram calls this for each message to the bot. Only calls carrying our secret header are accepted.
 *  - "/start <token>" in a private chat links that chat to the person who made the token in My profile.
 *  - "/start" alone shows how to connect (and the chat ID, which the Super Admin needs once for .env).
 *  - "/stop" disconnects that chat; blocking the bot does the same.
 * The reply goes back in the webhook answer (no second call to Telegram). Tokens are never logged.
 */
class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, TelegramNotificationService $telegram): JsonResponse
    {
        if (! $telegram->configured() || ! hash_equals($telegram->webhookSecret(), (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            return response()->json(['ok' => false], 403);
        }

        // Bot blocked / chat left: that chat is disconnected.
        $member = $request->input('my_chat_member');
        if (is_array($member) && in_array($member['new_chat_member']['status'] ?? '', ['kicked', 'left'], true)) {
            $link = TelegramLink::with('user')->where('chat_id', (string) ($member['chat']['id'] ?? ''))->first();
            if ($link?->user) $telegram->unlink($link->user, 'bot blocked in Telegram');

            return response()->json(['ok' => true]);
        }

        $message = $request->input('message');
        if (! is_array($message) || ($message['chat']['type'] ?? '') !== 'private') return response()->json(['ok' => true]);
        $chatId = (string) ($message['chat']['id'] ?? '');
        $text = trim((string) ($message['text'] ?? ''));
        if ($chatId === '' || $text === '') return response()->json(['ok' => true]);

        if (preg_match('/^\/start(?:@\w+)?\s+(\S+)$/', $text, $m)) {
            $result = $telegram->completeLink($m[1], $chatId, $message['from']['username'] ?? null);

            return $this->reply($chatId, ($result['ok'] ? '✅ ' : '⚠️ ').$result['message']);
        }
        if (preg_match('/^\/stop(?:@\w+)?$/', $text)) {
            $link = TelegramLink::with('user')->where('chat_id', $chatId)->first();
            if ($link?->user) $telegram->unlink($link->user, 'stopped in Telegram');

            return $this->reply($chatId, $link ? 'Disconnected. You will not get SWS HRMS notifications here any more.' : 'This chat is not connected to SWS HRMS.');
        }
        $linked = TelegramLink::where('chat_id', $chatId)->exists();

        return $this->reply($chatId, ($linked ? "This chat is connected to SWS HRMS.\n\n" : "To get your SWS HRMS notifications here: sign in to SWS HRMS, open My profile and tap \"Connect Telegram\".\n\n").'Chat ID: '.$chatId);
    }

    private function reply(string $chatId, string $text): JsonResponse
    {
        return response()->json(['method' => 'sendMessage', 'chat_id' => $chatId, 'text' => $text]);
    }
}
