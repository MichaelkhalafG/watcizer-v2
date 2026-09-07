<?php

use App\Http\Middleware\CheckApiCode;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\HttpCacheHeaders;
use App\Http\Middleware\NegotiateLegacyLocale;
use App\Http\Middleware\ResolveStorefront;
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
            HandleInertiaRequests::class,
        ]);

        $middleware->throttleApi();

        $middleware->alias([
            'storefront' => ResolveStorefront::class,
            'api.code' => CheckApiCode::class,
            'legacy.locale' => NegotiateLegacyLocale::class,
            'http.cache' => HttpCacheHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
