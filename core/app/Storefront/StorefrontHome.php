<?php

namespace App\Storefront;

use App\Compat\CompatHome;
use App\Compat\CompatServices;
use App\Compat\CompatStorefront;
use App\Domain\Content\BannerImage;
use App\Domain\Inventory\InventoryService;
use App\Support\Val;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The home page's data on the v2 API (2026-10-17, V2-READ-HALF step 1 — `GET /api/v2/{sf}/home`).
 *
 * WHICH products each rail shows is exactly what `catalog/home` shows: the rails come from
 * `storefront_home_rails` (the dashboard's Home rails screen) and the picks are {@see CompatHome}'s
 * rules, called for THIS storefront through a pinned {@see CompatServices} — so a rail reads the same on a
 * storefront's compat and v2 paths, and nothing in compat is edited (Watchizer's live path is compat; its
 * bytes are compared, not reasoned about). What differs is the SHAPE: v2 card rows (`ProductCards`), per rail,
 * in the rail's order, plus three things the spec's home needs that compat never served:
 *
 *  - `deals`: products ≥ 30% off AND in stock, newest first (spec §10.1/§19-12 — the deals band's source
 *    is a threshold, not the plain `offers` rail, which on this catalogue is the whole catalogue). The
 *    dashboard's per-rail threshold field (W3-deals) is still open; this is the decided rule, v2 only.
 *  - `categories`: the depth-1 menu nodes with their live count and ONE real product photo (the newest
 *    in-stock product placed under each) — spec §9 "Category entry".
 *  - `banner`: the active home banner, if the dashboard has one (spec §11.2: with a banner / without).
 *  - `brands` (2026-10-17, spec v2.11 §10.1 item 9): the brands that have at least one visible product on
 *    THIS storefront, with that count and the logo — the brand strip used to draw `meta.brands` (every
 *    active brand, 80 of them) and showed twelve logos with nothing behind them.
 *
 * Cached under the `home` family with the events that stale a listing index plus the rails screen's own
 * flush (the dashboard flushes the storefront on every rail write).
 */
final class StorefrontHome
{
    public const DEALS_MIN_PCT = 30;

    public const DEALS_LIMIT = 12;

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
        /** @var array<string, mixed> $home */
        $home = $this->cache->remember($this->ctx->id(), 'home', $this->ctx->locale, config()->integer('storefront.ttl.count'), fn (): array => $this->assemble());

