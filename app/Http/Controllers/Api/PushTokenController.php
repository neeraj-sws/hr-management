<?php

namespace App\Http\Controllers\Api;

use App\Models\PushToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The phone registers its Expo push token after sign-in and removes it on sign-out. */
class PushTokenController extends ApiController
{
    public function store(Request $request): JsonResponse
    {
        $user = $this->apiUser($request);
        $data = $request->validate(['token' => ['required', 'string', 'max:255', 'regex:/^Expo(nent)?PushToken\[[^\]]+\]$/'], 'platform' => ['nullable', 'in:ios,android']]);
        // A phone moves to whoever signs in on it last.
        PushToken::updateOrCreate(['token' => $data['token']], ['user_id' => $user->id, 'platform' => $data['platform'] ?? null, 'last_seen_at' => now()]);

        return response()->json(['success' => true, 'push_enabled' => \App\Notifications\Channels\ExpoPushChannel::enabled()]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $this->apiUser($request);
        $data = $request->validate(['token' => ['required', 'string', 'max:255']]);
        PushToken::where('user_id', $user->id)->where('token', $data['token'])->delete();

        return response()->json(['success' => true]);
    }
}
