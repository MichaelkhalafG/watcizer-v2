<?php

namespace App\Storefront;

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
 * sort (position|newest|price_asc|price_desc|rating|featured), page, per_page ≤ 96.
 */
final class ProductListing
{
    public const SORTS = ['position', 'newest', 'price_asc', 'price_desc', 'rating', 'featured'];

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
            'gender' => self::intList($input['gender'] ?? null),
            'color' => self::intList($input['color'] ?? null),
            'material' => self::intList($input['material'] ?? null),
            'grade' => self::intList($input['grade'] ?? null),
            'price_min' => self::numeric($input['price_min'] ?? null),
            'price_max' => self::numeric($input['price_max'] ?? null),
            'in_stock' => self::bool($input['in_stock'] ?? null),
            'q' => self::str($input['q'] ?? null),
        ];

        $indexed = self::indexed($node);
        $query = $this->cards->base($indexed ? self::PLANS[$sort] : null);
        $this->applyFilters($query, $filters, $node, $indexed);
        $hash = sha1(json_encode([$filters, $this->ctx->locale], JSON_THROW_ON_ERROR));
        $cap = config()->integer('storefront.listing.count_cap');
        $total = $this->cache->remember($this->ctx->id(), 'count', $hash, config()->integer('storefront.ttl.count'), function () use ($query, $cap): int {
            $inner = (clone $query)->selectRaw('1')->limit($cap);

            return DB::query()->fromSub($inner, 'bounded')->count();
        });

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
    private function applyFilters(Builder $query, array $f, ?array $node, bool $indexed): void
    {
        if ($node !== null) {
            $this->cards->placedIn($query, $this->tree->subtreeIds(Val::int($node, 'id')), $indexed);
        }
        /** @var list<string> $brands */
        $brands = $f['brand'];
        if ($brands !== []) {
            $ids = array_values(array_filter(array_map(fn (string $s) => $this->lookups->brandIdBySlug($s), $brands), fn (?int $i) => $i !== null));
            $query->whereIn('p.brand_id', $ids === [] ? [-1] : $ids);
        }
        /** @var list<int> $genders */
        $genders = $f['gender'];
        if ($genders !== []) {
            $query->whereIn('sp.product_id', fn (Builder $s) => $s->select('product_id')->from('catalog_product_gender')->whereIn('gender_id', $genders));
        }
        /** @var list<int> $colors */
        $colors = $f['color'];
        if ($colors !== []) {
            $query->whereIn('sp.product_id', fn (Builder $s) => $s->select('product_id')->from('catalog_product_color')->whereIn('color_id', $colors));
        }
        /** @var list<int> $materials */
        $materials = $f['material'];
        if ($materials !== []) {
            $query->whereIn('sp.product_id', fn (Builder $s) => $s->select('product_id')->from('catalog_product_watch_specs')
                ->where(fn (Builder $w) => $w->whereIn('band_material_id', $materials)->orWhereIn('case_material_id', $materials)));
        }
        /** @var list<int> $grades */
        $grades = $f['grade'];
        if ($grades !== []) {
            $query->whereIn('p.grade_id', $grades);
        }
        if ($f['price_min'] !== null) {
            $query->where('sp.effective_price', '>=', $f['price_min']);
        }
        if ($f['price_max'] !== null) {
            $query->where('sp.effective_price', '<=', $f['price_max']);
        }
        if ($f['in_stock'] === true) {
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
        $terms = preg_split('/\s+/u', $q) ?: [];
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
            default => $query->orderByDesc('p.created_at')->orderByDesc('p.id'),
        };
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
        $query = $this->cards->base($indexed ? self::PLANS[$sort] : null);
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
