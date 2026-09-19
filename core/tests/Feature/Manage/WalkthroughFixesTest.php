<?php

use App\Domain\Catalog\ProductWriter;
use App\Domain\Inventory\Actor;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use App\Transform\Row;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\CatalogFixture;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * The browser walkthrough's findings, pinned (docs/wave4d/BROWSER_WALKTHROUGH_2026-09-18.md).
 *
 * ── Why these are in one file ───────────────────────────────────────────────────────────────
 *
 * Every defect below was found by EYES, in a real browser, against the real catalogue — and every
 * one of them survived a green test suite. They are not related to each other by screen or by
 * layer; they are related by how they were found, and that is the useful grouping: this file is
 * the list of things the automated suite could not see until somebody looked.
 *
 * Each test names its finding id, so a failure leads straight back to the measurement that
 * justified the fix rather than to an assertion nobody can date.
 *
 * What is NOT here: anything whose only expression is layout. A column rendered off-screen (D-1),
 * a badge at 2:1 contrast (D-6) and an unlabelled input (D-21) are real defects with no server
 * behaviour to assert — they are held by review, and the next browser pass is where they are
 * checked. Pretending otherwise with a snapshot of a class attribute would be a test that fails
 * on every restyle and passes on every regression.
 */

/** A string prop, or `''` when it is absent or null — `T` has no nullable-string helper. */
function walkStr(mixed $value): string
{
    return is_string($value) ? $value : '';
}

/**
 * One list response's rows, by URL.
 *
 * @return list<array<mixed>>
 */
function walkRows(string $url): array
{
    $props = Props::of(actingAs(Staff::admin())->get($url)->assertOk());
    $out = [];
    foreach (T::arr(T::arr($props['table'] ?? [])['data'] ?? []) as $row) {
        $out[] = T::arr($row);
    }

    return $out;
}

/** The TOTAL a list reports, which is the number a filter changes — not the page length. */
function walkTotal(string $url): int
{
    $props = Props::of(actingAs(Staff::admin())->get($url)->assertOk());

    return T::int(T::arr(T::arr($props['table'] ?? [])['meta'] ?? [])['total'] ?? -1);
}

/*
 * ── D-2 · two screens, two numbers for "low stock" ──────────────────────────────────────────
 *
 * `/manage` reported 4,946 وصلت إلى حد التنبيه; `/manage/inventory` bannered 7,524 منتجًا تحت حد
 * التنبيه. Same words, same concept, two screens an operator reads in the same minute. The banner
 * was counting the 2,578 products that are not low but GONE, and announcing 97.5% of the shop as
 * an alert. The predicate had been hand-written in three places and two had drifted.
 */

it('gives the SAME low-stock number on the home tile and the stock banner', function () {
    $home = T::arr(Props::of(actingAs(Staff::admin())->get('/manage')->assertOk())['inventory'] ?? []);
    $stock = T::arr(Props::of(actingAs(Staff::admin())->get('/manage/inventory')->assertOk())['alerts'] ?? []);

    expect(T::int($stock['low'] ?? -1))->toBe(
        T::int($home['low_stock'] ?? -2),
        'the home tile and the stock banner must count "low" the same way — D-2',
    );
    expect(T::int($stock['out'] ?? -1))->toBe(
        T::int($home['out_of_stock'] ?? -2),
        'and "out of stock" too',
    );
});

it('counts LOW and OUT as two different things, never one sum', function () {
    $stock = T::arr(Props::of(actingAs(Staff::admin())->get('/manage/inventory')->assertOk())['alerts'] ?? []);

    // The shape of the original defect: `low` silently included every product with nothing left,
    // because `(express + market) <= threshold` is trivially true at zero.
    $low = T::int($stock['low'] ?? -1);
    $out = T::int($stock['out'] ?? -1);

    $bothAtOnce = DB::table('catalog_products')
        ->whereNull('deleted_at')->where('is_active', 1)->where('in_stock', 0)
        ->whereRaw('(stock_express + stock_market) <= low_stock_threshold')
        ->count();

    expect($bothAtOnce)->toBeGreaterThan(0, 'the premise: out-of-stock rows DO satisfy the low predicate')
        ->and($low + $bothAtOnce)->toBeGreaterThan($low)
        ->and($out)->toBeGreaterThanOrEqual($bothAtOnce);
});

