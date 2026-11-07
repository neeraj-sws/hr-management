<?php

namespace App\Http\Middleware;

use App\Support\Access;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccess
{
    public function handle(Request $request, Closure $next, string ...$rules): Response
    {
        abort_unless(Access::allows($request->user(), $rules), 403);
        // Billing pages: not for a login limited to one company until Billing is split by company (config features.company_billing).
        abort_if(str_starts_with((string) $request->route()?->getName(), 'billing.') && \App\Support\CompanyAccess::isLimited($request->user()) && ! config('features.company_billing'), 403);

        return $next($request);
    }
}
