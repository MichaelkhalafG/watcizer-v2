<?php

namespace App\Storefront;

use App\Support\Val;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The native `ProductCard` (CLEAN_CORE_STUDY §3.5.1) built for a set of product ids with a fixed
 * number of queries (§5.1 / §5.3): translations, cover images and primary placements are fetched
 * per id set, brand/grade/category names come from the cached lookups and tree.
 *
 * The card is the listing unit: it carries what a card renders (title, price, cover, brand,
 * grade, primary category, stock, rating, position flags). Filter facets (genders, colours,
 * filter ids) are NOT on the card — v2 filters server-side, so the client never needs them per
 * row; they live on the detail (`attributes`, `specs`). This keeps §5.3's listing budget honest.
 */
final class ProductCards
{
    public function __construct(
        private readonly StorefrontContext $ctx,
        private readonly Lookups $lookups,
        private readonly CategoryTree $tree,
    ) {}

    /**
     * The base listing query (visible placement ⨝ active product), no filters, no order.
     *
     * `$plan` (ProductListing::PLANS) pins the driving table and index for an index-ordered
     * page at depth (review 🟠-5): `sp` + a storefront_product index for position/price/featured,
     * `p` + a catalog_products index for newest/rating. Null lets the optimizer choose (small
     * result sets, where its semijoin + filesort over a handful of rows is the cheaper plan).
     */
    public function base(?string $plan = null): Builder
    {
        $sf = $this->ctx->id();

        $query = match ($plan) {
            'sp:sp_list_sort_idx' => DB::table(DB::raw('`storefront_product` as `sp` FORCE INDEX (`sp_list_sort_idx`)'))->join('catalog_products as p', 'p.id', '=', 'sp.product_id'),
            'sp:sp_list_price_idx' => DB::table(DB::raw('`storefront_product` as `sp` FORCE INDEX (`sp_list_price_idx`)'))->join('catalog_products as p', 'p.id', '=', 'sp.product_id'),
            'sp:sp_featured_idx' => DB::table(DB::raw('`storefront_product` as `sp` FORCE INDEX (`sp_featured_idx`)'))->join('catalog_products as p', 'p.id', '=', 'sp.product_id'),
            'p:cp_active_created_idx' => DB::table(DB::raw('`catalog_products` as `p` FORCE INDEX (`cp_active_created_idx`)'))->join('storefront_product as sp', 'sp.product_id', '=', 'p.id'),
            'p:cp_rating_idx' => DB::table(DB::raw('`catalog_products` as `p` FORCE INDEX (`cp_rating_idx`)'))->join('storefront_product as sp', 'sp.product_id', '=', 'p.id'),
            default => DB::table('storefront_product as sp')->join('catalog_products as p', 'p.id', '=', 'sp.product_id'),
        };

        return $query
            ->where('sp.storefront_id', $sf)
            ->where('sp.is_visible', 1)
            ->where(function (Builder $q): void {
                $q->whereNull('sp.published_at')->orWhere('sp.published_at', '<=', now());
            })
            ->where('p.is_active', 1)
            ->whereNull('p.deleted_at');
    }

    /** @return list<string> */
    public static function columns(): array
    {
        return [
            'sp.product_id', 'sp.slug', 'sp.is_featured', 'sp.sort_order', 'sp.effective_price', 'sp.effective_sale_price',
            'p.family', 'p.brand_id', 'p.grade_id', 'p.stock_express', 'p.stock_market', 'p.in_stock', 'p.rating_avg',
            'p.rating_count', 'p.created_at', 'p.updated_at',
        ];
    }

