<?php

use App\Compat\CompatRelated;
use App\Compat\CompatServices;
use App\Storefront\StorefrontCache;
use Illuminate\Support\Facades\DB;
use Tests\Support\T;

use function Pest\Laravel\withHeaders;

/*
 * The suggestion rails (C-1 stage 4, `CompatRelated`, `GET /api/catalog/related`) — the developer's
 * rules of 2026-09-29:
 *   - ADD-ONS ("Complete the look" in the cart, "Pairs well with" on the product page): in stock, not
 *     in the cart, a DIFFERENT family; tier 1 same brand + same gender, tier 2 same gender any brand,
 *     no tier 3; cheaper-first by closeness to the anchor's price, newest breaks ties; a cart anchors
 *     on its most expensive line.
 *   - SIMILAR STYLES (product page): the storefront's old score, same family only, with the decided
 *     fixes (out of stock, missing values, the price paid, the cart) and a uniform shuffle.
 * The old score is compared with a FROZEN copy of the storefront's JavaScript rule
 * (`tests/Fixtures/related-reference.js`, 149af67): equal on every candidate both keep, and every
 * candidate only the old rule keeps is one of the decided fixes.
 */

const RELATED_API_KEY = 'catalog-related-test-key';

beforeEach(function () {
    config(['compat.api_key' => RELATED_API_KEY]);
    app(StorefrontCache::class)->flush(1);
});

function relatedNode(): string
{
    return trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
}

function relatedGet(string $uri): mixed
{
    return withHeaders(['Api-Code' => RELATED_API_KEY])->getJson($uri)->assertOk()->json();
}

function relatedStorefrontFile(string $relative): string
{
    $path = dirname(base_path()).'/Frontend-next/'.$relative;
    expect(is_file($path))->toBeTrue("missing storefront file {$relative}");

    return (string) file_get_contents($path);
}

/**
 * A hand-made index entry.
 *
 * @param  array<string, mixed>  $o
 * @return array{id: int, brands: ?int, categories: ?int, subTypes: ?int, grades: ?int, materials: ?int, movements: ?int, shapes: ?int, displayTypes: ?int, genders: list<string>, dialColors: list<int>, bandColors: list<int>, pct: float, price: float, sellingRaw: ?string, saleRaw: ?string, rating: ?float, search: list<string>, words: list<string>, slug: string, family: ?string, inStock: bool, created: int}
 */
function relEntry(int $id, array $o = []): array
{
    /** @var array{id: int, brands: ?int, categories: ?int, subTypes: ?int, grades: ?int, materials: ?int, movements: ?int, shapes: ?int, displayTypes: ?int, genders: list<string>, dialColors: list<int>, bandColors: list<int>, pct: float, price: float, sellingRaw: ?string, saleRaw: ?string, rating: ?float, search: list<string>, words: list<string>, slug: string, family: ?string, inStock: bool, created: int} */
    return array_merge([
        'id' => $id, 'brands' => 1, 'categories' => 1, 'subTypes' => 1, 'grades' => null, 'materials' => null,
        'movements' => null, 'shapes' => null, 'displayTypes' => null, 'genders' => ['Men'], 'dialColors' => [], 'bandColors' => [],
        'pct' => 0.0, 'price' => 1000.0, 'sellingRaw' => '1000.00', 'saleRaw' => null, 'rating' => null, 'search' => [], 'words' => [],
        'slug' => 'p'.$id, 'family' => 'watch', 'inStock' => true, 'created' => $id,
    ], $o);
}

it('add-ons: a different family only, tier 1 (same brand + gender) before tier 2 (same gender), never another gender', function () {
    $entries = [
        relEntry(1, ['brands' => 7, 'family' => 'watch', 'price' => 4000.0]),                 // the anchor
        relEntry(2, ['brands' => 7, 'family' => 'watch']),                                     // same family: never
        relEntry(3, ['brands' => 7, 'family' => 'bag', 'price' => 3000.0]),                    // tier 1
        relEntry(4, ['brands' => 9, 'family' => 'bag', 'price' => 3900.0]),                    // tier 2
        relEntry(5, ['brands' => 7, 'family' => 'bag', 'genders' => ['Women']]),              // other gender: never
        relEntry(6, ['brands' => 9, 'family' => 'wallet', 'genders' => ['Unisex'], 'price' => 1000.0]), // unisex: tier 2
        relEntry(7, ['brands' => 7, 'family' => 'bag', 'inStock' => false]),                  // out of stock: never
        relEntry(8, ['brands' => 7, 'family' => null]),                                        // no family: never
    ];

    expect(CompatRelated::rankAddOns($entries, [1], []))->toBe([3, 4, 6])
        ->and(CompatRelated::rankAddOns($entries, [1], [3]))->toBe([4, 6]);          // the cart is excluded
});

