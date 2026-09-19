<?php

use App\Http\ManageError;
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
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

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

        /*
         * ── A dead end inside /manage is still inside /manage (D-17, 2026-09-19) ─────────────
         *
         * Two of the three ways an operator leaves the happy path ended on a bare white page in a
         * language most of the team does not read, with no dashboard chrome and no way back:
         *
         *   • data-entry opening `/manage/users`        →  `403 | This action is unauthorized.`
         *   • anyone opening `/manage/products`         →  `404 | Not Found`
         *
         * The second is not an exotic case: it is the un-scoped path, a natural guess, and exactly
         * what a stale bookmark holds — the real route is `/manage/storefronts/{id}/products`.
         *
         * Scoped to `/manage` on purpose. The generic-404 posture is DELIBERATE for the public API
         * (wave-2 review 🟡-11): out there a distinguishable 403 tells an attacker that a resource
         * exists, and that is a leak. Inside the dashboard the reader is a colleague who has
         * already signed in, and the same silence is just a broken screen.
         *
         * 500 is left alone: an error page that hides the stack trace from a developer is a worse
         * trade than a bare page, and the operator's answer for a 500 is the same either way.
         */
        $exceptions->respond(function (SymfonyResponse $response, Throwable $exception, Request $request): SymfonyResponse {
            $status = $response->getStatusCode();

            if (! $request->is('manage', 'manage/*') || $request->expectsJson()) {
                return $response;
            }
            if (! in_array($status, [403, 404, 405, 419], true)) {
                return $response;
            }

            return ManageError::render($request, $status, $exception);
        });
    })->create();
