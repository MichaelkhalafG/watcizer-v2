<?php

use App\Domain\Activity\ActivityLog;
use App\Domain\Promotions\PromotionRules;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * The wave-4A storefront screen: the first consumer of the table and form systems.
 */

it('lists storefronts through the table contract', function () {
    actingAs(Staff::admin())->get('/manage/storefronts')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Manage/Storefronts/Index')
            // Two storefronts since 2026-09-11: Watchizer (1) and Brand Fashion (2), in id order.
            ->has('table.data', 2)
            ->where('table.data.0.code', 'watchizer')
            ->where('table.data.1.code', 'brandfashion')
            ->where('table.meta.sortable', ['id', 'code', 'name', 'is_active', 'created_at'])
            // `rebuild_warning` was removed on 2026-09-18: it named AGENTS §2.20 and switch night
            // at an operator, which is machinery, and the catalogue half of it was the pre-switch
            // instruction the team has been told to stop following.
            ->missing('rebuild_warning'));
});

it('decodes the locales JSON column for the client', function () {
    actingAs(Staff::admin())->get('/manage/storefronts')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('table.data.0.locales', ['ar', 'en'])
        ->where('table.data.0.default_locale', 'en'));
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
        'money_rewards' => false,
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
        'money_rewards' => false,
    ])->assertSessionHasErrors('default_locale');

    // Unchanged by the refusal. 'en' because the storefront opens in ENGLISH — it always has, and the customer switches for themselves (developer, 2026-09-18).
    expect(DB::table('storefronts')->where('id', 1)->value('default_locale'))->toBe('en');
});

it('validates the rest of the form', function () {
    actingAs(Staff::admin())->put('/manage/storefronts/1', [])
        ->assertSessionHasErrors(['name', 'locales', 'default_locale', 'currency', 'is_active', 'money_rewards']);

    actingAs(Staff::admin())->put('/manage/storefronts/1', [
        'name' => 'X',
        'locales' => ['de'],
        'default_locale' => 'de',
        'currency' => 'TOOLONG',
        'is_active' => true,
        'money_rewards' => false,
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
        'money_rewards' => false,
    ])->assertRedirect('/manage/storefronts');

    expect(DB::table('storefronts')->where('id', 1)->value('code'))->toBe('watchizer');
});

// ── the money-reward switch (wave 4D) ────────────────────────────────────────────────────────

/**
 * The rest of the form, so a money-switch test states only what it is about.
 *
 * @return array<string, mixed>
 */
function storefrontPayload(bool $moneyRewards): array
{
    return [
        'name' => 'Watchizer',
        'domain' => null,
        'locales' => ['ar', 'en'],
        'default_locale' => 'ar',
        'currency' => 'EGP',
        'is_active' => true,
        'money_rewards' => $moneyRewards,
    ];
}

/**
 * Storefront 1's `settings` column, decoded and narrowed to string keys.
 *
 * @return array<string, mixed>
 */
function storefrontSettings(): array
{
    $out = [];
    foreach (T::arr(json_decode(T::str(DB::table('storefronts')->where('id', 1)->value('settings')), true)) as $key => $value) {
        $out[(string) $key] = $value;
    }

    return $out;
}

/**
 * The `promotions` bag inside it.
 *
 * @return array<string, mixed>
 */
function storefrontPromotionSettings(): array
{
    $out = [];
    foreach (T::arr(storefrontSettings()['promotions'] ?? []) as $key => $value) {
        $out[(string) $key] = $value;
    }

    return $out;
}

it('turns the money-reward switch on and off, and the engine follows immediately', function () {
    // It starts OFF: absent settings mean off, which is the state every storefront is in today.
    expect(PromotionRules::moneyRewardsEnabled(1))->toBeFalse();

    actingAs(Staff::admin())->put('/manage/storefronts/1', storefrontPayload(true))
        ->assertRedirect('/manage/storefronts');

    expect(PromotionRules::moneyRewardsEnabled(1))->toBeTrue()
        ->and(PromotionRules::isRewardAvailableOn('percent_discount', 1))->toBeTrue();

    actingAs(Staff::admin())->put('/manage/storefronts/1', storefrontPayload(false))
        ->assertRedirect('/manage/storefronts');

    expect(PromotionRules::moneyRewardsEnabled(1))->toBeFalse()
        ->and(PromotionRules::isRewardAvailableOn('percent_discount', 1))->toBeFalse()
        // …and the gift family is never affected by this switch, in either position.
        ->and(PromotionRules::isRewardAvailableOn('free_product', 1))->toBeTrue();
});