it('add-ons: cheaper-and-closest first, then pricier-and-closest, newest breaks ties', function () {
    $entries = [
        relEntry(1, ['family' => 'watch', 'price' => 4000.0]),
        relEntry(2, ['family' => 'bag', 'price' => 1000.0]),
        relEntry(3, ['family' => 'bag', 'price' => 3500.0, 'created' => 10]),
        relEntry(4, ['family' => 'bag', 'price' => 3500.0, 'created' => 20]),              // newer twin of 3
        relEntry(5, ['family' => 'bag', 'price' => 4200.0]),
        relEntry(6, ['family' => 'bag', 'price' => 9000.0]),
    ];

    expect(CompatRelated::rankAddOns($entries, [1], []))->toBe([4, 3, 2, 5, 6]);
});

it('add-ons for a cart: anchored on the most expensive line, every family already in the cart excluded', function () {
    $entries = [
        relEntry(1, ['brands' => 5, 'family' => 'watch', 'price' => 3000.0]),
        relEntry(2, ['brands' => 7, 'family' => 'wallet', 'price' => 6000.0]),               // the anchor: most expensive
        relEntry(3, ['brands' => 7, 'family' => 'bag', 'price' => 2000.0]),                  // tier 1 for brand 7
        relEntry(4, ['brands' => 5, 'family' => 'bag', 'price' => 2500.0]),                  // tier 2
        relEntry(5, ['brands' => 7, 'family' => 'watch']),                                   // watch: in the cart's families
        relEntry(6, ['brands' => 7, 'family' => 'wallet']),                                  // wallet: in the cart's families
    ];

    expect(CompatRelated::rankAddOns($entries, [1, 2], [1, 2]))->toBe([3, 4]);
});

it('similar styles: same family, in stock, not in the cart, and a missing value never counts as a match', function () {
    $entries = [
        relEntry(1, ['brands' => 7, 'subTypes' => null, 'categories' => 3]),
        relEntry(2, ['brands' => 7, 'subTypes' => null, 'categories' => 3]),                 // same brand + category
        relEntry(3, ['brands' => 7, 'family' => 'bag']),                                     // other family
        relEntry(4, ['brands' => 7, 'inStock' => false]),                                    // out of stock
        relEntry(5, ['brands' => 8, 'subTypes' => null, 'categories' => 4]),                 // shares only a MISSING sub-type
        relEntry(6, ['brands' => 7, 'categories' => 3]),                                     // in the cart
    ];

    $scores = CompatRelated::productScores($entries, $entries[0], [6]);
    // 2: brand 3 + category 1 + gender 2 + price band 2 = 8. Nothing else qualifies.
    expect($scores)->toBe([2 => 8])
        ->and(CompatRelated::rankSimilar($entries, 1, [6]))->toBe([2]);
});

it('keeps the old score, fed the price the shopper pays, on every candidate both rules keep — and every candidate only the old rule keeps is a decided fix', function () {
    $entries = app(CompatServices::class)->listing->entries();
    $byId = [];
    foreach ($entries as $e) {
        $byId[$e['id']] = $e;
    }
    $step = max(1, intdiv(count($entries), 20));
    $bases = [];
    for ($i = 0; $i < count($entries) && count($bases) < 20; $i += $step) {
        $bases[] = $entries[$i]['id'];
    }
    $php = [];
    foreach ($bases as $id) {
        $php[$id] = CompatRelated::productScores($entries, $byId[$id]);
    }
    $paid = [];
    foreach ($entries as $e) {
        $paid[$e['id']] = $e['price'];
    }

    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wz-related-'.bin2hex(random_bytes(4));
    mkdir($dir.'/utils', 0777, true);
    mkdir($dir.'/lib', 0777, true);
    file_put_contents($dir.'/utils/transformProduct.js', str_replace("from './imageUrl'", "from './imageUrl.js'", relatedStorefrontFile('src/utils/transformProduct.js')));
    file_put_contents($dir.'/utils/imageUrl.js', str_replace("from '../lib/env'", "from '../lib/env.js'", relatedStorefrontFile('src/utils/imageUrl.js')));
    file_put_contents($dir.'/lib/env.js', relatedStorefrontFile('src/lib/env.js'));
    file_put_contents($dir.'/reference.js', (string) file_get_contents(base_path('tests/Fixtures/related-reference.js')));
    file_put_contents($dir.'/package.json', '{"type":"module"}');
    file_put_contents($dir.'/data.json', json_encode([
        'all' => relatedGet('/api/all_product'),
        'tables' => T::arr(relatedGet('/api/catalog/meta'))['tables'],
        'ratings' => relatedGet('/api/all_product_rating'),
        'images' => relatedGet('/api/all_product_image'),
        'bases' => $bases, 'paid' => $paid,
    ], JSON_UNESCAPED_UNICODE));
    file_put_contents($dir.'/run.js', <<<'JS'
        import { readFileSync } from 'node:fs'
        import { transformProductData } from './utils/transformProduct.js'
        import { pdpScores } from './reference.js'

        const d = JSON.parse(readFileSync(new URL('./data.json', import.meta.url)))
        const products = transformProductData(d.all, d.tables, d.ratings, d.images, 'en')
        // Decided fix 3: score on the price the shopper pays — hand the frozen rule that price.
        products.forEach((p) => { const v = d.paid[p.id]; if (v !== undefined) { p.sale_price_after_discount = String(v); p.selling_price = String(v) } })
        const out = {}
        d.bases.forEach((id) => { out[id] = pdpScores(products, products.find((p) => p.id === id)) })
        console.log(JSON.stringify(out))
        JS);

    $raw = trim((string) shell_exec('node '.escapeshellarg($dir.DIRECTORY_SEPARATOR.'run.js').' 2>&1'));
    foreach ([...(glob($dir.'/*/*') ?: []), ...(glob($dir.'/*.*') ?: [])] as $file) {
        unlink($file);
    }
    @rmdir($dir.'/utils');
    @rmdir($dir.'/lib');
    @rmdir($dir);
    $js = json_decode($raw, true);
    expect($js)->toBeArray("node said: {$raw}");

    $same = fn (?int $a, ?int $b): bool => $a !== null && $a !== 0 && $a === $b;
    $bad = [];
    $compared = 0;
    foreach ($bases as $id) {
        $base = $byId[$id];
        $old = T::arr(T::arr($js)[(string) $id] ?? []);
        foreach ($php[$id] as $pid => $score) {
            $compared++;
            if (($old[(string) $pid] ?? null) !== $score) {
                $bad[] = "base {$id}, #{$pid}: old ".json_encode($old[(string) $pid] ?? null)." new {$score}";
            }
        }
        foreach (array_keys($old) as $pid) {
            $pid = (int) $pid;
            if (isset($php[$id][$pid])) {
                continue;
            }
            $p = $byId[$pid] ?? null;
            $explained = $p === null || $p['family'] !== $base['family'] || ! $p['inStock']
                || ! ($same($p['brands'], $base['brands']) || $same($p['subTypes'], $base['subTypes']) || $same($p['categories'], $base['categories']));
            if (! $explained) {
                $bad[] = "base {$id}: #{$pid} kept by the old rule and dropped for no decided reason";
            }
        }
    }

    expect($bad)->toBe([])->and($compared)->toBeGreaterThan(100);
})->skip(fn () => relatedNode() === '', 'node is not installed here');

