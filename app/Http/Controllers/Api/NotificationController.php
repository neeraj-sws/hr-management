<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends ApiController
{
    public function preferences(Request $request): JsonResponse
    {
        $saved = (array) ($request->user()->notification_preferences ?? []);

        return response()->json(['data' => collect(\App\Notifications\ApprovalRequested::GROUPS)->map(fn ($d, $group) => ['group' => $group, 'label' => $d[0], 'can_mute' => $d[1], 'in_app' => \App\Notifications\ApprovalRequested::preference($saved, $group, 'in_app'), 'email' => \App\Notifications\ApprovalRequested::preference($saved, $group, 'email'), 'push' => \App\Notifications\ApprovalRequested::preference($saved, $group, 'push')])->values(), 'push_available' => \App\Notifications\Channels\ExpoPushChannel::enabled()]);
    }

    public function savePreferences(Request $request): JsonResponse
    {
        $data = $request->validate(['preferences' => ['required', 'array'], 'preferences.*.in_app' => ['boolean'], 'preferences.*.email' => ['boolean'], 'preferences.*.push' => ['boolean']]);
        $clean = [];
        foreach (\App\Notifications\ApprovalRequested::GROUPS as $group => [, $mutable]) {
            $clean[$group] = ['in_app' => $mutable ? (bool) ($data['preferences'][$group]['in_app'] ?? true) : true, 'email' => (bool) ($data['preferences'][$group]['email'] ?? \App\Notifications\ApprovalRequested::EMAIL_DEFAULTS[$group]), 'push' => (bool) ($data['preferences'][$group]['push'] ?? true)];
        }
        $request->user()->forceFill(['notification_preferences' => $clean])->save();

        return $this->preferences($request);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $this->apiUser($request);
        $month = $this->monthRange($request);
        $query = $user->notifications()->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
            ->when($month, fn ($q) => $q->whereBetween('created_at', [$month[0].' 00:00:00', $month[1].' 23:59:59']))->latest();

        return response()->json($query->paginate(30)->through(fn ($n) => ['id' => $n->id, 'kind' => $n->data['kind'] ?? null, 'record_id' => $n->data['record_id'] ?? null, 'message' => $n->data['message'] ?? '', 'url' => $n->data['url'] ?? null, 'read_at' => $n->read_at?->toIso8601String(), 'created_at' => $n->created_at->toIso8601String()]));
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $this->apiUser($request)->notifications()->whereKey($id)->firstOrFail()->markAsRead();

        return response()->json(['success' => true]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $this->apiUser($request)->unreadNotifications->markAsRead();

        return response()->json(['success' => true]);
    }
}
