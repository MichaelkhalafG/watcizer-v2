<?php

namespace App\Providers;

use App\Domain\Inventory\StockWriteGuard;
use App\Support\LegacyReadOnly;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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

        // Wave 3: no statement outside InventoryService (or the transform, which opens its own
        // window) may write a stock column. Armed outside production, exactly as study §4.2
        // scopes it; production relies on the nightly `inventory:verify` reconciliation instead.
        if (! $this->app->isProduction()) {
            StockWriteGuard::arm();
        }

        // The `legacy` connection is read-only at the SESSION level, so raw SQL cannot write to a
        // legacy table either (milestone audit; see App\Support\LegacyReadOnly for why the test
        // suite is the one bounded exception).
        Event::listen(function (ConnectionEstablished $event): void {
            if ($event->connectionName === 'legacy' && ! $this->app->runningUnitTests()) {
                LegacyReadOnly::enforce($event->connection);
            }
        });
    }
}
