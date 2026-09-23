<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // ---- API middleware (mobile client) ---------------------------------
        // Attach the named 'api' rate limiter (defined in AppServiceProvider) to
        // EVERY /api route. The framework's default api middleware group carries
        // NO throttle, so without this the JSON API is unthrottled. The stricter
        // per-email 'api-login' limiter is applied to the auth routes in
        // routes/api.php on top of this baseline.
        $middleware->api(append: [
            'throttle:api',
        ]);

        $middleware->web(append: [
            // Resolves + enforces the active tenant from the session BEFORE the
            // Inertia props are shared, so Institution::current() is correct for
            // every shared prop (terminology, currency, theme).
            \App\Http\Middleware\ResolveTenant::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            // Forces a user holding a temporary (demo) password to change it
            // before using the app. No-op for everyone else.
            \App\Http\Middleware\EnsurePasswordIsChanged::class,
        ]);

        // Spatie Permission aliases
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'password.changed' => \App\Http\Middleware\EnsurePasswordIsChanged::class,
            'tenant' => \App\Http\Middleware\ResolveTenant::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();