it('narrows the stock list to exactly the rows each banner counted', function () {
    $stock = T::arr(Props::of(actingAs(Staff::admin())->get('/manage/inventory')->assertOk())['alerts'] ?? []);

    // Clicking the banner has to land on the rows it was talking about. When the count and the
    // filter were two different predicates, it did not.
    expect(walkTotal('/manage/inventory?filters[view]=low'))->toBe(T::int($stock['low'] ?? -1));
    expect(walkTotal('/manage/inventory?filters[view]=out'))->toBe(T::int($stock['out'] ?? -1));
});

/*
 * ── D-8 · the stock screen could not be searched by product name ────────────────────────────
 *
 * Every row on `/manage/inventory` is headed by an Arabic product name. Typing part of one
 * returned "لا توجد منتجات" — the query covered `wa_code` and `sku` only, and the placeholder
 * promised exactly that while the screen showed something else.
 */

it('finds a product on the STOCK screen by the name printed on its own row', function () {
    $rows = walkRows('/manage/inventory');
    expect($rows)->not->toBeEmpty();

    // A word taken from a row the screen is ALREADY showing — the exact thing the operator does.
    $title = null;
    foreach ($rows as $row) {
        $candidate = walkStr(T::arr($row)['title'] ?? null);
        if (mb_strlen($candidate) > 6) {
            $title = $candidate;

            break;
        }
    }
    expect($title)->not->toBeNull('the premise: the stock list prints product names');

    $words = preg_split('/\s+/u', T::str($title)) ?: [];
    $word = null;
    foreach ($words as $candidate) {
        if (mb_strlen($candidate) >= 3) {
            $word = $candidate;

            break;
        }
    }
    expect($word)->not->toBeNull();

    expect(walkTotal('/manage/inventory?q='.urlencode(T::str($word))))
        ->toBeGreaterThan(0, "searching the stock list for «{$word}», read off one of its own rows, found nothing — D-8");
});

it('answers a name search the same way on the stock list and the products list', function () {
    // Two lists of the same products may not disagree about whether a product exists. They did:
    // one ran FULLTEXT over the search index, the other ran LIKE over two code columns.
    $term = 'كرافت';

    $stock = walkTotal('/manage/inventory?q='.urlencode($term));
    $products = walkTotal('/manage/storefronts/1/products?q='.urlencode($term));

    expect($stock)->toBeGreaterThan(0)
        ->and($products)->toBeGreaterThan(0);
});

/*
 * ── D-11 · `user #5` in the ledger ──────────────────────────────────────────────────────────
 *
 * On the one screen whose product is accountability, the actor was a raw foreign key.
 */

it('names the person who moved the stock, never a raw id', function () {
    $product = Row::int(Row::cast(T::one(DB::table('catalog_products')->whereNull('deleted_at')->orderBy('id'))), 'id');
    $admin = Staff::admin();

    app(InventoryService::class)->adjust(
        StockTarget::product($product),
        'express',
        1,
        'restock',
        actor: Actor::user(T::int($admin->getAuthIdentifier())),
        note: 'D-11 regression',
    );

    $rows = walkRows('/manage/inventory/ledger?filters[product_id]='.$product);
    expect($rows)->not->toBeEmpty();

    $actor = walkStr(T::arr($rows[0] ?? [])['actor'] ?? null);
    expect($actor)->not->toBe('')
        ->and($actor)->not->toBe('user')
        ->and($actor)->not->toMatch('/^user\s*#?\d+$/', 'the ledger printed the actor TYPE and its id instead of a name — D-11');
});