    /**
     * Cards for base rows (in row order).
     *
     * @param  iterable<stdClass>  $rows
     * @return list<array<string, mixed>>
     */
    public function build(iterable $rows): array
    {
        $rows = is_array($rows) ? $rows : iterator_to_array($rows, false);
        $ids = array_values(array_map(fn (stdClass $r) => Row::int($r, 'product_id'), $rows));
        $translations = $this->translations($ids, ['title', 'short_description']);
        $covers = $this->covers($ids);
        $primary = $this->primaryPlacements($ids);

        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->card($row, $translations, $covers, $primary);
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, array<string, string>>>  $translations  product id => field => locale => value
     * @param  array<int, array<string, mixed>>  $covers
     * @param  array<int, int>  $primary  product id => node id
     * @return array<string, mixed>
     */
    public function card(stdClass $row, array $translations, array $covers, array $primary): array
    {
        $id = Row::int($row, 'product_id');
        $tr = $translations[$id] ?? [];
        $primaryNode = isset($primary[$id]) ? $this->tree->node($primary[$id]) : null;
        $sf = $this->ctx->id();
        $this->ctx->tags->product($sf, $id);

        return [
            'id' => $id,
            'slug' => Row::str($row, 'slug'),
            'family' => Row::str($row, 'family'),
            'title' => $tr['title'] ?? [],
            'short_description' => $tr['short_description'] ?? [],
            'brand' => $this->lookups->ref('brands', Row::int($row, 'brand_id')),
            'grade' => $this->lookups->ref('grades', Row::nint($row, 'grade_id')),
            'primary_category' => $primaryNode === null ? null : CategoryTree::ref($primaryNode),
            'image' => $covers[$id] ?? null,
            'price' => StorefrontPricing::resolve(Row::money($row, 'effective_price'), Row::nmoney($row, 'effective_sale_price'), $this->currency()),
            'stock' => ['express' => Row::int($row, 'stock_express'), 'market' => Row::int($row, 'stock_market'), 'in_stock' => Row::bool($row, 'in_stock')],
            'rating' => ['avg' => Row::nfloat($row, 'rating_avg'), 'count' => Row::int($row, 'rating_count')],
            'is_featured' => Row::bool($row, 'is_featured'),
            'sort_order' => Row::int($row, 'sort_order'),
            'created_at' => self::ts(Row::nstr($row, 'created_at')),
            'updated_at' => self::ts(Row::nstr($row, 'updated_at')),
        ];
    }

    public function currency(): string
    {
        $c = $this->ctx->storefront->getAttribute('currency');

        return is_string($c) && $c !== '' ? $c : 'EGP';
    }

    /**
     * product id => field => locale => value; a missing locale row leaves the locale key out (fallback OFF).
     *
     * @param  list<int>  $ids
     * @param  list<string>  $fields
     * @return array<int, array<string, array<string, string>>>
     */
    public function translations(array $ids, array $fields): array
    {
        $out = [];
        if ($ids === []) {
            return $out;
        }
        $rows = DB::table('catalog_product_translations')
            ->select(array_merge(['product_id', 'locale'], $fields))
            ->whereIn('product_id', $ids)
            ->whereIn('locale', $this->ctx->locales())
            ->orderBy('product_id')->orderBy('locale')
            ->get();
        foreach ($rows as $r) {
            $pid = Row::int($r, 'product_id');
            $locale = Row::str($r, 'locale');
            foreach ($fields as $field) {
                $v = Row::nstr($r, $field);
                if ($v !== null && $v !== '') {
                    $out[$pid][$field][$locale] = $v;
                }
            }
        }

        return $out;
    }

    /**
     * product id => cover image object (lowest sort cover row).
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    public function covers(array $ids): array
    {
        $out = [];
        if ($ids === []) {
            return $out;
        }
        $rows = DB::table('catalog_product_images')
            ->select(['product_id', 'path', 'width', 'height', 'alt_en', 'alt_ar', 'renditions'])
            ->whereIn('product_id', $ids)
            ->where('is_cover', 1)
            ->orderBy('product_id')->orderBy('sort')->orderBy('id')
            ->get();
        foreach ($rows as $r) {
            $pid = Row::int($r, 'product_id');
            if (isset($out[$pid])) {
                continue;
            }
            $out[$pid] = self::image($r);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function image(stdClass $r): array
    {
        $renditions = Row::nstr($r, 'renditions');
        $decoded = $renditions === null ? null : json_decode($renditions, true);
        $alt = Row::nstr($r, 'alt_ar') ?? Row::nstr($r, 'alt_en');

        return ImageUrl::object(Row::str($r, 'path'), Row::nint($r, 'width'), Row::nint($r, 'height'), $alt, is_array($decoded) ? $decoded : null);
    }

    /**
     * product id => primary visible node id (deepest primary placement; falls back to any visible placement).
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    public function primaryPlacements(array $ids): array
    {
        $out = [];
        if ($ids === []) {
            return $out;
        }
        $rows = DB::table('storefront_category_product')
            ->select(['product_id', 'storefront_category_id', 'is_primary'])
            ->where('storefront_id', $this->ctx->id())
            ->whereIn('product_id', $ids)
            ->orderByDesc('is_primary')->orderBy('storefront_category_id')
            ->get();
        foreach ($rows as $r) {
            $pid = Row::int($r, 'product_id');
            $node = $this->tree->node(Row::int($r, 'storefront_category_id'));
            if ($node === null) {
                continue;                                   // invisible node: never a public reference
            }
            $current = isset($out[$pid]) ? $this->tree->node($out[$pid]) : null;
            if ($current === null || (Row::bool($r, 'is_primary') && Val::int($node, 'depth') > Val::int($current, 'depth'))) {
                $out[$pid] = Val::int($node, 'id');
            }
        }

        return $out;
    }

    /**
     * Placement filter: products placed on any of the given nodes.
     *
     * Small subtrees: an IN-subquery the optimizer may turn into a semijoin driven from the
     * placement table (a few rows, then a cheap filesort). Large subtrees (`$indexed`): a correlated
     * EXISTS with LIMIT 1 — MariaDB never rewrites it into a semijoin, so the driving table stays
     * the index-ordered `sp` scan and membership is one unique-index probe per candidate row.
     *
     * @param  list<int>  $nodeIds
     */
    public function placedIn(Builder $query, array $nodeIds, bool $indexed = false): Builder
    {
        $sf = $this->ctx->id();
        if ($indexed) {
            return $query->whereExists(function (Builder $sub) use ($nodeIds, $sf): void {
                $sub->selectRaw('1')->from('storefront_category_product as scp')
                    ->whereColumn('scp.product_id', 'sp.product_id')
                    ->where('scp.storefront_id', $sf)
                    ->whereIn('scp.storefront_category_id', $nodeIds)
                    ->limit(1);
            });
        }

        return $query->whereIn('sp.product_id', function (Builder $sub) use ($nodeIds, $sf): void {
            $sub->select('scp.product_id')->from('storefront_category_product as scp')
                ->where('scp.storefront_id', $sf)
                ->whereIn('scp.storefront_category_id', $nodeIds);
        });
    }

    public static function ts(?string $dbValue): ?string
    {
        if ($dbValue === null || $dbValue === '') {
            return null;
        }

        return Carbon::parse($dbValue, config()->string('app.timezone'))->toJSON();
    }
}
