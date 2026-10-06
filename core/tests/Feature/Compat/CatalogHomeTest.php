<?php

use App\Compat\CompatCategories;
use App\Compat\CompatHome;
use App\Compat\CompatServices;
use App\Domain\Content\HomeRails;
use App\Storefront\StorefrontCache;
use Illuminate\Support\Facades\DB;
use Tests\Support\T;

use function Pest\Laravel\withHeaders;

/*
 * The home page's rails (C-1 stage 4 slice C, `CompatHome`, `GET /api/catalog/home`, table
 * `storefront_home_rails` M1y). The seed reproduces today's home page, and each rail's cards follow
 * the rule the browser used over the whole catalogue — checked here against `all_product`, the very
 * payload the browser derived them from.
 */

const HOME_API_KEY = 'catalog-home-test-key';

beforeEach(function () {
    config(['compat.api_key' => HOME_API_KEY]);
    app(StorefrontCache::class)->flush(1);
});

/** @return array{rails: list<array{id: int, kind: string, target: ?int, title: array{en: ?string, ar: ?string}, products: list<int>}>, products: list<array<array-key, mixed>>, ratings: list<array<array-key, mixed>>, images: list<array<array-key, mixed>>} */
function homeBuild(): array
{
    return app(CompatServices::class)->home->build();
}

/**
 * Replace storefront 1's rails with exactly these, in this order.
 *
 * @param  list<array{kind: string, target?: ?int, count?: int, title?: ?string, active?: bool}>  $rails
 * @return list<array{id: int, kind: string, target_id: ?int, title_en: ?string, title_ar: ?string, position: int, is_active: bool, card_count: int}>
 */
function homeRails(array $rails): array
{
    DB::table('storefront_home_rails')->where('storefront_id', 1)->delete();
    foreach ($rails as $rail) {
        HomeRails::create(1, [
            'kind' => $rail['kind'], 'target_id' => $rail['target'] ?? null, 'title_en' => $rail['title'] ?? null,
            'title_ar' => null, 'is_active' => $rail['active'] ?? true, 'card_count' => $rail['count'] ?? 8,
        ]);
    }

    return HomeRails::all(1);
}

it('seeds today\'s home page: offers (12), featured (5), then every grade in id order (8)', function () {
    $rails = HomeRails::all(1);
    $grades = DB::table('catalog_grades')->orderBy('id')->pluck('id')->map(fn ($id): int => T::int($id))->all();

    expect(array_map(fn (array $r): array => [$r['kind'], $r['target_id'], $r['card_count'], $r['is_active']], $rails))->toBe([
        ['offers', null, 12, true],
        ['featured', null, 5, true],
        ...array_map(fn (int $g): array => ['grade', $g, 8, true], $grades),
    ]);
});