        return $home;
    }

    /** @return array<string, mixed> */
    private function assemble(): array
    {
        $sf = $this->ctx->id();
        $compat = new CompatServices(app(StorefrontCache::class), app(InventoryService::class), CompatStorefront::pinned($sf));
        $railsRaw = $compat->home->build()['rails'];

        $ids = [];
        foreach ($railsRaw as $rail) {
            foreach ($rail['products'] as $id) {
                $ids[$id] = true;
            }
        }
        $byId = $this->cardsById(array_keys($ids));

        $rails = [];
        foreach ($railsRaw as $rail) {
            $cards = [];
            foreach ($rail['products'] as $id) {
                if (isset($byId[$id])) {
                    $cards[] = $byId[$id];
                }
            }
            if ($cards === []) {
                continue;
            }
            $rails[] = [
                'id' => $rail['id'],
                'kind' => $rail['kind'],
                'target' => $rail['target'],
                'title' => $rail['title'],
                'count' => count($cards),
                'cards' => $cards,
            ];
        }

        return [
            'rails' => $rails,
            'deals' => $this->deals(),
            'categories' => $this->categories(),
            'banner' => $this->banner(),
            'brands' => $this->brands(),
        ];
    }

    /**
     * v2 card rows for a set of product ids, keyed by id (the caller restores its own order).
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function cardsById(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->cards->base()->whereIn('sp.product_id', $ids)->select(ProductCards::columns())->get()->all();
        $out = [];
        foreach ($this->cards->build($rows) as $card) {
            $out[Val::int($card, 'id')] = $card;
        }

        return $out;
    }

    /** @return list<array<string, mixed>> ≥ 30% off and in stock, newest first */
    private function deals(): array
    {
        $rows = $this->cards->base()
            ->where('p.in_stock', 1)
            // a VALID sale, its discount ROUNDED AS THE CARD PRINTS IT (2026-10-19): the band and its "See all" — the Sale
            // page's `min_discount`, the same rounding — then agree on every card; a −30 % card is a 30 % deal
            ->where('sp.effective_sale_price', '>', 0)
            ->whereColumn('sp.effective_sale_price', '<', 'sp.effective_price')
            ->whereRaw('ROUND((sp.effective_price - sp.effective_sale_price) / sp.effective_price * 100) >= ?', [self::DEALS_MIN_PCT])
            ->select(ProductCards::columns())
            ->orderByDesc('p.created_at')->orderByDesc('p.id')
            ->limit(self::DEALS_LIMIT)
            ->get()->all();

        return $this->cards->build($rows);
    }

    /** @return list<array<string, mixed>> depth-1 menu nodes: ref + count + one real product photo */
    private function categories(): array
    {
        $out = [];
        foreach ($this->tree->data()['nodes'] as $node) {
            if (Val::int($node, 'depth') !== 1 || Val::int($node, 'product_count') < 1) {
                continue;
            }
            // The newest IN-STOCK product's photo; when the whole family is sold out (electronics, 72 of 72
            // on 2026-10-17) the newest product's photo — an entry with a count and no picture was an empty
            // tile on the home (bug 3 of the 2026-10-17 review).
            $subtree = $this->tree->subtreeIds(Val::int($node, 'id'));
            $row = $this->cards->placedIn($this->cards->base()->where('p.in_stock', 1), $subtree)
                ->select(ProductCards::columns())
                ->orderByDesc('p.created_at')->orderByDesc('p.id')
                ->limit(1)->get()->all();
            if ($row === []) {
                $row = $this->cards->placedIn($this->cards->base(), $subtree)
                    ->select(ProductCards::columns())
                    ->orderByDesc('p.created_at')->orderByDesc('p.id')
                    ->limit(1)->get()->all();
            }
            $card = $this->cards->build($row)[0] ?? null;
            $out[] = CategoryTree::ref($node) + [
                'count' => Val::int($node, 'product_count'),
                'image' => $card === null ? null : ($card['image'] ?? null),
                'family' => $card === null ? null : ($card['family'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * The brands with at least one visible product here, most products first: `{id, slug, name, logo, count}`.
     * One grouped query over the listing base; names and logos from the cached lookups.
     *
     * @return list<array<string, mixed>>
     */
    private function brands(): array
    {
        $rows = $this->cards->base()
            ->selectRaw('p.brand_id, COUNT(*) AS n')
            ->groupBy('p.brand_id')
            ->orderByDesc('n')->orderBy('p.brand_id')
            ->get();
        $out = [];
        foreach ($rows as $r) {
            $brand = $this->lookups->get('brands', Row::int($r, 'brand_id'));
            if ($brand === null || ($brand['is_active'] ?? true) !== true) {
                continue;
            }
            $out[] = ['id' => $brand['id'], 'slug' => $brand['slug'], 'name' => $brand['name'], 'logo' => $brand['logo'] ?? null, 'count' => Row::int($r, 'n')];
        }

        return $out;
    }

    /** @return array<string, mixed>|null the active in-window home banner, or null (→ the system hero, §11.2) */
    private function banner(): ?array
    {
        $row = DB::table('storefront_banners')
            ->where('storefront_id', $this->ctx->id())
            ->where('placement', 'home')
            ->where('is_active', 1)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->orderBy('sort_order')->orderBy('id')
            ->first();
        if ($row === null) {
            return null;
        }

        $node = $this->tree->node(Row::nint($row, 'storefront_category_id') ?? 0);

        return [
            'id' => Row::int($row, 'id'),
            'image' => ImageUrl::object(BannerImage::path(Row::str($row, 'image_path')), null, null, null, null),
            'link_url' => Row::nstr($row, 'link_url'),
            'product_id' => Row::nint($row, 'product_id'),
            'category' => $node === null ? null : CategoryTree::ref($node),
        ];
    }
}
