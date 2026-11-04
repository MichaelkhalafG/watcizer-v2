<?php

use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * The dashboard's 404 on an address that matches NO route (Part 1, 1.6, 2026-11-04).
 *
 * `/manage/storefronts/1/promotions` is a wrong URL (promotions are not storefront-scoped). The router
 * refuses it before any route middleware runs, so `HandleInertiaRequests::share()` never ran, the page
 * arrived with no `auth`, `branding` or `translations`, and the Error page died on the first of them it
 * read — a blank white page with "Cannot read properties of undefined (reading 'user')" in the console
 * in place of the 404 it exists to draw. `ManageError::render()` now shares the shell's props itself
 * when they are missing, and the page reads them defensively.
 */

it('renders the dashboard 404 for an unmatched URL WITH the shell props the page reads', function () {
    $response = actingAs(Staff::admin())->get('/manage/storefronts/1/promotions');
    $response->assertNotFound();

    $page = $response->viewData('page');
    expect($page)->toBeArray();
    $page = T::arr($page);
    expect($page['component'])->toBe('Manage/Error');

    $props = Props::of($response);
    // the props the page and its Brand component read, present even though no route middleware ran
    expect($props)->toHaveKeys(['auth', 'branding', 'translations', 'locale', 'dir', 'status', 'title', 'home'])
        ->and(T::arr($props['auth']))->toHaveKey('user')
        ->and(T::arr($props['branding']))->toHaveKey('name')
        ->and($props['status'])->toBe(404);
});

it('still answers a guest the same page, and a real 404 inside the dashboard keeps its shell', function () {
    get('/manage/storefronts/1/promotions')->assertNotFound();

    // a routed-but-missing product: the route middleware ran, the shell props were shared the ordinary way
    $response = actingAs(Staff::admin())->get('/manage/storefronts/1/products/999999999/edit');
    $response->assertNotFound();
    $props = Props::of($response);
    expect(T::arr($props['auth'])['user'])->not->toBeNull()
        ->and($props['nav'])->toBeArray();
});