it('says النظام for a movement nobody signed, rather than inventing a person', function () {
    $product = Row::int(Row::cast(T::one(DB::table('catalog_products')->whereNull('deleted_at')->orderBy('id'))), 'id');

    app(InventoryService::class)->adjust(
        StockTarget::product($product),
        'express',
        1,
        'restock',
        actor: Actor::system(),
        note: 'D-11 system row',
    );

    $rows = walkRows('/manage/inventory/ledger?filters[product_id]='.$product);
    expect(walkStr(T::arr($rows[0] ?? [])['actor'] ?? null))->toBe('النظام');
});

/*
 * ── D-10 · one payment value, three renderings ──────────────────────────────────────────────
 *
 * `order_items.type_stock` holds `Express`/`Market` with a capital; every other screen in the
 * system speaks `express`/`market`. The client's label map is keyed lowercase, so the order line
 * printed the raw legacy casing beside a stock screen calling the same shelf «ماركت».
 */

it('sends the order line its stock bucket in the vocabulary the rest of the system uses', function () {
    $orderId = Row::int(Row::cast(T::one(
        DB::table('orders as o')
            ->join('order_items as oi', 'oi.order_id', '=', 'o.id')
            ->whereNotNull('oi.type_stock')
            ->orderBy('o.id')
            ->select('o.id')
    )), 'id');

    $props = Props::of(actingAs(Staff::admin())->get('/manage/orders/'.$orderId)->assertOk());
    $items = T::arr($props['items'] ?? []);

    $buckets = [];
    foreach ($items as $item) {
        $bucket = walkStr(T::arr($item)['bucket'] ?? null);
        if ($bucket !== '') {
            $buckets[] = $bucket;
        }
    }

    expect($buckets)->not->toBeEmpty();
    foreach ($buckets as $bucket) {
        expect($bucket)->toBeIn(['express', 'market'], "the order line carried «{$bucket}», which no label map is keyed for — D-10");
    }
});

/*
 * ── D-12 · the sign-out message came back in the wrong language ─────────────────────────────
 *
 * Signing out in English landed on the Arabic guest login page carrying `You have been signed
 * out.` — English words in a right-to-left paragraph. The string was resolved in the DEPARTING
 * user's locale, on a page that is always rendered in the guest's.
 */

it('says goodbye in the language of the page the message lands on', function () {
    $admin = Staff::admin();

    // The operator's dashboard is in English…
    DB::table('core_user_preferences')->updateOrInsert(
        ['user_id' => T::int($admin->getAuthIdentifier())],
        ['locale' => 'en', 'updated_at' => now(), 'created_at' => now()],
    );

    $response = actingAs($admin)->post('/manage/logout');
    $status = walkStr(session('status'));

    expect($status)->toBe(
        'تم تسجيل الخروج.',
        'the farewell is read on the GUEST login page, which is Arabic — D-12',
    );

    $response->assertRedirect(route('manage.login'));
});

/*
 * ── D-17 · 403 and 404 were bare English pages with no way back ─────────────────────────────
 *
 * `403 | This action is unauthorized.` and `404 | Not Found`, on white, in a language most of the
 * team does not read, with no dashboard chrome and no link anywhere.
 */

/**
 * The Inertia COMPONENT a response rendered, or '' when it rendered no Inertia page.
 *
 * @param  TestResponse<Response>  $response
 */
function walkComponent(TestResponse $response): string
{
    $page = $response->viewData('page');

    return is_array($page) && is_string($page['component'] ?? null) ? $page['component'] : '';
}

