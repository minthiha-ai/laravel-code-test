<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // This is an API-only app with no `login` page. By default Laravel
        // points guests at a `login` route, which doesn't exist here and makes
        // the auth middleware throw a RouteNotFoundException (500) while
        // building the redirect. Disabling the guest redirect lets
        // unauthenticated requests resolve to a clean 401 instead.
        $middleware->redirectGuestsTo(null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Treat every /api/* request as wanting JSON, so unauthenticated and
        // error responses come back as JSON (with a message) even when the
        // client didn't send an `Accept: application/json` header.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
