<?php

declare(strict_types=1);

namespace App\Storefront;

use App\Compat\CompatCategories;
use App\Support\Val;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * The mega menu's data (`GET /api/v2/{sf}/menu`, the twelve updates Part 4, 2026-11-05).
 *
 * The header menu used to be a narrow column per entry that scrolled inside itself. It becomes ONE wide
 * panel: a rail of top-level entries, and for the entry pointed at, its sub-categories as picture tiles,
 * a row of popular brands, and a featured card. Every part of that panel is a FACT of this storefront,
 * built here once and read by both storefront apps:
 *
 *   categories[]  the top-level categories that have products, in the tree's own order, each with
 *                   children[]  its sub-categories that have products: name, count, picture
 *                               (own → newest buyable product's → none: {@see CategoryImages})
 *                   brands[]    the brands with the most products in it (the "popular brands" chips —
 *                               popular here means most stocked on this storefront, which is the only
 *                               ranking the database can honestly give)
 *                   picks       up to four more products of it (the same ranking), for a section with few tiles
 *                   featured    one product of it, in stock: the biggest discount, else the newest —
 *                               with `price_from`, the lowest price a shopper can pay in that category
 *                               today. The card says "{category} from {price}" and links to the category.
 *   genders[]     the genders products carry here (Men, Women, …), each with the sub-categories that
 *                 hold products of that gender (count and picture OF THAT GENDER), its brands and a
 *                 featured product — the reference's "Men" panel.
 *   brands[]      the storefront's brands, most products first.
 *   sale          how many in-stock products carry a valid discount and the largest of them, or null
 *                 when none does — so "On sale" is an entry only while something is.
 *
 * `legacy_id` on a node is the id the storefronts' own tables and routes know it by
 * ({@see CompatCategories::legacyIdOf()}): the menu links the clean routes the old menu linked
 * (`/category/…`, `/subtypes/…`), which resolve by those tables.
 *
 * Counts are the tree's and the listing's own (visible, active, not deleted). Nothing here is typed by
 * hand and nothing is a placeholder: an entry with no products is not served.
 *
 * Cached for an hour under the `menu` family and the storefront's version; every product, placement,
 * stock, brand and category write flushes it.
 */
final class StorefrontMenu
{
    public const BRANDS_PER_PANEL = 8;

    public const BRANDS_ALL = 30;

    public function __construct(
        private readonly StorefrontContext $ctx,
        private readonly StorefrontCache $cache,
        private readonly ProductCards $cards,
        private readonly CategoryTree $tree,
        private readonly Lookups $lookups,
    ) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        /** @var array<string, mixed> $menu */
        $menu = $this->cache->remember($this->ctx->id(), 'menu', $this->ctx->locale, config()->integer('storefront.ttl.tree'), fn (): array => $this->assemble());

        return $menu;
    }

    /** @return array<string, mixed> */
    private function assemble(): array
    {
        $nodes = $this->tree->data()['nodes'];
        $legacy = $this->legacyIds(array_keys($nodes));

        $categories = [];
        $subIds = [];
        foreach ($nodes as $node) {
            if (Val::int($node, 'depth') !== 1 || Val::int($node, 'product_count') < 1) {
                continue;
            }
            $id = Val::int($node, 'id');
            $children = [];
            foreach (Val::intList($node, 'children') as $childId) {
                $child = $nodes[$childId] ?? null;
                if ($child === null || Val::int($child, 'product_count') < 1) {
                    continue;
                }
                $subIds[] = $childId;
                $children[] = self::entry($child, $legacy);
            }
            $subtree = $this->tree->subtreeIds($id);
            $categories[] = self::entry($node, $legacy) + [
                'children' => $children,
                'brands' => $this->brands($this->cards->placedIn($this->cards->base(), $subtree), self::BRANDS_PER_PANEL),
                'featured' => $this->featured(fn (): Builder => $this->cards->placedIn($this->cards->base(), $subtree)),
                'picks' => $this->picks(fn (): Builder => $this->cards->placedIn($this->cards->base(), $subtree)),
            ];
        }

        return [
            'categories' => $categories,
            'genders' => $this->genders($nodes, $subIds, $legacy),
            'brands' => $this->brands($this->cards->base(), self::BRANDS_ALL),
            'sale' => $this->sale($nodes, $legacy),
        ];
    }

    /**
     * A node as a menu entry: its ref, count, picture and the id the storefront's routes know it by.
     *
     * @param  array<string, mixed>  $node
     * @param  array<int, int>  $legacy
     * @return array<string, mixed>
     */
    private static function entry(array $node, array $legacy): array
    {
        return CategoryTree::ref($node) + [
            'legacy_id' => $legacy[Val::int($node, 'id')] ?? null,
            'count' => Val::int($node, 'product_count'),
            'image' => $node['image'] ?? null,
            'image_source' => $node['image_source'] ?? null,
        ];
    }

    /**
     * The genders that have products here, each with ITS sub-categories, brands and featured product.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  list<int>  $subIds  the sub-categories the menu shows
     * @param  array<int, int>  $legacy
     * @return list<array<string, mixed>>
     */
    private function genders(array $nodes, array $subIds, array $legacy): array
    {
        if ($subIds === []) {
            return [];
        }
        $ofGender = fn (int $genderId): Builder => $this->cards->base()->whereExists(
            fn (Builder $q) => $q->selectRaw('1')->from('catalog_product_gender as pg')->whereColumn('pg.product_id', 'sp.product_id')->where('pg.gender_id', $genderId),
        );

        $out = [];
        foreach ($this->lookups->list('genders') as $gender) {
            $genderId = Val::int($gender, 'id');
            $total = $ofGender($genderId)->count();
            if ($total < 1) {
                continue;
            }

            // How many products of this gender each sub-category holds…
            $counts = [];
            foreach (
                $ofGender($genderId)
                    ->join('storefront_category_product as scp', fn (JoinClause $j) => $j->on('scp.product_id', '=', 'sp.product_id')->where('scp.storefront_id', '=', $this->ctx->id()))
                    ->whereIn('scp.storefront_category_id', $subIds)
                    ->groupBy('scp.storefront_category_id')
                    ->selectRaw('scp.storefront_category_id AS id, COUNT(DISTINCT sp.product_id) AS n')
                    ->get() as $raw
            ) {
                $counts[Row::int($raw, 'id')] = Row::int($raw, 'n');
            }
            if ($counts === []) {
                continue;
            }

            // …and the newest one of them a shopper can buy (a sold-out one only when none can), for the tile's picture.
            $ranked = $ofGender($genderId)
                ->join('storefront_category_product as scp', fn (JoinClause $j) => $j->on('scp.product_id', '=', 'sp.product_id')->where('scp.storefront_id', '=', $this->ctx->id()))
                ->whereIn('scp.storefront_category_id', array_keys($counts))
                ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('catalog_product_images as i')->whereColumn('i.product_id', 'sp.product_id'))
                ->selectRaw('scp.storefront_category_id AS node_id, sp.product_id AS product_id, ROW_NUMBER() OVER (PARTITION BY scp.storefront_category_id ORDER BY p.in_stock DESC, p.created_at DESC, p.id DESC) AS rn');
            $newest = [];
            foreach (DB::query()->fromSub($ranked, 'r')->where('r.rn', 1)->get(['r.node_id', 'r.product_id']) as $raw) {
                $newest[Row::int($raw, 'node_id')] = Row::int($raw, 'product_id');
            }
            $covers = CategoryImages::covers(array_values(array_unique($newest)));

            $tiles = [];
            foreach ($subIds as $nodeId) {                       // the tree's order
                if (! isset($counts[$nodeId], $nodes[$nodeId])) {
                    continue;
                }
                $tiles[] = CategoryTree::ref($nodes[$nodeId]) + [
                    'legacy_id' => $legacy[$nodeId] ?? null,
                    'count' => $counts[$nodeId],
                    'image' => isset($newest[$nodeId]) ? ($covers[$newest[$nodeId]] ?? null) : null,
                ];
            }

            $out[] = [
                'id' => $genderId,
                'name' => $gender['name'] ?? [],
                'count' => $total,
                'children' => $tiles,
                'brands' => $this->brands($ofGender($genderId), self::BRANDS_PER_PANEL),
                'featured' => $this->featured(fn (): Builder => $ofGender($genderId)),
                'picks' => $this->picks(fn (): Builder => $ofGender($genderId)),
            ];
        }

        return $out;
    }

    /**
     * The brands with the most products in a set, most first: `{id, slug, name, logo, count}`.
     *
     * @return list<array<string, mixed>>
     */
    private function brands(Builder $set, int $limit): array
    {
        $out = [];
        foreach ($set->selectRaw('p.brand_id, COUNT(*) AS n')->groupBy('p.brand_id')->orderByDesc('n')->orderBy('p.brand_id')->limit($limit * 2)->get() as $raw) {
            $brand = $this->lookups->get('brands', Row::nint($raw, 'brand_id'));
            if ($brand === null || ($brand['is_active'] ?? true) !== true) {
                continue;
            }
            $out[] = ['id' => $brand['id'], 'slug' => $brand['slug'], 'name' => $brand['name'], 'logo' => $brand['logo'] ?? null, 'count' => Row::int($raw, 'n')];
            if (count($out) === $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * One product of a set that a shopper can buy now — the biggest discount, else the newest — and the
     * lowest price in that set today. Null when nothing in it is in stock.
     *
     * @param  callable(): Builder  $set  a fresh query each call
     * @return array{product: array<string, mixed>, price_from: float}|null
     */
    private function featured(callable $set): ?array
    {
        // What the shopper pays: the sale price when it is a VALID sale, else the price — the cards' own rule.
        $paid = 'CASE WHEN sp.effective_sale_price > 0 AND sp.effective_sale_price < sp.effective_price THEN sp.effective_sale_price ELSE sp.effective_price END';

        $rows = $set()->where('p.in_stock', 1)
            ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('catalog_product_images as i')->whereColumn('i.product_id', 'sp.product_id'))
            ->select(ProductCards::columns())
            ->orderByRaw('CASE WHEN sp.effective_sale_price > 0 AND sp.effective_sale_price < sp.effective_price THEN (sp.effective_price - sp.effective_sale_price) / sp.effective_price ELSE 0 END DESC')
            ->orderByDesc('p.created_at')->orderByDesc('p.id')
            ->limit(1)->get()->all();
        $card = $this->cards->build($rows)[0] ?? null;
        if ($card === null) {
            return null;
        }
        $from = $set()->where('p.in_stock', 1)->min(DB::raw($paid));

        return [
            'product' => [
                'id' => $card['id'], 'slug' => $card['slug'], 'title' => $card['title'], 'brand' => $card['brand'],
                'image' => $card['image'], 'price' => $card['price'],
            ],
            'price_from' => is_numeric($from) ? (float) $from : 0.0,
        ];
    }

    /**
     * A section's TOP PICKS (the developer's review, 2026-11-05): up to four products of it a shopper can buy now — the
     * biggest discounts, else the newest — after the one the featured card already shows. The menu draws them under
     * a section that has few sub-categories, so the panel is filled with real products rather than white space.
     *
     * @param  callable(): Builder  $set  a fresh query each call
     * @return list<array<string, mixed>>
     */
    private function picks(callable $set): array
    {
        $rows = $set()->where('p.in_stock', 1)
            ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('catalog_product_images as i')->whereColumn('i.product_id', 'sp.product_id'))
            ->select(ProductCards::columns())
            ->orderByRaw('CASE WHEN sp.effective_sale_price > 0 AND sp.effective_sale_price < sp.effective_price THEN (sp.effective_price - sp.effective_sale_price) / sp.effective_price ELSE 0 END DESC')
            ->orderByDesc('p.created_at')->orderByDesc('p.id')
            ->limit(5)->get()->all();

        $out = [];
        foreach (array_slice($this->cards->build($rows), 1) as $card) {      // the first is the featured card's
            $out[] = ['id' => $card['id'], 'slug' => $card['slug'], 'title' => $card['title'], 'brand' => $card['brand'], 'image' => $card['image'], 'price' => $card['price']];
        }

        return $out;
    }

    /**
     * Everything discounted, for the SALE panel (the developer's review, 2026-11-05): how many in-stock products
     * carry a valid discount and the largest of them — and the same two numbers per top-level category and per
     * brand, most products first. Null when nothing is discounted, so "Sale" is an entry only while something is.
     * (The live campaigns are not here: a campaign starts and ends by the minute and this payload is cached for an
     * hour — the storefront reads them from `/campaigns`.)
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<int, int>  $legacy
     * @return array{count: int, max_discount: int, categories: list<array<string, mixed>>, brands: list<array<string, mixed>>}|null
     */
    private function sale(array $nodes, array $legacy): ?array
    {
        $pct = 'ROUND((sp.effective_price - sp.effective_sale_price) / sp.effective_price * 100)';
        $valid = fn (): Builder => $this->cards->base()->where('p.in_stock', 1)
            ->where('sp.effective_sale_price', '>', 0)->whereColumn('sp.effective_sale_price', '<', 'sp.effective_price');
        $count = $valid()->count();
        if ($count < 1) {
            return null;
        }
        $max = $valid()->max(DB::raw($pct));

        $categories = [];
        foreach ($nodes as $node) {
            if (Val::int($node, 'depth') !== 1 || Val::int($node, 'product_count') < 1) {
                continue;
            }
            $subtree = $this->tree->subtreeIds(Val::int($node, 'id'));
            $row = $this->cards->placedIn($valid(), $subtree)->selectRaw('COUNT(*) AS n, MAX('.$pct.') AS m')->first();
            $n = $row === null ? 0 : Row::int($row, 'n');
            if ($n < 1) {
                continue;
            }
            $categories[] = ['count' => $n, 'max_discount' => Row::int($row, 'm')] + self::entry($node, $legacy);
        }

        $brands = [];
        foreach ($valid()->selectRaw('p.brand_id, COUNT(*) AS n, MAX('.$pct.') AS m')->groupBy('p.brand_id')->orderByDesc('n')->orderBy('p.brand_id')->limit(self::BRANDS_ALL)->get() as $raw) {
            $brand = $this->lookups->get('brands', Row::nint($raw, 'brand_id'));
            if ($brand === null || ($brand['is_active'] ?? true) !== true) {
                continue;
            }
            $brands[] = ['id' => $brand['id'], 'slug' => $brand['slug'], 'name' => $brand['name'], 'logo' => $brand['logo'] ?? null, 'count' => Row::int($raw, 'n'), 'max_discount' => Row::int($raw, 'm')];
            if (count($brands) === 12) {
                break;
            }
        }

        return ['count' => $count, 'max_discount' => is_numeric($max) ? (int) $max : 0, 'categories' => $categories, 'brands' => $brands];
    }

    /**
     * Node id → the id the storefront's own tables know it by.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function legacyIds(array $ids): array
    {
        $out = [];
        if ($ids === []) {
            return $out;
        }
        foreach (DB::table('storefront_categories')->whereIn('id', $ids)->get(['id', 'legacy_id']) as $raw) {
            $id = Row::int($raw, 'id');
            $out[$id] = Row::nint($raw, 'legacy_id') ?? CompatCategories::NATIVE_ID_OFFSET + $id;
        }

        return $out;
    }
}