it('gives data-entry a dashboard page when a screen is not theirs, not a bare 403', function () {
    $response = actingAs(Staff::dataEntry())->get('/manage/users');

    $response->assertForbidden();
    expect(walkComponent($response))->toBe('Manage/Error');

    $props = Props::of($response);
    expect(T::int($props['status'] ?? 0))->toBe(403)
        // The whole point: a 403 has a person on the other side of it, and the page names them.
        ->and(walkStr($props['who_to_ask'] ?? null))->not->toBe('')
        ->and(walkStr($props['home'] ?? null))->not->toBe('');
});

it('answers the un-scoped products URL — the stale bookmark — with a way back', function () {
    // `/manage/products` is the natural guess and what every pre-storefront bookmark holds; the
    // real route is `/manage/storefronts/{id}/products`.
    $response = actingAs(Staff::admin())->get('/manage/products');

    $response->assertNotFound();
    expect(walkComponent($response))->toBe('Manage/Error');
    expect(T::int(Props::of($response)['status'] ?? 0))->toBe(404);
});

it('offers no gatekeeper to ask about an address that does not exist', function () {
    // A 404 has nobody behind it. "Ask the administrator" about a typo wastes two afternoons.
    $response = actingAs(Staff::admin())->get('/manage/no-such-screen');

    $response->assertNotFound();
    expect(Props::of($response))->toHaveKey('who_to_ask')
        ->and(Props::of($response)['who_to_ask'])->toBeNull();
});

it('leaves the PUBLIC API generic 404 exactly as it was', function () {
    /*
     * The indistinguishable-404 posture outside /manage is deliberate (wave-2 review 🟡-11): a
     * distinguishable 403 out there tells an attacker that a resource exists. The dashboard page
     * must not leak across that boundary.
     */
    $response = actingAs(Staff::admin())->get('/api/v1/no-such-thing');

    $response->assertNotFound();
    expect($response->getContent())->not->toContain('Manage/Error');
});

/*
 * ── D-13 · the English shell opened with an Arabic product name ─────────────────────────────
 */

it('sends no untranslatable brand suffix to the shell', function () {
    /*
     * `branding.suffix` carried the ENV default «لوحة التحكم» and was rendered as the VISIBLE
     * product name in three places, behind an `i18n-exempt` marker claiming it was a browser-title
     * suffix. It is not in the payload any more; the word comes through the seam as
     * `shell.dashboard`, so the English shell says "Dashboard".
     */
    $branding = T::arr(Props::of(actingAs(Staff::admin())->get('/manage')->assertOk())['branding'] ?? []);

    expect($branding)->not->toHaveKey('suffix')
        ->and($branding)->toHaveKey('name');
});

/*
 * ── D-3 / J-3 · 7,087 products told the operator to file them into the wrong tree ───────────
 *
 * The products list flagged 7,087 of 7,713 rows red `بلا تصنيف`, with a tooltip telling the
 * operator to choose a Watchizer category. Those products are the Brand Fashion catalogue: they
 * are not uncategorised on Watchizer, they are not SOLD on Watchizer — which the same row states
 * correctly two columns away as `— Watchizer`, and which Home has always called `غير مضاف`.
 *
 * A diligent junior working that list as a to-do would move the entire Brand Fashion catalogue
 * into the Watchizer taxonomy, one legal action at a time, with nothing refusing any of it.
 */

it('agrees with Home about how many products on this shop have no category', function () {
    $home = Props::of(actingAs(Staff::admin())->get('/manage')->assertOk());

    $watchizer = null;
    foreach (T::arr($home['storefronts'] ?? []) as $row) {
        if (T::int(T::arr($row)['id'] ?? null) === 1) {
            $watchizer = T::arr($row);
        }
    }
    expect($watchizer)->not->toBeNull('the premise: Home reports per storefront');

    // One phrase, one predicate. Home said 0 and the list said 7,087 for the same words.
    expect(walkTotal('/manage/storefronts/1/products?filters[flag]=unplaced'))
        ->toBe(T::int($watchizer['unplaced'] ?? -1));
});

