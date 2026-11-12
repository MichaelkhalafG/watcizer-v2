<?php

namespace App\Storefront;

use App\Compat\CompatHome;
use App\Compat\CompatServices;
use App\Compat\CompatStorefront;
use App\Domain\Content\HomeBanners;
use App\Domain\Content\HomeRails;
use App\Domain\Inventory\InventoryService;
use App\Support\Val;
use App\Transform\Row;

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
        // `rails()`, not `build()['rails']`: compat's full build also reads this storefront's settings and
        // its banners for ITS own payload, and this endpoint builds both itself.
        // The hero is on the storefront row this request already resolved: handed to the rails (the Banners block
        // row depends on it) rather than read a second time.
        $settings = $this->ctx->storefront->getAttribute('settings');
        $hero = HomeHero::of(is_array($settings) ? $settings : null);
        $railsRaw = $compat->home->rails($hero);

        $ids = [];
        foreach ($railsRaw as $rail) {
            foreach ($rail['products'] as $id) {
                $ids[$id] = true;
            }
        }
        $byId = $this->cardsById(array_keys($ids));

        $rails = [];
        foreach ($railsRaw as $rail) {
            if (in_array($rail['kind'], HomeRails::SECTIONS, true)) {
                // A SECTION's place among the sliders (the Banners block, the campaign sliders, the category tiles,
                // the brand strip): no cards — the page draws the section here from this same payload. Compat
                // already left the Banners block out when it leads the page.
                $rails[] = ['id' => $rail['id'], 'kind' => $rail['kind'], 'target' => null, 'title' => $rail['title'],
                    'count' => 0, 'cards' => [], 'background' => null, 'blend' => false, 'masks' => [], 'more_href' => null];

                continue;
            }
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
                // Part 3.2 (2026-11-05): the slider's section colour and whether its photos blend into it.
                'background' => $rail['background'],
                'blend' => $rail['blend'],
                // …and, with the blend on, the mask of each card's studio background (card id => a `data:` URL): the
                // photo is drawn as it is and only its white gives way to the colour (CutMasks, 2026-11-05).
                'masks' => $rail['masks'],
                // a hand-picked rail's "See more" link (Block A3, 2026-11-12); null = no button
                'more_href' => $rail['more_href'],
            ] + ($rail['kind'] !== HomeRails::BRAND_BANNER ? [] : [
                // Part 3.3: a brand banner — the brand (with its logo and the slug its page lives at), the three
                // colours that fill it and its sentence. The cards above are its three products.
                'brand' => $this->brandRef($rail['target']),
                'colors' => $rail['colors'],
                'description' => $rail['description'],
            ]);
        }

        // D1 (2026-10-20): EVERY active in-window banner, in the dashboard's order, each with its `device`
        // — the hero rotates through them. `banner` stays beside it, the FIRST ELEMENT OF THIS SAME LIST, because
        // core deploys before the storefronts do (RUNBOOK_BF_LAUNCH §3 then §6) and the build running in that
        // window reads the singular key. Retire `banner` once both apps are past this release. Read once: asking
        // twice cost a second banner query and a second tree lookup per row, and the home's cold query budget
        // (HomeAndRelatedTest) came within one query of its ceiling.
        $banners = $this->banners();

        return [
            // Which hero this storefront's home draws (the addendum, 2026-10-20) — `three_d` or `banner`,
            // from `settings.home.hero`. The storefront app reads THIS and never its own build: one shared
            // hero component, one value per shop, flipped from the dashboard. See {@see HomeHero}.
            'hero' => $hero,
            'rails' => $rails,
            // the sections the dashboard has a row for, on or off (see CompatHome::build)
            'managed_sections' => HomeRails::managedSections($sf),
            'deals' => $this->deals(),
            'categories' => $this->categories(),
            'banners' => $banners,
            'banner' => $banners[0] ?? null,
            // The two slots beside the large hero, `[top, bottom]`, each a banner or null (Part 3.1, 2026-11-05).
            // Both null and the hero is full width, as it has always been.
            'side_banners' => HomeBanners::side($sf),
            'brands' => $this->brands(),
        ];
    }

    /**
     * The brand a brand banner is about: `{id, slug, name, logo}` — null when the brand is gone or switched off.
     *
     * @return array<string, mixed>|null
     */
    private function brandRef(?int $brandId): ?array
    {
        $brand = $brandId === null ? null : $this->lookups->get('brands', $brandId);
        if ($brand === null || ($brand['is_active'] ?? true) !== true) {
            return null;
        }

        return ['id' => $brand['id'], 'slug' => $brand['slug'], 'name' => $brand['name'], 'logo' => $brand['logo'] ?? null];
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

    /**
     * EVERY active in-window home banner, in the dashboard's order (D1, 2026-10-20).
     *
     * It used to be one row — `->first()` — so the storefront could not rotate what it was never sent: three
     * banners on the dashboard, one on the page. The hero now cycles this list.
     *
     * `device` (F1) rides on each row and the FILTERING IS THE CLIENT'S, never this endpoint's. The CDN in front of
     * both API hosts keeps ONE COPY PER URL and ignores `Vary`, so a payload narrowed by user-agent here would be
     * served to the wrong device from a cached copy — the same mechanism that made v2 private on 2026-10-19
     * (V2-CDN-PRIVATE). Every active banner goes out; the page shows the ones that match its own viewport.
     *
     * @return list<array<string, mixed>>
     */
    private function banners(): array
    {
        $out = [];
        foreach (HomeBanners::list($this->ctx->id()) as $banner) {
            // `category`, the full tree ref, is v2's own addition: it has been in this payload since the
            // endpoint was written and the Brand Fashion build in flight still reads it. The hero reads
            // `href` and `alt` — resolved in HomeBanners, so compat's home answers the same two keys.
            $categoryId = Val::nint($banner, 'category_id');
            $node = $categoryId === null ? null : $this->tree->node($categoryId);
            unset($banner['category_id']);
            $out[] = $banner + ['category' => $node === null ? null : CategoryTree::ref($node)];
        }

        return $out;
    }
}
