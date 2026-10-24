<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsureAccess;
use App\Http\Middleware\EnsureWebAccess;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->append(SecurityHeaders::class);
        // Settings > Security > Website access: who may use the website (the apps use the API, not this).
        $middleware->web(append: EnsureWebAccess::class);
        $middleware->alias(['account.active' => EnsureActiveAccount::class, 'access' => EnsureAccess::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn ($request, $exception) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
