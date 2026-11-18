<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'jwt'  => \App\Http\Middleware\JwtMiddleware::class,
        ]);
        // Disable CSRF for API routes
        $middleware->validateCsrfTokens(except: ['api/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Multi-tenancy (stancl) is installed but no tenants exist. Requests for
        // unknown hosts (e.g. bots hitting the bare server IP) used to surface as
        // a logged ERROR + HTTP 500. Treat them as a plain 404 and don't log them.
        $exceptions->dontReport(\Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException::class);
        $exceptions->render(function (\Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException $e, $request) {
            return $request->expectsJson()
                ? response()->json(['success' => false, 'message' => 'Not found.'], 404)
                : response('Not Found', 404);
        });
    })->create();
