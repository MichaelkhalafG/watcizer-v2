<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Activity\ActivityLog;
use App\Domain\Content\HomeRails;
use App\Storefront\StorefrontCache;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * The NEW HOME of a storefront, as rows of its Home sliders screen (the developer's review, 2026-11-05).
 *
 *   php artisan home:rebuild --storefront=watchizer            the plan; writes nothing
 *   php artisan home:rebuild --storefront=watchizer --apply    switches every current row OFF and writes the new ones
 *   php artisan home:rebuild --storefront=watchizer --restore  puts the rows back exactly as they were
 *
 * WHAT IT DOES. Every current home rail and section of the storefront is DEACTIVATED — never deleted — and a new
 * home is laid out from everything the storefront can show: the Hot Deals and Flash Sale sections, the categories
 * with their pictures, the Banners block, new arrivals, two brand banners, best value, the brand strip, a
 * category's own slider on a colour, a slider under a price, two sliders curated by type, the discounts. Every row
 * is an ordinary row of the Home sliders screen: the team moves it, switches it off or edits it there afterwards.
 *
 * FROM REAL DATA, AT THE MOMENT IT RUNS. Which brands get a banner (the ones with the most products in stock that
 * have a logo and three pictured products), which category gets its own slider (the largest), the price of the
 * under-a-price slider (the price a third of the in-stock products are at or below, rounded to 500), which types
 * are curated (the two largest sub-categories) — all are read from this storefront's own catalogue, and the dry run
 * prints each choice. A piece the data cannot fill (no brand with three pictured products, say) is left out and
 * said so; nothing is invented.
 *
 * RESTORE. `--apply` records, in the storefront's settings (`home.rebuild`), every row it switched off with the
 * state it had, and every row it created. `--restore` gives each old row back its on/off state and its place,
 * switches the created rows off (it does not delete them either), and removes the record. A second `--apply` is
 * refused while a record exists: restore first, so the "before" is never the output of an earlier run.
 *
 * The HERO is not touched: what leads the home (the campaign, the 3D watch or the Banners block) is the Banners
 * screen's setting. The storefront's caches are flushed after a write.
 *
 * @phpstan-type Data array{kind: string, target_id: ?int, product_ids?: ?list<int>, title_en: ?string, title_ar: ?string, is_active: bool, card_count: int, background?: ?string, blend?: bool, colors?: list<string>, description_ar?: ?string, description_en?: ?string, max_price?: ?int}
 */
final class HomeRebuildCommand extends Command
{
    /** Each shop's own palette for the sliders that sit on a colour and for its brand banners. */
    private const LOOK = [
        // The two coloured sliders take two of the shop's PRESETS (HomeRails::PRESETS; the review of 2026-11-05 — the
        // beige of the first cut is gone). Watchizer: midnight and racing green, the photos left on the cards' own
        // white (a white studio shot reads best that way on a dark band). Brand Fashion: sky and blush, blended —
        // only the photo's white background gives way to the colour, the product is untouched (CutoutMask).
        'watchizer' => ['rail' => '#111A2C', 'rail2' => '#13261F', 'blend' => false, 'banner' => ['#15130F', '#C8A45C', '#F3EBDD'], 'banner2' => ['#1F2A37', '#B08D3E', '#EDE6DA']],
        'brandfashion' => ['rail' => '#DCEBFA', 'rail2' => '#FBE1E4', 'blend' => true, 'banner' => ['#14213D', '#D0182E', '#F4F4F4'], 'banner2' => ['#0F3D2E', '#F2B705', '#F1F6F3']],
    ];

    /**
     * EACH SHOP ITS OWN HOME. Watchizer (a campaign hero leads): the deals first, then the categories, the Banners
     * block as a section, and the long read of sliders broken by two brand banners. Brand Fashion (the Banners
     * block leads): the categories under it, then both sales and the best value, and the brands earlier.
     *
     * @var array<string, list<string>>
     */
    private const ORDER = [
        // Watchizer (the developer's order, 2026-11-05, item 38): from the home alone a shopper sees the shop has
        // everything, for men and for women, across the categories — and Hot Deals and the Flash Sale are never
        // neighbours. Every entry is a section that already existed; the six "who and what" sliders are CURATED
        // rows (hand-pickable on the Home sliders screen), filled here with products in stock, brands mixed.
        'watchizer' => ['categories', 'hot', 'mens_watches', 'banners', 'womens_watches', 'flash', 'bags_wallets', 'banner1', 'mens_accessories', 'womens_bags', 'banner2', 'brands', 'electronics', 'newest'],
        // Brand Fashion (item 39, decided 2026-11-05 — the developer likes its order; SMALL changes only, on item 38's
        // principles): (1) the best value moves between Hot Deals and the Flash Sale, which were neighbours; (2) the
        // generic "largest category" slider (watches) becomes "Men's watches", brands mixed; (3) the two "by type"
        // sliders (Chronograph — watches again — and Bags) become "Women's bags & perfumes" and "Men's accessories",
        // so men, women and three kinds of product are on the page by its middle; (4) the new arrivals move below
        // the brands strip, so two all-catalogue sliders are not neighbours. Everything else stays where it was.
        'brandfashion' => ['banners', 'categories', 'hot', 'best', 'flash', 'mens_watches', 'banner1', 'womens_bags', 'brands', 'newest', 'banner2', 'price', 'mens_accessories', 'offers'],
    ];

    protected $signature = 'home:rebuild
        {--storefront=watchizer : the storefront code}
        {--apply : switch the current rows off and write the new home; without it nothing is written}
        {--restore : put the rows back as they were before --apply}';

    protected $description = 'Lay out a storefront\'s new home as rows of the Home sliders screen (deactivates the current ones, never deletes); --restore undoes it';

    public function handle(StorefrontCache $cache): int
    {
        $code = Coerce::str($this->option('storefront'));
        $row = DB::table('storefronts')->where('code', $code)->first();
        if ($row === null || ! isset(self::LOOK[$code])) {
            $this->error('No storefront with that code.');

            return self::FAILURE;
        }
        $storefront = Row::int($row, 'id');
        $settings = Coerce::arr(json_decode(Row::nstr($row, 'settings') ?? '{}', true));
        $home = Coerce::arr($settings['home'] ?? []);
        $record = Coerce::arr($home['rebuild'] ?? []);

        if ((bool) $this->option('restore')) {
            return $this->restore($storefront, $code, $settings, $record, $cache);
        }
        if ($record !== []) {
            $this->error('A new home was already applied here on '.Coerce::str($record['at'] ?? '?').'. Run --restore first: the rows to go back to are the ones recorded then.');

            return self::FAILURE;
        }

        $plan = $this->plan($storefront, $code);
        $current = HomeRails::all($storefront);
        $this->line('Current rows: '.count($current).' ('.count(array_filter($current, fn (array $r): bool => $r['is_active'])).' on) — every one is switched OFF, none is deleted.');
        $this->line('The new home, top to bottom (the hero above it is the Banners screen\'s setting and is not touched):');
        foreach ($plan as $i => $entry) {
            $this->line(sprintf('  %2d. %-13s %s', $i + 1, $entry['data']['kind'], $entry['note']));
        }
        if (! (bool) $this->option('apply')) {
            $this->info('Dry run: add --apply to write this. --restore afterwards puts the old rows back.');

            return self::SUCCESS;
        }

        $created = [];
        $before = [];
        DB::transaction(function () use ($storefront, $plan, $current, &$created, &$before): void {
            foreach ($current as $rail) {
                $before[(string) $rail['id']] = ['is_active' => $rail['is_active'], 'position' => $rail['position']];
            }
            DB::table('storefront_home_rails')->where('storefront_id', $storefront)->update(['is_active' => false, 'updated_at' => now()]);

            $order = [];
            foreach ($plan as $entry) {
                $data = $entry['data'];
                // a SECTION has one row per storefront: the existing one is switched back on and takes its new place
                $existing = in_array($data['kind'], HomeRails::SECTIONS, true) ? HomeRails::sectionId($storefront, $data['kind']) : null;
                if ($existing !== null) {
                    DB::table('storefront_home_rails')->where('id', $existing)->update(['is_active' => true, 'updated_at' => now()]);
                    $order[] = $existing;

                    continue;
                }
                $id = HomeRails::create($storefront, $data);
                $created[] = $id;
                $order[] = $id;
            }
            // the new rows first, in the plan's order; the old ones (all off) after them, in the order they had
            $rest = array_values(array_diff(array_map(fn (array $r): int => $r['id'], $current), $order));
            HomeRails::reorder($storefront, [...$order, ...$rest]);
        });

        $home['rebuild'] = ['at' => now()->toDateTimeString(), 'created' => $created, 'before' => $before];
        $settings['home'] = $home;
        DB::table('storefronts')->where('id', $storefront)->update(['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => now()]);
        ActivityLog::record('storefronts', $storefront, ActivityLog::UPDATED, ['home_rebuild' => null], ['home_rebuild' => 'applied: '.count($plan).' rows laid out, '.count($before).' old rows switched off'], label: $code.' — home:rebuild', storefrontId: $storefront);
        $cache->forgetStorefront($storefront, $code);
        $cache->flush($storefront);

        $this->info(sprintf('Written: %d rows in the new home (%d created), %d old rows switched off. Undo: php artisan home:rebuild --storefront=%s --restore', count($plan), count($created), count($before), $code));

        return self::SUCCESS;
    }

    /**
     * @param  array<mixed>  $settings
     * @param  array<mixed>  $record
     */
    private function restore(int $storefront, string $code, array $settings, array $record, StorefrontCache $cache): int
    {
        if ($record === []) {
            $this->error('Nothing to restore: no new home was applied on this storefront.');

            return self::FAILURE;
        }
        $before = Coerce::arr($record['before'] ?? []);
        $created = array_map(fn (mixed $id): int => Coerce::int($id), Coerce::arr($record['created'] ?? []));

        DB::transaction(function () use ($storefront, $before, $created): void {
            // everything off, then each old row back to the state and the place it had; the created rows stay, off
            DB::table('storefront_home_rails')->where('storefront_id', $storefront)->update(['is_active' => false, 'updated_at' => now()]);
            foreach ($before as $id => $state) {
                $state = Coerce::arr($state);
                DB::table('storefront_home_rails')->where('storefront_id', $storefront)->where('id', (int) $id)
                    ->update(['is_active' => (bool) ($state['is_active'] ?? false), 'position' => Coerce::int($state['position'] ?? 0), 'updated_at' => now()]);
            }
            $last = DB::table('storefront_home_rails')->where('storefront_id', $storefront)->whereNotIn('id', $created)->max('position');
            $place = is_numeric($last) ? (int) $last : 0;
            foreach ($created as $id) {
                $place += 10;
                DB::table('storefront_home_rails')->where('storefront_id', $storefront)->where('id', $id)->update(['position' => $place]);
            }
        });

        $home = Coerce::arr($settings['home'] ?? []);
        unset($home['rebuild']);
        $settings['home'] = $home;
        DB::table('storefronts')->where('id', $storefront)->update(['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => now()]);
        ActivityLog::record('storefronts', $storefront, ActivityLog::UPDATED, ['home_rebuild' => 'applied'], ['home_rebuild' => 'restored: '.count($before).' rows back as they were'], label: $code.' — home:rebuild --restore', storefrontId: $storefront);
        $cache->forgetStorefront($storefront, $code);
        $cache->flush($storefront);

        $this->info(sprintf('Restored: %d rows are back as they were; the %d rows of the new home are switched off (not deleted).', count($before), count($created)));

        return self::SUCCESS;
    }

    /** The products this storefront shows that a shopper can buy and that have a picture. */
    private static function buyable(int $storefront): Builder
    {
        return DB::table('catalog_products as p')
            ->join('storefront_product as sp', fn (JoinClause $j) => $j->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', $storefront)->where('sp.is_visible', 1))
            ->whereNull('p.deleted_at')->where('p.is_active', 1)->where('p.in_stock', 1)
            ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('catalog_product_images as i')->whereColumn('i.product_id', 'p.id'));
    }

    /**
     * The new home, top to bottom — each row as `HomeRails::create()` takes it, with a line saying what it is.
     *
     * @return list<array{data: Data, note: string}>
     */
    private function plan(int $storefront, string $code): array
    {
        $look = self::LOOK[$code];
        // the brands with the most buyable products that have a logo — a banner needs three pictured products
        $banners = [];
        foreach (self::buyable($storefront)->join('catalog_brands as b', 'b.id', '=', 'p.brand_id')->where('b.is_active', 1)
            ->whereNotNull('b.logo_path')->where('b.logo_path', '!=', '')
            ->groupBy('b.id', 'b.slug')->havingRaw('COUNT(*) >= 3')->orderByRaw('COUNT(*) DESC')->orderBy('b.id')->limit(2)
            ->get(['b.id', 'b.slug']) as $brand) {
            $brandId = Row::int($brand, 'id');
            $name = self::name('catalog_brand_translations', 'brand_id', $brandId, Row::str($brand, 'slug'));
            // the brand's RANGE: a watch, a bag, an accessory — never three of a kind when it has more (HomeRails)
            $banners[] = ['id' => $brandId, 'name' => $name, 'products' => HomeRails::suggestBannerProducts($storefront, $brandId)];
        }

        // the largest top-level category, for a slider of its own
        $top = DB::table('storefront_categories as c')
            ->join('storefront_category_product as scp', 'scp.storefront_category_id', '=', 'c.id')
            ->where('c.storefront_id', $storefront)->where('c.depth', 1)
            ->groupBy('c.id', 'c.slug')->orderByRaw('COUNT(*) DESC')->first(['c.id', 'c.slug']);

        // the two largest SUB-categories, each curated as its newest twelve buyable products
        $types = [];
        foreach (DB::table('storefront_categories as c')
            ->join('storefront_category_product as scp', 'scp.storefront_category_id', '=', 'c.id')
            ->joinSub(self::buyable($storefront)->select('p.id'), 'ok', 'ok.id', '=', 'scp.product_id')
            ->where('c.storefront_id', $storefront)->where('c.depth', 2)
            ->groupBy('c.id', 'c.slug')->havingRaw('COUNT(*) >= 4')->orderByRaw('COUNT(*) DESC')->limit(2)->get(['c.id', 'c.slug']) as $type) {
            $typeId = Row::int($type, 'id');
            $types[] = [
                'name' => self::name('storefront_category_translations', 'storefront_category_id', $typeId, Row::str($type, 'slug')),
                'products' => self::newest(self::buyable($storefront)->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('storefront_category_product as x')->whereColumn('x.product_id', 'p.id')->where('x.storefront_category_id', $typeId)), 12),
            ];
        }

        // the price a third of the buyable products are at or below, rounded up to 500
        $paid = 'CASE WHEN sp.effective_sale_price > 0 AND sp.effective_sale_price < sp.effective_price THEN sp.effective_sale_price ELSE sp.effective_price END';
        $prices = array_values(array_map(fn (mixed $v): float => is_numeric($v) ? (float) $v : 0.0, self::buyable($storefront)->selectRaw($paid.' AS paid')->orderBy('paid')->pluck('paid')->all()));
        $cap = $prices === [] ? 0 : (int) (ceil($prices[(int) floor((count($prices) - 1) / 3)] / 500) * 500);

        /** @var array<string, array{data: Data, note: string}> $pieces */
        $pieces = [
            'hot' => ['data' => self::row(HomeRails::HOT_DEALS), 'note' => 'the Hot Deals section (drawn only while a campaign is live) — in the place the "featured" banner had'],
            'categories' => ['data' => self::row(HomeRails::CATEGORIES), 'note' => 'the categories, as picture tiles'],
            'flash' => ['data' => self::row(HomeRails::FLASH_SALE), 'note' => 'the Flash Sale section (drawn only while one is live)'],
            'banners' => ['data' => self::row(HomeRails::BANNERS), 'note' => 'the Banners block (the large banner and its two side slots; first on the page by itself when no hero leads)'],
            'newest' => ['data' => self::row(HomeRails::NEWEST), 'note' => 'new arrivals: the newest products in stock'],
            'best' => ['data' => self::row(HomeRails::BEST_VALUE, ['background' => $look['rail'], 'blend' => $look['blend']]), 'note' => 'best value: the biggest real discounts in stock, on a colour'],
            'brands' => ['data' => self::row(HomeRails::BRANDS), 'note' => 'the brands strip'],
            'offers' => ['data' => self::row(HomeRails::OFFERS), 'note' => 'on sale: every discounted product'],
        ];
        foreach ([['banner1', 0, 'banner', ' (the brand with the most products in stock that has a logo)'], ['banner2', 1, 'banner2', '']] as [$key, $i, $colors, $why]) {
            if (isset($banners[$i]) && count($banners[$i]['products']) === HomeRails::BANNER_PRODUCTS) {
                $pieces[$key] = ['data' => self::banner($banners[$i], $look[$colors]), 'note' => 'brand banner: '.$banners[$i]['name']['en'].$why];
            }
        }
        if ($top !== null) {
            $pieces['category'] = ['data' => self::row(HomeRails::CATEGORY_TYPE, ['target_id' => Row::int($top, 'id'), 'background' => $look['rail'], 'blend' => $look['blend']]), 'note' => 'the largest category ('.Row::str($top, 'slug').'), on a colour'];
        }
        if ($cap > 0) {
            $pieces['price'] = ['data' => self::row(HomeRails::PRICE, ['max_price' => $cap]), 'note' => 'under EGP '.number_format($cap).' (a third of the products in stock cost that or less)'];
        }
        foreach ($types as $i => $type) {
            if (count($type['products']) >= 4) {
                $pieces['type'.($i + 1)] = ['data' => self::row(HomeRails::CUSTOM, ['product_ids' => $type['products'], 'title_en' => $type['name']['en'], 'title_ar' => $type['name']['ar'], 'card_count' => count($type['products'])]), 'note' => 'curated by type: '.$type['name']['en'].' ('.count($type['products']).' newest in stock)'];
            }
        }

        foreach ($this->curated($storefront, $look, self::ORDER[$code]) as $key => $piece) {
            $pieces[$key] = $piece;
        }

        // EACH SHOP ITS OWN ORDER: a piece the data could not fill is simply not in the list
        $plan = [];
        foreach (self::ORDER[$code] as $key) {
            if (isset($pieces[$key])) {
                $plan[] = $pieces[$key];
            }
        }

        return $plan;
    }

    /** A curated slider needs at least this many products in stock to be worth a row of the home. */
    private const THIN = 6;

    /**
     * THE "WHO AND WHAT" SLIDERS (item 38): men's watches, women's watches, bags and wallets, men's accessories,
     * women's bags and perfumes, electronics — each a CURATED row (kind `custom`) of up to twelve products in stock,
     * the brands taken in turn so no brand fills a row, and no product shown in two of them. Built from what the
     * catalogue has: the categories by their slugs, the gender by its English name.
     *
     * A slider with fewer than {@see self::THIN} products in stock is not padded with unrelated products: it is
     * MERGED where a neighbour makes sense (men's accessories take men's bags) or left out, and its note says so.
     *
     * @param  array{rail: string, rail2: string, blend: bool, banner: list<string>, banner2: list<string>}  $look
     * @param  list<string>  $order  the shop's order: only the sliders it names are built
     * @return array<string, array{data: Data, note: string}>
     */
    private function curated(int $storefront, array $look, array $order): array
    {
        $gender = [];
        foreach (DB::table('catalog_gender_translations')->where('locale', 'en')->get(['gender_id', 'name']) as $raw) {
            $gender[strtolower(trim(Row::str($raw, 'name')))] = Row::int($raw, 'gender_id');
        }
        $of = fn (string $who): Builder => self::buyable($storefront)->whereExists(
            fn (Builder $q) => $q->selectRaw('1')->from('catalog_product_gender as pg')->whereColumn('pg.product_id', 'p.id')->where('pg.gender_id', $gender[$who] ?? 0),
        );
        /** @param list<string> $slugs a category by its slug, or everything under a top-level one */
        $in = fn (Builder $set, array $slugs): Builder => $set->whereExists(
            fn (Builder $q) => $q->selectRaw('1')->from('storefront_category_product as x')
                ->join('storefront_categories as c', 'c.id', '=', 'x.storefront_category_id')
                ->leftJoin('storefront_categories as t', 't.id', '=', 'c.parent_id')
                ->whereColumn('x.product_id', 'p.id')->where('x.storefront_id', $storefront)
                ->where(fn (Builder $w) => $w->whereIn('c.slug', $slugs)->orWhereIn('t.slug', $slugs)),
        );

        // key => [English title, Arabic title, the set, the colour of its section (or null), what it merges with when thin]
        $wanted = [
            'mens_watches' => ['Men\'s watches', 'ساعات رجالي', $in($of('men'), ['watches']), null], // i18n-exempt: storefront page text
            'womens_watches' => ['Women\'s watches', 'ساعات حريمي', $in($of('women'), ['watches']), null], // i18n-exempt: storefront page text
            'bags_wallets' => ['Bags & wallets', 'حقائب ومحافظ', $in(self::buyable($storefront), ['bags', 'wallets']), $look['rail']], // i18n-exempt: storefront page text
            'mens_accessories' => ['Men\'s accessories', 'إكسسوارات رجالي', $in($of('men'), ['belts', 'wallets', 'bracelets']), null], // i18n-exempt: storefront page text
            'womens_bags' => ['Women\'s bags & perfumes', 'حقائب وعطور حريمي', $in($of('women'), ['bags', 'perfumes']), $look['rail2']], // i18n-exempt: storefront page text
            'electronics' => ['Electronics', 'إلكترونيات', $in(self::buyable($storefront), ['electronics']), null], // i18n-exempt: storefront page text
        ];
        // the neighbour a thin slider takes in, rather than unrelated products
        $merge = [
            'mens_accessories' => ['Men\'s bags & accessories', 'حقائب وإكسسوارات رجالي', $in($of('men'), ['belts', 'wallets', 'bracelets', 'bags'])], // i18n-exempt: storefront page text
        ];

        $out = [];
        $used = [];
        foreach ($wanted as $key => [$en, $ar, $set, $colour]) {
            if (! in_array($key, $order, true)) {
                continue;
            }
            $count = (clone $set)->count();
            $why = '';
            if ($count < self::THIN && isset($merge[$key])) {
                [$en, $ar, $set] = $merge[$key];
                $why = sprintf(' — MERGED: the slider asked for had only %d in stock', $count);
                $count = (clone $set)->count();
            }
            $picked = self::mixed($set, $used);
            if ($count < self::THIN || count($picked['ids']) < self::THIN) {
                $this->line(sprintf('      (left out: "%s" has %d in stock — too thin for a row; nothing unrelated is added to pad it)', $en, $count));

                continue;
            }
            $used = [...$used, ...$picked['ids']];
            $out[$key] = [
                'data' => self::row(HomeRails::CUSTOM, [
                    'product_ids' => $picked['ids'], 'title_en' => $en, 'title_ar' => $ar, 'card_count' => count($picked['ids']),
                ] + ($colour === null ? [] : ['background' => $colour, 'blend' => $look['blend']])),
                'note' => sprintf('%s — %d in stock; %d shown, from %d brands%s', $en, $count, count($picked['ids']), $picked['brands'], $why),
            ];
        }

        return $out;
    }

    /**
     * Up to `$limit` of a set's newest products in stock, THE BRANDS TAKEN IN TURN (the brand of the newest product
     * first), leaving out the ones another slider already shows.
     *
     * @param  list<int>  $skip
     * @return array{ids: list<int>, brands: int}
     */
    private static function mixed(Builder $set, array $skip, int $limit = 12): array
    {
        /** @var array<int, list<int>> $byBrand brand id => its product ids, newest first; the keys in order of first sight */
        $byBrand = [];
        foreach ((clone $set)->when($skip !== [], fn (Builder $q) => $q->whereNotIn('p.id', $skip))
            ->orderByDesc('p.created_at')->orderByDesc('p.id')->limit(240)->get(['p.id', 'p.brand_id']) as $raw) {
            $byBrand[Row::nint($raw, 'brand_id') ?? 0][] = Row::int($raw, 'id');
        }
        $ids = [];
        $brands = [];
        while (count($ids) < $limit && $byBrand !== []) {
            foreach ($byBrand as $brand => $products) {
                $next = array_shift($products);
                if ($next !== null) {
                    $ids[] = $next;
                    $brands[$brand] = true;
                }
                if ($products === []) {
                    unset($byBrand[$brand]);
                } else {
                    $byBrand[$brand] = $products;
                }
                if (count($ids) >= $limit) {
                    break;
                }
            }
        }

        return ['ids' => $ids, 'brands' => count($brands)];
    }

    /**
     * The newest buyable products of a set, newest first.
     *
     * @return list<int>
     */
    private static function newest(Builder $set, int $limit): array
    {
        return array_values(array_map(fn (mixed $id): int => Coerce::int($id), $set->orderByDesc('p.created_at')->orderByDesc('p.id')->limit($limit)->pluck('p.id')->all()));
    }

    /**
     * One row, as `HomeRails::create()` takes it: on, twelve cards, and whatever `$more` says.
     *
     * @param  array{target_id?: int, product_ids?: list<int>, title_en?: string, title_ar?: string, card_count?: int, background?: string, blend?: bool, colors?: list<string>, description_ar?: string, description_en?: string, max_price?: int}  $more
     * @return Data
     */
    private static function row(string $kind, array $more = []): array
    {
        return [
            'kind' => $kind, 'target_id' => $more['target_id'] ?? null, 'product_ids' => $more['product_ids'] ?? null,
            'title_en' => $more['title_en'] ?? null, 'title_ar' => $more['title_ar'] ?? null, 'is_active' => true,
            'card_count' => $more['card_count'] ?? 12, 'background' => $more['background'] ?? null, 'blend' => $more['blend'] ?? false,
            'colors' => $more['colors'] ?? [], 'description_ar' => $more['description_ar'] ?? null, 'description_en' => $more['description_en'] ?? null,
            'max_price' => $more['max_price'] ?? null,
        ];
    }

    /**
     * @param  array{id: int, name: array{en: string, ar: string}, products: list<int>}  $brand
     * @param  list<string>  $colors
     * @return Data
     */
    private static function banner(array $brand, array $colors): array
    {
        return self::row(HomeRails::BRAND_BANNER, [
            'target_id' => $brand['id'], 'product_ids' => $brand['products'], 'card_count' => count($brand['products']), 'colors' => $colors,
            // plain words the team will rewrite: what the banner is, nothing claimed about the products
            'title_en' => $brand['name']['en'], 'title_ar' => $brand['name']['ar'],
            'description_en' => 'The newest '.$brand['name']['en'].' pieces in stock. See the whole brand.',
            'description_ar' => 'أحدث ما وصل من '.$brand['name']['ar'].' والمتوفر الآن. شاهد الماركة كاملة.', // i18n-exempt: storefront page text
        ]);
    }

    /** @return array{en: string, ar: string} a row's name in both languages, the slug when it has none */
    private static function name(string $table, string $key, int $id, string $slug): array
    {
        $names = [];
        foreach (DB::table($table)->where($key, $id)->get(['locale', 'name']) as $raw) {
            $names[Row::str($raw, 'locale')] = trim(Row::str($raw, 'name'));
        }
        $en = ($names['en'] ?? '') !== '' ? $names['en'] : $slug;

        return ['en' => $en, 'ar' => ($names['ar'] ?? '') !== '' ? $names['ar'] : $en];
    }
}
