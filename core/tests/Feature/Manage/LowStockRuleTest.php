<?php

use App\Domain\Inventory\InventoryService;
use App\Support\Sql;
use Illuminate\Support\Facades\DB;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * "LOW STOCK" means ONE thing (B5, 2026-09-20).
 *
 * It meant three. The products list ran the arithmetic alone and answered 7,524; the stock view
 * added `is_active` and `in_stock` and answered 4,946; the badge on that same stock screen ran the
 * arithmetic in PHP and fired on all 2,578 out-of-stock rows — rows the filter beside it excluded.
 * Two screens used the same Arabic words and disagreed by 2,578, and one screen contradicted
 * itself.
 *
 * ── Why it came back, which is the part worth remembering ───────────────────────────────────
 *
 * It was "fixed" on 2026-09-19. That pass added an `$alias` argument to the shared helper, with a
 * comment saying it existed *because the stock list joins `catalog_products as p`* — and then never
 * changed the products list to call it. The helper gained an arm for a caller that was never
 * connected, which is indistinguishable from a helper in use, so nothing failed and nobody looked.
 * The inline SQL sat one line away from the fix for a month.
 *
 * The shape of the repair is the lesson: a helper that owns a FRAGMENT cannot stop this, because
 * every caller still has to remember the rest and two of three did not. `Sql::lowStock()` owns the
 * whole rule, so there is nothing left for a caller to add and therefore nothing to forget.
 */

it('gives every screen the same answer, because they ask the same function', function () {
    $rule = T::int(DB::table('catalog_products')->whereNull('deleted_at')->whereRaw(Sql::lowStock())->count());

    expect($rule)->toBeGreaterThan(0, 'this catalogue no longer proves anything about low stock');

    // 1 — the service, which feeds the banner and the home tile.
    expect(T::int(InventoryService::lowStockProducts()->count()))->toBe($rule);

    // 2 — the stock screen's own view.
    $stock = Props::of(actingAs(Staff::admin())->get('/manage/inventory?filters[view]=low')->assertOk());
    expect(T::int(T::arr(T::arr($stock['table'] ?? null)['meta'] ?? null)['total'] ?? null))->toBe($rule);

    /*
     * 3 — the products list. This is the one that was never wired: it answered 7,524 against the
     * stock screen's 4,946, in the same words, on the same catalogue.
     *
     * Catalogue-wide, because the products list defaults to the selected shop (W1) and this
     * assertion is about the RULE, not about placement.
     */
    $products = Props::of(actingAs(Staff::admin())
        ->get('/manage/storefronts/1/products?filters[flag]=low_stock&filters[scope]=all')->assertOk());
    expect(T::int(T::arr(T::arr($products['table'] ?? null)['meta'] ?? null)['total'] ?? null))->toBe($rule);
});

it('badges exactly the rows the filter beside it returns', function () {
    /*
     * The contradiction that lived on ONE screen: the badge said «منخفض» on every out-of-stock row
     * and the filter returned none of them. It is now selected from the same expression the filter
     * uses, so a disagreement is not expressible rather than merely absent.
     */
    $admin = Staff::admin();

    $low = Props::of(actingAs($admin)->get('/manage/inventory?filters[view]=low&per_page=50')->assertOk());
    foreach (T::arr(T::arr($low['table'] ?? null)['data'] ?? null) as $row) {
        $cast = T::arr($row);
        expect($cast['is_low'] ?? null)->toBeTrue(
            'a row the LOW filter returned is not badged low: '.T::str($cast['wa_code'] ?? null)
        );
    }

    // …and the other side of it: nothing in the OUT view may be badged low. This is the exact set
    // — all 2,578 out-of-stock rows — that the old PHP badge got wrong.
    $out = Props::of(actingAs($admin)->get('/manage/inventory?filters[view]=out&per_page=50')->assertOk());
    $rows = T::arr(T::arr($out['table'] ?? null)['data'] ?? null);
    expect($rows)->not->toBe([], 'no out-of-stock rows, so this proves nothing');

    foreach ($rows as $row) {
        $cast = T::arr($row);
        expect($cast['is_low'] ?? null)->toBeFalse(
            'an OUT of stock row is badged low: '.T::str($cast['wa_code'] ?? null)
        );
    }
});