it('counts the products that are simply not on this shop under their own name', function () {
    $home = Props::of(actingAs(Staff::admin())->get('/manage')->assertOk());

    $watchizer = [];
    foreach (T::arr($home['storefronts'] ?? []) as $row) {
        if (T::int(T::arr($row)['id'] ?? null) === 1) {
            $watchizer = T::arr($row);
        }
    }

    expect(walkTotal('/manage/storefronts/1/products?filters[flag]=absent'))
        ->toBe(T::int($watchizer['not_added'] ?? -1))
        ->toBeGreaterThan(0, 'the premise: most of the catalogue is not sold on Watchizer');
});

it('badges a product that is not on this shop NEUTRALLY, and says nothing needs doing', function () {
    $rows = walkRows('/manage/storefronts/1/products?filters[flag]=absent');
    expect($rows)->not->toBeEmpty();

    foreach (array_slice($rows, 0, 10) as $row) {
        $row = T::arr($row);
        expect(walkStr($row['placement'] ?? null))->toBe(
            'absent',
            'a product with no row on this storefront is `absent`, never `none` — D-3',
        );

        // …and it is not reported as MISSING a category either, which is the other half of the
        // instruction: `بيانات ناقصة: تصنيف` said the same wrong thing in the other badge.
        $missing = [];
        foreach (T::arr($row['missing'] ?? []) as $token) {
            $missing[] = walkStr($token);
        }
        expect($missing)->not->toContain('category');
    }
});

it('keeps the red badge for a product that IS on this shop with no category', function () {
    // The state the red badge is actually for. It must survive the fix — a filter that now
    // returns nothing would mean the badge had been disabled rather than corrected.
    $rows = walkRows('/manage/storefronts/1/products?filters[flag]=unplaced');

    foreach ($rows as $row) {
        expect(walkStr(T::arr($row)['placement'] ?? null))->toBeIn(['none', 'root_only']);
    }
});

/*
 * ── W-2 · the reorder threshold, in bulk, and a default that means something ────────────────
 *
 * 7,578 of 7,713 products carried the default 5 while almost all stock sits between 0 and 3, so
 * the low-stock alert covered 97.5% of the shop. There was no way to change that except 7,578
 * individual form saves.
 */

it('sets the reorder threshold on a batch in one action', function () {
    $ids = [];
    foreach (
        DB::table('catalog_products')->whereNull('deleted_at')->orderBy('id')->limit(3)->pluck('id') as $value
    ) {
        $ids[] = T::int($value);
    }
    expect($ids)->toHaveCount(3);

    actingAs(Staff::admin())
        ->post('/manage/storefronts/1/products/bulk', [
            'action' => 'set_threshold',
            'ids' => $ids,
            'threshold' => 0,
        ])
        ->assertSessionHasNoErrors();

    $left = DB::table('catalog_products')->whereIn('id', $ids)->where('low_stock_threshold', '!=', 0)->count();
    expect($left)->toBe(0);
});

it('accepts ZERO as a threshold, because that is the value the action exists to set', function () {
    // A `min:1` here would forbid exactly the change that makes the alert meaningful again.
    $id = Row::int(Row::cast(T::one(DB::table('catalog_products')->whereNull('deleted_at')->orderBy('id'))), 'id');

    actingAs(Staff::admin())
        ->post('/manage/storefronts/1/products/bulk', [
            'action' => 'set_threshold',
            'ids' => [$id],
            'threshold' => 0,
        ])
        ->assertSessionHasNoErrors();

    expect(T::int(DB::table('catalog_products')->where('id', $id)->value('low_stock_threshold')))->toBe(0);
});

