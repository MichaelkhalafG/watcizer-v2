<?php

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Staff;

use function Pest\Laravel\actingAs;

/*
 * The wave-4A storefront screen: the first consumer of the table and form systems.
 */

it('lists storefronts through the table contract', function () {
    actingAs(Staff::admin())->get('/manage/storefronts')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Manage/Storefronts/Index')
            ->has('table.data', 1)
            ->where('table.data.0.code', 'watchizer')
            ->where('table.meta.sortable', ['id', 'code', 'name', 'is_active', 'created_at'])
            ->has('rebuild_warning'));
});

it('decodes the locales JSON column for the client', function () {
    actingAs(Staff::admin())->get('/manage/storefronts')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('table.data.0.locales', ['ar', 'en'])
        ->where('table.data.0.default_locale', 'ar'));
});

it('filters and searches server-side', function () {
    actingAs(Staff::admin())->get('/manage/storefronts?filters[is_active]=0')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('table.data', 0));

    actingAs(Staff::admin())->get('/manage/storefronts?q=watchizer')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('table.data', 1));

    actingAs(Staff::admin())->get('/manage/storefronts?q=no-such-store')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('table.data', 0));
});

it('serves the edit form with the row it is editing', function () {
    actingAs(Staff::admin())->get('/manage/storefronts/1/edit')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Manage/Storefronts/Edit')
            ->where('storefront.code', 'watchizer')
            ->where('storefront.currency', 'EGP')
            ->has('locale_options', 2));
});

it('saves a change and flashes a status the shell renders', function () {
    actingAs(Staff::admin())->put('/manage/storefronts/1', [
        'name' => 'Watchizer Egypt',
        'domain' => 'watchizereg.com',
        'locales' => ['ar', 'en'],
        'default_locale' => 'ar',
        'currency' => 'egp',
        'is_active' => true,
    ])->assertRedirect('/manage/storefronts')->assertSessionHas('status');

    $row = DB::table('storefronts')->where('id', 1)->first(['name', 'currency']);
    expect($row?->name)->toBe('Watchizer Egypt')
        // Normalised, so a lower-case entry cannot become a second currency code.
        ->and($row?->currency)->toBe('EGP');
});

it('refuses a default locale that is not one of the enabled locales', function () {
    actingAs(Staff::admin())->put('/manage/storefronts/1', [
        'name' => 'Watchizer',
        'domain' => null,
        'locales' => ['ar'],
        'default_locale' => 'en',
        'currency' => 'EGP',
        'is_active' => true,
    ])->assertSessionHasErrors('default_locale');

    expect(DB::table('storefronts')->where('id', 1)->value('default_locale'))->toBe('ar');
});

it('validates the rest of the form', function () {
    actingAs(Staff::admin())->put('/manage/storefronts/1', [])
        ->assertSessionHasErrors(['name', 'locales', 'default_locale', 'currency', 'is_active']);

    actingAs(Staff::admin())->put('/manage/storefronts/1', [
        'name' => 'X',
        'locales' => ['de'],
        'default_locale' => 'de',
        'currency' => 'TOOLONG',
        'is_active' => true,
    ])->assertSessionHasErrors(['locales.0', 'default_locale', 'currency']);
});

it('never lets the form rename the storefront code', function () {
    // `code` is identity: it appears in /api/v2/{storefront}/…, in every cache key and in the
    // transform's deterministic-id guard. The form must ignore it even when a request sends one.
    actingAs(Staff::admin())->put('/manage/storefronts/1', [
        'code' => 'hijacked',
        'name' => 'Watchizer',
        'domain' => null,
        'locales' => ['ar', 'en'],
        'default_locale' => 'ar',
        'currency' => 'EGP',
        'is_active' => true,
    ])->assertRedirect('/manage/storefronts');

    expect(DB::table('storefronts')->where('id', 1)->value('code'))->toBe('watchizer');
});
