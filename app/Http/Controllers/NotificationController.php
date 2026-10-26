<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Live bell (every 30 seconds): the unread count, the bell panel again, and the titles of notifications
     * that arrived after ?since= (the newest one the page already had), for the popup and the sound.
     */
    public function live(Request $request): JsonResponse
    {
        $user = $request->user();
        $since = rescue(fn () => $request->filled('since') ? \Illuminate\Support\Carbon::parse((string) $request->query('since')) : null, null, false);
        $new = $since ? $user->notifications()->where('created_at', '>', $since)->latest()->limit(5)->get() : collect();
        $presenter = new \App\Support\NotificationPresenter($user);
        $unreadCount = $user->unreadNotifications()->count();
        $bellItems = $presenter->present($user->notifications()->latest()->limit(6)->get());

        return response()->json([
            'count' => $unreadCount,
            'since' => optional($user->notifications()->latest()->value('created_at'))->toIso8601String(),
            'sound' => (bool) (($user->notification_preferences ?? [])['web_sound'] ?? true),
            'new' => collect($presenter->present($new))->pluck('title')->filter()->values()->all(),
            'panel' => view('partials.bell-panel', compact('unreadCount', 'bellItems'))->render(),
        ]);
    }

    /** "Mark all read" in the bell. */
    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back();
    }

    /** Marks a notification read and follows its link (relative paths only). */
    public function open(Request $request, string $id): RedirectResponse
    {
        $notification = $request->user()->notifications()->whereKey($id)->firstOrFail();
        $notification->markAsRead();
        $url = (string) ($notification->data['url'] ?? '');

        return redirect(str_starts_with($url, '/') && ! str_starts_with($url, '//') ? $url : route('system.notifications'));
    }
}
