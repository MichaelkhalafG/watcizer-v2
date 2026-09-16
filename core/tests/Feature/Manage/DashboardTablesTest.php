<?php

use App\Console\Commands\CoreChecksumCommand;
use App\Models\Storefront\Storefront;
use Database\Seeders\StorefrontSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\LedgerState;

/*
 * The rule the 2026-09-11 decision settled (AGENTS §2.20): a table whose content is AUTHORED IN
 * THE DASHBOARD is never in the drop list.
 *
 * Switch night's drop-and-rebuild exists to throw away transform OUTPUT and rebuild it from
 * legacy. A row a human typed has no legacy source, so dropping it is data loss — and it would
 * happen at the worst possible moment, on the night the team goes live.
 *
 * Wave 4A found both halves of this the hard way, and both halves are pinned below: the DROP
 * (`storefronts` was in the list) and the OVERWRITE (`StorefrontSeeder::ensure()` rewrote every
 * column on every transform run, so the settings screen's saves would have been reverted by the
 * next rehearsal even without a drop).
 */

it('keeps every dashboard-authored table out of the drop list', function () {
    // `toContain($needle, $more)` takes MORE NEEDLES, never a message. Under `->not->` the
    // original assertion happened to stay correct (it asserted the absence of both the table AND
    // the sentence, and the table is the one that matters), but it read as a message and was not
    // one — corrected 2026-09-11 while auditing the same trap in wave 4B.
    //
    // It is written as an INTERSECTION rather than a loop of `in_array` for two reasons: it names
    // every offender at once instead of stopping at the first, and a per-table `in_array` over two
    // constant lists is something PHPStan can answer at analysis time, which made it report the
    // assertion as impossible — a test whose result the analyser already knows is not a test.
    $overlap = array_values(array_intersect(
        CoreChecksumCommand::DASHBOARD_TABLES,
        CoreChecksumCommand::CLEAN_TABLES,
    ));

    expect($overlap)->toBe(
        [],
        'these tables are authored in the dashboard: dropping them on switch night destroys human work — '
        .implode(', ', $overlap),
    );
});

it('names EVERY table the dashboard authors, in order, so adding one is a deliberate edit', function () {
    /*
     * A change to this list is a decision, so it fails a test rather than passing silently — which
     * is exactly what happened on 2026-09-13 when the findings table was added.
     *
     * Wave 4C added the three payments tables (§3.9.1): they hold the merchant's CONTRACTS —
     * encrypted credentials, integration ids, customer-facing labels and the order the admin
     * dragged them into — and no transform can regenerate any of it. Dropping them on switch
     * night would take the storefront's ability to take money with it.
     *
     * The review's 🔴-1 added `payment_reconciliation_findings` for the same reason in a different
     * currency: an unresolved finding is the record that a callback and an order disagree about
     * MONEY and that nobody has judged it yet. A rebuild that erased it would erase the only
     * evidence that a refund, a double charge or a decline-after-payment ever arrived.
     */
    /*
     * Fourteen since M1o (2026-09-15): `core_activity_log` — who changed what, and what it was
     * before. It is the one table on this list whose entire value is in being OLD, so it is the
     * last one that may ever be dropped: a rebuild that erased it would erase the history of the
     * rebuild itself, including the answer to whatever question prompted somebody to look.
     *
     * Thirteen since M1m (2026-09-14): `core_user_preferences` — the dashboard language an
     * operator chose. It is the smallest row on this list and it is here for the same reason as
     * the largest: a human typed it and no transform can regenerate it.
     *
     * Twelve since wave 4D (M1l): the five promotion tables joined the list on 2026-09-13
     * (study §3.16.9 resolution 🔴-3). A rule, its storefronts, its conditions, its rewards and
     * its skip counters are all typed by an admin and have no legacy source, so a rebuild would
     * delete a promotion the shop is running — and the `promotion_rule_id` on the order lines it
     * already granted would point at nothing.
     *
     * FIFTEEN since M1r added `promotion_order_discounts` — what a money reward took off an order
     * and which rule took it. It is on the list for the reasons above and one more: it describes an
     * ORDER, and orders are legacy rows a rebuild never touches. Dropping the discount record would
     * leave those orders permanently unexplained — a total of 475 against lines of 500, with
     * nothing left to say why.
     *
     * The list is pinned BY NAME on purpose: adding a table here must be a deliberate edit to this
     * assertion, because the alternative is a table quietly joining the never-dropped set and
     * nobody noticing until switch night proves it should not have.
     */
    expect(CoreChecksumCommand::DASHBOARD_TABLES)->toBe([
        'storefronts', 'storefront_banners', 'core_user_roles',
        'storefront_payment_providers', 'storefront_payment_methods',
        'storefront_payment_method_translations',
        'payment_reconciliation_findings',
        'promotion_rules', 'promotion_rule_storefront', 'promotion_rule_conditions',
        'promotion_rule_rewards', 'promotion_rule_skips',
        'promotion_order_discounts',
        'core_user_preferences',
        'core_activity_log',
    ]);

    foreach (CoreChecksumCommand::DASHBOARD_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeTrue("{$table} does not exist, so the exclusion above is about nothing");
    }
});

