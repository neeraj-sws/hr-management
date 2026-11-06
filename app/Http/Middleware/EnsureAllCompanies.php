<?php

namespace App\Http\Middleware;

use App\Support\CompanyAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Pages not split by company yet (e.g. Billing): only logins of all companies, never a login limited to one. */
class EnsureAllCompanies
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(CompanyAccess::isLimited($request->user()), 403);

        return $next($request);
    }
}
