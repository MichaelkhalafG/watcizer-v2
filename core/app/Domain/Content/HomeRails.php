<?php

declare(strict_types=1);

namespace App\Domain\Content;

use App\Storefront\HomeHero;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The home page's rails (`storefront_home_rails`, M1y) — reading them for `catalog/home` and the
 * dashboard, and writing them for the dashboard. One class so the two sides cannot disagree about
 * what a valid rail is.
 *
 * @phpstan-type Rail array{id: int, kind: string, target_id: ?int, product_ids: list<int>, title_en: ?string, title_ar: ?string, position: int, is_active: bool, card_count: int, background: ?string, blend: bool, colors: list<string>, description_ar: ?string, description_en: ?string, max_price: ?int}
 */
final class HomeRails
{
    public const OFFERS = 'offers';

    public const FEATURED = 'featured';

    public const NEWEST = 'newest';

    public const GRADE = 'grade';

    public const BRAND = 'brand';

    public const CATEGORY_TYPE = 'category_type';

    /**
     * Products picked by hand, in order (M2e, 2026-10-02). It has no target to be named after, so
     * BOTH titles are required; its card count is the number of products picked.
     */
    public const CUSTOM = 'custom';

    /**
     * A BRAND BANNER (the twelve updates, Part 3.3, 2026-11-05): not a slider of cards but one band — a brand,
     * three of its products, three colours, a title and a sentence — that links to the brand's page. It lives
     * here because the team orders it among the sliders, on the sliders page.
     */
    public const BRAND_BANNER = 'brand_banner';

    /**
     * THE BANNERS BLOCK AS A HOME SECTION (the design pass, 2026-11-05): the large banner with its two side
     * slots, placed among the sliders. It is a row here — one per storefront, no target, no products — because
     * its place is set "in the home ordering exactly like the slider rows" (the developer): it moves with the
     * same arrows and is switched on and off with the same switch.
     *
     * WHEN IT COUNTS: only while something else leads the home (the 3D watch or the campaign). When the hero
     * setting is `banner` the block IS the top of the page and this row is passed over — so switching the
     * campaign off puts the banners first without anybody reordering anything ({@see HomeHero}).
     */
    public const BANNERS = 'banners';

    /*
     * EVERY HOME SECTION IS A ROW (the developer's review, 2026-11-05: "the order of EVERY home section is controlled
     * from the dashboard"). Until then the campaign sliders, the category tiles and the brand strip sat at fixed
     * places in each storefront's code. They are SECTIONS now, like the Banners block: one row per storefront each,
     * no target and no products of their own, moved and switched like a slider.
     */

    /** The Hot Deals slider — drawn only while a Hot Deals campaign is live. */
    public const HOT_DEALS = 'hot_deals';

    /** The Flash Sale slider — drawn only while a Flash Sale is live. */
    public const FLASH_SALE = 'flash_sale';

    /** The categories, as picture tiles. */
    public const CATEGORIES = 'categories';

    /** The brands the storefront shows, as a strip of logos. */
    public const BRANDS = 'brands';

    /** BEST VALUE: the in-stock products with the biggest REAL discount, biggest first. */
    public const BEST_VALUE = 'best_value';

    /** UNDER A PRICE: the in-stock products a shopper pays `max_price` or less for, newest first. */
    public const PRICE = 'price';

    public const KINDS = [
        self::OFFERS, self::FEATURED, self::NEWEST, self::GRADE, self::BRAND, self::CATEGORY_TYPE, self::CUSTOM, self::BRAND_BANNER,
        self::BANNERS, self::HOT_DEALS, self::FLASH_SALE, self::CATEGORIES, self::BRANDS, self::BEST_VALUE, self::PRICE,
    ];

    /** The kinds that are a SECTION of the page rather than a slider of products: one per storefront, nothing to fill in. */
    public const SECTIONS = [self::BANNERS, self::HOT_DEALS, self::FLASH_SALE, self::CATEGORIES, self::BRANDS];

