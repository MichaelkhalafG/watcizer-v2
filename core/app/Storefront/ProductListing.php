<?php

namespace App\Storefront;

use App\Support\ArabicSearch;
use App\Support\Val;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * `GET /api/v2/{storefront}/products` — server-side paginated listing (CLEAN_CORE_STUDY §5.1):
 * one query over `storefront_product` with the visibility predicate first, joined to
 * `catalog_products` for the card columns, then the card follow-ups per id set; the bounded
 * COUNT(*) is cached per (storefront, filter-set) for 10 minutes.
 *
 * Filters (v1 study §3.8.3): category (slug path, subtree), brand (slug list), gender/color/
 * material/grade (id lists), price_min/max (effective price), in_stock, q (search, §5.1 L6),
 * sort (position|newest|price_asc|price_desc|rating|featured|available), page, per_page ≤ 96.
 *
 * 2026-10-17, the v2 READ half for Brand Fashion's listing (V2-MOVE-SCOPE, option C; B4-LISTING-REQUIREMENT):
 *  - `facets=1` adds `facets` to the response — per-RESULT option counts for brand, gender, colour,
 *    material and grade, each computed with every filter applied EXCEPT its own (compat's rule, "as if
 *    that section were cleared", so a shopper can switch within a section), plus `in_stock` counts and
 *    the `price` bounds of the result. Seven aggregate queries cold, cached per (storefront, filter set)
 *    under the same hash and TTL as the bounded count; opt-in, so a caller that does not ask pays nothing.
 *  - `sort=available` is spec §19-5's "available first": in-stock products first, then today's position
 *    order, with `sp.product_id` as the total tie-breaker so two pages of the same sort never overlap or
 *    skip while the data stands still (a stock change between two page loads moves exactly that product —
 *    the proof and its limit are in RUN_20261017.md). Not the default: Watchizer's v2 consumers see no
 *    change; Brand Fashion asks for it by name.
 */
final class ProductListing
{
    public const SORTS = ['position', 'newest', 'price_asc', 'price_desc', 'rating', 'featured', 'available'];

    /** The facet sections `facets=1` counts, in the order the sidebar shows them (spec §10.2). */
    public const FACETS = ['brand', 'gender', 'color', 'material', 'grade'];

    /**
     * Driving table + index per sort for the index-ordered page (review 🟠-5): storefront-wide
     * listings and large subtrees (> `storefront.listing.indexed_subtree`) use it; small subtrees
     * let the optimizer drive from the placement rows. Ordering columns follow the index (plus
     * the implicit primary key), so the plan has no temp table and no filesort at any offset.
     */
    public const PLANS = [
        'position' => 'sp:sp_list_sort_idx',
        'price_asc' => 'sp:sp_list_price_idx',
        'price_desc' => 'sp:sp_list_price_idx',
        'featured' => 'sp:sp_featured_idx',
        'newest' => 'p:cp_active_created_idx',
        'rating' => 'p:cp_rating_idx',
    ];

    public function __construct(
        private readonly StorefrontContext $ctx,
        private readonly StorefrontCache $cache,
        private readonly Lookups $lookups,
        private readonly CategoryTree $tree,
        private readonly ProductCards $cards,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated request input
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>, links: array<string, string|null>}|null null when a category filter names an unknown node
     */
    public function run(array $input, string $selfPath): ?array
    {
        $perPage = self::intIn($input['per_page'] ?? null, 1, config()->integer('storefront.listing.max_per_page'), config()->integer('storefront.listing.per_page'));
        $page = self::intIn($input['page'] ?? null, 1, 100000, 1);
        $category = self::str($input['category'] ?? null);
        $node = null;
        if ($category !== null) {
            $node = $this->tree->byPath($category);
            if ($node === null) {
                return null;
            }
            $this->ctx->tags->category($this->ctx->id(), Val::int($node, 'id'));
        }
        $sort = self::str($input['sort'] ?? null);
        $sort = in_array($sort, self::SORTS, true) ? $sort : ($node !== null ? 'position' : 'newest');

        $filters = [
            'category' => $node === null ? null : Val::str($node, 'path'),
            'brand' => self::strList($input['brand'] ?? null),
            'gender' => $this->genderIds($input['gender'] ?? null),
            'color' => self::intList($input['color'] ?? null),
            'material' => self::intList($input['material'] ?? null),
            'grade' => self::intList($input['grade'] ?? null),
            'price_min' => self::numeric($input['price_min'] ?? null),
            'price_max' => self::numeric($input['price_max'] ?? null),
            'in_stock' => self::bool($input['in_stock'] ?? null),
            'q' => self::str($input['q'] ?? null),
        ];

        $indexed = self::indexed($node);
        $query = $this->cards->base($indexed ? (self::PLANS[$sort] ?? null) : null);
        $this->applyFilters($query, $filters, $node, $indexed);
        $hash = sha1(json_encode([$filters, $this->ctx->locale], JSON_THROW_ON_ERROR));
        $cap = config()->integer('storefront.listing.count_cap');
        $total = $this->cache->remember($this->ctx->id(), 'count', $hash, config()->integer('storefront.ttl.count'), function () use ($query, $cap): int {
            $inner = (clone $query)->selectRaw('1')->limit($cap);

            return DB::query()->fromSub($inner, 'bounded')->count();
        });
        $facets = null;
        if (self::bool($input['facets'] ?? null) === true) {
            /** @var array<string, mixed> $facets */
            $facets = $this->cache->remember($this->ctx->id(), 'facets', $hash, config()->integer('storefront.ttl.count'), fn (): array => $this->facets($filters, $node, $indexed));
        }

        $this->applySort($query, $sort);
        $rows = $query->select(ProductCards::columns())->forPage($page, $perPage)->get()->all();
        $data = $this->cards->build($rows);

        $lastPage = max(1, (int) ceil(min($total, $cap) / $perPage));
        $link = function (?int $p) use ($selfPath, $input, $perPage): ?string {
            if ($p === null) {
                return null;
            }
            $q = array_filter($input, fn ($v) => $v !== null && $v !== '');
            $q['page'] = $p;
            $q['per_page'] = $perPage;

            return $selfPath.'?'.http_build_query($q);
        };

        return [
            'data' => $data,
        ] + ($facets === null ? [] : ['facets' => $facets]) + [
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => min($total, $cap),
                'total_capped' => $total >= $cap,
                'last_page' => $lastPage,
                'sort' => $sort,
                'locale' => $this->ctx->locale,
                'filters' => $filters,
                'category' => $node === null ? null : CategoryTree::ref($node),
            ],
            'links' => [
                'self' => $link($page),
                'next' => $page < $lastPage ? $link($page + 1) : null,
                'prev' => $page > 1 ? $link($page - 1) : null,
            ],
        ];
    }

    /**
     * Storefront-wide, or a subtree bigger than the threshold → index-ordered plan.
     *
     * @param  array<string, mixed>|null  $node
     */
    private static function indexed(?array $node): bool
    {
        return $node === null || Val::int($node, 'product_count') > config()->integer('storefront.listing.indexed_subtree');
    }

    /**
     * @param  array<string, mixed>  $f
     * @param  array<string, mixed>|null  $node
     */
    /**
     * `$except` names ONE filter to leave out — how a facet section counts "as if it were cleared"
     * (brand | gender | color | material | grade | price | in_stock). Category and the search always apply.
     *
     * @param  array<string, mixed>  $f
     * @param  array<string, mixed>|null  $node
     */
    private function applyFilters(Builder $query, array $f, ?array $node, bool $indexed, ?string $except = null): void
    {
        if ($node !== null) {
            $this->cards->placedIn($query, $this->tree->subtreeIds(Val::int($node, 'id')), $indexed);
        }
        /** @var list<string> $brands */
        $brands = $f['brand'];
        if ($except !== 'brand' && $brands !== []) {
            // A brand token is its slug (`rolex`) or its numeric id (`12`) — 2026-10-17, for the Brand Fashion listing
            // adapter, whose filter state is ids; an id the lookup does not know matches nothing, like an unknown slug.
            $ids = array_values(array_filter(array_map(fn (string $s) => ctype_digit($s) ? ($this->lookups->ref('brands', (int) $s) === null ? null : (int) $s) : $this->lookups->brandIdBySlug($s), $brands), fn (?int $i) => $i !== null));
            $query->whereIn('p.brand_id', $ids === [] ? [-1] : $ids);
        }
        /** @var list<int> $genders */
        $genders = $f['gender'];
        if ($except !== 'gender' && $genders !== []) {
            $query->whereIn('sp.product_id', fn (Builder $s) => $s->select('product_id')->from('catalog_product_gender')->whereIn('gender_id', $genders));
        }
        /** @var list<int> $colors */
        $colors = $f['color'];
        if ($except !== 'color' && $colors !== []) {
            $query->whereIn('sp.product_id', fn (Builder $s) => $s->select('product_id')->from('catalog_product_color')->whereIn('color_id', $colors));
        }
        /** @var list<int> $materials */
        $materials = $f['material'];
        if ($except !== 'material' && $materials !== []) {
            $query->whereIn('sp.product_id', fn (Builder $s) => $s->select('product_id')->from('catalog_product_watch_specs')
                ->where(fn (Builder $w) => $w->whereIn('band_material_id', $materials)->orWhereIn('case_material_id', $materials)));
        }
        /** @var list<int> $grades */
        $grades = $f['grade'];
        if ($except !== 'grade' && $grades !== []) {
            $query->whereIn('p.grade_id', $grades);
        }
        if ($except !== 'price' && $f['price_min'] !== null) {
            $query->where('sp.effective_price', '>=', $f['price_min']);
        }
        if ($except !== 'price' && $f['price_max'] !== null) {
            $query->where('sp.effective_price', '<=', $f['price_max']);
        }
        if ($except !== 'in_stock' && $f['in_stock'] === true) {
            $query->where('p.in_stock', 1);
        }
        $q = $f['q'];
        if (is_string($q) && trim($q) !== '') {
            $this->applySearch($query, trim($q));
        }
    }

    /**
     * §5.1 L6: word-based InnoDB FULLTEXT in boolean mode on `catalog_product_search` for the
     * request locale; queries shorter than 3 characters (innodb_ft_min_token_size) fall back to a
     * LIKE on the title (R2-16).
     */
    private function applySearch(Builder $query, string $q): void
    {
        $locale = $this->ctx->locale;

        /*
         * The CUSTOMER'S search is folded exactly as the dashboard's is (A-UX-1, 2026-09-17).
         *
         * `catalog_product_search.body` now holds one normalised spelling of Arabic, and this is the
         * other reader of that table — so it moves with the index or it stops matching. The customer
         * gains the same thing the operator does: `ساعه` finds the 4,674 watches spelled `ساعة`.
         *
         * Before the tokenising below, because folding strips tatweel and diacritics and therefore
         * changes the length the three-character floor is measured against.
         */
        $terms = preg_split('/\s+/u', ArabicSearch::normalise($q)) ?: [];
        $tokens = [];
        foreach ($terms as $term) {
            $term = (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $term);   // letters and digits only: every FTS operator and wildcard is stripped
            if (mb_strlen($term) >= 3) {
                $tokens[] = '+'.$term.'*';
            }
        }
        if ($tokens === []) {
            $query->whereIn('sp.product_id', fn (Builder $s) => $s->select('product_id')->from('catalog_product_translations')
                ->where('locale', $locale)->where('title', 'like', '%'.addcslashes($q, '%_\\').'%'));

            return;
        }
        $boolean = implode(' ', $tokens);
        $query->whereIn('sp.product_id', fn (Builder $s) => $s->select('product_id')->from('catalog_product_search')
            ->where('locale', $locale)->whereRaw('MATCH(body) AGAINST (? IN BOOLEAN MODE)', [$boolean]));
    }

    private function applySort(Builder $query, string $sort): void
    {
        // Tie-breakers are the primary key the index implicitly ends with (sp.id / p.id), so the
        // order is total AND index-served. NULL ratings sort last under DESC.
        match ($sort) {
            'position' => $query->orderBy('sp.sort_order')->orderBy('sp.product_id'),
            'price_asc' => $query->orderBy('sp.effective_price')->orderBy('sp.id'),
            'price_desc' => $query->orderByDesc('sp.effective_price')->orderByDesc('sp.id'),
            'rating' => $query->orderByDesc('p.rating_avg')->orderByDesc('p.id'),
            'featured' => $query->orderByDesc('sp.is_featured')->orderBy('sp.sort_order')->orderBy('sp.id'),
            // §19-5 "available first": in stock, then today's order, then the one total tie-breaker.
            'available' => $query->orderByDesc('p.in_stock')->orderBy('sp.sort_order')->orderBy('sp.product_id'),
            default => $query->orderByDesc('p.created_at')->orderByDesc('p.id'),
        };
    }

    /**
     * The facet block for `facets=1`: per-result option counts, each section with every filter applied
     * except its own, the `in_stock` split, and the price bounds of the result (without the price filter).
     * Seven aggregate queries; the caller caches the block under the filter hash.
     *
     * @param  array<string, mixed>  $f
     * @param  array<string, mixed>|null  $node
     * @return array<string, mixed>
     */
    private function facets(array $f, ?array $node, bool $indexed): array
    {
        $base = function (string $except) use ($f, $node, $indexed): Builder {
            $q = $this->cards->base(null);
            $this->applyFilters($q, $f, $node, $indexed, $except);

            return $q;
        };
        // the grouped column is passed as a column name (wrapped by the grammar), never as raw SQL
        $counted = fn (Builder $q, string $column): Builder => $q->selectRaw('COUNT(DISTINCT sp.product_id) as c')->addSelect($column.' as k')->groupBy($column);

        $out = [];
        $out['brand'] = $this->facetRefs('brands', $counted($base('brand'), 'p.brand_id')->get());
        $out['gender'] = $this->facetRefs('genders', $counted($base('gender')->join('catalog_product_gender as pg', 'pg.product_id', '=', 'sp.product_id'), 'pg.gender_id')->get());
        $out['color'] = $this->facetRefs('colors', $counted($base('color')->join('catalog_product_color as pc', 'pc.product_id', '=', 'sp.product_id'), 'pc.color_id')->get());
        // material: a product counts once per material whether it is its band's or its case's
        $band = $base('material')->join('catalog_product_watch_specs as ws', 'ws.product_id', '=', 'sp.product_id')->whereNotNull('ws.band_material_id')->selectRaw('sp.product_id as pid, ws.band_material_id as k');
        $case = $base('material')->join('catalog_product_watch_specs as ws', 'ws.product_id', '=', 'sp.product_id')->whereNotNull('ws.case_material_id')->selectRaw('sp.product_id as pid, ws.case_material_id as k');
        $out['material'] = $this->facetRefs('materials', DB::query()->fromSub($band->unionAll($case), 'm')->selectRaw('k, COUNT(DISTINCT pid) as c')->groupBy('k')->get());
        $out['grade'] = $this->facetRefs('grades', $counted($base('grade')->whereNotNull('p.grade_id'), 'p.grade_id')->get());

        $stock = $base('in_stock')->selectRaw('COALESCE(SUM(p.in_stock = 1), 0) as yes, COALESCE(SUM(p.in_stock = 0), 0) as no')->first();
        $out['in_stock'] = ['in' => $stock === null ? 0 : Row::int($stock, 'yes'), 'out' => $stock === null ? 0 : Row::int($stock, 'no')];
        $price = $base('price')->selectRaw('MIN(sp.effective_price) as lo, MAX(sp.effective_price) as hi')->first();
        $lo = $price === null ? null : Row::nmoney($price, 'lo');
        $hi = $price === null ? null : Row::nmoney($price, 'hi');
        $out['price'] = ['min' => $lo === null ? null : (float) $lo, 'max' => $hi === null ? null : (float) $hi];

        return $out;
    }

    /**
     * Grouped (k, c) rows → lookup refs with a `count`, highest count first then id; a key the lookup
     * does not know is dropped. Only values with a match are present — spec §10.2's render rule.
     *
     * @param  iterable<mixed>  $rows
     * @return list<array<string, mixed>>
     */
    private function facetRefs(string $table, iterable $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            if (! $r instanceof \stdClass) {
                continue;
            }
            $id = Row::nint($r, 'k');
            $count = Row::int($r, 'c');
            if ($id === null || $count < 1) {
                continue;
            }
            $ref = $this->lookups->ref($table, $id);
            if ($ref !== null) {
                $out[] = $ref + ['count' => $count];
            }
        }
        usort($out, fn (array $a, array $b): int => [$b['count'], $a['id']] <=> [$a['count'], $b['id']]);

        return $out;
    }

    /**
     * `gender=1,2` or `gender=men,women` (2026-10-17): a token is a lookup id or the English name of a gender,
     * case-insensitive — the storefronts' URLs and filter state carry the English name (`?gender=Men`),
     * and the v2 lookups are where the names live. An unknown token resolves to nothing.
     *
     * @return list<int>
     */
    private function genderIds(mixed $v): array
    {
        $out = [];
        $tokens = self::strList($v);
        foreach ($tokens as $token) {
            if (ctype_digit($token)) {
                $out[] = (int) $token;

                continue;
            }
            foreach ($this->lookups->list('genders') as $row) {
                $name = is_array($row['name'] ?? null) ? ($row['name']['en'] ?? null) : null;
                if (is_string($name) && strcasecmp($name, $token) === 0) {
                    $out[] = Val::int($row, 'id');

                    break;
                }
            }
        }

        // asked for a gender nobody has → nothing matches (as an unknown brand slug does), never "no filter"
        return $tokens !== [] && $out === [] ? [-1] : array_values(array_unique($out));
    }

    private static function intIn(mixed $v, int $min, int $max, int $default): int
    {
        if (is_int($v)) {
            $n = $v;
        } elseif (is_string($v) && ctype_digit($v)) {
            $n = (int) $v;
        } else {
            return $default;
        }

        return max($min, min($max, $n));
    }

    private static function str(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }

    /** @return list<string> */
    private static function strList(mixed $v): array
    {
        if (is_string($v)) {
            $v = explode(',', $v);
        }
        if (! is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return $out;
    }

    /** @return list<int> */
    private static function intList(mixed $v): array
    {
        $out = [];
        foreach (self::strList(is_int($v) ? (string) $v : $v) as $s) {
            if (ctype_digit($s)) {
                $out[] = (int) $s;
            }
        }

        return $out;
    }

    private static function numeric(mixed $v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }

    private static function bool(mixed $v): ?bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_string($v)) {
            return in_array(strtolower($v), ['1', 'true', 'yes'], true) ? true : (in_array(strtolower($v), ['0', 'false', 'no'], true) ? false : null);
        }

        return is_int($v) ? $v !== 0 : null;
    }

    /**
     * The subtree ids of a node — exposed for the EXPLAIN command and tests.
     *
     * @return list<int>
     */
    public function subtree(string $path): array
    {
        $node = $this->tree->byPath($path);

        return $node === null ? [] : $this->tree->subtreeIds(Val::int($node, 'id'));
    }

    /**
     * The SQL of the page query for a filter set (EXPLAIN tooling).
     *
     * @param  array<string, mixed>  $input
     * @return array{sql: string, bindings: list<mixed>}|null
     */
    public function pageSql(array $input): ?array
    {
        $category = self::str($input['category'] ?? null);
        $node = $category === null ? null : $this->tree->byPath($category);
        if ($category !== null && $node === null) {
            return null;
        }
        $filters = ['category' => null, 'brand' => self::strList($input['brand'] ?? null), 'gender' => [], 'color' => [], 'material' => [], 'grade' => [], 'price_min' => null, 'price_max' => null, 'in_stock' => self::bool($input['in_stock'] ?? null), 'q' => null];
        $sort = self::str($input['sort'] ?? null);
        $sort = in_array($sort, self::SORTS, true) ? $sort : ($node !== null ? 'position' : 'newest');
        $indexed = self::indexed($node) || ($input['force_indexed'] ?? false) === true;
        $query = $this->cards->base($indexed ? (self::PLANS[$sort] ?? null) : null);
        $this->applyFilters($query, $filters, $node, $indexed);
        $this->applySort($query, $sort);
        $offset = self::intIn($input['offset'] ?? null, 0, 1000000, 0);
        $query->select(ProductCards::columns())->offset($offset)->limit(24);

        /** @var list<mixed> $bindings */
        $bindings = $query->getBindings();

        return ['sql' => $query->toSql(), 'bindings' => $bindings];
    }

    /**
     * @param  iterable<mixed>  $rows
     * @return list<int>
     */
    public static function idsOf(iterable $rows): array
    {
        $ids = [];
        foreach ($rows as $r) {
            if ($r instanceof \stdClass) {
                $ids[] = Row::int($r, 'product_id');
            }
        }

        return $ids;
    }
}