it('on the real catalogue: a Tommy Hilfiger men\'s watch gets Tommy non-watch add-ons first; a Rolex gets no watch at all', function () {
    $entries = app(CompatServices::class)->listing->entries();
    $byId = [];
    foreach ($entries as $e) {
        $byId[$e['id']] = $e;
    }
    $brand = fn (string $name): int => T::int(DB::table('catalog_brand_translations')->where('locale', 'en')->where('name', $name)->value('brand_id'));
    $pick = function (int $brandId) use ($entries): ?array {
        foreach ($entries as $e) {
            if ($e['brands'] === $brandId && $e['family'] === 'watch' && $e['inStock'] && in_array('Men', $e['genders'], true)) {
                return $e;
            }
        }

        return null;
    };

    $tommy = T::arr($pick($brand('Tommy Hilfiger')));
    $addons = CompatRelated::rankAddOns($entries, [T::int($tommy['id'])], []);
    expect($addons)->not->toBeEmpty();
    foreach ($addons as $i => $id) {
        expect($byId[$id]['family'])->not->toBe('watch')
            ->and($byId[$id]['inStock'])->toBeTrue()
            ->and(array_intersect(['Men', 'Unisex'], $byId[$id]['genders']))->not->toBeEmpty();
    }
    expect($byId[$addons[0]]['brands'])->toBe($tommy['brands']);                       // tier 1 leads

    $rolex = T::arr($pick($brand('Rolex')));
    foreach (CompatRelated::rankAddOns($entries, [T::int($rolex['id'])], []) as $id) {
        expect($byId[$id]['family'])->not->toBe('watch')->and($byId[$id]['brands'])->not->toBe($rolex['brands']);
    }
});

it('answers GET catalog/related for the cart, the product page\'s add-ons, and its similar styles', function () {
    $entries = app(CompatServices::class)->listing->entries();
    $id = $entries[0]['id'];

    $cart = T::arr(relatedGet('/api/catalog/related?cart='.$id.','.$id));
    $addons = T::arr(relatedGet('/api/catalog/related?product='.$id.'&kind=addons'));
    $similar = T::arr(relatedGet('/api/catalog/related?product='.$id.'&kind=similar&exclude='.$id));

    expect(array_keys($cart))->toBe(['products', 'ratings', 'images'])
        ->and(count(T::arr($addons['products'])))->toBeLessThanOrEqual(CompatRelated::ADDON_LIMIT)
        ->and(count(T::arr($similar['products'])))->toBeLessThanOrEqual(CompatRelated::LIMIT)
        ->and(array_column(T::arr($similar['products']), 'id'))->not->toContain($id);
    withHeaders(['Api-Code' => RELATED_API_KEY])->getJson('/api/catalog/related')->assertStatus(422);
    withHeaders(['Api-Code' => RELATED_API_KEY])->getJson('/api/catalog/related?product=1&kind=nope')->assertStatus(422);
});