it('records ONE audit row for a threshold batch, not one per product', function () {
    $ids = [];
    foreach (
        DB::table('catalog_products')->whereNull('deleted_at')->orderBy('id')->limit(5)->pluck('id') as $value
    ) {
        $ids[] = T::int($value);
    }

    // Sign in FIRST: granting the test account its role is itself an audited event, and counting
    // before that made this assertion measure the sign-in rather than the batch.
    $admin = Staff::admin();
    $before = DB::table('core_activity_log')->count();

    actingAs($admin)->post('/manage/storefronts/1/products/bulk', [
        'action' => 'set_threshold',
        'ids' => $ids,
        'threshold' => 2,
    ]);

    // "Who set thresholds to 2?" is a question about the BATCH. Five identical rows saying
    // `حد التنبيه: 2 ← 5` would bury the answer rather than record it — the same call
    // CategoryController::reorder() already makes for the same reason.
    expect(DB::table('core_activity_log')->count() - $before)->toBe(1);
});

it('gives a NEW product no reorder alert until somebody decides one', function () {
    /*
     * The default was 5, and 5 was a guess applied to 7,578 products. 0 says the honest thing:
     * nobody has set a reorder point for this product yet.
     *
     * Through the WRITER, not through `CatalogFixture::product()` — the fixture inserts the row
     * directly and carries its own literal 5, so it could never prove anything about this.
     */
    $id = app(ProductWriter::class)->create([
        'wa_code' => 'ZZ-THRESHOLD-'.bin2hex(random_bytes(4)),
        'brand_id' => T::int(DB::table('catalog_brands')->orderBy('id')->value('id')),
        'family' => 'fashion',
        'selling_price' => '500.00',
        'purchase_price' => '0.00',
        'currency' => 'EGP',
        'is_active' => true,
        'title' => ['ar' => 'منتج بلا حد تنبيه', 'en' => 'Product with no reorder point'],
    ], null);

    expect(T::int(DB::table('catalog_products')->where('id', $id)->value('low_stock_threshold')))->toBe(0);
});

/*
 * ── W-3 · select all matching, not just the page ────────────────────────────────────────────
 *
 * The header checkbox selected the 25 rows on screen and nothing offered "all 627 matching", so
 * every bulk action was capped at 25 per round trip.
 */

it('applies a bulk action to every row matching the filters, not just one page', function () {
    $matching = walkTotal('/manage/storefronts/1/products?filters[p.family]=watch');
    expect($matching)->toBeGreaterThan(25, 'the premise: the filtered set is bigger than one page');

    actingAs(Staff::admin())
        ->post('/manage/storefronts/1/products/bulk', [
            'action' => 'set_threshold',
            'scope' => 'matching',
            'query' => '?filters[p.family]=watch',
            'threshold' => 7,
        ])
        ->assertSessionHasNoErrors();

    // Counted BOTH ways. `family = watch AND threshold = 7` would come back right even if every
    // product in the catalogue had been changed — which is exactly what the first version of this
    // assertion missed, and exactly what the code was doing.
    $changed = DB::table('catalog_products')->where('low_stock_threshold', 7)->count();
    $inFamily = DB::table('catalog_products')
        ->whereNull('deleted_at')->where('family', 'watch')->where('low_stock_threshold', 7)->count();

    expect($inFamily)->toBe($matching, 'every matching row must be changed')
        ->and($changed)->toBe($matching, 'and NOTHING outside the filter — the scope is not "everything"');
});

it('cannot be talked into selecting a row the screen would not have shown', function () {
    /*
     * The safety argument for select-all: the scope is re-resolved through the SAME whitelists the
     * list renders from, so an invented filter is dropped rather than obeyed. If a hand-written
     * `query` could widen the set, "apply to all matching" would be a way to edit the whole
     * catalogue from a crafted request.
     */
    actingAs(Staff::admin())->post('/manage/storefronts/1/products/bulk', [
        'action' => 'set_threshold',
        'scope' => 'matching',
        // `p.family=watch` is real; the rest is invented and must not narrow OR widen anything.
        'query' => '?filters[p.family]=watch&filters[p.purchase_price]=0&filters[nonsense]=1&sort=password',
        'threshold' => 9,
    ])->assertSessionHasNoErrors();

    $watches = DB::table('catalog_products')->whereNull('deleted_at')->where('family', 'watch')->count();
    $changed = DB::table('catalog_products')->where('low_stock_threshold', 9)->count();

    expect($changed)->toBe($watches);
});