    /** Kinds that name a target: a grade, a brand, a top-level category — and a brand banner's brand. */
    public const TARGETED = [self::GRADE, self::BRAND, self::CATEGORY_TYPE, self::BRAND_BANNER];

    /** The kinds whose products are PICKED by hand (stored in `product_ids`), not derived from a rule. */
    public const PICKED = [self::CUSTOM, self::BRAND_BANNER];

    /** A brand banner shows exactly this many products, and is filled by exactly this many colours. */
    public const BANNER_PRODUCTS = 3;

    public const BANNER_COLORS = 3;

    public const MAX_CARDS = 24;

    /**
     * Every rail of a storefront, in order — the dashboard's list.
     *
     * @return list<Rail>
     */
    public static function all(int $storefrontId): array
    {
        $out = [];
        foreach (DB::table('storefront_home_rails')->where('storefront_id', $storefrontId)->orderBy('position')->orderBy('id')->get() as $raw) {
            $out[] = self::rail($raw);
        }

        return $out;
    }

    /** @return list<Rail> */
    public static function active(int $storefrontId): array
    {
        return array_values(array_filter(self::all($storefrontId), fn (array $r): bool => $r['is_active']));
    }

    /** @return Rail|null */
    public static function find(int $storefrontId, int $id): ?array
    {
        $raw = DB::table('storefront_home_rails')->where('storefront_id', $storefrontId)->where('id', $id)->first();

        return is_object($raw) ? self::rail($raw) : null;
    }

