<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Client logins only ever see the client portal: any other page of the HRMS sends them to their timesheet,
 * and HRMS staff cannot open the portal pages.
 */
class ClientPortalOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) return $next($request);
        $portal = $request->routeIs('client.*');
        if ($user->client_id) {
            if ($portal || $request->routeIs('logout', '*livewire.*', 'login', 'password.*')) return $next($request);
            if ($request->hasHeader('X-Livewire') || $request->expectsJson()) abort(403);

            return redirect()->route('client.dashboard');
        }
        abort_if($portal, 403, 'The client portal is for client logins.');

        return $next($request);
    }
}
