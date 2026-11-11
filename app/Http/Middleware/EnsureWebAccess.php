<?php

namespace App\Http\Middleware;

use App\Services\WebAccessService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Website access (Settings > Security): someone signed in to the website whose role may no longer use it
 * is signed out on their next click and sent to the sign-in page with the reason. API requests (desktop
 * and mobile apps, with a token) never pass through here.
 */
class EnsureWebAccess
{
    public function __construct(private readonly WebAccessService $access, private readonly \App\Services\IpRestrictionService $ips) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();
        // Client logins use only the client portal, whatever the staff website-access setting is.
        if (! $user || $user->client_id || $request->routeIs('logout') || \App\Services\ImpersonationService::active()) return $next($request);
        // Website access by role first, then the office-IP restriction (Settings > Security).
        $message = ! $this->access->allows($user) ? WebAccessService::MESSAGE
            : (! $this->ips->allows($user, $request->ip()) ? \App\Services\IpRestrictionService::MESSAGE : null);
        if ($message === null) return $next($request);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        if ($request->hasHeader('X-Livewire') || $request->expectsJson()) abort(403, $message);

        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
