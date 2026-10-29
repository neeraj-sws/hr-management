<?php

namespace App\Http\Controllers\Api;

use App\Models\TelegramLink;
use App\Services\TelegramNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The app's "Connect Telegram" (for people who use only the app): status, a single-use 10-minute bot link that the
 * app opens in Telegram (the person taps Start there), and disconnect. Same rules as My profile on the website.
 */
class TelegramController extends ApiController
{
    public function show(Request $request, TelegramNotificationService $telegram): JsonResponse
    {
        $link = TelegramLink::where('user_id', $this->apiUser($request)->id)->whereNotNull('chat_id')->first();

        return response()->json(['data' => ['available' => $telegram->enabled(), 'connected' => (bool) $link, 'username' => $link?->telegram_username, 'linked_at' => $link?->linked_at?->toIso8601String(), 'bot' => $telegram->enabled() ? $telegram->botUsername() : null]]);
    }

    public function connect(Request $request, TelegramNotificationService $telegram): JsonResponse
    {
        abort_unless($telegram->enabled(), 422, 'Telegram notifications are not switched on for the company yet.');
        $link = $telegram->startLink($this->apiUser($request));
        abort_unless($link['url'], 422, 'The Telegram bot is not set up yet. Ask the administrator.');

        return response()->json(['data' => ['url' => $link['url'], 'expires_at' => $link['expires_at']->toIso8601String()]]);
    }

    public function disconnect(Request $request, TelegramNotificationService $telegram): JsonResponse
    {
        $telegram->unlink($this->apiUser($request), 'disconnected in the app');

        return $this->show($request, $telegram);
    }
}
