<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends ApiController
{
    public function preferences(Request $request): JsonResponse
    {
        $saved = (array) ($request->user()->notification_preferences ?? []);

        return response()->json(['data' => collect(\App\Notifications\ApprovalRequested::GROUPS)->map(fn ($d, $group) => ['group' => $group, 'label' => $d[0], 'can_mute' => $d[1], 'in_app' => \App\Notifications\ApprovalRequested::preference($saved, $group, 'in_app'), 'email' => \App\Notifications\ApprovalRequested::preference($saved, $group, 'email'), 'push' => \App\Notifications\ApprovalRequested::preference($saved, $group, 'push'), 'telegram' => \App\Notifications\ApprovalRequested::preference($saved, $group, 'telegram')])->values(), 'push_available' => \App\Notifications\Channels\ExpoPushChannel::enabled(),
            // Telegram column: shown when the company switched Telegram on; ticks work once the person connected it.
            'telegram_available' => \App\Notifications\Channels\TelegramChannel::enabled(), 'telegram_connected' => \App\Notifications\Channels\TelegramChannel::linked($request->user()->id)]);
    }

    public function savePreferences(Request $request): JsonResponse
    {
        $data = $request->validate(['preferences' => ['required', 'array'], 'preferences.*.in_app' => ['boolean'], 'preferences.*.email' => ['boolean'], 'preferences.*.push' => ['boolean'], 'preferences.*.telegram' => ['boolean']]);
        $clean = [];
        foreach (\App\Notifications\ApprovalRequested::GROUPS as $group => [, $mutable]) {
            $clean[$group] = ['in_app' => $mutable ? (bool) ($data['preferences'][$group]['in_app'] ?? true) : true, 'email' => (bool) ($data['preferences'][$group]['email'] ?? \App\Notifications\ApprovalRequested::EMAIL_DEFAULTS[$group]), 'push' => (bool) ($data['preferences'][$group]['push'] ?? true),
                // An older app sends no Telegram choice: keep what was saved (or the default).
                'telegram' => (bool) ($data['preferences'][$group]['telegram'] ?? \App\Notifications\ApprovalRequested::preference((array) ($request->user()->notification_preferences ?? []), $group, 'telegram'))];
        }
        // The website's sound setting is not part of the app's list: keep it.
        $saved = (array) ($request->user()->notification_preferences ?? []);
        if (array_key_exists('web_sound', $saved)) $clean['web_sound'] = (bool) $saved['web_sound'];
        $request->user()->forceFill(['notification_preferences' => $clean])->save();

        return $this->preferences($request);
    }

    /**
     * The person's notifications, newest first, each with its type (icon and colours), a title with the
     * name in bold, details, time, and — for requests waiting on them — the approval to approve or reject.
     * ?filter=all|action|attendance|daily_status|tasks|payroll, ?unread=1, ?month=YYYY-MM (older apps).
     * "meta" carries the chip counts and the type table, so the app uses the same icons and colours as the website.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->apiUser($request);
        $presenter = new \App\Support\NotificationPresenter($user);
        $filter = (string) $request->query('filter', 'all');
        $meta = ['counts' => $presenter->counts(), 'types' => \App\Support\NotificationPresenter::typesForApi(), 'chips' => \App\Support\NotificationPresenter::CHIPS];

        if ($filter === 'action') {
            $items = collect($presenter->needsAction())->when($request->boolean('unread'), fn ($c) => $c->where('unread', true))->values();

            return response()->json(['data' => $items->all(), 'total' => $items->count(), 'current_page' => 1, 'last_page' => 1, 'meta' => $meta]);
        }
        $month = $this->monthRange($request);
        $chip = array_key_exists($filter, \App\Support\NotificationPresenter::CHIPS) ? $filter : null;
        $page = $user->notifications()->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
            ->when($chip, fn ($q) => $q->whereIn('data->kind', \App\Support\NotificationPresenter::kindsForChip($chip)))
            ->when($month, fn ($q) => $q->whereBetween('created_at', [$month[0].' 00:00:00', $month[1].' 23:59:59']))->latest()->orderBy('id')->paginate(30);

        return response()->json(['data' => $presenter->present($page->items()), 'total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'meta' => $meta]);
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