it('refuses a per-product bulk action over an unreasonable matching set, and says why', function () {
    /*
     * `set_threshold` is one statement and has no cap. `activate` runs each product through the
     * writer — ~29 queries each — so "all 7,578 matching" would be a quarter of a million queries
     * holding row locks. It is refused with a number and an instruction rather than attempted.
     */
    actingAs(Staff::admin())
        ->post('/manage/storefronts/1/products/bulk', [
            'action' => 'activate',
            'scope' => 'matching',
            'query' => '',
        ])
        ->assertSessionHas('error');
});

/*
 * ── W-1 · bulk category assign and remove ───────────────────────────────────────────────────
 */

it('adds a category to a batch without discarding the categories they already had', function () {
    CatalogFixture::assumeSwitched();

    $node = T::int(DB::table('storefront_categories')->where('storefront_id', 1)->orderBy('id')->value('id'));
    $other = T::int(
        DB::table('storefront_categories')->where('storefront_id', 1)->where('id', '!=', $node)->orderBy('id')->value('id')
    );

    // A product that already sits in `$other` and not in `$node`.
    $productId = T::int(
        DB::table('storefront_category_product')
            ->where('storefront_id', 1)->where('storefront_category_id', $other)
            ->whereNotIn('product_id', function (QueryBuilder $sub) use ($node): void {
                $sub->from('storefront_category_product')
                    ->where('storefront_id', 1)->where('storefront_category_id', $node)
                    ->select('product_id');
            })
            ->value('product_id')
    );
    expect($productId)->toBeGreaterThan(0, 'the premise: a product filed somewhere but not in the target node');

    actingAs(Staff::admin())->post('/manage/storefronts/1/placement/bulk', [
        'action' => 'set_category',
        'product_ids' => [$productId],
        'category_id' => $node,
    ]);

    $after = DB::table('storefront_category_product')
        ->where('storefront_id', 1)->where('product_id', $productId)
        ->pluck('storefront_category_id')->all();

    // BOTH. A bulk action that replaced the set would strip categories nobody on this screen can
    // see — twenty selected products have twenty different existing sets.
    expect(count($after))->toBeGreaterThanOrEqual(2);
});

it('refuses to take the LAST category off a visible product, and names it', function () {
    CatalogFixture::assumeSwitched();

    // A visible product with exactly one category: removing it would leave the product on sale
    // and reachable from no listing — created in bulk, silently.
    $row = DB::table('storefront_category_product as scp')
        ->join('storefront_product as sp', function (JoinClause $join): void {
            $join->on('sp.product_id', '=', 'scp.product_id')->where('sp.storefront_id', '=', 1);
        })
        ->where('scp.storefront_id', 1)
        ->where('sp.is_visible', true)
        ->groupBy('scp.product_id')
        ->havingRaw('COUNT(*) = 1')
        ->select('scp.product_id', DB::raw('MIN(scp.storefront_category_id) as node'))
        ->first();

    expect($row)->toBeInstanceOf(stdClass::class, 'the premise: a visible product with exactly one category');
    $cast = Row::cast(T::row($row));
    $productId = Row::int($cast, 'product_id');
    $node = Row::int($cast, 'node');

    actingAs(Staff::admin())->post('/manage/storefronts/1/placement/bulk', [
        'action' => 'clear_category',
        'product_ids' => [$productId],
        'category_id' => $node,
    ])->assertSessionHasErrors('bulk');

    expect(
        DB::table('storefront_category_product')
            ->where('storefront_id', 1)->where('product_id', $productId)->count()
    )->toBe(1, 'the category must still be there — the refusal has to actually refuse');
});

