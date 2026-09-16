<?php

use App\Http\Middleware\CheckApiCode;
use App\Http\Middleware\CompatAuth;
use App\Http\Middleware\CompatGuestCart;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\HttpCacheHeaders;
use App\Http\Middleware\NegotiateLegacyLocale;
use App\Http\Middleware\ResolveStorefront;
use App\Http\Middleware\SetDashboardLocale;
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
        $middleware->web(append: [
            /*
             * BEFORE the Inertia share, and before any controller: `__()` resolves against the
             * locale that is set when the controller runs, so applying the operator's choice later
             * would translate the props and not the flash message (i18n step 0).
             */
            SetDashboardLocale::class,
            HandleInertiaRequests::class,
        ]);

        $middleware->throttleApi();

        // The dashboard's login route is `manage.login`, not the framework's default `login`.
        // Without this, `auth` throws RouteNotFoundException for a guest — a 500 where a redirect
        // belongs (caught by RouteAuthorizationTest on its first run).
        $middleware->redirectGuestsTo(fn () => route('manage.login'));

        $middleware->alias([
            'storefront' => ResolveStorefront::class,
            'api.code' => CheckApiCode::class,
            'http.cache' => HttpCacheHeaders::class,
            'legacy.locale' => NegotiateLegacyLocale::class,
            'compat.guest' => CompatGuestCart::class,
            'compat.auth' => CompatAuth::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
