<?php

use App\Storefront\StorefrontCampaigns;

/*
 * ── A campaign card's bar and its stock label never contradict (the developer's review, 2026-11-05) ─────────
 *
 * A card read "50% sold" and "Sold out" at once. The rule is one function, {@see StorefrontCampaigns::bar()},
 * over three real numbers: the units at activation (`start`), the units the ledger says were sold since (`sold`),
 * and the units a shopper can buy on this storefront now (`left`).
 */

it('says how much is sold only while a unit can still be bought, and never 100 % then', function () {
    expect(StorefrontCampaigns::bar(start: 4, sold: 2, units: 2, inStock: true))
        ->toBe(['start' => 4, 'sold' => 2, 'left' => 2, 'pct' => 50, 'state' => 'selling'])
        // restocked during the campaign: more sold than the snapshot, units still on the shelf — not "100% sold"
        ->and(StorefrontCampaigns::bar(start: 4, sold: 6, units: 3, inStock: true))
        ->toBe(['start' => 4, 'sold' => 6, 'left' => 3, 'pct' => 99, 'state' => 'selling'])
        ->and(StorefrontCampaigns::bar(start: 10, sold: 0, units: 10, inStock: true)['pct'])->toBe(0);
});

it('fills the bar and says sold out only when the campaign SOLD every unit', function () {
    expect(StorefrontCampaigns::bar(start: 4, sold: 4, units: 0, inStock: false))
        ->toBe(['start' => 4, 'sold' => 4, 'left' => 0, 'pct' => 100, 'state' => 'sold_out']);
});

it('draws NO bar when the stock reached zero some other way than this deal\'s sales', function () {
    // two of four sold, then the last two left by an adjustment: "50% sold" beside "Sold out" was the defect
    expect(StorefrontCampaigns::bar(start: 4, sold: 2, units: 0, inStock: false))
        ->toBe(['start' => 4, 'sold' => 2, 'left' => 0, 'pct' => null, 'state' => 'unavailable'])
        // units on the shelf that this storefront cannot sell are not "left"
        ->and(StorefrontCampaigns::bar(start: 4, sold: 2, units: 2, inStock: false))
        ->toBe(['start' => 4, 'sold' => 2, 'left' => 0, 'pct' => null, 'state' => 'unavailable']);
});

it('never lets a state and a percentage disagree, whatever the three numbers are', function () {
    foreach ([1, 3, 10] as $start) {
        foreach ([0, 1, 3, 10, 14] as $sold) {
            foreach ([0, 1, 5] as $units) {
                foreach ([true, false] as $inStock) {
                    $bar = StorefrontCampaigns::bar($start, $sold, $units, $inStock);
                    $ok = match ($bar['state']) {
                        'selling' => $bar['left'] > 0 && $bar['pct'] !== null && $bar['pct'] < 100,
                        'sold_out' => $bar['left'] === 0 && $bar['pct'] === 100,
                        default => $bar['left'] === 0 && $bar['pct'] === null,
                    };
                    expect($ok)->toBeTrue("start {$start}, sold {$sold}, units {$units}, in stock ".($inStock ? 'yes' : 'no'));
                }
            }
        }
    }
});