it('writes the switch as a real boolean, not the string "true"', function () {
    /*
     * `moneyRewardsEnabled()` reads it with a strict `=== true`, so a storefront whose setting
     * arrived as the STRING "true" from a hand-edit is treated as OFF — deliberately, because the
     * failure direction for a switch that changes what a customer is charged is off. This asserts
     * the SCREEN can never produce that shape, which is the half that would otherwise be a
     * silent mismatch between the writer and the reader.
     */
    actingAs(Staff::admin())->put('/manage/storefronts/1', storefrontPayload(true));

    $promotions = storefrontPromotionSettings();

    expect($promotions['money_rewards'] ?? null)->toBeTrue();
});

it('keeps every other key in settings when the switch is written', function () {
    // The column is a general per-storefront bag; this screen owns one key in it. A writer that
    // replaced the whole object would silently drop whatever another feature had put there.
    DB::table('storefronts')->where('id', 1)->update([
        'settings' => json_encode(['theme' => 'dark', 'promotions' => ['something_else' => 7]], JSON_THROW_ON_ERROR),
    ]);

    actingAs(Staff::admin())->put('/manage/storefronts/1', storefrontPayload(true));

    $settings = storefrontSettings();
    $promotions = storefrontPromotionSettings();

    expect($settings['theme'] ?? null)->toBe('dark')
        ->and($promotions['something_else'] ?? null)->toBe(7)
        ->and($promotions['money_rewards'] ?? null)->toBeTrue();
});

it('shows the switch on the edit form', function () {
    actingAs(Staff::admin())->get('/manage/storefronts/1/edit')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('storefront.money_rewards', false));
});

it('is ADMIN-ONLY, like the rest of this screen', function () {
    /*
     * It is a money switch, so it carries the same gate the rest of the storefront settings do:
     * `can:manage-storefronts`, which no data-entry grant includes. Asserted rather than assumed —
     * a new field on an existing form is exactly where an authorization hole hides.
     */
    actingAs(Staff::dataEntry())->put('/manage/storefronts/1', storefrontPayload(true))->assertForbidden();
    actingAs(Staff::dataEntry())->get('/manage/storefronts/1/edit')->assertForbidden();

    expect(PromotionRules::moneyRewardsEnabled(1))->toBeFalse();
});

it('records the settings change, and never copies the settings blob into the log', function () {
    /*
     * The gap this closes (2026-10-05). Every field on this form changes what a CUSTOMER sees:
     * `is_active` closes the shop, `currency` changes the sign in front of every price,
     * `default_locale` changes the language it opens in. One admin-only form, and no trace of any
     * of it — so "the shop was down on Friday" and "prices showed in dollars" had no author.
     */
    $admin = Staff::admin();
    actingAs($admin);

    // Something else's key, already in the bag — the reason the blob itself is not snapshotted.
    DB::table('storefronts')->where('id', 1)->update([
        'settings' => json_encode(['theme' => 'dark'], JSON_THROW_ON_ERROR),
    ]);

    actingAs($admin)->put('/manage/storefronts/1', [
        'name' => 'Watchizer USD',
        'domain' => null,
        'locales' => ['ar', 'en'],
        'default_locale' => 'ar',
        'currency' => 'USD',
        'is_active' => true,
        'money_rewards' => true,
    ])->assertRedirect('/manage/storefronts');

    $row = T::one(DB::table(ActivityLog::TABLE)
        ->where('subject_type', 'storefronts')->where('subject_id', 1)
        ->where('action', ActivityLog::UPDATED)->orderByDesc('id'));

    expect(T::int($row->user_id))->toBe(T::int($admin->getAttribute('id')))
        ->and(T::str($row->user_name))->toBe(Staff::nameOf($admin))
        ->and(T::str($row->subject_label))->toBe('Watchizer USD')
        ->and(T::int($row->storefront_id))->toBe(1);

    $changes = T::arr(json_decode(T::str($row->changes), true));

    // The currency it used to charge in, and the language it used to open in.
    $currency = T::arr($changes['currency'] ?? null);
    expect(T::str($currency['from'] ?? null))->toBe('EGP')
        ->and(T::str($currency['to'] ?? null))->toBe('USD');

    /*
     * The `settings` column is a general per-storefront bag and this screen owns ONE key in it. The
     * blob never enters the log — it would be a truncated JSON dump nobody can read, it carries
     * other features' keys, and `ActivityLog::REDACTED_FIELDS` matches by FIELD NAME, so a secret
     * nested inside it one day would land here in the clear. The one key this form writes is logged
     * as its own flat boolean instead.
     */
    $rewards = T::arr($changes['money_rewards'] ?? null);
    expect($rewards['from'] ?? null)->toBeFalse()
        ->and($rewards['to'] ?? null)->toBeTrue()
        ->and($changes)->not->toHaveKey('settings');

    // …and the key the screen does not own is still in the column, untouched by either of them.
    expect(storefrontSettings()['theme'] ?? null)->toBe('dark');
});
