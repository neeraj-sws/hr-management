<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
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
