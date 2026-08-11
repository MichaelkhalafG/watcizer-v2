<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;
use App\Models\Product;
use App\Observers\ProductObserver;

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
        // Admin list pages use Bootstrap 5 markup for the paginator links.
        Paginator::useBootstrapFive();

        // Restock notifications + gift-offer cleanup: watch catalog changes.
        Product::observe(ProductObserver::class);
    }
}
