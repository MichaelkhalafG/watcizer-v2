<?php

namespace App\Providers;

use App\Compat\CatalogWarmer;
use App\Domain\Access\Abilities;
use App\Domain\Access\Roles;
use App\Domain\Access\UserWriteGuard;
use App\Domain\Import\ImageCache;
use App\Domain\Inventory\StockWriteGuard;
use App\Domain\Payment\ProviderRegistry;
use App\Storefront\StorefrontCache;
use App\Support\LegacyReadOnly;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One instance per request: Roles memoises grants, and two instances would mean two
        // queries and — worse — two answers if a grant changed mid-request.
        $this->app->singleton(Roles::class);

        /*
         * ONE image cache per process. `CoverImages` is built while the import command's
         * dependencies are resolved, which is before its options are parsed — so `--refresh-images`
         * has to reach the same instance afterwards, and only a singleton makes that true.
         */
        $this->app->singleton(ImageCache::class);

        /*
         * The payment provider registry (wave 4C, study §3.9.3). Bound here rather than
         * auto-resolved because its constructor takes the map: the container cannot guess it, and
         * a callback route that 500s on an unresolvable dependency would look like a provider
         * outage. One instance per request — the implementations are stateless.
         */
        $this->app->singleton(ProviderRegistry::class, fn (): ProviderRegistry => ProviderRegistry::default());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // N+1 discipline (CLEAN_CORE_STUDY §5.3): lazy loads throw outside production,
        // and silently discarded attributes throw everywhere.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes();

        // The database is SHARED with the legacy application in every environment,
        // including the local copy. migrate:fresh / migrate:refresh / migrate:reset /
        // db:wipe would drop the legacy tables, so they are prohibited unconditionally.
        DB::prohibitDestructiveCommands();

        // Per-IP limiter for every /api route (v2, compat and proxied): 60/min, the legacy app's
        // `throttle:api` value (review 🟡-7); the edge cache carries the read load.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by($request->ip() ?? 'unknown'));

        /*
         * ── Per-ENDPOINT limiters for the two unauthenticated writes (review 🟠-4 / 🟡-11) ───────
         *
         * The global 60/min is a fair-use ceiling for reads. It is far too loose for these two,
         * which is what 🟡-11 named:
         *
         *   • `auth/reset-password` takes a TOKEN. Sixty guesses a minute, indefinitely, against a
         *     credential that exists for an hour, is a guessing budget nobody granted. And unlike
         *     `login` there was no per-endpoint counter at all.
         *   • `register` CREATES an account and sends mail. At 60/min one IP can mint 3,600 accounts
         *     an hour and have this application send 3,600 e-mails from the shop's own domain —
         *     which is how a sender reputation is destroyed by somebody else.
         *
         * Keyed by IP, deliberately, not by e-mail. An e-mail key would let an attacker exhaust a
         * real customer's budget and lock them out of their own password reset — turning a
         * guessing defence into a denial-of-service against the person it protects. (`login` keys
         * on both because there the e-mail IS the thing being attacked one password at a time.)
         *
         * Two windows each: a burst limit for a script, and an hourly limit for a patient one. A
         * shared mobile NAT can legitimately produce a handful of registrations, so the hourly
         * figures are generous rather than tight; the point is a ceiling, not a gate.
         */
        RateLimiter::for('customer-reset', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip() ?? 'unknown')->response(self::tooMany()),
            Limit::perHour(30)->by($request->ip() ?? 'unknown')->response(self::tooMany()),
        ]);

        /*
         * `add_order` (security audit, Finding 2). Unauthenticated by nature, and every accepted
         * call RESERVES REAL STOCK through InventoryService and sends mail — so at the global 60/min
         * one IP could hold a product's whole stock hostage without paying for any of it. A shopper
         * places an order or two, and retries a few times after a validation error.
         */
        RateLimiter::for('add-order', fn (Request $request) => [
            Limit::perMinute(10)->by($request->ip() ?? 'unknown')->response(self::tooMany()),
            Limit::perHour(60)->by($request->ip() ?? 'unknown')->response(self::tooMany()),
        ]);

        RateLimiter::for('customer-register', fn (Request $request) => [
            Limit::perMinute(3)->by($request->ip() ?? 'unknown')->response(self::tooMany()),
            Limit::perHour(20)->by($request->ip() ?? 'unknown')->response(self::tooMany()),
        ]);

        // Wave 3: no statement outside InventoryService (or the transform, which opens its own
        // window) may write a stock column. Armed outside production, exactly as study §4.2
        // scopes it; production relies on the nightly `inventory:verify` reconciliation instead.
        if (! $this->app->isProduction()) {
            StockWriteGuard::arm();
        }

        /*
         * The `users` door, at the QUERY level (review 🟠-7).
         *
         * `UserWrites` was enforced in one place — `User::booted()` — so it covered Eloquent and
         * nothing else. `DB::table('users')->update([...])` and `DB::statement(...)` wrote the
         * table holding every customer account and every operator login without the model ever
         * seeing them.
         *
         * Armed EVERYWHERE, unlike the stock guard: `users` has no nightly reconciliation to fall
         * back on, the model guard it mirrors is armed in production too, and a census of `app/`
         * finds zero legitimate builder writes to this table — all seven call sites are reads. So
         * the only thing that can trip it is a bug.
         */
        UserWriteGuard::arm();

        // Wave 4A: dashboard abilities (admin | data-entry). Registered here rather than in a
        // policy so `can:` route middleware, `Gate::allows()` and the nav filter are one source.
        Abilities::register();

        /*
         * Two per-CONNECTION guards, armed as each connection is established.
         *
         * `LegacyReadOnly` has been here since the milestone audit. `UserWriteGuard::refuse()`
         * joins it because a `QueryExecuted` listener alone is an ALARM, not a door: that event
         * fires AFTER the statement, so a builder or raw write to `users` OUTSIDE a transaction
         * lands and is then reported. Measured (review 🟠-7): the row really changed, and the
         * throw only described it afterwards.
         *
         * `Connection::beforeExecuting()` runs before `runQueryCallback()`, so throwing there
         * prevents the statement outright. Both are kept: this one is the door, and the
         * `QueryExecuted` listener stays as the net for anything that reaches the driver by a
         * route these callbacks do not see.
         */
        Event::listen(function (ConnectionEstablished $event): void {
            if ($event->connectionName === 'legacy') {
                // The legacy connection is read-only at the SESSION level, so raw SQL cannot write
                // to a legacy table either (see App\Support\LegacyReadOnly for why the test suite
                // is the one bounded exception).
                if (! $this->app->runningUnitTests()) {
                    LegacyReadOnly::enforce($event->connection);
                }

                // `users` lives on the DEFAULT connection. The legacy one is refused wholesale by
                // the line above and needs no second opinion.
                return;
            }

            UserWriteGuard::refuse($event->connection);
        });

        /*
         * Any connection established BEFORE this listener existed never fires the event, so it is
         * armed directly here.
         *
         * `getConnections()` returns what the manager has ALREADY resolved — deliberately, rather
         * than `DB::connection()`, which would OPEN one. Forcing a database handshake in `boot()`
         * would put a connection on every request, including the ones that never touch the
         * database, to arm a guard those requests do not need. Normally this list is empty and the
         * listener above does all the work.
         */
        foreach (DB::getConnections() as $connection) {
            UserWriteGuard::refuse($connection);
        }

        /*
         * The listing index, rebuilt right after a write made it stale (2026-09-28, CatalogWarmer).
         * `terminating` runs once the response has been sent, so the dashboard user who saved does
         * not wait for it and the next shopper does not pay it. A failure here is logged and
         * forgotten: the next tap, or the 5-minute `catalog:warm`, builds the index anyway.
         */
        $this->app->terminating(function (): void {
            $ids = StorefrontCache::takeFlushed();
            if ($ids === [] || ! config()->boolean('compat.warm_on_write')) {
                return;
            }
            try {
                $warmer = $this->app->make(CatalogWarmer::class);
                $warmer->warm($warmer->allowed($ids));
            } catch (\Throwable $e) {
                Log::warning('catalog warm-up after a write failed', ['storefronts' => $ids, 'error' => $e->getMessage()]);
            }
        });
    }

    /**
     * The 429 the customer endpoints answer with.
     *
     * `{"error": ...}` and not the framework's `{"message": ...}`, because every other refusal
     * these routes give uses `error` and the storefront reads that key. A throttle that answers in
     * a different shape shows the shopper a blank failure.
     */
    private static function tooMany(): callable
    {
        return static fn (): JsonResponse => response()->json(
            ['error' => 'Too many attempts. Please try again later.'], 429,
        );
    }
}
