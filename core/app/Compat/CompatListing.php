<?php

namespace App\Compat;

use App\Storefront\StorefrontCache;

/**
 * The storefront's listing, searched, filtered, sorted, paged and COUNTED on the server (C-1
 * stage 3, 2026-09-27) — what the browser used to do over the whole downloaded catalogue.
 *
 * ── The contract is the storefront's previous behaviour, bug for bug ─────────────────────────
 *
 * Every rule below reproduces the client code it replaces: `filterPredicate.js` (the predicate),
 * `ListingClient.jsx` (search, sorts, paging), `SideBar.jsx` + `SmartSuggestions.jsx` (the facet
 * counts) and `transformProduct.js` (the fields they read, and the default order). The parity test
 * (tests/Feature/Compat/CatalogListingTest.php) runs that JavaScript in node over the same
 * `all_product` rows and compares. Oddities kept on purpose, because changing them is a product
 * decision and not a port:
 *
 *  - a product with no sale price is priced 0 by the filter and the price sorts (JS `null < n`);
 *  - search matches the title, short description and brand in the shopper's language only (no
 *    English fallback), plus the search keywords, by substring;
 *  - facet counts ignore the search text (the sidebar never applied it).
 *
 * The rows it returns for a page are the RAW `all_product` rows, with their ratings and gallery
 * images, so the storefront runs its own card transform on 24 rows instead of the whole catalogue.
 *
 * @phpstan-type Entry array{id: int, brands: ?int, categories: ?int, subTypes: ?int, grades: ?int, materials: ?int, movements: ?int, shapes: ?int, displayTypes: ?int, genders: list<string>, dialColors: list<int>, bandColors: list<int>, pct: float, price: float, rating: ?float, text: array{en: list<string>, ar: list<string>}, keywords: ?string}
 * @phpstan-type Filters array{brands: list<int>, categories: list<int>, subTypes: list<int>, genders: list<string>, offers: bool, price: array{0: float, 1: float}, dialColors: list<int>, bandColors: list<int>, materials: list<int>, movements: list<int>, shapes: list<int>, displayTypes: list<int>, grades: list<int>}
 * @phpstan-type Cards array{products: list<array<array-key, mixed>>, ratings: list<array<array-key, mixed>>, images: list<array<array-key, mixed>>}
 */
final class CompatListing
{
    /** Filter keys, in `passesFilters` order. */
    public const FILTERS = ['brands', 'categories', 'subTypes', 'genders', 'offers', 'price', 'dialColors', 'bandColors', 'materials', 'movements', 'shapes', 'displayTypes', 'grades'];

    /** The facet sections the sidebar and the chip strip count. */
    public const FACETS = ['brands', 'categories', 'subTypes', 'genders', 'dialColors', 'bandColors', 'materials', 'movements', 'shapes', 'displayTypes', 'grades'];

    public const SORTS = ['default', 'price_asc', 'price_desc', 'newest', 'rating'];

    /** `ListingClient`'s PRICE_MAX: a max at or above it means "no upper bound". */
    public const PRICE_MAX = 99999999;

    public function __construct(
        private readonly StorefrontCache $cache,
        private readonly CompatCatalog $catalog,
        private readonly CompatMeta $meta,
        private readonly int $storefrontId,
    ) {}

    /**
     * @param  Filters  $filters
     * @return array{total: int, page: int, per_page: int, products: list<array<array-key, mixed>>, ratings: list<array<array-key, mixed>>, images: list<array<array-key, mixed>>, facets: array<string, array<string, int>>}
     */
    public function query(array $filters, string $q, string $sort, int $page, int $perPage, string $lang): array
    {
        // JS `trim()` strips Unicode white space too.
        $q = mb_strtolower((string) preg_replace('/^\s+|\s+$/u', '', $q));

        $hits = [];
        /** @var array<string, array<string, int>> $facets */
        $facets = array_fill_keys(self::FACETS, []);
        foreach ($this->index() as $p) {
            $failed = self::failedFilters($p, $filters);
            if (count($failed) > 1) {
                continue;
            }
            // Facet counts: a product that fails no filter counts in every section; one that fails
            // exactly one counts only in that section — "as if that section were cleared".
            foreach (self::FACETS as $section) {
                if ($failed !== [] && $failed[0] !== $section) {
                    continue;
                }
                foreach (self::values($p, $section) as $v) {
                    $facets[$section][$v] = ($facets[$section][$v] ?? 0) + 1;
                }
            }
            if ($failed === [] && ($q === '' || self::matches($p, $q, $lang === 'ar' ? 'ar' : 'en'))) {
                $hits[] = $p;
            }
        }

        $hits = self::sorted($hits, $sort);
        $page = max(1, $page);
        $ids = array_map(fn (array $p): int => $p['id'], array_slice($hits, ($page - 1) * $perPage, $perPage));
        $cards = $this->cards($ids);

        return [
            'total' => count($hits),
            'page' => $page,
            'per_page' => $perPage,
            'products' => array_map(self::cardRow(...), $cards['products']),
            'ratings' => $cards['ratings'],
            'images' => $cards['images'],
            'facets' => $facets,
        ];
    }

