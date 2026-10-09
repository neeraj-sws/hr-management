<?php

namespace App\Notifications\Channels;

use App\Models\PushToken;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends a phone notification through Expo's push service to every phone the
 * person is signed in on. Enabled with EXPO_PUSH_ENABLED=true; phones that
 * report "DeviceNotRegistered" (app removed / signed out) are forgotten.
 */
class ExpoPushChannel
{
    public const ENDPOINT = 'https://exp.host/--/api/v2/push/send';

    public static function enabled(): bool
    {
        return (bool) config('services.expo_push.enabled');
    }

    public function send(object $notifiable, Notification $notification): void
    {
        if (! self::enabled() || ! method_exists($notification, 'toPush')) return;
        $tokens = PushToken::where('user_id', $notifiable->getKey())->pluck('token')->all();
        if (! $tokens) return;
        $message = $notification->toPush($notifiable);
        $payload = array_map(fn ($token) => ['to' => $token, 'sound' => 'default', ...$message], $tokens);

        try {
            $response = Http::timeout(10)->acceptJson()
                ->when(config('services.expo_push.access_token'), fn ($http, $token) => $http->withToken($token))
                ->post(self::ENDPOINT, $payload);
        } catch (\Throwable $e) {
            Log::warning('Expo push failed', ['user_id' => $notifiable->getKey(), 'error' => $e->getMessage()]);

            return;
        }
        if (! $response->successful()) {
            Log::warning('Expo push rejected', ['user_id' => $notifiable->getKey(), 'status' => $response->status()]);

            return;
        }
        foreach ((array) $response->json('data', []) as $index => $ticket) {
            if (($ticket['details']['error'] ?? null) === 'DeviceNotRegistered' && isset($tokens[$index])) PushToken::where('token', $tokens[$index])->delete();
        }
    }
}
