<?php

namespace App\Compat;

use App\Domain\Catalog\MetaText;
use App\Storefront\StorefrontCache;
use App\Support\LegacySlug;
use Illuminate\Support\Facades\DB;

/**
 * The storefront's listing, searched, filtered, sorted, paged and COUNTED on the server (C-1
 * stage 3, 2026-09-27) — what the browser used to do over the whole downloaded catalogue.
 *
 * ── The contract ─────────────────────────────────────────────────────────────────────────────
 *
 * It reproduces the client code it replaced — `filterPredicate.js` (the predicate),
 * `ListingClient.jsx` (sorts, paging), `SideBar.jsx` + `SmartSuggestions.jsx` (the facet counts),
 * `transformProduct.js` (the fields, the default order) — EXCEPT three inherited bugs, fixed on
 * purpose by the developer's decision (2026-09-27). The parity test
 * (tests/Feature/Compat/CatalogListingTest.php) runs that JavaScript in node over the same rows
 * with the three new rules written into its reference:
 *
 *  - PRICE is what the shopper pays: the sale price only when 0 < sale < list, else the list price
 *    (`CompatCart::catalogPrice`, the card's own rule). It used to read a blank sale price as 0, so
 *    a product without a discount vanished from every price range and sorted first.
 *  - FACET COUNTS follow the search: a product the search does not match counts nowhere. They used
 *    to count the whole catalogue while the grid showed the search results.
 *  - SEARCH matches the title, brand and short description in BOTH languages plus the keywords,
 *    with Arabic spelling folded (أ/إ/آ→ا, ة→ه, ى→ي, no tashkeel/tatweel, Arabic digits). Only when
 *    that finds nothing anywhere, one typo is tolerated per word of 4+ letters (2 for 8+, a swap of
 *    two neighbours counts as one) against the words of the titles and brand names.
 *
 * The rows it returns for a page are the RAW `all_product` rows, with their ratings and gallery
 * images, so the storefront runs its own card transform on 24 rows instead of the whole catalogue.
 *
 * @phpstan-type Entry array{id: int, brands: ?int, categories: ?int, subTypes: ?int, grades: ?int, materials: ?int, movements: ?int, shapes: ?int, displayTypes: ?int, genders: list<string>, dialColors: list<int>, bandColors: list<int>, pct: float, price: float, sellingRaw: ?string, saleRaw: ?string, rating: ?float, search: list<string>, words: list<string>, slug: string, family: ?string, inStock: bool, created: int}
 * @phpstan-type Index array{entries: list<Entry>, vocab: array<string, list<int>>}
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

    /** The listing index's shape version — its cache-key suffix (see `index()`). */
    public const INDEX_SHAPE = 's4';

    /** How many products' full rows the warm-up holds at once when writing card entries (L8). */
    private const WARM_CHUNK = 500;

    /** `ListingClient`'s PRICE_MAX: a max at or above it means "no upper bound". */
    public const PRICE_MAX = 99999999;

    /**
     * Where the last query() spent its time, in ms — sent as a `Server-Timing` header so a slow tap
     * can be taken apart from the browser (2026-09-27): `index` (and whether it had to be BUILT, i.e.
     * the cache was cold), `filter` (search, filters, facets, sort), `cards` (the page's rows).
     *
     * @var array{index: float, cold: bool, filter: float, cards: float}
     */
    public array $timing = ['index' => 0.0, 'cold' => false, 'filter' => 0.0, 'cards' => 0.0];

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
    public function query(array $filters, string $q, string $sort, int $page, int $perPage): array
    {
        $t0 = hrtime(true);
        $this->timing['cold'] = false;
        $index = $this->index();
        $t1 = hrtime(true);
        $q = self::fold($q);
        // The products the search matches — decided once, for the whole catalogue, BEFORE the
        // filters: a near miss is only tried when the exact search finds nothing anywhere, so a
        // query with real matches never picks up fuzzy extras.
        $matched = $q === '' ? null : self::searchMatches($index, $q);

        $hits = [];
        /** @var array<string, array<string, int>> $facets */
        $facets = array_fill_keys(self::FACETS, []);
        foreach ($index['entries'] as $i => $p) {
            // A product the search does not match counts nowhere — not in the grid, not in a facet.
            if ($matched !== null && ! isset($matched[$i])) {
                continue;
            }
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
            if ($failed === []) {
                $hits[] = $p;
            }
        }

        $hits = self::sorted($hits, $sort);
        $page = max(1, $page);
        $ids = array_map(fn (array $p): int => $p['id'], array_slice($hits, ($page - 1) * $perPage, $perPage));
        $t2 = hrtime(true);
        $cards = $this->cards($ids);
        $this->timing['index'] = ($t1 - $t0) / 1e6;
        $this->timing['filter'] = ($t2 - $t1) / 1e6;
        $this->timing['cards'] = (hrtime(true) - $t2) / 1e6;

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
     * Each product's row and gallery are cached ON THEIR OWN (`compat_card`, 2026-09-28), so a page
     * reads its 24 entries. It used to read the whole cached catalogue to pick them: 40–67 ms warm,
     * ~400 ms when that cache had just expired. `warm()` writes every entry; one that is missing is
     * read live for just that product and stored. Ratings are always read live (no event forgets
     * them). An id the catalogue no longer shows (hidden, deleted since the index was built) is
     * left out.
     *
     * @param  list<int>  $ids
     * @return Cards
     */
    public function cards(array $ids): array
    {
        if ($ids === []) {
            return ['products' => [], 'ratings' => [], 'images' => []];
        }
        $locale = config()->string('compat.pinned_locale');
        $suffix = fn (int $id): string => "{$locale}:{$id}";
        $cached = $this->cache->many($this->storefrontId, 'compat_card', array_map($suffix, $ids));

        /** @var array<int, array{row: array<string, mixed>, images: list<array<string, mixed>>}> $entries */
        $entries = [];
        $missing = [];
        foreach ($ids as $id) {
            $hit = $cached[$suffix($id)] ?? null;
            if (is_array($hit) && is_array($hit['row'] ?? null) && is_array($hit['images'] ?? null)) {
                /** @var array{row: array<string, mixed>, images: list<array<string, mixed>>} $hit */
                $entries[$id] = $hit;
            } else {
                $missing[] = $id;
            }
        }
        if ($missing !== []) {
            $built = self::cardEntries($this->catalog->productRows($locale, $missing), $this->catalog->productImages($missing));
            $store = [];
            foreach ($built as $id => $entry) {
                $entries[$id] = $entry;
                $store[$suffix($id)] = $entry;
            }
            $this->cache->putMany($this->storefrontId, 'compat_card', $store, config()->integer('compat.ttl.all_product'));
        }

        $products = [];
        $images = [];
        foreach ($ids as $id) {
            if (isset($entries[$id])) {
                $products[] = $entries[$id]['row'];
                array_push($images, ...$entries[$id]['images']);
            }
        }
        // The catalogue-wide order the storefront has always received: gallery rows by their id.
        usort($images, fn (array $a, array $b): int => ($a['id'] ?? 0) <=> ($b['id'] ?? 0));
        $byId = array_fill_keys(array_keys($entries), true);

        return [
            'products' => $products,
            'ratings' => self::forProducts($this->catalog->productRatings(array_keys($entries)), $byId),
            'images' => $images,
        ];
    }

    /**
     * Rows and gallery images grouped into one `compat_card` entry per product.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $images
     * @return array<int, array{row: array<string, mixed>, images: list<array<string, mixed>>}>
     */
    private static function cardEntries(array $rows, array $images): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (is_int($row['id'] ?? null)) {
                $out[$row['id']] = ['row' => $row, 'images' => []];
            }
        }
        foreach ($images as $image) {
            $pid = $image['product_id'] ?? null;
            if (is_int($pid) && isset($out[$pid])) {
                $out[$pid]['images'][] = $image;
            }
        }

        return $out;
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
     * The index positions of the entries the search matches: every entry whose folded text contains
     * the folded query; if there is none in the whole catalogue, the near-miss fallback.
     *
     * @param  Index  $index
     * @return array<int, true>
     */
    private static function searchMatches(array $index, string $q): array
    {
        $exact = [];
        foreach ($index['entries'] as $i => $p) {
            foreach ($p['search'] as $text) {
                if (str_contains($text, $q)) {
                    $exact[$i] = true;
                    break;
                }
            }
        }

        return $exact !== [] ? $exact : self::nearMisses($index, $q);
    }

    /**
     * One typo per query word: a word of 4+ letters may be one edit away (2 for 8+ letters) from a
     * word of a title or a brand name, where swapping two neighbouring letters is one edit. Shorter
     * words must appear exactly. An entry matches when EVERY query word does.
     *
     * @param  Index  $index
     * @return array<int, true>
     */
    private static function nearMisses(array $index, string $q): array
    {
        $tokens = self::words($q);
        if ($tokens === []) {
            return [];
        }
        $result = null;
        foreach ($tokens as $token) {
            $len = mb_strlen($token);
            $max = $len >= 8 ? 2 : ($len >= 4 ? 1 : 0);
            $hit = [];
            foreach ($index['entries'] as $i => $p) {
                foreach ($p['search'] as $text) {
                    if (str_contains($text, $token)) {
                        $hit[$i] = true;
                        break;
                    }
                }
            }
            if ($max > 0) {
                foreach ($index['vocab'] as $word => $positions) {
                    if (abs(mb_strlen((string) $word) - $len) <= $max && self::editDistance($token, (string) $word, $max) <= $max) {
                        foreach ($positions as $i) {
                            $hit[$i] = true;
                        }
                    }
                }
            }
            $result = $result === null ? $hit : array_intersect_key($result, $hit);
            if ($result === []) {
                return [];
            }
        }

        return $result;
    }

    /**
     * Optimal string alignment distance (Levenshtein + adjacent transposition), on characters not
     * bytes (Arabic is two bytes a letter). Stops early once every cell of a row exceeds `$cap`.
     */
    private static function editDistance(string $a, string $b, int $cap): int
    {
        $x = mb_str_split($a);
        $y = mb_str_split($b);
        $n = count($x);
        $m = count($y);
        $prev2 = [];
        $prev = range(0, $m);
        for ($i = 1; $i <= $n; $i++) {
            $cur = [$i];
            $best = $i;
            for ($j = 1; $j <= $m; $j++) {
                $cost = $x[$i - 1] === $y[$j - 1] ? 0 : 1;
                $v = min($prev[$j] + 1, $cur[$j - 1] + 1, $prev[$j - 1] + $cost);
                if ($i > 1 && $j > 1 && $x[$i - 1] === $y[$j - 2] && $x[$i - 2] === $y[$j - 1]) {
                    $v = min($v, $prev2[$j - 2] + 1);
                }
                $cur[$j] = $v;
                $best = min($best, $v);
            }
            if ($best > $cap) {
                return $cap + 1;
            }
            $prev2 = $prev;
            $prev = $cur;
        }

        return $prev[$m];
    }

    /**
     * Lower case, trimmed, and Arabic spelling folded: hamza forms of alef → ا, ة → ه, ى → ي, no
     * tashkeel or tatweel, Arabic-Indic digits → 0-9. Applied to the query AND to the text.
     */
    public static function fold(string $s): string
    {
        $s = mb_strtolower((string) preg_replace('/^\s+|\s+$/u', '', $s));
        $s = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s);

        return strtr($s, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', // i18n-exempt: matching data, never rendered — a character-folding table compares text, it shows none (proven by CatalogSearchTest "folds Arabic spelling on both sides")
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', // i18n-exempt: matching data, never rendered — digit folding for search comparison, shows nothing
        ]);
    }

    /** @return list<string> the letter/digit runs of an already folded string */
    private static function words(string $s): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', $s, $m);

        return array_values(array_unique($m[0]));
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
     * Every visible product's index entry, in the storefront's catalogue order (market stock 0 last,
     * then newest first) — what the related-products rules score (C-1 stage 4, `CompatRelated`).
     *
     * @return list<Entry>
     */
    public function entries(): array
    {
        return $this->index()['entries'];
    }

    /**
     * A product's meta title and description per language, cleaned (S-AR stage 2) — for the product
     * page's <title> and description. Null where the team wrote none or the title is cut off; the
     * storefront falls back.
     *
     * @return array{title: array{en: ?string, ar: ?string}, description: array{en: ?string, ar: ?string}}
     */
    public function productMeta(int $id): array
    {
        $out = ['title' => ['en' => null, 'ar' => null], 'description' => ['en' => null, 'ar' => null]];
        foreach (DB::table('catalog_product_translations')->where('product_id', $id)->whereIn('locale', ['en', 'ar'])
            ->get(['locale', 'meta_title', 'meta_description']) as $row) {
            $locale = $row->locale === 'ar' ? 'ar' : 'en';
            $out['title'][$locale] = MetaText::usableTitle(is_string($row->meta_title) ? $row->meta_title : null);
            $out['description'][$locale] = MetaText::description(is_string($row->meta_description) ? $row->meta_description : null);
        }

        return $out;
    }

    /**
     * The product a product-page URL names — the storefront's rule (`findProductInCatalog`): digits
     * are an id; anything else is the slug of the English title, and the FIRST product in catalogue
     * order with that slug wins (twins share a URL). Null when nothing visible matches.
     */
    public function idForParam(string $param): ?int
    {
        $param = trim($param);
        if ($param === '') {
            return null;
        }
        $isId = preg_match('/^\d+$/', $param) === 1;
        foreach ($this->entries() as $e) {
            if ($isId ? $e['id'] === (int) $param : $e['slug'] === $param) {
                return $e['id'];
            }
        }

        return null;
    }

    /**
     * The listing index: one small entry per product with only what the filters, the search and the
     * sorts read, in the storefront's default order. Cached like the rows it is derived from.
     *
     * Plus the search vocabulary: every word of 4+ letters in a title or brand name (both languages,
     * folded) → the entries it appears in, for the near-miss fallback.
     *
     * @return Index
     */
    private function index(): array
    {
        $ttl = config()->integer('compat.ttl.all_product');

        /** @var Index */
        // The key's suffix is the index's SHAPE: bump it whenever an entry gains or loses a field, so an
        // index cached by the previous deploy is never read by code that expects the new fields
        // (C-1 stage 4 added slug / family / inStock / created).
        return $this->cache->remember($this->storefrontId, 'compat_listing', self::INDEX_SHAPE, $ttl, function (): array {
            $this->timing['cold'] = true;

            return $this->buildIndex();
        });
    }

    /**
     * Rebuild the index NOW, with the catalogue under it, and store both (2026-09-28). Run by
     * `catalog:warm` on the schedule, so the index is replaced before it expires, and right after a
     * dashboard write has made it stale — so neither the TTL nor the write leaves the ~1 s rebuild
     * to the next shopper's tap.
     */
    public function warm(): void
    {
        $locale = config()->string('compat.pinned_locale');
        $ttl = config()->integer('compat.ttl.all_product');
        // The version is read before anything is built (see StorefrontCache::refresh): a write that
        // lands meanwhile leaves these entries under the old version, which nothing reads.
        $version = $this->cache->version($this->storefrontId);
        $index = $this->cache->refresh($this->storefrontId, 'compat_listing', self::INDEX_SHAPE, $ttl, fn (): array => $this->buildIndex());

        // Card entries built a CHUNK of products at a time (L8, 2026-10-06). Reading every product's
        // full `all_product` row at once is the memory the warm-up could not afford on Brand Fashion
        // (§3.2: 55 MB held, 136 MB peak, over PHP's 128 MB); a chunk of 500 holds a fraction of that
        // and is freed before the next. The whole-catalogue `compat_all_product` cache is no longer
        // written at all — nothing on the request path reads it (the index and nav are built leanly,
        // and a listing page reads its 24 card files). The ids come from the index just built, in its
        // order; `productRows()` reads each chunk live through the same builder `cards()` uses.
        //
        // The built index itself is sizeable for a large catalogue (7,579 entries carry search text
        // and vocabulary), and it is NOT needed while the cards are written — only its ids are. So it
        // is dropped here, before the loop, or its ~tens of MB sit alongside each chunk and the
        // serialize buffer and push Brand Fashion's warm-up back over 128 MB (measured 2026-10-06).
        $ids = array_map(fn (array $entry): int => $entry['id'], $index['entries']);
        unset($index);
        foreach (array_chunk($ids, self::WARM_CHUNK) as $chunk) {
            $store = [];
            foreach (self::cardEntries($this->catalog->productRows($locale, $chunk), $this->catalog->productImages($chunk)) as $id => $entry) {
                $store["{$locale}:{$id}"] = $entry;
            }
            $this->cache->putMany($this->storefrontId, 'compat_card', $store, $ttl, $version);
        }
    }

    /**
     * The index, built from the LEAN catalogue (L8, 2026-10-06): `CompatCatalog::leanCatalog()` reads
     * only the fields an entry uses, never the full `all_product` rows (§3). Its gender entries carry
     * both names (nav needs ar); the index needs only the English name, so reduce them here to the
     * `list<string>` `buildIndexFrom()` expects. `buildIndexFromRows()` is the pre-L8 builder, kept as
     * the parity reference; this must reproduce it exactly (CatalogL8ParityTest).
     *
     * @return Index
     */
    private function buildIndex(): array
    {
        $rows = [];
        foreach ($this->catalog->leanCatalog(config()->string('compat.pinned_locale')) as $row) {
            $row['genders'] = self::enNames($row['genders']);
            $rows[] = $row;
        }

        return $this->buildIndexFrom($rows);
    }

    /**
     * The pre-L8 index builder, reading the full legacy `all_product` rows — kept ONLY as the
     * reference `CatalogL8ParityTest` holds `buildIndex()` to. The relations are reduced to the index
     * shape (English gender names; colour ids) exactly as the index always did, then handed to the
     * shared builder. Not on any request path.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return Index
     */
    public function buildIndexFromRows(array $rows): array
    {
        $norm = [];
        foreach ($rows as $row) {
            $row['genders'] = self::relationNames($row['gender'] ?? null, 'gender_name');
            $row['dialColors'] = self::relationIds($row['dial_color'] ?? null);
            $row['bandColors'] = self::relationIds($row['band_color'] ?? null);
            $norm[] = $row;
        }

        return $this->buildIndexFrom($norm);
    }

    /**
     * The one index algorithm, over rows already reduced to the index's own shape: `genders` a
     * `list<string>` (English names), `dialColors`/`bandColors` `list<int>`, and the scalar columns
     * under their `all_product` names. Both `buildIndex()` (lean) and `buildIndexFromRows()` (the
     * reference) feed it, which is what makes the lean index provably identical.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return Index
     */
    private function buildIndexFrom(array $rows): array
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

        // Each product's family (watch, bag, wallet, …) — not part of the legacy row shape, so read here.
        $families = [];
        foreach (DB::table('catalog_products')->whereNull('deleted_at')->pluck('family', 'id') as $pid => $family) {
            if (is_string($family) && $family !== '') {
                $families[(int) $pid] = $family;
            }
        }

        $entries = [];
        foreach ($rows as $row) {
            if (! is_int($row['id'] ?? null)) {
                continue;
            }
            $id = $row['id'];
            $title = self::translated($row['translations'] ?? null, 'product_title');
            $short = self::translated($row['translations'] ?? null, 'short_description');
            $brand = is_int($row['brand_id'] ?? null) && isset($brandNames[$row['brand_id']]) ? $brandNames[$row['brand_id']] : ['en' => null, 'ar' => null];
            // What the search reads: both languages, folded; the keywords too.
            $search = [];
            foreach ([$title['en'], $title['ar'], $short['en'], $short['ar'], $brand['en'], $brand['ar'], is_string($row['search_keywords'] ?? null) ? $row['search_keywords'] : null] as $text) {
                if ($text !== null) {
                    $search[] = self::fold($text);
                }
            }
            // The near-miss vocabulary: title and brand words only (descriptions would match anything).
            $words = [];
            foreach ([$title['en'], $title['ar'], $brand['en'], $brand['ar']] as $text) {
                foreach ($text === null ? [] : self::words(self::fold($text)) as $w) {
                    if (mb_strlen($w) >= 4) {
                        $words[$w] = true;
                    }
                }
            }
            $selling = is_numeric($row['selling_price'] ?? null) ? (string) $row['selling_price'] : '0';
            $sale = is_numeric($row['sale_price_after_discount'] ?? null) ? (string) $row['sale_price_after_discount'] : null;

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
                    'genders' => is_array($row['genders'] ?? null) ? array_values(array_filter($row['genders'], 'is_string')) : [],
                    'dialColors' => is_array($row['dialColors'] ?? null) ? array_values(array_filter($row['dialColors'], 'is_int')) : [],
                    'bandColors' => is_array($row['bandColors'] ?? null) ? array_values(array_filter($row['bandColors'], 'is_int')) : [],
                    // JS `Number(x) > 0`: null and '' are 0; a non-numeric string is NaN, never > 0.
                    'pct' => is_numeric($row['percentage_discount'] ?? null) ? (float) $row['percentage_discount'] : 0.0,
                    // What the shopper pays — the card's and the checkout's rule, not a blank read as 0.
                    'price' => CompatCart::catalogPrice($selling, $sale),
                    // The raw price strings, for the related-products rules ported from the
                    // storefront (C-1 stage 4): they score on `Number(sale || selling)` and on
                    // `selling` alone — JavaScript's truthiness of a "0.00" string included.
                    'sellingRaw' => is_numeric($row['selling_price'] ?? null) ? (string) $row['selling_price'] : null,
                    'saleRaw' => is_numeric($row['sale_price_after_discount'] ?? null) ? (string) $row['sale_price_after_discount'] : null,
                    'rating' => isset($ratings[$id]) ? array_sum($ratings[$id]) / count($ratings[$id]) : null,
                    'search' => $search,
                    'words' => array_keys($words),
                    // The product page's URL slug: the storefront's toSlug() of the ENGLISH title
                    // (LegacySlug mirrors it exactly). Twins share one — the first in this order wins,
                    // as `list.find()` did in the browser (C-1 stage 4).
                    'slug' => LegacySlug::make((string) ($title['en'] ?? '')),
                    // For the suggestion rails (C-1 stage 4, developer's rule 2026-09-29): what KIND of
                    // product it is, whether it can be bought now, and how new it is (the tie-break).
                    'family' => $families[$id] ?? null,
                    'inStock' => (is_int($row['stock'] ?? null) ? $row['stock'] : 0) + (is_int($row['market_stock'] ?? null) ? $row['market_stock'] : 0) > 0,
                    'created' => self::millis(is_string($row['created_at'] ?? null) ? $row['created_at'] : null),
                ],
                'out' => ($row['market_stock'] ?? 0) === 0 ? 1 : 0,
                'created' => self::millis(is_string($row['created_at'] ?? null) ? $row['created_at'] : null),
            ];
        }

        // transformProduct's order: market stock 0 last, then newest created first; stable.
        usort($entries, fn (array $a, array $b): int => [$a['out'], $b['created']] <=> [$b['out'], $a['created']]);

        $list = array_map(fn (array $e): array => $e['entry'], $entries);
        $vocab = [];
        foreach ($list as $i => $entry) {
            foreach ($entry['words'] as $w) {
                $vocab[$w][] = $i;
            }
        }

        return ['entries' => $list, 'vocab' => $vocab];
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

    /**
     * The English gender names present on a lean row, in order — the index's `genders` shape.
     * Matches `relationNames(..., 'gender_name')`: a non-null, non-empty English name per gender
     * (`CompatCatalog::leanCatalog` already drops genders whose lookup master is missing).
     *
     * @param  list<array{en: ?string, ar: ?string}>  $genders
     * @return list<string>
     */
    private static function enNames(array $genders): array
    {
        $out = [];
        foreach ($genders as $g) {
            $en = $g['en'] ?? null;
            if (is_string($en) && $en !== '') {
                $out[] = $en;
            }
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