    /**
     * Add a rail at the END of the list.
     *
     * @param  array{kind: string, target_id: ?int, product_ids?: ?list<int>, title_en: ?string, title_ar: ?string, is_active: bool, card_count: int, background?: ?string, blend?: bool, colors?: list<string>, description_ar?: ?string, description_en?: ?string, max_price?: ?int}  $data
     */
    public static function create(int $storefrontId, array $data): int
    {
        self::assertValid($data);
        self::assertOneBlock($storefrontId, $data['kind'], null);
        $last = DB::table('storefront_home_rails')->where('storefront_id', $storefrontId)->max('position');

        return (int) DB::table('storefront_home_rails')->insertGetId([
            ...self::columns($data),
            'storefront_id' => $storefrontId,
            'position' => (is_numeric($last) ? (int) $last : 0) + 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param  array{kind: string, target_id: ?int, product_ids?: ?list<int>, title_en: ?string, title_ar: ?string, is_active: bool, card_count: int, background?: ?string, blend?: bool, colors?: list<string>, description_ar?: ?string, description_en?: ?string, max_price?: ?int}  $data */
    public static function update(int $storefrontId, int $id, array $data): void
    {
        self::assertValid($data);
        self::assertOneBlock($storefrontId, $data['kind'], $id);
        DB::table('storefront_home_rails')->where('storefront_id', $storefrontId)->where('id', $id)
            ->update([...self::columns($data), 'updated_at' => now()]);
    }

    public static function delete(int $storefrontId, int $id): void
    {
        DB::table('storefront_home_rails')->where('storefront_id', $storefrontId)->where('id', $id)->delete();
    }

    /** The id of this storefront's Banners block row, or null when it has none. */
    public static function blockId(int $storefrontId): ?int
    {
        return self::sectionId($storefrontId, self::BANNERS);
    }

    /** The id of this storefront's row of one SECTION kind, or null when it has none. */
    public static function sectionId(int $storefrontId, string $kind): ?int
    {
        $id = DB::table('storefront_home_rails')->where('storefront_id', $storefrontId)->where('kind', $kind)->orderBy('id')->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * The section kinds this storefront HAS A ROW for — on or off. A storefront draws such a section only where its
     * row is (and not at all when the row is off); a section with no row keeps the fixed place it had in the
     * storefront's code before 2026-11-05, so nothing moves until the team adds the row.
     *
     * @return list<string>
     */
    public static function managedSections(int $storefrontId): array
    {
        $out = [];
        foreach (DB::table('storefront_home_rails')->where('storefront_id', $storefrontId)->whereIn('kind', self::SECTIONS)->distinct()->orderBy('kind')->pluck('kind') as $kind) {
            if (is_string($kind)) {
                $out[] = $kind;
            }
        }

        return $out;
    }

    /** A storefront has ONE row of each section: a second would draw the same section twice. */
    private static function assertOneBlock(int $storefrontId, string $kind, ?int $exceptId): void
    {
        if (! in_array($kind, self::SECTIONS, true)) {
            return;
        }
        $other = self::sectionId($storefrontId, $kind);
        if ($other !== null && $other !== $exceptId) {
            throw new InvalidArgumentException('This storefront already has its row of that section.');
        }
    }

    /**
     * Put the rails in exactly this order (positions 10, 20, 30…). The list must name every rail of
     * the storefront once — a partial or stale list (another tab added a rail meanwhile) is refused
     * rather than guessed at.
     *
     * @param  list<int>  $ids
     */
    public static function reorder(int $storefrontId, array $ids): void
    {
        $existing = array_map(fn (array $r): int => $r['id'], self::all($storefrontId));
        $sorted = $ids;
        sort($sorted);
        sort($existing);
        if ($sorted !== $existing) {
            throw new InvalidArgumentException('The order must list every rail exactly once.');
        }
        DB::transaction(function () use ($storefrontId, $ids): void {
            foreach ($ids as $i => $id) {
                DB::table('storefront_home_rails')->where('storefront_id', $storefrontId)->where('id', $id)
                    ->update(['position' => ($i + 1) * 10, 'updated_at' => now()]);
            }
        });
    }

    /** @param  array{kind: string, target_id: ?int, product_ids?: ?list<int>, title_en: ?string, title_ar: ?string, is_active: bool, card_count: int, background?: ?string, blend?: bool, colors?: list<string>, description_ar?: ?string, description_en?: ?string, max_price?: ?int}  $data */
    private static function assertValid(array $data): void
    {
        if (! in_array($data['kind'], self::KINDS, true)) {
            throw new InvalidArgumentException("Unknown rail kind {$data['kind']}.");
        }
        if (in_array($data['kind'], self::TARGETED, true) !== ($data['target_id'] !== null)) {
            throw new InvalidArgumentException('A grade, brand or category rail needs a target; the others take none.');
        }
        if ($data['kind'] === self::CUSTOM) {
            $picks = $data['product_ids'] ?? [];
            if ($picks === [] || count($picks) > self::MAX_CARDS || count(array_unique($picks)) !== count($picks)) {
                throw new InvalidArgumentException('A custom rail needs 1 to '.self::MAX_CARDS.' different products.');
            }
            if (trim($data['title_ar'] ?? '') === '' || trim($data['title_en'] ?? '') === '') {
                throw new InvalidArgumentException('A custom rail needs a title in both languages.');
            }
        }
        if ($data['card_count'] < 1 || $data['card_count'] > self::MAX_CARDS) {
            throw new InvalidArgumentException('Card count out of range.');
        }
        if ($data['kind'] === self::PRICE && ($data['max_price'] ?? 0) < 1) {
            throw new InvalidArgumentException('An under-a-price rail needs its price.');
        }

        // Part 3.2: the section's colour is a plain hex or nothing; blending is meaningless without one.
        $background = $data['background'] ?? null;
        if ($background !== null && ! self::isHex($background)) {
            throw new InvalidArgumentException('The background must be a colour like #1A2B3C.');
        }
        if (($data['blend'] ?? false) && $background === null) {
            throw new InvalidArgumentException('Blending needs a background colour to blend into.');
        }

        if ($data['kind'] === self::BRAND_BANNER) {
            $picks = $data['product_ids'] ?? [];
            if (count($picks) !== self::BANNER_PRODUCTS || count(array_unique($picks)) !== self::BANNER_PRODUCTS) {
                throw new InvalidArgumentException('A brand banner shows exactly '.self::BANNER_PRODUCTS.' different products.');
            }
            // …of THAT brand: a banner for one brand showing another brand's watch is a mistake nobody would see here.
            $ofBrand = DB::table('catalog_products')->whereIn('id', $picks)->where('brand_id', $data['target_id'])->whereNull('deleted_at')->count();
            if ($ofBrand !== self::BANNER_PRODUCTS) {
                throw new InvalidArgumentException('Every product of a brand banner must belong to its brand.');
            }
            $colors = $data['colors'] ?? [];
            if (count($colors) !== self::BANNER_COLORS || array_filter($colors, fn (string $c): bool => ! self::isHex($c)) !== []) {
                throw new InvalidArgumentException('A brand banner needs '.self::BANNER_COLORS.' colours like #1A2B3C.');
            }
            foreach (['title_ar', 'title_en', 'description_ar', 'description_en'] as $field) {
                if (trim($data[$field] ?? '') === '') {
                    throw new InvalidArgumentException('A brand banner needs a title and a description in both languages.');
                }
            }
        }
    }

    /**
     * THE PRESET COLOURS OF A SLIDER'S SECTION, per shop (the developer's review, 2026-11-05: the beige the first cut
     * used "looks bad"). A small set chosen to flatter product photos, offered on the Home sliders screen beside the
     * free colour field — a preset is only a shortcut to a hex value, the row stores the value and nothing else.
     *
     *   Watchizer      dark luxury: the white cards and the steel, gold and leather on them stand out against deep,
     *                  slightly warm or cool darks; one pale neutral (pearl) for a quiet row. No beige.
     *   Brand Fashion  light and colourful: clear pastels with enough colour to read as a band, light enough that a
     *                  product photo — and its blended studio background — sits on them without a hard edge.
     *
     * The slider's heading takes ink or white by itself (the storefronts' `onColor`), so every preset is readable.
     * A storefront this list does not name gets Watchizer's.
     *
     * @var array<string, list<array{key: string, hex: string}>>
     */
    public const PRESETS = [
        'watchizer' => [
            ['key' => 'midnight', 'hex' => '#111A2C'],
            ['key' => 'racing_green', 'hex' => '#13261F'],
            ['key' => 'oxblood', 'hex' => '#3A1216'],
            ['key' => 'espresso', 'hex' => '#2B1F18'],
            ['key' => 'graphite', 'hex' => '#22262B'],
            ['key' => 'pearl', 'hex' => '#E4E7EB'],
        ],
        'brandfashion' => [
            ['key' => 'sky', 'hex' => '#DCEBFA'],
            ['key' => 'blush', 'hex' => '#FBE1E4'],
            ['key' => 'mint', 'hex' => '#DDF2E6'],
            ['key' => 'butter', 'hex' => '#FFF0C4'],
            ['key' => 'lilac', 'hex' => '#E9E3F8'],
            ['key' => 'peach', 'hex' => '#FFE2D2'],
        ],
    ];

    /** @return list<array{key: string, hex: string}> */
    public static function presets(string $storefrontCode): array
    {
        return self::PRESETS[$storefrontCode] ?? self::PRESETS['watchizer'];
    }

    /** The top-level category whose products are watches, and the sub-categories that are bags / of low value — by slug. */
    private const WATCH_ROOT = 'watches';

    private const BAG_SLUGS = ['bags'];

    private const LOW_VALUE_SLUGS = ['socks'];

    /**
     * THE THREE PRODUCTS A BRAND BANNER IS OFFERED BY DEFAULT — the brand's RANGE, not three of a kind (the developer's
     * review, 2026-11-05: a Tommy Hilfiger banner showed socks, socks and a belt set).
     *
     * Of the brand's products a shopper can buy on this storefront (visible, active, in stock, pictured), newest
     * first: a WATCH first, then a BAG, then an ACCESSORY (anything that is neither — a bracelet, a wallet, a belt);
     * a kind the brand does not have is filled from the kinds it does, in that same order. Two kinds come last and are
     * used only when the brand has nothing else: an UNSORTED product (placed in a top-level category only, never in
     * one of its sub-categories — measured on Brand Fashion, where socks sit in "fashion" and nowhere else, so no
     * slug could name them) and, after it, a LOW-VALUE one (socks, by the sub-category's slug). It is a SUGGESTION: the dashboard's picker starts from it and the
     * team may pick any three by hand; `home:rebuild` uses it as it is.
     *
     * @return list<int> up to {@see self::BANNER_PRODUCTS} product ids
     */
    public static function suggestBannerProducts(int $storefrontId, int $brandId): array
    {
        $rows = DB::table('catalog_products as p')
            ->join('storefront_product as sp', function (JoinClause $join) use ($storefrontId): void {
                $join->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', '=', $storefrontId)->where('sp.is_visible', '=', 1);
            })
            ->leftJoin('storefront_category_product as scp', function (JoinClause $join) use ($storefrontId): void {
                $join->on('scp.product_id', '=', 'p.id')->where('scp.storefront_id', '=', $storefrontId);
            })
            ->leftJoin('storefront_categories as c', 'c.id', '=', 'scp.storefront_category_id')
            ->leftJoin('storefront_categories as top', 'top.id', '=', 'c.parent_id')
            ->where('p.brand_id', $brandId)->whereNull('p.deleted_at')->where('p.is_active', 1)->where('p.in_stock', 1)
            ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('catalog_product_images as i')->whereColumn('i.product_id', 'p.id'))
            ->orderByDesc('p.created_at')->orderByDesc('p.id')
            ->get(['p.id', 'c.slug as slug', 'top.slug as top_slug']);

        // Each product's kind, from ALL its placements: a watch if any of them is under Watches, else a bag, else
        // LOW VALUE if any of them says so (socks are also placed in "Fashion", which must not make them an
        // accessory), else an accessory if any of them is a SUB-category, else unsorted ("loose": top-level only, or
        // nowhere). The keys keep the newest-first order of their first sight.
        $rank = ['watch' => 0, 'bag' => 1, 'low' => 2, 'accessory' => 3, 'loose' => 4];
        $kind = [];
        foreach ($rows as $raw) {
            $id = Row::int($raw, 'id');
            $slug = Row::nstr($raw, 'slug');
            $top = Row::nstr($raw, 'top_slug');
            $is = match (true) {
                $slug === self::WATCH_ROOT || $top === self::WATCH_ROOT => 'watch',
                in_array($slug, self::BAG_SLUGS, true) => 'bag',
                in_array($slug, self::LOW_VALUE_SLUGS, true) => 'low',
                $top === null => 'loose',
                default => 'accessory',
            };
            if (! isset($kind[$id]) || $rank[$is] < $rank[$kind[$id]]) {
                $kind[$id] = $is;
            }
        }
        $byKind = ['watch' => [], 'bag' => [], 'accessory' => [], 'loose' => [], 'low' => []];
        foreach ($kind as $id => $is) {
            $byKind[$is][] = $id;
        }

        // one of each kind first, in order…
        $picks = [];
        foreach (['watch', 'bag', 'accessory'] as $is) {
            if ($byKind[$is] !== []) {
                $picks[] = array_shift($byKind[$is]);
            }
        }
        // …then the gaps from what the brand does have, the same order; the unsorted and socks last
        foreach (['watch', 'bag', 'accessory', 'loose', 'low'] as $is) {
            while (count($picks) < self::BANNER_PRODUCTS && $byKind[$is] !== []) {
                $picks[] = array_shift($byKind[$is]);
            }
        }

        return $picks;
    }

    /** `#RRGGBB`, the one form the colour inputs produce and the storefronts print into a style. */
    public static function isHex(string $value): bool
    {
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1;
    }

    /**
     * @param  array{kind: string, target_id: ?int, product_ids?: ?list<int>, title_en: ?string, title_ar: ?string, is_active: bool, card_count: int, background?: ?string, blend?: bool, colors?: list<string>, description_ar?: ?string, description_en?: ?string, max_price?: ?int}  $data
     * @return array<string, mixed>
     */
    private static function columns(array $data): array
    {
        $title = fn (?string $t): ?string => $t === null || trim($t) === '' ? null : trim($t);

        $custom = in_array($data['kind'], self::PICKED, true);

        // A section is what it shows: it has no title, no cards and no look of its own to store.
        if (in_array($data['kind'], self::SECTIONS, true)) {
            return ['kind' => $data['kind'], 'target_id' => null, 'product_ids' => null, 'title_en' => null, 'title_ar' => null,
                'is_active' => $data['is_active'], 'card_count' => 1, 'settings' => null];
        }

        /*
         * `settings` (Part 3.2 / 3.3): only the keys that say something. A rail with no colour and no blend —
         * every rail that existed before — keeps NULL, and renders exactly as it did.
         */
        $settings = [];
        if (($data['background'] ?? null) !== null) {
            $settings['background'] = strtoupper($data['background']);
            if ($data['blend'] ?? false) {
                $settings['blend'] = true;
            }
        }
        if ($data['kind'] === self::PRICE) {
            $settings['max_price'] = (int) ($data['max_price'] ?? 0);
        }
        if ($data['kind'] === self::BRAND_BANNER) {
            $settings['colors'] = array_map('strtoupper', $data['colors'] ?? []);
            $settings['description'] = ['ar' => trim($data['description_ar'] ?? ''), 'en' => trim($data['description_en'] ?? '')];
        }

        return [
            'kind' => $data['kind'],
            'target_id' => $data['target_id'],
            // Only a rail whose products are picked by hand keeps picks; switching it to another kind drops them.
            'product_ids' => $custom ? (string) json_encode($data['product_ids'] ?? []) : null,
            'title_en' => $title($data['title_en']),
            'title_ar' => $title($data['title_ar']),
            'is_active' => $data['is_active'],
            // A custom rail shows exactly what was picked.
            'card_count' => $custom ? count($data['product_ids'] ?? []) : $data['card_count'],
            'settings' => $settings === [] ? null : (string) json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * The `settings` column, read into the four things it can say — each with the value that means "nothing":
     * no colour, no blend, no colours, no description. Anything malformed reads as nothing rather than failing
     * the home page.
     *
     * @return array{background: string|null, blend: bool, colors: list<string>, description_ar: string|null, description_en: string|null, max_price: int|null}
     */
    private static function settings(?string $json): array
    {
        $raw = $json === null ? null : json_decode($json, true);
        $raw = is_array($raw) ? $raw : [];

        $background = is_string($raw['background'] ?? null) && self::isHex($raw['background']) ? $raw['background'] : null;
        $colors = [];
        foreach (is_array($raw['colors'] ?? null) ? $raw['colors'] : [] as $color) {
            if (is_string($color) && self::isHex($color)) {
                $colors[] = $color;
            }
        }
        $description = is_array($raw['description'] ?? null) ? $raw['description'] : [];
        $text = fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? trim($v) : null;

        return [
            'background' => $background,
            'blend' => $background !== null && ($raw['blend'] ?? false) === true,
            'colors' => $colors,
            'description_ar' => $text($description['ar'] ?? null),
            'description_en' => $text($description['en'] ?? null),
            'max_price' => is_int($raw['max_price'] ?? null) && $raw['max_price'] > 0 ? $raw['max_price'] : null,
        ];
    }

    /**
     * A stored JSON list of product ids, in order, positive ints only.
     *
     * @return list<int>
     */
    private static function ids(?string $json): array
    {
        $decoded = json_decode($json ?? '[]', true);

        return array_values(array_filter(is_array($decoded) ? $decoded : [], fn (mixed $id): bool => is_int($id) && $id > 0));
    }

    /** @return Rail */
    private static function rail(object $raw): array
    {
        $row = Row::cast($raw);

        return [
            'id' => Row::int($row, 'id'),
            'kind' => Row::str($row, 'kind'),
            'target_id' => Row::nint($row, 'target_id'),
            'product_ids' => self::ids(Row::nstr($row, 'product_ids')),
            'title_en' => Row::nstr($row, 'title_en'),
            'title_ar' => Row::nstr($row, 'title_ar'),
            'position' => Row::int($row, 'position'),
            'is_active' => Row::bool($row, 'is_active'),
            'card_count' => Row::int($row, 'card_count'),
            ...self::settings(Row::nstr($row, 'settings')),
        ];
    }
}