/*
 * ── J-1 / J-2 · the two defaults that handed out mistakes ───────────────────────────────────
 */

it('offers data-entry FIRST, so a hurried grant is not an administrator', function () {
    $roles = T::arr(Props::of(actingAs(Staff::admin())->get('/manage/users')->assertOk())['roles'] ?? []);

    expect($roles)->not->toBeEmpty();
    // A select with no explicit default shows its first option. That first option was
    // `مدير (كل الصلاحيات)`: full access — payments, settings, users, cancellation — granted by
    // leaving the field alone.
    expect(walkStr(T::arr($roles[0] ?? [])['value'] ?? null))->toBe('data_entry');
});

/*
 * ── J-9 · the dead taxonomy was offered like a live section ─────────────────────────────────
 *
 * `شجرة التصنيفات القديمة` is a parked copy of the pre-migration tree. Nothing on either
 * storefront lists from it — 7 nodes per shop, 0 products — and it was selectable in both category
 * pickers exactly like `ساعات`. A product filed there is filed into a taxonomy the storefront will
 * never read, and the form looks completely normal while it happens.
 */

it('does not offer the parked legacy tree as somewhere to file a product', function () {
    $sections = T::arr(Props::of(
        actingAs(Staff::admin())->get('/manage/storefronts/1/products/create')->assertOk()
    )['sections'] ?? []);

    expect($sections)->not->toBeEmpty();

    $legacy = [];
    $live = [];
    foreach ($sections as $section) {
        foreach (T::arr(T::arr($section)['categories'] ?? []) as $option) {
            $row = T::arr($option);
            $id = T::int($row['value'] ?? null);
            $source = DB::table('storefront_categories')->where('id', $id)->value('legacy_source');

            // Everything under a `category_root` node — the root itself included.
            $path = walkStr(DB::table('storefront_categories')->where('id', $id)->value('path'));
            $inDeadTree = $source === 'category_root' || DB::table('storefront_categories')
                ->where('legacy_source', 'category_root')
                ->whereRaw('? LIKE CONCAT(path, ?)', [$path, '%'])
                ->exists();

            if ($inDeadTree) {
                $legacy[] = $row;
            } else {
                $live[] = $row;
            }
        }
    }

    expect($legacy)->not->toBeEmpty('the premise: the legacy tree is still in the payload');

    foreach ($legacy as $row) {
        expect($row['selectable'] ?? null)->toBeFalse(
            'a node of the parked legacy tree must not be pickable — J-9',
        );
    }

    // …and the live tree is untouched. A fix that made everything unpickable would "pass" the
    // assertion above and break the form.
    expect($live)->not->toBeEmpty();
    foreach ($live as $row) {
        expect($row['selectable'] ?? null)->toBeTrue();
    }
});

it('sends the category picker a plain name and an English one to search by', function () {
    /*
     * The `— ` depth prefix is for a native `<select>`, which has no other way to show nesting.
     * The tree picker indents properly, so it needs the name without it — and the English name,
     * because the team is bilingual and half of them will type "watches" at a tree labelled
     * in Arabic.
     */
    $sections = T::arr(Props::of(
        actingAs(Staff::admin())->get('/manage/storefronts/1/products/create')->assertOk()
    )['sections'] ?? []);

    $deep = null;
    foreach ($sections as $section) {
        foreach (T::arr(T::arr($section)['categories'] ?? []) as $option) {
            $row = T::arr($option);
            if (T::int($row['depth'] ?? null) > 1) {
                $deep = $row;

                break 2;
            }
        }
    }

    expect($deep)->not->toBeNull('the premise: the tree is more than one level deep');
    expect(walkStr($deep['name'] ?? null))->not->toStartWith('—')
        ->and(walkStr($deep['label'] ?? null))->toStartWith('—')
        ->and($deep)->toHaveKey('en');
});