it('picks each rail\'s cards with the browser\'s rule over all_product: offers and grades, in catalogue order; empty grades left out', function () {
    $all = catalogueReferenceRows();
    // The browser did not use the catalogue's order: `transformProductData` re-sorts it — no Market
    // stock last, then newest first (a stable sort, like PHP 8's usort).
    usort($all, function (mixed $a, mixed $b): int {
        $a = T::arr($a);
        $b = T::arr($b);
        $aOut = T::int($a['market_stock'] ?? 0) === 0;
        $bOut = T::int($b['market_stock'] ?? 0) === 0;
        if ($aOut !== $bOut) {
            return $aOut ? 1 : -1;
        }
        $time = fn (array $p): int => isset($p['created_at']) && is_string($p['created_at']) ? (int) strtotime($p['created_at']) : 0;

        return $time($b) <=> $time($a);
    });
    /** @param  callable(array<array-key, mixed>): bool  $keep
     *  @return list<int> */
    $browser = function (callable $keep, int $cap) use ($all): array {
        $out = [];
        foreach ($all as $p) {
            $p = T::arr($p);
            if ($keep($p)) {
                $out[] = T::int($p['id']);
            }
        }

        return array_slice($out, 0, $cap);
    };

    $built = homeBuild();
    $byKey = [];
    foreach ($built['rails'] as $rail) {
        $byKey[$rail['kind'].':'.($rail['target'] ?? '')] = $rail['products'];
    }

    expect($byKey['offers:'])->toBe($browser(fn (array $p): bool => T::float($p['percentage_discount'] ?? 0) > 0, 12));
    $failures = [];
    foreach (DB::table('catalog_grades')->orderBy('id')->pluck('id') as $gradeId) {
        $grade = T::int($gradeId);
        $want = $browser(fn (array $p): bool => ($p['grade_id'] ?? null) !== null && T::int($p['grade_id']) === $grade, 8);
        $got = $byKey['grade:'.$grade] ?? null;
        if ($want === [] ? $got !== null : $got !== $want) {
            $failures[] = "grade {$grade}: browser ".json_encode($want).', core '.json_encode($got);
        }
    }
    expect($failures)->toBe([]);

    // Every card a rail names is in the payload, once.
    $cardIds = array_map(fn (array $p): int => T::int($p['id']), $built['products']);
    $named = [];
    foreach ($built['rails'] as $rail) {
        foreach ($rail['products'] as $id) {
            $named[$id] = $id;
        }
    }
    $named = array_values($named);
    sort($cardIds);
    sort($named);
    expect($cardIds)->toBe($named);
});

it('featured: the products marked featured, else the browser\'s pool — on sale with a picture', function () {
    homeRails([['kind' => 'featured', 'count' => 5]]);
    $entries = app(CompatServices::class)->listing->entries();
    $byId = [];
    foreach ($entries as $e) {
        $byId[$e['id']] = $e;
    }

    $ids = homeBuild()['rails'][0]['products'];
    expect($ids)->toHaveCount(5);
    foreach ($ids as $id) {
        $e = $byId[$id];
        expect((float) $e['saleRaw'])->toBeGreaterThan(0.0)->toBeLessThan((float) $e['sellingRaw'])
            ->and(DB::table('catalog_product_images')->where('product_id', $id)->exists())->toBeTrue();
    }

    // Once the shop marks products featured, only those are shown.
    $marked = [$entries[3]['id'], $entries[7]['id']];
    DB::table('storefront_product')->where('storefront_id', 1)->whereIn('product_id', $marked)->update(['is_featured' => true]);
    $again = homeBuild()['rails'][0]['products'];
    sort($again);
    sort($marked);
    expect($again)->toBe($marked);
});

it('follows the table: order, switched-off rails, card counts, custom titles, brand, category and newest rails', function () {
    $entries = app(CompatServices::class)->listing->entries();
    $brand = T::int($entries[0]['brands']);
    $watches = T::int(DB::table('storefront_categories')->where('storefront_id', 1)->where('depth', 1)->where('slug', 'watches')->value('id'));
    $watchesLegacy = null;
    foreach (app(CompatServices::class)->categories->categoryTypes() as $node) {
        if ($node['id'] === $watches) {
            $watchesLegacy = CompatCategories::legacyIdOf($node);
        }
    }
    expect($watchesLegacy)->not->toBeNull();

    homeRails([
        ['kind' => 'newest', 'count' => 4],
        ['kind' => 'brand', 'target' => $brand, 'count' => 3, 'title' => 'Our pick'],
        ['kind' => 'offers', 'count' => 12, 'active' => false],
        ['kind' => 'category_type', 'target' => $watches, 'count' => 6],
    ]);
    $rails = homeBuild()['rails'];

    expect(array_column($rails, 'kind'))->toBe(['newest', 'brand', 'category_type'])     // offers is switched off
        ->and($rails[1]['title'])->toBe(['en' => 'Our pick', 'ar' => null])
        ->and($rails[2]['target'])->toBe($watchesLegacy)
        ->and(count($rails[0]['products']))->toBe(4)
        ->and(count($rails[1]['products']))->toBeLessThanOrEqual(3)
        ->and(count($rails[2]['products']))->toBe(6);

    $byId = [];
    foreach ($entries as $e) {
        $byId[$e['id']] = $e;
    }
    $created = array_map(fn (int $id): int => $byId[$id]['created'], $rails[0]['products']);
    $sorted = $created;
    rsort($sorted);
    expect($created)->toBe($sorted);
    foreach ($rails[0]['products'] as $id) {
        expect($byId[$id]['inStock'])->toBeTrue();
    }
    foreach ($rails[1]['products'] as $id) {
        expect($byId[$id]['brands'])->toBe($brand);
    }
    foreach ($rails[2]['products'] as $id) {
        expect($byId[$id]['categories'])->toBe($watchesLegacy);
    }
});

