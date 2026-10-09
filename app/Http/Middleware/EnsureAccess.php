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

        return $next($request);
    }
}