it('keeps the promise the product form makes about a threshold of zero', function () {
    /*
     * The form says, in as many words, *leave it at zero if you do not want an alert for this
     * product*. The rule now says so directly.
     *
     * Measured 2026-09-20: no product currently sits at 0, so this clause changes no number today.
     * It is here so the promise survives somebody relaxing `in_stock = 1` later — which is the only
     * other clause that happens to exclude those rows.
     */
    /*
     * Only `low_stock_threshold` is written here, and never a stock column. `StockWriteGuard`
     * refuses a stock write outside `InventoryService` — correctly; it caught the first draft of
     * this test, which set `stock_express` to zero to make its point. The rule can be exercised
     * without doing that: pick a product whose stock is ALREADY at or below a small number, and
     * move only the threshold.
     */
    $row = T::row(DB::table('catalog_products')->whereNull('deleted_at')
        ->where('is_active', 1)->where('in_stock', 1)
        ->whereRaw('(stock_express + stock_market) BETWEEN 1 AND 20')
        ->first(['id', 'low_stock_threshold', 'stock_express', 'stock_market']));

    $product = T::int($row->id);
    $wasThreshold = T::int($row->low_stock_threshold);
    $onHand = T::int($row->stock_express) + T::int($row->stock_market);

    // A threshold ABOVE the stock on hand: by the arithmetic this is low, and it is.
    DB::table('catalog_products')->where('id', $product)->update(['low_stock_threshold' => $onHand + 1]);
    expect(DB::table('catalog_products')->where('id', $product)->whereRaw(Sql::lowStock())->exists())
        ->toBeTrue('a product below its own threshold is not being reported as low');

    // Zero: the form's "no alert for this product". The arithmetic still fires for a product at
    // zero stock, so this clause is what keeps the promise — not the arithmetic, and not luck.
    DB::table('catalog_products')->where('id', $product)->update(['low_stock_threshold' => 0]);
    expect(DB::table('catalog_products')->where('id', $product)->whereRaw(Sql::lowStock())->exists())
        ->toBeFalse('a product with no alert threshold was reported as low');

    // Put it back exactly: this runs against the developer's own catalogue.
    DB::table('catalog_products')->where('id', $product)->update(['low_stock_threshold' => $wasThreshold]);
});

it('refuses an alias it has no arm for, rather than building SQL from an argument', function () {
    // The `literal-string` guarantee is the reason `whereRaw()` may take this at all. A third
    // alias is a third arm in `Sql`, written by a person, not a string concatenated at runtime.
    expect(fn () => Sql::lowStock('x'))->toThrow(InvalidArgumentException::class, 'no arm for the alias');
    expect(fn () => Sql::lowStockSelect('x'))->toThrow(InvalidArgumentException::class, 'no arm for the alias');
});

it('has no hand-written copy of the rule left anywhere', function () {
    /*
     * The fragment came back once because a second copy existed to come back to. This is what says
     * it cannot happen a third time: the arithmetic appears in `Sql` and nowhere else.
     */
    $offenders = [];
    foreach (['app', 'database', 'routes'] as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory)));
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php' || str_ends_with($file->getPathname(), 'Sql.php')) {
                continue;
            }
            /*
             * The ARITHMETIC, not the two column names near each other. Co-occurrence flagged
             * seven files that merely list the columns — the transform step, the model, the
             * validation rules — which is a grep matching prose rather than the hazard, the same
             * defect as the `logoutOtherDevices` one. What must not exist twice is the comparison.
             */
            $body = (string) preg_replace('/\s+/', ' ', (string) file_get_contents($file->getPathname()));
            if (preg_match('/stock_express[^;]{0,40}stock_market[^;]{0,20}<=[^;]{0,30}low_stock_threshold/', $body) === 1) {
                $offenders[] = $file->getPathname();
            }
        }
    }

    expect($offenders)->toBe([], 'the low-stock rule is written out by hand here; it belongs in Sql::lowStock()');
});
