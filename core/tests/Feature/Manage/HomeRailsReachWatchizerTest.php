<?php

use App\Domain\Content\HomeRails;
use Illuminate\Support\Facades\DB;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

/*
 * A home-rails REORDER reaches WATCHIZER's shop — the half `HomeRailsReachTheShopTest` never covered
 * (2026-10-21).
 *
 * That test proves the promise for storefront 2 against `v2/brandfashion/home`, because the bug it was written
 * for was v2's cached payload. Watchizer is storefront 1 and its shop reads COMPAT, and the only thing asserting
 * that compat serves a reorder was a sentence in that test's docblock — "compat reads the table on every
 * request". That sentence is true, but a sentence is not a test, and the shop that is live and taking real
 * orders was the one with no coverage.
 *
 * These assert the whole path on storefront 1: the POST the screen actually sends, the rows the controller
 * writes, and the order `catalog/home` serves immediately afterwards.
 */

/** @return list<int> the rail ids compat's home serves, in order */
function compatRailOrder(): array
{
    $key = T::str(config('compat.api_key'));
    $rails = T::arr(getJson('/api/catalog/home?api_code='.$key)->assertOk()->json('rails'));

    return array_values(array_map(fn (mixed $r): int => T::int(T::arr($r)['id'] ?? null), $rails));
}

/** @return list<int> the rail ids the TABLE holds, in its own order */
function tableRailOrder(int $storefrontId): array
{
    return array_values(array_map(
        fn (mixed $id): int => T::int($id),
        DB::table('storefront_home_rails')->where('storefront_id', $storefrontId)
            ->orderBy('position')->orderBy('id')->pluck('id')->all(),
    ));
}

it('writes the order the screen posts, for the storefront in the URL and no other', function () {
    $before1 = tableRailOrder(1);
    $before2 = tableRailOrder(2);
    expect(count($before1))->toBeGreaterThan(1);

    $swapped = $before1;
    [$swapped[0], $swapped[1]] = [$swapped[1], $swapped[0]];

    // exactly what Index.tsx sends: every rail id, in the new order, to this storefront's own URL
    actingAs(Staff::admin())->post('/manage/storefronts/1/home-rails/order', ['order' => $swapped])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(tableRailOrder(1))->toBe($swapped, 'the table did not take the order the screen posted')
        // the other storefront must not have moved: a switch that writes to the wrong shop looks exactly
        // like a reorder that never arrives
        ->and(tableRailOrder(2))->toBe($before2, 'editing storefront 1 moved storefront 2 rails');
});

it('serves that order to Watchizer at once, because compat reads the table on every request', function () {
    $ids = tableRailOrder(1);
    expect(count($ids))->toBeGreaterThan(1);

    $before = compatRailOrder();
    $swapped = $ids;
    [$swapped[0], $swapped[1]] = [$swapped[1], $swapped[0]];

    actingAs(Staff::admin())->post('/manage/storefronts/1/home-rails/order', ['order' => $swapped])
        ->assertRedirect()->assertSessionHasNoErrors();

    $after = compatRailOrder();

    // the ACTIVE rails only reach the shop, so compare against the posted order filtered to what is served
    $expected = array_values(array_filter($swapped, fn (int $id): bool => in_array($id, $after, true)));

    expect($after)->toBe($expected, 'compat served a different order than the table holds')
        ->and($after)->not->toBe($before, 'the shop served the same order before and after the reorder');
});

it('refuses a list that belongs to another storefront, rather than writing it', function () {
    $other = tableRailOrder(2);
    if ($other === []) {
        expect(true)->toBeTrue('storefront 2 has no rails to borrow');

        return;
    }
    $before = tableRailOrder(1);

    // storefront 2's ids posted to storefront 1's URL: the set cannot match, so it must be refused
    actingAs(Staff::admin())->post('/manage/storefronts/1/home-rails/order', ['order' => $other])
        ->assertSessionHasErrors('order');

    expect(tableRailOrder(1))->toBe($before, 'a refused reorder still moved rows');
});