it('refuses a rail that makes no sense, and a reorder that does not name every rail once', function () {
    $rails = homeRails([['kind' => 'offers'], ['kind' => 'newest']]);
    $bad = [
        ['kind' => 'grade', 'target_id' => null],               // a grade rail with no grade
        ['kind' => 'offers', 'target_id' => 3],                 // an offers rail with a target
        ['kind' => 'bestsellers', 'target_id' => null],         // no such kind
    ];
    foreach ($bad as $data) {
        expect(fn () => HomeRails::create(1, $data + ['title_en' => null, 'title_ar' => null, 'is_active' => true, 'card_count' => 8]))
            ->toThrow(InvalidArgumentException::class);
    }
    expect(fn () => HomeRails::create(1, ['kind' => 'offers', 'target_id' => null, 'title_en' => null, 'title_ar' => null, 'is_active' => true, 'card_count' => 99]))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => HomeRails::reorder(1, [$rails[0]['id']]))->toThrow(InvalidArgumentException::class);
    HomeRails::reorder(1, [$rails[1]['id'], $rails[0]['id']]);
    expect(array_column(HomeRails::all(1), 'kind'))->toBe(['newest', 'offers']);
});

it('answers GET catalog/home with the API key, and only with it', function () {
    $body = T::arr(withHeaders(['Api-Code' => HOME_API_KEY])->getJson('/api/catalog/home')->assertOk()->json());
    expect(array_keys($body))->toBe(['rails', 'products', 'ratings', 'images'])
        ->and(T::arr($body['rails']))->not->toBeEmpty();
    withHeaders(['Api-Code' => 'not-the-key'])->getJson('/api/catalog/home')->assertStatus(401);
});

/**
 * A hand-made index entry.
 *
 * @param  array<string, mixed>  $o
 * @return array{id: int, brands: ?int, categories: ?int, subTypes: ?int, grades: ?int, materials: ?int, movements: ?int, shapes: ?int, displayTypes: ?int, genders: list<string>, dialColors: list<int>, bandColors: list<int>, pct: float, price: float, sellingRaw: ?string, saleRaw: ?string, rating: ?float, search: list<string>, words: list<string>, slug: string, family: ?string, inStock: bool, created: int}
 */
function homeEntry(int $id, array $o = []): array
{
    /** @var array{id: int, brands: ?int, categories: ?int, subTypes: ?int, grades: ?int, materials: ?int, movements: ?int, shapes: ?int, displayTypes: ?int, genders: list<string>, dialColors: list<int>, bandColors: list<int>, pct: float, price: float, sellingRaw: ?string, saleRaw: ?string, rating: ?float, search: list<string>, words: list<string>, slug: string, family: ?string, inStock: bool, created: int} */
    return array_merge([
        'id' => $id, 'brands' => 1, 'categories' => 1, 'subTypes' => 1, 'grades' => null, 'materials' => null,
        'movements' => null, 'shapes' => null, 'displayTypes' => null, 'genders' => ['Men'], 'dialColors' => [], 'bandColors' => [],
        'pct' => 0.0, 'price' => 1000.0, 'sellingRaw' => '1000.00', 'saleRaw' => null, 'rating' => null, 'search' => [], 'words' => [],
        'slug' => 'p'.$id, 'family' => 'watch', 'inStock' => true, 'created' => $id,
    ], $o);
}