    /**
     * Raw `all_product` rows for these ids (in this order), with their ratings and gallery images —
     * what the storefront's card transform needs, for a handful of products.
     *
     * @param  list<int>  $ids
     * @return Cards
     */
    public function cards(array $ids): array
    {
        if ($ids === []) {
            return ['products' => [], 'ratings' => [], 'images' => []];
        }
        $wanted = array_flip($ids);
        $byId = [];
        foreach ($this->catalog->allProduct(config()->string('compat.pinned_locale')) as $row) {
            if (is_int($row['id'] ?? null) && isset($wanted[$row['id']])) {
                $byId[$row['id']] = $row;
            }
        }
        $products = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $products[] = $byId[$id];
            }
        }

        return [
            'products' => $products,
            'ratings' => self::forProducts($this->catalog->allProductRating(), $byId),
            'images' => self::forProducts($this->catalog->allProductImage(), $byId),
        ];
    }

    /**
     * A listing row trimmed to what a product CARD reads (the storefront's `catalogProjection`,
     * server-side): no long or short descriptions, and empty feature / gender / colour relations —
     * a card shows none of them, and they were ~80% of each row. The storefront's transform maps
     * those relations, so they stay present as empty lists rather than disappearing. `catalog/cards`
     * keeps FULL rows.
     *
     * @param  array<array-key, mixed>  $row
     * @return array<array-key, mixed>
     */
    private static function cardRow(array $row): array
    {
        unset($row['long_description'], $row['short_description']);
        foreach (['feature', 'gender', 'dial_color', 'band_color'] as $relation) {
            $row[$relation] = [];
        }
        $translations = [];
        foreach (is_array($row['translations'] ?? null) ? $row['translations'] : [] as $t) {
            if (is_array($t)) {
                $translations[] = ['locale' => $t['locale'] ?? null, 'product_title' => $t['product_title'] ?? null];
            }
        }
        $row['translations'] = $translations;

        return $row;
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @param  array<int, mixed>  $byId
     * @return list<array<array-key, mixed>>
     */
    private static function forProducts(array $rows, array $byId): array
    {
        $out = [];
        foreach ($rows as $r) {
            if (is_array($r) && is_int($r['product_id'] ?? null) && isset($byId[$r['product_id']])) {
                $out[] = $r;
            }
        }

        return $out;
    }

    /**
     * The values of one facet section on this entry, as count keys (a product counts once per value).
     *
     * @param  Entry  $p
     * @return list<string>
     */
    private static function values(array $p, string $section): array
    {
        $raw = match ($section) {
            'genders' => $p['genders'],
            'dialColors' => $p['dialColors'],
            'bandColors' => $p['bandColors'],
            'brands' => [$p['brands']],
            'categories' => [$p['categories']],
            'subTypes' => [$p['subTypes']],
            'materials' => [$p['materials']],
            'movements' => [$p['movements']],
            'shapes' => [$p['shapes']],
            'displayTypes' => [$p['displayTypes']],
            'grades' => [$p['grades']],
            default => [],
        };
        $out = [];
        foreach ($raw as $v) {
            if ($v !== null) {
                $out[(string) $v] = true;
            }
        }

        return array_map('strval', array_keys($out));
    }

    /**
     * The filters this entry fails, in `passesFilters` order (only the first two matter).
     *
     * @param  Entry  $p
     * @param  Filters  $f
     * @return list<string>
     */
    private static function failedFilters(array $p, array $f): array
    {
        $failed = [];
        foreach (self::FILTERS as $key) {
            if (! self::passes($key, $p, $f)) {
                $failed[] = $key;
                if (count($failed) > 1) {
                    break;
                }
            }
        }

        return $failed;
    }

    /**
     * One filter of `passesFilters`.
     *
     * @param  Entry  $p
     * @param  Filters  $f
     */
    private static function passes(string $key, array $p, array $f): bool
    {
        return match ($key) {
            'genders' => $f['genders'] === [] || array_intersect($f['genders'], $p['genders']) !== [],
            'offers' => ! $f['offers'] || $p['pct'] > 0,
            'price' => self::passesPrice($p['price'], $f['price']),
            'dialColors' => $f['dialColors'] === [] || array_intersect($f['dialColors'], $p['dialColors']) !== [],
            'bandColors' => $f['bandColors'] === [] || array_intersect($f['bandColors'], $p['bandColors']) !== [],
            'brands' => $f['brands'] === [] || in_array($p['brands'], $f['brands'], true),
            'categories' => $f['categories'] === [] || in_array($p['categories'], $f['categories'], true),
            'subTypes' => $f['subTypes'] === [] || in_array($p['subTypes'], $f['subTypes'], true),
            'materials' => $f['materials'] === [] || in_array($p['materials'], $f['materials'], true),
            'movements' => $f['movements'] === [] || in_array($p['movements'], $f['movements'], true),
            'shapes' => $f['shapes'] === [] || in_array($p['shapes'], $f['shapes'], true),
            'displayTypes' => $f['displayTypes'] === [] || in_array($p['displayTypes'], $f['displayTypes'], true),
            'grades' => $f['grades'] === [] || in_array($p['grades'], $f['grades'], true),
            default => true,
        };
    }

    /** @param  array{0: float, 1: float}  $range */
    private static function passesPrice(float $price, array $range): bool
    {
        [$min, $max] = $range;
        if ($min > 0 && $price < $min) {
            return false;
        }

        return ! ($max < self::PRICE_MAX && $price > $max);
    }

    /**
     * @param  Entry  $p
     * @param  'en'|'ar'  $lang
     */
    private static function matches(array $p, string $q, string $lang): bool
    {
        foreach ([...$p['text'][$lang], $p['keywords']] as $text) {
            if ($text !== null && str_contains($text, $q)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `ListingClient`'s sorts. "default" is the index order (`transformProduct`'s). All stable.
     *
     * @param  list<Entry>  $rows
     * @return list<Entry>
     */
    private static function sorted(array $rows, string $sort): array
    {
        match ($sort) {
            'price_asc' => usort($rows, fn (array $a, array $b): int => $a['price'] <=> $b['price']),
            'price_desc' => usort($rows, fn (array $a, array $b): int => $b['price'] <=> $a['price']),
            'newest' => usort($rows, fn (array $a, array $b): int => $b['id'] <=> $a['id']),
            'rating' => usort($rows, fn (array $a, array $b): int => ($b['rating'] ?? 0.0) <=> ($a['rating'] ?? 0.0)),
            default => null,
        };

        return $rows;
    }

    /**
     * The listing index: one small entry per product with only what the filters, the search and the
     * sorts read, in the storefront's default order. Cached like the rows it is derived from.
     *
     * @return list<Entry>
     */
    private function index(): array
    {
        $ttl = config()->integer('compat.ttl.all_product');

        /** @var list<Entry> */
        return $this->cache->remember($this->storefrontId, 'compat_listing', '', $ttl, fn () => $this->buildIndex());
    }

    /** @return list<Entry> */
    private function buildIndex(): array
    {
        $brandNames = [];
        $meta = $this->meta->build(config()->string('compat.pinned_locale'));
        $tables = is_array($meta['tables'] ?? null) ? $meta['tables'] : [];
        foreach (is_array($tables['brands'] ?? null) ? $tables['brands'] : [] as $brand) {
            if (is_array($brand) && is_int($brand['id'] ?? null)) {
                $brandNames[$brand['id']] = self::translated($brand['translations'] ?? null, 'brand_name');
            }
        }

        $ratings = [];
        foreach ($this->catalog->allProductRating() as $r) {
            if (is_int($r['product_id'] ?? null) && is_int($r['rating'] ?? null)) {
                $ratings[$r['product_id']][] = $r['rating'];
            }
        }

        $entries = [];
        foreach ($this->catalog->allProduct(config()->string('compat.pinned_locale')) as $row) {
            if (! is_int($row['id'] ?? null)) {
                continue;
            }
            $id = $row['id'];
            $title = self::translated($row['translations'] ?? null, 'product_title');
            $short = self::translated($row['translations'] ?? null, 'short_description');
            $brand = is_int($row['brand_id'] ?? null) && isset($brandNames[$row['brand_id']]) ? $brandNames[$row['brand_id']] : ['en' => null, 'ar' => null];
            $text = ['en' => [], 'ar' => []];
            foreach (['en', 'ar'] as $locale) {
                foreach ([$title[$locale], $short[$locale], $brand[$locale]] as $s) {
                    if ($s !== null) {
                        $text[$locale][] = mb_strtolower($s);
                    }
                }
            }
            $sale = $row['sale_price_after_discount'] ?? null;

            $entries[] = [
                'entry' => [
                    'id' => $id,
                    'brands' => self::nint($row['brand_id'] ?? null),
                    'categories' => self::nint($row['category_type_id'] ?? null),
                    'subTypes' => self::nint($row['sub_type_id'] ?? null),
                    'grades' => self::nint($row['grade_id'] ?? null),
                    'materials' => self::nint($row['band_material_id'] ?? null),
                    'movements' => self::nint($row['watch_movement_id'] ?? null),
                    'shapes' => self::nint($row['case_shape_id'] ?? null),
                    'displayTypes' => self::nint($row['dial_display_type_id'] ?? null),
                    'genders' => self::relationNames($row['gender'] ?? null, 'gender_name'),
                    'dialColors' => self::relationIds($row['dial_color'] ?? null),
                    'bandColors' => self::relationIds($row['band_color'] ?? null),
                    // JS `Number(x) > 0`: null and '' are 0; a non-numeric string is NaN, never > 0.
                    'pct' => is_numeric($row['percentage_discount'] ?? null) ? (float) $row['percentage_discount'] : 0.0,
                    // JS coercion in `<` / `-`: null is 0; a non-numeric string is NaN (every comparison false).
                    'price' => $sale === null ? 0.0 : (is_numeric($sale) ? (float) $sale : NAN),
                    'rating' => isset($ratings[$id]) ? array_sum($ratings[$id]) / count($ratings[$id]) : null,
                    'text' => $text,
                    'keywords' => is_string($row['search_keywords'] ?? null) ? mb_strtolower($row['search_keywords']) : null,
                ],
                'out' => ($row['market_stock'] ?? 0) === 0 ? 1 : 0,
                'created' => self::millis(is_string($row['created_at'] ?? null) ? $row['created_at'] : null),
            ];
        }

        // transformProduct's order: market stock 0 last, then newest created first; stable.
        usort($entries, fn (array $a, array $b): int => [$a['out'], $b['created']] <=> [$b['out'], $a['created']]);

        return array_map(fn (array $e): array => $e['entry'], $entries);
    }

    /** @return array{en: ?string, ar: ?string} the field per locale; null when missing or empty (JS `getTranslatedName`) */
    private static function translated(mixed $translations, string $field): array
    {
        $out = ['en' => null, 'ar' => null];
        $seen = [];
        foreach (is_array($translations) ? $translations : [] as $t) {
            // JS `find()`: the FIRST row of a locale decides, even when its field is empty.
            if (! is_array($t) || ! in_array($t['locale'] ?? null, ['en', 'ar'], true) || isset($seen[$t['locale']])) {
                continue;
            }
            $locale = $t['locale'] === 'ar' ? 'ar' : 'en';
            $seen[$locale] = true;
            $v = $t[$field] ?? null;
            $out[$locale] = is_string($v) && $v !== '' ? $v : null;
        }

        return $out;
    }

    /** @return list<string> the English names of a relation (JS `genders_en`) */
    private static function relationNames(mixed $rows, string $field): array
    {
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            $name = is_array($r) ? self::translated($r['translations'] ?? null, $field)['en'] : null;
            if ($name !== null) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /** @return list<int> */
    private static function relationIds(mixed $rows): array
    {
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            if (is_array($r) && is_int($r['id'] ?? null)) {
                $out[] = $r['id'];
            }
        }

        return $out;
    }

    /** JS `new Date(s).getTime()`: milliseconds; a missing or unreadable date is the epoch. */
    private static function millis(?string $s): int
    {
        if ($s === null) {
            return 0;
        }
        try {
            return (int) (new \DateTimeImmutable($s))->format('Uv');
        } catch (\Exception) {
            return 0;
        }
    }

    private static function nint(mixed $v): ?int
    {
        return is_int($v) ? $v : null;
    }
}
