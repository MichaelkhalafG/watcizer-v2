<?php

use App\Http\Controllers\Compat\SitemapCompatController;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Wave 0 walking skeleton. Authentication and the /manage/* dashboard routes arrive in wave 4.
Route::get('/', fn () => Inertia::render('Dashboard'))->name('dashboard');

// Legacy Watchizer sitemap contract (CLEAN_CORE_STUDY §3.3, §6.4): the Next.js rewrite fetches
// `/en/sitemap.xml`; the bare path 302s to the negotiated locale exactly like the legacy host.
// Not Inertia responses: the dashboard middleware would add `Vary: X-Inertia`, which the legacy host never sends.
Route::get('/sitemap.xml', [SitemapCompatController::class, 'redirect'])->withoutMiddleware(HandleInertiaRequests::class);
Route::get('/{locale}/sitemap.xml', [SitemapCompatController::class, 'show'])->where('locale', '[a-z]{2}')->withoutMiddleware(HandleInertiaRequests::class);