it('picks by the rule on hand-made entries: catalogue order, the count, and exactly the kind\'s condition', function () {
    $entries = [
        homeEntry(1, ['pct' => 10.0, 'grades' => 2, 'brands' => 5, 'categories' => 7]),
        homeEntry(2, ['pct' => 0.0, 'grades' => 2, 'created' => 50]),
        homeEntry(3, ['pct' => 5.0, 'grades' => 3, 'brands' => 5, 'inStock' => false, 'created' => 90]),
        homeEntry(4, ['pct' => 20.0, 'grades' => null, 'categories' => 7, 'created' => 70]),
        homeEntry(5, ['pct' => 0.0, 'grades' => 2, 'brands' => 5]),
    ];

    expect(CompatHome::pick('offers', null, 12, $entries))->toBe([1, 3, 4])      // out of stock kept: the browser kept it
        ->and(CompatHome::pick('offers', null, 2, $entries))->toBe([1, 3])
        ->and(CompatHome::pick('grade', 2, 8, $entries))->toBe([1, 2, 5])
        ->and(CompatHome::pick('grade', 9, 8, $entries))->toBe([])
        ->and(CompatHome::pick('brand', 5, 8, $entries))->toBe([1, 3, 5])
        ->and(CompatHome::pick('category_type', 7, 8, $entries))->toBe([1, 4])
        ->and(CompatHome::pick('newest', null, 3, $entries))->toBe([4, 2, 5]);     // 3 is out of stock
});

it('featured pool: marked products win; else on sale with a picture; else any with a picture when fewer than 3 are on sale', function () {
    $sale = fn (int $id, ?string $s, string $sell = '1000.00'): array => homeEntry($id, ['saleRaw' => $s, 'sellingRaw' => $sell]);
    $entries = [$sale(1, '900.00'), $sale(2, '800.00'), $sale(3, '700.00'), $sale(4, null), $sale(5, '1000.00'), $sale(6, '0'), $sale(7, '600.00')];

    expect(CompatHome::featuredPool($entries, [], [1, 2, 3, 4, 5, 6]))->toBe([1, 2, 3])          // 7 has no picture; 4, 5, 6 are not on sale
        ->and(CompatHome::featuredPool($entries, [], [1, 2, 4, 5]))->toBe([1, 2, 4, 5])          // fewer than 3 on sale: any with a picture
        ->and(CompatHome::featuredPool($entries, [99, 5], [1, 2, 3]))->toBe([5]);                // marked and visible only
});

it('a custom rail shows the team\'s picks in the team\'s order, and only products the storefront shows', function () {
    $entries = [homeEntry(1), homeEntry(2), homeEntry(3), homeEntry(5)];
    // 4 is not in the storefront's index (hidden or deleted since it was picked): skipped, no gap.
    expect(CompatHome::picked([5, 4, 1, 3], $entries))->toBe([5, 1, 3])
        ->and(CompatHome::picked([], $entries))->toBe([]);
});

it('serves a custom rail from the table: its picks, in order, under its own titles', function () {
    $entries = app(CompatServices::class)->listing->entries();
    $picks = [$entries[4]['id'], $entries[0]['id'], $entries[2]['id']];
    DB::table('storefront_home_rails')->where('storefront_id', 1)->delete();
    HomeRails::create(1, ['kind' => 'custom', 'target_id' => null, 'product_ids' => [...$picks, 999999999], 'title_en' => 'Our picks', 'title_ar' => 'اختياراتنا', 'is_active' => true, 'card_count' => 1]);

    $home = homeBuild();
    expect(count($home['rails']))->toBe(1)
        ->and($home['rails'][0]['kind'])->toBe('custom')
        ->and($home['rails'][0]['products'])->toBe($picks)                     // the order picked; the unknown id dropped
        ->and($home['rails'][0]['title'])->toBe(['en' => 'Our picks', 'ar' => 'اختياراتنا'])
        ->and(HomeRails::all(1)[0]['card_count'])->toBe(4);                   // the count is the picks, whatever was sent
});
