<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
    }
}
