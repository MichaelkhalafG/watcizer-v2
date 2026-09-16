<?php

use App\Domain\Promotions\PromotionEngine;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Tests\Support\PromotionFixture;
use Tests\Support\T;

/*
 * A promotion belongs to a storefront, and never leaks into the other one (review 🔴-4).
 *
 * ── The decision this encodes ───────────────────────────────────────────────────────────────
 *
 * Option (a): the cart carries its storefront identity, and the engine considers only rules
 * enabled for it. Watchizer and Brand Fashion share one catalogue, one cart table and one checkout
 * path — what they must not share is a discount. "Buy two, get a free strap" authored for Brand
 * Fashion must never fire on a Watchizer cart, and a Watchizer reward must never be granted to a
 * Brand Fashion shopper.
 *
 * ── Why the proof runs both directions ──────────────────────────────────────────────────────
 *
 * A one-directional test passes just as happily when the engine ignores storefronts entirely and
 * the fixture happens to sit on the storefront being tested. Each case below therefore builds ONE
 * rule, evaluates it against BOTH carts, and asserts it fires on exactly one of them.
 *
 * What this file does NOT prove is which storefront a live request belongs to — that is the
 * identity half, which lives in `CompatServices` and is a separate seam. This file proves the
 * engine half: given a correct identity, nothing crosses.
 */

/** A product that is live and visible on `$storefrontId` — the fixture's helper is pinned to 1. */
function giftableOn(int $storefrontId): int
{
    $id = DB::table('catalog_products as p')
        ->join('storefront_product as sp', function (JoinClause $join) use ($storefrontId): void {
            $join->on('sp.product_id', '=', 'p.id')
                ->where('sp.storefront_id', '=', $storefrontId)
                ->where('sp.is_visible', '=', 1);
        })
        ->whereNull('p.deleted_at')
        ->where('p.is_active', 1)
        ->where('p.stock_express', '>=', 3)
        ->orderBy('p.id')
        ->value('p.id');

    expect($id)->not->toBeNull("no giftable product is visible on storefront {$storefrontId}");

    return T::int($id);
}

/** Did any reward land? */
function granted(int $storefrontId, int $productId, int $ruleProductId): bool
{
    $cart = PromotionFixture::cart(
        lines: [['product' => $productId, 'qty' => 3, 'price' => 500.0]],
        storefrontId: $storefrontId,
    );

    $outcome = app(PromotionEngine::class)->evaluate($cart);

    foreach ($outcome->rewards as $reward) {
        if ($reward->productId === $ruleProductId) {
            return true;
        }
    }

    return false;
}

it('never applies a BRAND FASHION rule to a Watchizer cart', function () {
    $onBoth = giftableOn(1);
    $gift = giftableOn(2);

    // One rule, enabled for storefront 2 only.
    PromotionFixture::rule(
        storefronts: [2],
        conditions: [PromotionFixture::subtotalAtLeast(100.0)],
        rewards: [PromotionFixture::freeProduct($gift)],
    );

    expect(granted(2, giftableOn(2), $gift))->toBeTrue('the rule did not fire on its OWN storefront — the fixture is wrong, not the isolation')
        ->and(granted(1, $onBoth, $gift))->toBeFalse('a Brand Fashion rule fired on a Watchizer cart');
});

it('never applies a WATCHIZER rule to a Brand Fashion cart', function () {
    // The mirror image. Without it, an engine that simply always answers "storefront 1" would pass
    // the case above for the wrong reason.
    $gift = giftableOn(1);
    $onTwo = giftableOn(2);

    PromotionFixture::rule(
        storefronts: [1],
        conditions: [PromotionFixture::subtotalAtLeast(100.0)],
        rewards: [PromotionFixture::freeProduct($gift)],
    );

    expect(granted(1, giftableOn(1), $gift))->toBeTrue('the rule did not fire on its own storefront')
        ->and(granted(2, $onTwo, $gift))->toBeFalse('a Watchizer rule fired on a Brand Fashion cart');
});

it('applies a rule enabled for BOTH storefronts on both', function () {
    /*
     * The other half of "scoped correctly": a rule the merchant enabled everywhere must not be
     * withheld. An engine that refused everything would pass both cases above.
     */
    $gift = giftableOn(1);

    PromotionFixture::rule(
        storefronts: [1, 2],
        conditions: [PromotionFixture::subtotalAtLeast(100.0)],
        rewards: [PromotionFixture::freeProduct($gift)],
    );

    expect(granted(1, giftableOn(1), $gift))->toBeTrue()
        ->and(granted(2, giftableOn(2), $gift))->toBeTrue();
});

it('reads the storefront pivot as an INNER JOIN, so no later code can forget it', function () {
    /*
     * A structural guard on the mechanism rather than the behaviour. The scoping works because
     * `candidates()` joins `promotion_rule_storefront` and filters on it IN THE QUERY — a rule not
     * enabled for the cart's storefront is never a candidate at all, so there is no later branch
     * that could forget to check. Rewriting that join as a post-filter would keep every test above
     * green right up until somebody added a code path that skipped the filter.
     */
    $source = T::str(file_get_contents(app_path('Domain/Promotions/PromotionEngine.php')));

    expect($source)->toContain("->join('promotion_rule_storefront as rs'")
        ->and($source)->toContain("->where('rs.storefront_id', \$storefrontId)");
});
