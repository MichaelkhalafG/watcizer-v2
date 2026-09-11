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

it('names the three tables the dashboard authors today', function () {
    // A change to this list is a decision, so it should fail a test rather than pass silently.
    // 4C adds the payment provider/method tables here (§3.9.1).
    expect(CoreChecksumCommand::DASHBOARD_TABLES)->toBe(['storefronts', 'storefront_banners', 'core_user_roles']);

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