it('still covers dashboard tables in the core digest, so a silent change is visible', function () {
    // Excluded from the DROP list, not from the CHECKSUM: a rehearsal must still be able to see
    // that something changed on the clean side.
    foreach (CoreChecksumCommand::DASHBOARD_TABLES as $table) {
        expect(CoreChecksumCommand::CORE_TABLES)->toContain($table);
    }
    foreach (CoreChecksumCommand::CLEAN_TABLES as $table) {
        expect(CoreChecksumCommand::CORE_TABLES)->toContain($table);
    }

    expect(CoreChecksumCommand::compute(CoreChecksumCommand::DASHBOARD_TABLES)['digest'])->toBeString();
});

it('does NOT overwrite dashboard-owned storefront columns when the transform ensures the row', function () {
    $storefront = Storefront::query()->findOrFail(Storefront::WATCHIZER_ID);
    $storefront->forceFill([
        'name' => 'Watchizer — edited in the dashboard',
        'domain' => 'edited.example',
        'currency' => 'USD',
        'locales' => ['ar'],
        'default_locale' => 'ar',
        'is_active' => false,
    ])->save();

    // What step 14 does on every run.
    StorefrontSeeder::ensure(StorefrontSeeder::WATCHIZER);

    $after = Storefront::query()->findOrFail(Storefront::WATCHIZER_ID);
    expect($after->name)->toBe('Watchizer — edited in the dashboard')
        ->and($after->domain)->toBe('edited.example')
        ->and($after->currency)->toBe('USD')
        ->and($after->locales)->toBe(['ar'])
        ->and($after->is_active)->toBeFalse();
});

it('still INSERTS storefront 1 on a fresh install, at its deterministic id', function () {
    // The other half of insert-only: skipping the update must not skip the insert. Deleting the
    // row inside this transaction simulates a fresh install (FKs to it are re-pointed by M2 and
    // nothing references it in the rows we touch here).
    DB::table('storefront_product')->where('storefront_id', Storefront::WATCHIZER_ID)->delete();
    DB::table('storefront_category_product')->where('storefront_id', Storefront::WATCHIZER_ID)->delete();
    DB::table('storefront_categories')->where('storefront_id', Storefront::WATCHIZER_ID)->delete();
    DB::table('storefront_redirects')->where('storefront_id', Storefront::WATCHIZER_ID)->delete();
    DB::table('core_user_roles')->whereNotNull('storefront_id')->delete();
    DB::table('storefronts')->where('id', Storefront::WATCHIZER_ID)->delete();
    // Storefront 1 only: Brand Fashion (id 2) is seeded too and is not what this test is about.
    expect(Storefront::query()->whereKey(Storefront::WATCHIZER_ID)->count())->toBe(0);

    $created = StorefrontSeeder::ensure(StorefrontSeeder::WATCHIZER);

    expect($created->id)->toBe(Storefront::WATCHIZER_ID)
        ->and($created->code)->toBe('watchizer')
        ->and($created->currency)->toBe('EGP')
        ->and($created->is_active)->toBeTrue();
});

it('runs the transform with storefronts preserved: the row survives and reconciliation passes', function () {
    LedgerState::skipIfDirty();

    $storefront = Storefront::query()->findOrFail(Storefront::WATCHIZER_ID);
    $storefront->forceFill(['name' => 'Edited before the transform'])->save();

    expect(Artisan::call('core:transform', ['--force' => true]))->toBe(0);

    expect(Artisan::output())->toContain('ALL COUNTS RECONCILE')
        ->and(Storefront::query()->findOrFail(Storefront::WATCHIZER_ID)->name)->toBe('Edited before the transform');
});
