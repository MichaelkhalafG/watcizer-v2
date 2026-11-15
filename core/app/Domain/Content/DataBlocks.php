<?php

declare(strict_types=1);

namespace App\Domain\Content;

use App\Domain\Catalog\SearchExposure;
use App\Domain\Seo\LandingPages;
use App\Storefront\BoundStorefront;
use App\Storefront\ProductCards;
use App\Storefront\StorefrontCache;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * LIVE DATA BLOCKS (SEO Phase 3, 2026-11-14): the tokens an article carries instead of hard-coded lists —
 *
 *   {{products brand=tommy-hilfiger gender=men family=watch sort=bestseller limit=6}}   a grid of cards
 *   {{new-arrivals brand=guess days=30 limit=4}}                                         the newest, within `days`
 *   {{count brand=… family=…}}                                                           the LIVE count (inline)
 *   {{price-range brand=… family=…}}                                                     lowest – highest (inline)
 *   {{compare models=1710604,1791476}}                                                   those models side by side
 *   {{in-stock-alternatives model=…}}                                                    "available now instead"
 *
 * Arguments: brand (slug, comma list), family (watch|bag|wallet|perfume, or the landing words watches|bags|…),
 * gender (men|women|unisex), category (a category's slug: sunglasses, crossbody-bag — its whole subtree), limit (1–12), sort (bestseller|newest|price_asc|price_desc|
 * position|rating), days, model(s).
 *
 * THE STOCK RULE: a block holds every LIVE product of its scope — in stock first, then out of stock, each group in
 * the block's own order; count and price-range are computed over ALL LIVE products (`total`), with the in-stock
 * figure beside it (`available`). LIVE = the storefront shows the product's page AND search engines are not kept away
 * from it (the quality gate's definition, so a block never shows what the gate would refuse to name).
 *
 * SPEED: resolved by core, never by the browser — `forBodies()` is cached under the guarded cache (one build at a time,
 * the last good copy served meanwhile) and warmed by catalog:warm for the newest articles; the nightly
 * blog:refresh-blocks re-resolves every article.
 */
final class DataBlocks
{
    public const KINDS = ['products', 'count', 'price-range', 'new-arrivals', 'compare', 'in-stock-alternatives'];

    public const PATTERN = '/\{\{\s*(products|count|price-range|new-arrivals|compare|in-stock-alternatives)((?:\s+[a-z_]+=[^\s{}]+)*)\s*\}\}/u';

    public const MAX_LIMIT = 12;

    /** How long a resolved set of blocks is kept (a flush of the shop — a sale, a restock, an edit — forgets it first). */
    public const TTL = 1800;

    private const SORTS = ['bestseller', 'newest', 'price_asc', 'price_desc', 'position', 'rating'];

    public function __construct(private readonly StorefrontCache $cache) {}

    /**
     * Every block token in a text, once each, in order of appearance.
     *
     * @return list<array{token: string, kind: string, args: array<string, string>}>
     */
    public static function parse(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $hit) {
            $token = self::canonical($hit[1], self::args($hit[2]));
            if (! isset($out[$token])) {
                $out[$token] = ['token' => $token, 'kind' => $hit[1], 'args' => self::args($hit[2])];
            }
        }

        return array_values($out);
    }

    /**
     * The token as the storefront finds it in the body — the raw text between the braces, whitespace collapsed. The
     * storefront looks a block up by this string.
     *
     * @param  array<string, string>  $args
     */
    public static function canonical(string $kind, array $args): string
    {
        $parts = [$kind];
        foreach ($args as $k => $v) {
            $parts[] = $k.'='.$v;
        }

        return implode(' ', $parts);
    }

    /** @return array<string, string> */
    private static function args(string $raw): array
    {
        $out = [];
        preg_match_all('/([a-z_]+)=([^\s{}]+)/u', $raw, $m, PREG_SET_ORDER);
        foreach ($m as $a) {
            $out[$a[1]] = $a[2];
        }

        return $out;
    }

    /**
     * The blocks of an article's bodies (both languages share them), resolved, cached under the guarded cache.
     *
     * @return array<string, array<string, mixed>> token => resolved block
     */
    public function forBodies(int $storefrontId, string ...$bodies): array
    {
        $blocks = self::parse(implode("\n", $bodies));
        if ($blocks === []) {
            return [];
        }
        $suffix = md5(implode('|', array_column($blocks, 'token')));

        /** @var array<string, array<string, mixed>> */
        return $this->cache->rememberGuarded($storefrontId, 'blog_blocks', $suffix, self::TTL, fn (): array => self::resolveAll($storefrontId, $blocks));
    }

    /** Build the cache entry of an article's blocks NOW (the warm-up). */
    public function warm(int $storefrontId, string ...$bodies): void
    {
        $blocks = self::parse(implode("\n", $bodies));
        if ($blocks !== []) {
            $this->cache->refreshGuarded($storefrontId, 'blog_blocks', md5(implode('|', array_column($blocks, 'token'))), self::TTL, fn (): array => self::resolveAll($storefrontId, $blocks));
        }
    }

    /**
     * catalog:warm's part: the blocks of the shop's newest published articles, where they are not cached — so the first
     * reader after a sale, a restock or an edit is not the one who resolves them. Returns how many were built.
     */
    public function warmNewest(int $storefrontId, int $articles = 30): int
    {
        $built = 0;
        $ids = DB::table('core_blogs')->where('storefront_id', $storefrontId)->whereNotNull('published_at')->where('published_at', '<=', now())
            ->orderByDesc('published_at')->limit($articles)->pluck('id')->all();
        foreach ($ids as $id) {
            $bodies = array_map(fn ($b): string => Coerce::str($b), DB::table('core_blog_translations')->where('blog_id', $id)->pluck('body')->all());
            $blocks = self::parse(implode("\n", $bodies));
            if ($blocks === []) {
                continue;
            }
            $suffix = md5(implode('|', array_column($blocks, 'token')));
            if (! \Illuminate\Support\Facades\Cache::has($this->cache->key($storefrontId, 'blog_blocks', $suffix))) {
                $this->cache->refreshGuarded($storefrontId, 'blog_blocks', $suffix, self::TTL, fn (): array => self::resolveAll($storefrontId, $blocks));
                $built++;
            }
        }

        return $built;
    }

    /**
     * Resolve blocks now, uncached (the nightly job; the cache's builder).
     *
     * @param  list<array{token: string, kind: string, args: array<string, string>}>  $blocks
     * @return array<string, array<string, mixed>>
     */
    public static function resolveAll(int $storefrontId, array $blocks): array
    {
        return BoundStorefront::run($storefrontId, function (int $sf) use ($blocks): array {
            $out = [];
            foreach ($blocks as $b) {
                $out[$b['token']] = self::resolve($sf, $b['kind'], $b['args']);
            }

            return $out;
        });
    }

    /**
     * One block. Shapes: a list kind → {kind, total, available, items, unknown?}; count → {kind, total, available};
     * price-range → {kind, total, min, max, currency}. `unknown` names arguments that matched nothing (a brand slug
     * the shop does not have): the block then shows nothing, and the quality gate holds the article.
     *
     * @param  array<string, string>  $args
     * @return array<string, mixed>
     */
    private static function resolve(int $sf, string $kind, array $args): array
    {
        $unknown = [];
        $scope = self::scope($sf, $args, $unknown);
        if ($kind === 'compare' || $kind === 'in-stock-alternatives') {
            return self::byModels($sf, $kind, $args);
        }
        $base = fn (): Builder => self::scoped(self::live($sf), $sf, $scope);
        $total = (clone $base())->count();
        $available = (clone $base())->where('p.in_stock', 1)->count();
        $out = ['kind' => $kind, 'total' => $total, 'available' => $available, 'set' => self::setOf($base())] + ($unknown === [] ? [] : ['unknown' => $unknown]);
        if ($kind === 'count') {
            return $out;
        }
        if ($kind === 'price-range') {
            $range = Row::cast((clone $base())->selectRaw('MIN('.self::PAID.') AS lo, MAX('.self::PAID.') AS hi')->first() ?? new \stdClass);

            return $out + ['min' => (int) round(Row::nfloat($range, 'lo') ?? 0.0), 'max' => (int) round(Row::nfloat($range, 'hi') ?? 0.0), 'currency' => 'EGP'];
        }
        $limit = max(1, min(self::MAX_LIMIT, Coerce::int($args['limit'] ?? ($kind === 'new-arrivals' ? 4 : 6))));
        $q = $base();
        if ($kind === 'new-arrivals') {
            $days = max(1, min(365, Coerce::int($args['days'] ?? 30)));
            $q->where('p.created_at', '>=', now()->subDays($days));
            $out['total'] = (clone $q)->count();
            $out['available'] = (clone $q)->where('p.in_stock', 1)->count();
            $out['set'] = self::setOf(clone $q);
            $sort = 'newest';
        } else {
            $sort = in_array($args['sort'] ?? '', self::SORTS, true) ? $args['sort'] : 'bestseller';
        }
        $q->orderByDesc('p.in_stock');                                           // the STOCK RULE: in stock first
        self::order($q, $sf, $sort);

        return $out + ['items' => self::cards($q->limit($limit)->get(ProductCards::columns())->all())];
    }

    /**
     * compare: the named models, in the order named, GONE ones listed in `missing`; in-stock-alternatives: up to
     * `limit` in-stock products of the model's brand and family (never the model itself) — the storefront shows
     * them only while the model is out of stock.
     *
     * @param  array<string, string>  $args
     * @return array<string, mixed>
     */
    private static function byModels(int $sf, string $kind, array $args): array
    {
        $models = array_values(array_filter(array_map(fn (string $m): string => strtoupper(trim($m)), explode(',', $args['models'] ?? $args['model'] ?? ''))));
        if ($kind === 'compare') {
            $rows = self::live($sf)->whereIn(DB::raw('UPPER(p.model_number)'), $models)->get([...ProductCards::columns(), 'p.model_number'])->all();
            $byModel = [];
            foreach ($rows as $r) {
                $byModel[strtoupper(Row::str(Row::cast($r), 'model_number'))] ??= $r;
            }
            $ordered = array_values(array_filter(array_map(fn (string $m) => $byModel[$m] ?? null, $models)));

            return ['kind' => $kind, 'total' => count($ordered), 'available' => count(array_filter($ordered, fn ($r): bool => Row::bool(Row::cast($r), 'in_stock'))),
                'models' => $models, 'missing' => array_values(array_diff($models, array_keys($byModel))), 'items' => self::cards($ordered)];
        }
        $model = $models[0] ?? '';
        $found = $model === '' ? null : self::live($sf)->where(DB::raw('UPPER(p.model_number)'), $model)->first(['p.id', 'p.brand_id', 'p.family', 'p.in_stock']);
        $of = $found === null ? null : Row::cast($found);
        if ($of === null) {
            return ['kind' => $kind, 'model' => $model, 'missing' => [$model], 'total' => 0, 'available' => 0, 'model_in_stock' => false, 'items' => []];
        }
        $limit = max(1, min(self::MAX_LIMIT, Coerce::int($args['limit'] ?? 3)));
        $q = self::live($sf)->where('p.brand_id', Row::int($of, 'brand_id'))->where('p.family', Row::str($of, 'family'))
            ->where('p.id', '<>', Row::int($of, 'id'))->where('p.in_stock', 1);
        self::order($q, $sf, 'bestseller');
        $items = self::cards($q->limit($limit)->get(ProductCards::columns())->all());

        return ['kind' => $kind, 'model' => $model, 'model_in_stock' => Row::bool($of, 'in_stock'), 'total' => count($items), 'available' => count($items), 'items' => $items];
    }

    /**
     * Cards for rows, trimmed to what an article shows: the address, the title, the brand, the photo, the price and
     * whether it is in stock.
     *
     * @param  array<int, \stdClass>  $rows
     * @return list<array<string, mixed>>
     */
    private static function cards(array $rows): array
    {
        $rows = array_values($rows);
        if ($rows === []) {
            return [];
        }
        $models = DB::table('catalog_products')->whereIn('id', array_map(fn ($r): int => Row::int(Row::cast($r), 'product_id'), $rows))->pluck('model_number', 'id')->all();
        $out = [];
        foreach (app(ProductCards::class)->build(array_map(fn ($r) => Row::cast($r), $rows)) as $card) {
            $id = Coerce::int($card['id'] ?? 0);
            $out[] = [
                'id' => $id,
                'slug' => $card['slug'] ?? '',
                'title' => $card['title'] ?? ['en' => '', 'ar' => ''],
                'brand' => is_array($card['brand'] ?? null) ? ['slug' => $card['brand']['slug'] ?? '', 'name' => $card['brand']['name'] ?? null] : null,
                'model' => is_string($models[$id] ?? null) ? $models[$id] : null,
                'image' => $card['image'] ?? null,
                'price' => $card['price'] ?? null,
                'in_stock' => (bool) (Coerce::arr($card['stock'] ?? [])['in_stock'] ?? false),
            ];
        }

        return $out;
    }

    /** The block's whole LIVE set as one fingerprint — a product added or GONE changes it; a stock flip does not. */
    private static function setOf(Builder $q): string
    {
        return md5(implode(',', array_map(fn ($v): int => Coerce::int($v), $q->orderBy('sp.product_id')->pluck('sp.product_id')->all())));
    }

    /** LIVE products of the storefront: its page answers and search engines are not kept away from it. */
    private static function live(int $sf): Builder
    {
        return ProductCards::visibleOn(DB::table('storefront_product as sp')->join('catalog_products as p', 'p.id', '=', 'sp.product_id'), $sf)
            ->whereRaw('COALESCE('.SearchExposure::effectiveSql('p').", '') <> ?", [SearchExposure::HIDDEN]);
    }

    /**
     * @param  array<string, string>  $args
     * @param  list<string>  $unknown
     * @return array{brand_ids: list<int>, family: list<string>, gender_ids: list<int>, category: ?string}
     */
    private static function scope(int $sf, array $args, array &$unknown): array
    {
        $brands = array_values(array_filter(explode(',', $args['brand'] ?? '')));
        $brandIds = $brands === [] ? [] : array_values(array_map(fn ($v): int => Coerce::int($v), DB::table('catalog_brands')->whereIn('slug', $brands)->pluck('id')->all()));
        if (count($brandIds) < count($brands)) {
            $unknown[] = 'brand';
        }
        $families = [];
        foreach (array_filter(explode(',', $args['family'] ?? '')) as $f) {
            $f = strtolower($f);
            $family = array_key_exists($f, LandingPages::FAMILIES) ? $f : array_search($f, LandingPages::FAMILIES, true);
            if (is_string($family)) {
                $families[] = $family;
            } else {
                $unknown[] = 'family';
            }
        }
        $genderIds = [];
        foreach (array_filter(explode(',', $args['gender'] ?? '')) as $g) {
            $id = DB::table('catalog_gender_translations')->where('locale', 'en')->whereRaw('LOWER(TRIM(name)) = ?', [strtolower($g)])->value('gender_id');
            if ($id === null) {
                $unknown[] = 'gender';
            } else {
                $genderIds[] = Coerce::int($id);
            }
        }
        // the category by its slug on this storefront; its stored path (`/2/51/`) scopes its whole subtree
        $category = null;
        if (isset($args['category'])) {
            $path = DB::table('storefront_categories')->where('storefront_id', $sf)->where('slug', trim($args['category'], '/'))->value('path');
            $category = is_string($path) && $path !== '' ? $path : '/0/';
            if ($category === '/0/') {
                $unknown[] = 'category';
            }
        }

        return ['brand_ids' => $brandIds === [] && $brands !== [] ? [0] : $brandIds, 'family' => $families, 'gender_ids' => $genderIds, 'category' => $category];
    }

    /** @param  array{brand_ids: list<int>, family: list<string>, gender_ids: list<int>, category: ?string}  $scope */
    private static function scoped(Builder $q, int $sf, array $scope): Builder
    {
        if ($scope['brand_ids'] !== []) {
            $q->whereIn('p.brand_id', $scope['brand_ids']);
        }
        if ($scope['family'] !== []) {
            $q->whereIn('p.family', $scope['family']);
        }
        if ($scope['gender_ids'] !== []) {
            $q->whereIn('sp.product_id', fn (Builder $s) => $s->select('product_id')->from('catalog_product_gender')->whereIn('gender_id', $scope['gender_ids']));
        }
        if ($scope['category'] !== null) {
            $path = $scope['category'];
            $q->whereIn('sp.product_id', fn (Builder $s) => $s->select('scp.product_id')->from('storefront_category_product as scp')
                ->join('storefront_categories as sc', 'sc.id', '=', 'scp.storefront_category_id')
                ->where('sc.storefront_id', $sf)
                ->where('sc.path', 'like', $path.'%'));
        }

        return $q;
    }

    /** The price a shopper pays (the sale price when it is a real one) — the card's rule (catalogPrice). */
    private const PAID = 'CASE WHEN sp.effective_sale_price > 0 AND sp.effective_sale_price < sp.effective_price THEN sp.effective_sale_price ELSE sp.effective_price END';

    /**
     * The block's order within each stock group. `bestseller` = units sold on this storefront in the last 365 days
     * (cancelled orders and rewards left out), then the dashboard's order.
     */
    private static function order(Builder $q, int $sf, string $sort): void
    {
        match ($sort) {
            'newest' => $q->orderByDesc('p.created_at')->orderByDesc('p.id'),
            'price_asc' => $q->orderByRaw(self::PAID)->orderBy('sp.product_id'),
            'price_desc' => $q->orderByRaw(self::PAID.' DESC')->orderBy('sp.product_id'),
            'rating' => $q->orderByDesc('p.rating_avg')->orderBy('sp.product_id'),
            'position' => $q->orderBy('sp.sort_order')->orderBy('sp.product_id'),
            default => $q->leftJoinSub(
                DB::table('order_items as oi')->join('orders as o', 'o.id', '=', 'oi.order_id')
                    ->where('o.storefront_id', $sf)->where('o.status', '<>', 'cancelled')->where('oi.is_reward', 0)
                    ->where('o.created_at', '>=', now()->subDays(365))
                    ->groupBy('oi.product_id')->selectRaw('oi.product_id, SUM(oi.quantity) AS units'),
                'sold', 'sold.product_id', '=', 'sp.product_id',
            )->orderByRaw('COALESCE(sold.units, 0) DESC')->orderBy('sp.sort_order')->orderBy('sp.product_id'),
        };
    }

    /**
     * What the nightly job compares, per block: the LIVE ids it lists, the LIVE count and the price range. Stock is
     * deliberately NOT in it — an in stock ↔ out of stock flip is not a content change.
     *
     * @param  array<string, array<string, mixed>>  $resolved
     * @return array<string, array{ids: list<int>, set: string, total: int, min: int, max: int, missing: list<string>}>
     */
    public static function snapshot(array $resolved): array
    {
        $out = [];
        foreach ($resolved as $token => $b) {
            $ids = array_map(fn ($c): int => Coerce::int(Coerce::arr($c)['id'] ?? 0), Coerce::arr($b['items'] ?? []));
            sort($ids);
            $out[$token] = ['ids' => $ids, 'set' => Coerce::str($b['set'] ?? ''), 'total' => Coerce::int($b['total'] ?? 0), 'min' => Coerce::int($b['min'] ?? 0), 'max' => Coerce::int($b['max'] ?? 0),
                'missing' => array_values(array_map(fn ($m): string => Coerce::str($m), Coerce::arr($b['missing'] ?? [])))];
        }

        return $out;
    }

    /**
     * The REAL changes between two snapshots (the brief's thresholds): a LIVE product added or GONE from a list, the
     * price range moved more than 5 %, the LIVE count changed more than 10 %, a compared model GONE. An empty list =
     * no content change (dates stay as they are).
     *
     * @param  array<mixed>  $before
     * @param  array<mixed>  $now
     * @return list<string>
     */
    public static function realChanges(array $before, array $now): array
    {
        $changes = [];
        foreach ($now as $token => $n) {
            $n = Coerce::arr($n);
            $b = Coerce::arr($before[$token] ?? null);
            if ($b === []) {
                $changes[] = "{$token}: new block";

                continue;
            }
            // the block's whole LIVE set (a product added or GONE anywhere in its scope), else the models it lists
            if (Coerce::str($n['set'] ?? '') !== '' ? Coerce::str($n['set'] ?? '') !== Coerce::str($b['set'] ?? '') : Coerce::arr($n['ids'] ?? []) != Coerce::arr($b['ids'] ?? [])) {
                $changes[] = "{$token}: LIVE products added or GONE";
            }
            $moved = fn (int $was, int $is): bool => $was > 0 && abs($is - $was) / $was > 0.05;
            if ($moved(Coerce::int($b['min'] ?? 0), Coerce::int($n['min'] ?? 0)) || $moved(Coerce::int($b['max'] ?? 0), Coerce::int($n['max'] ?? 0))) {
                $changes[] = "{$token}: the price range moved more than 5%";
            }
            $was = Coerce::int($b['total'] ?? 0);
            if (($was === 0) !== (Coerce::int($n['total'] ?? 0) === 0) || ($was > 0 && abs(Coerce::int($n['total'] ?? 0) - $was) / $was > 0.10)) {
                $changes[] = "{$token}: the LIVE count changed more than 10%";
            }
            $strs = fn (mixed $l): array => array_map(fn (mixed $m): string => Coerce::str($m), Coerce::arr($l));
            if (array_diff($strs($n['missing'] ?? []), $strs($b['missing'] ?? [])) !== []) {
                $changes[] = "{$token}: a compared model is GONE";
            }
        }

        return $changes;
    }
}
