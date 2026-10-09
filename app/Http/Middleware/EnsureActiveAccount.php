<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $account=$request->user();
        if($account && isset($account->is_active) && ! (bool)$account->is_active){
            abort(403,'This account is inactive.');
        }

        return $next($request);
    }
}
