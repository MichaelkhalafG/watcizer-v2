<?php

declare(strict_types=1);

namespace App\Domain\Import\BrandFashion;

use App\Domain\Activity\ActivityLog;
use App\Domain\Catalog\CategoryTreeWriter;
use App\Domain\Catalog\LookupWriter;
use App\Domain\Catalog\PlacementWriter;
use App\Domain\Import\CategoryMerger;
use App\Domain\Promotions\PromotionWriter;
use App\Models\Storefront\Storefront;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The data decisions Michael took on 2026-11-11, applied BEFORE the legacy import, each through
 * the dashboard's own writer and each recorded for undo:
 *
 *   D-19  three lookup values the old specs need: a metres unit (water resistance), "Mineral"
 *         (a glass), "Clasp" (a closure) — O-3: Buckle's Arabic becomes إبزيم first, so Clasp takes
 *         مشبك (both shops show it: Buckle is core's, on Watchizer's product pages too);
 *   D-10  the old site's brands that core lacks, created under their real names — the four
 *         confirmed aliases ({@see LegacyImporter::BRAND_ALIASES}) and "H"/"X" are NOT created;
 *   D-12  every Brand Fashion category the import places products in that is switched off, and
 *         the switched-off parents above them (a child under an inactive parent is unreachable) -
 *         switched ON the way they were switched off: active AND in the menu. Active alone is not
 *         on: the app lists, filters and serves only nodes that are in the menu (CompatCategories;
 *         the 2026-11-11 hop test - /category/quartz and 30 more answered 404), so "active, out of
 *         the menu" would sell the products with no category page, menu entry or filter;
 *   D-11  the dashboard twins of the sheet duplicates made visible on Brand Fashion (337 of 342
 *         already are), and a review sheet for the other duplicates;
 *   D-15  the old site's SATIN10 coupon, as Brand Fashion's own rule.
 *
 * Nothing here deletes. `restore()` reverses what can be reversed by a writer (nodes, placements,
 * the rule) and lists what stays (lookups and brands that a product now uses).
 */
final class CataloguePrepare
{
    /**
     * D-19. Arabic: the old site's own word where it had one (معدني — its correctly spelt form);
     * metres as متر; "Clasp" as مشبك — free once Buckle is renamed (RENAMES, O-3, Michael 2026-11-11
     * night): the dashboard refuses two rows of one list with the same Arabic name.
     *
     * @var list<array{list: string, en: string, ar: string, extra: array<string, string>}>
     */
    public const LOOKUPS = [
        ['list' => 'units', 'en' => 'metres', 'ar' => 'متر', 'extra' => ['code' => 'metre']],
        ['list' => 'materials', 'en' => 'Mineral', 'ar' => 'معدني', 'extra' => []],
        ['list' => 'closure_types', 'en' => 'Clasp', 'ar' => 'مشبك', 'extra' => []],
    ];

    /**
     * O-3 (Michael, 2026-11-11 night): existing lookup rows whose ARABIC changes, applied BEFORE the
     * lookups are created. Buckle is إبزيم (the old site's word for it); مشبك goes to Clasp.
     *
     * @var list<array{list: string, en: string, from: string, to: string}>
     */
    public const RENAMES = [
        ['list' => 'closure_types', 'en' => 'Buckle', 'from' => 'مشبك', 'to' => 'إبزيم'],
    ];

    /** D-10: the old site's spelling → the brand's own (the rest are created as written). */
    public const BRAND_DISPLAY = [
        'invicta' => 'Invicta',
        'rayban' => 'Ray-Ban',
        'hermes' => 'Hermès',
        'prada' => 'Prada',
    ];

    /** D-15: SATIN10 as the old WooCommerce coupon was (10 %, the Satin category, sale items included, no limits). */
    public const SATIN10 = [
        'code' => 'SATIN10',
        'name' => 'SATIN10 — the old site\'s Satin coupon',
        'percent' => 10,
        'category_path' => 'fashion/materials/satin',
        // Core refuses a rule with no end date (PromotionWriter, by design); the old coupon had none.
        'ends_at' => '2026-12-31 23:59:59',
    ];

    private int $storefront = Storefront::BRAND_FASHION_ID;

    /** @var array<int, array{image: string, price: float|null}> legacy id → its first photo and price */
    private array $legacy = [];

    public function __construct(
        private readonly LookupWriter $lookups,
        private readonly CategoryTreeWriter $tree,
        private readonly PlacementWriter $placements,
        private readonly PromotionWriter $promotions,
        private readonly CatalogueCleanup $cleanup,
    ) {}

    /**
     * What an apply would do. Nothing is written.
     *
     * @return array{renames: list<array<string, mixed>>, lookups: list<array<string, mixed>>, brands: list<array<string, mixed>>, nodes: list<array<string, mixed>>, twins: list<array<string, mixed>>, review: list<array<string, mixed>>, coupon: array<string, mixed>}
     */
    public function plan(string $productsFile): array
    {
        // ── O-3 ──
        $renames = [];
        foreach (self::RENAMES as $rn) {
            $def = LookupWriter::definition($rn['list']);
            $id = Coerce::nint(DB::table($def['translations'])->where('locale', 'en')->whereRaw('LOWER(name) = ?', [mb_strtolower($rn['en'])])->value($def['fk']));
            $ar = $id === null ? null : Coerce::nstr(DB::table($def['translations'])->where($def['fk'], $id)->where('locale', 'ar')->value('name'));
            $taken = Coerce::nint(DB::table($def['translations'])->where('locale', 'ar')->where('name', $rn['to'])->where($def['fk'], '!=', $id ?? 0)->value($def['fk']));
            $renames[] = $rn + ['id' => $id, 'current' => $ar, 'action' => match (true) {
                $id === null => 'missing',
                $ar === $rn['to'] => 'done',
                $taken !== null => 'refused: '.$rn['to'].' is already row '.$taken,
                default => 'rename',
            }];
        }

        // ── D-19 ──
        $lookups = [];
        foreach (self::LOOKUPS as $l) {
            $def = LookupWriter::definition($l['list']);
            $exists = DB::table($def['translations'])->where('locale', 'en')->whereRaw('LOWER(name) = ?', [mb_strtolower($l['en'])])->value($def['fk']);
            $lookups[] = $l + ['exists' => Coerce::nint($exists)];
        }

        // ── D-10 ──
        $known = [];
        foreach (DB::table('catalog_brands as b')->leftJoin('catalog_brand_translations as t', fn (JoinClause $j) => $j->on('t.brand_id', '=', 'b.id')->where('t.locale', 'en'))
            ->get(['b.slug', 't.name']) as $b) {
            $r = Row::cast($b);
            foreach ([Row::nstr($r, 'name') ?? '', Row::str($r, 'slug')] as $n) {
                $known[LegacyImporter::brandKeyOf($n)] = true;
            }
        }
        $paths = [];
        $wanted = [];
        $handle = fopen($productsFile, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot read {$productsFile}");
        }
        while (($line = fgets($handle)) !== false) {
            if (trim($line) === '') {
                continue;
            }
            $record = Coerce::arr(json_decode($line, true, 512, JSON_THROW_ON_ERROR));
            $this->legacy[Coerce::int($record['legacy_id'] ?? null)] = [
                'image' => Coerce::str(Coerce::arr(array_values(Coerce::arr($record['images'] ?? null))[0] ?? null)['file'] ?? null),
                'price' => Coerce::nfloat($record['regular'] ?? null),
            ];
            foreach (Coerce::arr($record['category_paths'] ?? null) as $p) {
                $paths[Coerce::str($p)] = true;
            }
            $brand = Coerce::nstr($record['brand'] ?? null);
            if ($brand === null) {
                continue;
            }
            $key = LegacyImporter::canonicalBrandKey($brand);
            if ($key === '' || isset($known[$key]) || in_array($key, LegacyImporter::BRAND_NEVER_CREATED, true)) {
                continue;
            }
            $wanted[$key] ??= ['key' => $key, 'name' => self::BRAND_DISPLAY[$key] ?? $brand, 'old' => $brand, 'products' => 0];
            $wanted[$key]['products']++;
        }
        fclose($handle);

        // ── D-12 ──
        $nodes = [];
        foreach (array_keys($paths) as $path) {
            $walked = [];
            foreach (explode('/', $path) as $segment) {
                $walked[] = $segment;
                $sub = implode('/', $walked);
                $id = CategoryMerger::find($this->storefront, $sub);
                if ($id === null || isset($nodes[$id])) {
                    continue;
                }
                $found = DB::table('storefront_categories')->where('id', $id)->first(['is_active', 'show_in_menu']);
                if ($found === null) {
                    continue;
                }
                $flags = Row::cast($found);
                if (! Row::bool($flags, 'is_active')) {
                    $nodes[$id] = ['id' => $id, 'path' => $sub, 'placements' => DB::table('storefront_category_product')->where('storefront_category_id', $id)->count(), 'used_by_import' => $sub === $path, 'show_in_menu' => Row::bool($flags, 'show_in_menu')];
                }
            }
        }

        // ── D-11 ──
        $twins = [];
        $review = [];
        $rows = $this->cleanup->classify(true)['rows'];
        $dupIds = [];
        foreach ($rows as $row) {
            if (($row['bucket'] ?? null) === CatalogueCleanup::DUPLICATE) {
                foreach (Coerce::intList($row['duplicate_of'] ?? []) as $o) {
                    $dupIds[$o] = true;
                }
            }
        }
        $described = [];
        foreach ($this->cleanup->describe(array_keys($dupIds)) as $d) {
            $described[Coerce::int($d['id'])] = $d;
        }
        foreach ($rows as $row) {
            if (($row['bucket'] ?? null) !== CatalogueCleanup::DUPLICATE) {
                continue;
            }
            $others = array_map(fn (int $o): array => $described[$o] ?? ['id' => $o], Coerce::intList($row['duplicate_of'] ?? []));
            $dashboard = array_values(array_filter($others, fn (array $o): bool => ($o['origin'] ?? null) === CatalogueCleanup::ORIGIN_DASHBOARD));
            if ($dashboard !== []) {
                foreach ($dashboard as $d) {
                    $visible = DB::table('storefront_product')->where('storefront_id', $this->storefront)->where('product_id', Coerce::int($d['id']))->value('is_visible');
                    if (! Coerce::bool($visible)) {
                        $twins[Coerce::int($d['id'])] = ['id' => Coerce::int($d['id']), 'sheet_id' => Coerce::int($row['id']), 'title' => Coerce::str($d['title'] ?? null), 'placed' => $visible !== null];
                    }
                }

                continue;
            }
            $review[] = $this->reviewRow($row, $others);
        }

        // ── D-15 ──
        $satin = CategoryMerger::find($this->storefront, self::SATIN10['category_path']);
        $coupon = [
            'code' => self::SATIN10['code'],
            'exists' => DB::table('promotion_rules')->where('code', self::SATIN10['code'])->value('id'),
            'category_id' => $satin,
        ];

        return ['renames' => $renames, 'lookups' => $lookups, 'brands' => array_values($wanted), 'nodes' => array_values($nodes), 'twins' => array_values($twins), 'review' => $review, 'coupon' => $coupon];
    }

    /**
     * Apply a plan; returns the undo record (also what was done).
     *
     * @param  array{renames?: list<array<string, mixed>>, lookups: list<array<string, mixed>>, brands: list<array<string, mixed>>, nodes: list<array<string, mixed>>, twins: list<array<string, mixed>>, review: list<array<string, mixed>>, coupon: array<string, mixed>}  $plan
     * @return array<string, mixed>
     */
    public function apply(array $plan): array
    {
        $undo = ['kind' => 'bf-catalogue-prepare', 'created_at' => date(DATE_ATOM), 'renames' => [], 'lookups' => [], 'brands' => [], 'nodes' => [], 'placements' => [], 'rule' => null, 'refused' => []];

        foreach ($plan['renames'] ?? [] as $rn) {
            $id = Coerce::nint($rn['id'] ?? null);
            if ($id === null || ($rn['action'] ?? null) !== 'rename') {
                if (str_starts_with(Coerce::str($rn['action'] ?? null), 'refused')) {
                    $undo['refused'][] = Coerce::str($rn['en'] ?? null).': '.Coerce::str($rn['action'] ?? null);
                }

                continue;
            }
            $list = Coerce::str($rn['list'] ?? null);
            $en = Coerce::str($rn['en'] ?? null);
            $from = Coerce::str($rn['current'] ?? null);
            $to = Coerce::str($rn['to'] ?? null);
            $this->lookups->update($list, $id, ['name' => ['ar' => $to, 'en' => $en]]);
            ActivityLog::record('catalog_lookups', $id, ActivityLog::UPDATED, ['list' => $list, 'ar' => $from], ['list' => $list, 'ar' => $to, 'reason' => 'O-3 legacy import'], label: $en);
            $undo['renames'][] = ['list' => $list, 'id' => $id, 'en' => $en, 'ar' => $from];
        }

        foreach ($plan['lookups'] as $l) {
            if (Coerce::nint($l['exists'] ?? null) !== null) {
                continue;
            }
            $list = Coerce::str($l['list'] ?? null);
            $id = $this->lookups->create($list, ['name' => ['ar' => Coerce::str($l['ar'] ?? null), 'en' => Coerce::str($l['en'] ?? null)], 'extra' => Coerce::arr($l['extra'] ?? null)]);
            ActivityLog::record('catalog_lookups', $id, ActivityLog::CREATED, [], ['list' => $list, 'en' => $l['en'] ?? null, 'reason' => 'D-19 legacy import'], label: Coerce::str($l['en'] ?? null));
            $undo['lookups'][] = ['list' => $list, 'id' => $id];
        }

        foreach ($plan['brands'] as $b) {
            $name = Coerce::str($b['name'] ?? null);
            $id = $this->lookups->create('brands', ['name' => ['ar' => $name, 'en' => $name], 'extra' => ['slug' => '', 'is_active' => true]]);
            ActivityLog::record('catalog_lookups', $id, ActivityLog::CREATED, [], ['list' => 'brands', 'en' => $name, 'reason' => 'D-10 legacy import'], label: $name);
            $undo['brands'][] = ['id' => $id, 'name' => $name];
        }

        foreach ($plan['nodes'] as $n) {
            $id = Coerce::int($n['id'] ?? null);
            $menu = Coerce::bool($n['show_in_menu'] ?? false);
            $this->tree->setFlags($this->storefront, $id, true, true);
            ActivityLog::record('storefront_categories', $id, ActivityLog::UPDATED, ['is_active' => false, 'show_in_menu' => $menu], ['is_active' => true, 'show_in_menu' => true], label: Coerce::str($n['path'] ?? null), storefrontId: $this->storefront);
            $undo['nodes'][] = ['id' => $id, 'is_active' => false, 'show_in_menu' => $menu];
        }

        foreach ($plan['twins'] as $t) {
            $id = Coerce::int($t['id'] ?? null);
            $before = DB::table('storefront_product')->where('storefront_id', $this->storefront)->where('product_id', $id)->first(['is_visible', 'is_featured', 'sort_order', 'slug']);
            if ($before === null) {
                $undo['refused'][] = "product {$id}: not placed on Brand Fashion — give it a category there first";

                continue;
            }
            $b = Row::cast($before);
            $missing = $this->placements->missingForVisibility($id);
            if ($missing !== []) {
                $undo['refused'][] = "product {$id}: cannot be visible yet — ".implode(', ', $missing);

                continue;
            }
            $this->placements->save($this->storefront, $id, [
                'is_visible' => true, 'is_featured' => Row::bool($b, 'is_featured'), 'sort_order' => Row::int($b, 'sort_order'), 'slug' => Row::str($b, 'slug'),
            ]);
            ActivityLog::record('storefront_product', $id, ActivityLog::UPDATED, ['is_visible' => false], ['is_visible' => true], storefrontId: $this->storefront);
            $undo['placements'][] = ['product_id' => $id, 'is_visible' => Row::bool($b, 'is_visible')];
        }

        $coupon = $plan['coupon'];
        if (Coerce::nint($coupon['exists'] ?? null) === null) {
            $category = Coerce::nint($coupon['category_id'] ?? null);
            if ($category === null) {
                $undo['refused'][] = 'SATIN10: no '.self::SATIN10['category_path'].' node on Brand Fashion';
            } else {
                $id = $this->promotions->save([
                    'name' => self::SATIN10['name'], 'code' => self::SATIN10['code'], 'apply_per' => 'item',
                    'is_active' => true, 'priority' => 0, 'stackable' => false, 'include_sale_items' => true,
                    'starts_at' => now()->toDateTimeString(), 'ends_at' => self::SATIN10['ends_at'],
                    'storefronts' => [$this->storefront], 'conditions' => [],
                    'rewards' => [['type' => 'percent_discount', 'amount' => self::SATIN10['percent']]],
                    'scopes' => [['role' => 'target', 'dimension' => 'category', 'value_id' => $category]],
                ], null, null);
                ActivityLog::record('promotion_rules', $id, ActivityLog::CREATED, [], ['code' => self::SATIN10['code'], 'reason' => 'D-15 the old site\'s coupon'], label: self::SATIN10['name'], storefrontId: $this->storefront);
                $undo['rule'] = $id;
            }
        }

        return $undo;
    }

    /**
     * Reverse an apply: nodes switched off again, placements back to what they were, the rule
     * switched off. Lookups and brands are deleted only when nothing uses them (the writer refuses
     * otherwise — a brand on an imported product stays until the import is undone).
     *
     * @param  array<string, mixed>  $undo
     * @return list<string>
     */
    public function restore(array $undo): array
    {
        if (($undo['kind'] ?? null) !== 'bf-catalogue-prepare') {
            throw new RuntimeException('Not a bf:catalogue-prepare undo file.');
        }
        $done = [];
        $rule = Coerce::nint($undo['rule'] ?? null);
        if ($rule !== null) {
            DB::table('promotion_rules')->where('id', $rule)->update(['is_active' => 0, 'updated_at' => now()]);
            ActivityLog::record('promotion_rules', $rule, ActivityLog::UPDATED, ['is_active' => true], ['is_active' => false], storefrontId: $this->storefront);
            $done[] = "rule {$rule} switched off";
        }
        foreach (Coerce::arr($undo['placements'] ?? null) as $p) {
            $row = Coerce::arr($p);
            $id = Coerce::int($row['product_id'] ?? null);
            $current = DB::table('storefront_product')->where('storefront_id', $this->storefront)->where('product_id', $id)->first(['is_featured', 'sort_order', 'slug']);
            if ($current === null) {
                continue;
            }
            $c = Row::cast($current);
            $this->placements->save($this->storefront, $id, ['is_visible' => Coerce::bool($row['is_visible'] ?? false), 'is_featured' => Row::bool($c, 'is_featured'), 'sort_order' => Row::int($c, 'sort_order'), 'slug' => Row::str($c, 'slug')]);
            $done[] = "product {$id} visibility restored";
        }
        foreach (Coerce::arr($undo['nodes'] ?? null) as $n) {
            // an entry is {id, is_active, show_in_menu} (the flags before); a bare id is the first
            // round's file, which changed is_active only
            $node = is_array($n) ? $n : ['id' => $n, 'is_active' => false, 'show_in_menu' => null];
            $id = Coerce::int($node['id'] ?? null);
            $menu = $node['show_in_menu'] ?? null;
            $this->tree->setFlags($this->storefront, $id, Coerce::bool($node['is_active'] ?? false), $menu === null ? null : Coerce::bool($menu));
            $done[] = "category {$id} switched off";
        }
        foreach (Coerce::arr($undo['brands'] ?? null) as $b) {
            $id = Coerce::int(Coerce::arr($b)['id'] ?? null);
            $done[] = $this->tryDelete('brands', $id);
        }
        foreach (Coerce::arr($undo['lookups'] ?? null) as $l) {
            $row = Coerce::arr($l);
            $done[] = $this->tryDelete(Coerce::str($row['list'] ?? null), Coerce::int($row['id'] ?? null));
        }
        foreach (Coerce::arr($undo['renames'] ?? null) as $rn) {
            $row = Coerce::arr($rn);
            $list = Coerce::str($row['list'] ?? null);
            $id = Coerce::int($row['id'] ?? null);
            $ar = Coerce::str($row['ar'] ?? null);
            $def = LookupWriter::definition($list);
            $holder = Coerce::nint(DB::table($def['translations'])->where('locale', 'ar')->where('name', $ar)->where($def['fk'], '!=', $id)->value($def['fk']));
            if ($holder !== null) {
                $done[] = "{$list} {$id} keeps its new Arabic: {$ar} is row {$holder}'s (still used — undo the import first)";

                continue;
            }
            $this->lookups->update($list, $id, ['name' => ['ar' => $ar, 'en' => Coerce::str($row['en'] ?? null)]]);
            ActivityLog::record('catalog_lookups', $id, ActivityLog::UPDATED, [], ['list' => $list, 'ar' => $ar, 'reason' => 'O-3 undone'], label: Coerce::str($row['en'] ?? null));
            $done[] = "{$list} {$id} back to {$ar}";
        }

        return $done;
    }

    private function tryDelete(string $list, int $id): string
    {
        try {
            $this->lookups->delete($list, $id);

            return "{$list} {$id} deleted";
        } catch (\Throwable $e) {
            return "{$list} {$id} kept — still used (".mb_substr($e->getMessage(), 0, 60).')';
        }
    }

    /**
     * One line of the D-11 review sheet: a sheet duplicate whose twin is NOT a dashboard product.
     *
     * @param  array<string, mixed>  $row
     * @param  list<array<string, mixed>>  $others
     * @return array<string, mixed>
     */
    private function reviewRow(array $row, array $others): array
    {
        $ref = Coerce::str($row['import_ref'] ?? null);
        $wpId = (int) substr($ref, strlen(CatalogueCleanup::SHEET_PREFIX));
        $byName = ($row['matched_by'] ?? null) === 'name';
        $twin = $others[0] ?? [];
        $twinSheet = ($twin['origin'] ?? null) === CatalogueCleanup::ORIGIN_SHEET;
        // Compare the two OLD products (photo, price) — the sheet copies only carry what the sheet had.
        $twinWp = 0;
        $twinRef = Coerce::nstr(DB::table('catalog_products')->where('id', Coerce::int($twin['id'] ?? null))->value('import_ref'));
        if ($twinRef !== null && str_starts_with($twinRef, CatalogueCleanup::SHEET_PREFIX)) {
            $twinWp = (int) substr($twinRef, strlen(CatalogueCleanup::SHEET_PREFIX));
        }
        $mine = $this->legacy[$wpId] ?? null;
        $theirs = $this->legacy[$twinWp] ?? null;
        $evidence = $mine === null ? 'this old product is no longer published'
            : ($theirs === null ? 'the twin\'s old product is no longer published'
                : (($mine['image'] !== '' && $mine['image'] === $theirs['image']) ? 'same photo on the old site'
                    : 'different photos'.($mine['price'] !== $theirs['price'] ? ' and prices ('.($mine['price'] ?? 0).' / '.($theirs['price'] ?? 0).')' : '').' on the old site'));
        $same = str_starts_with($evidence, 'same photo');
        $proposal = match (true) {
            $mine === null => 'Remove this sheet copy: its old product is gone.',
            $twinSheet && ! $same => 'NOT a duplicate ('.$evidence.'): remove this sheet copy so the clean old product is imported; give the products distinct names later.',
            $twinSheet => 'A real duplicate ('.$evidence.'): keep one — remove this sheet copy unless it is the one the team edited.',
            default => 'Twin is a '.Coerce::str($twin['origin'] ?? null).' product ('.$evidence.'): keep the twin, remove this sheet copy.',
        };

        return [
            'sheet_id' => Coerce::int($row['id'] ?? null),
            'wordpress_id' => $wpId,
            'sku' => Coerce::str($row['sku'] ?? null),
            'title' => Coerce::str($row['title'] ?? null),
            'matched_by' => Coerce::str($row['matched_by'] ?? null),
            'twins' => implode(' | ', array_map(fn (array $o): string => sprintf('%d %s [%s, %s]', Coerce::int($o['id'] ?? null), Coerce::str($o['wa_code'] ?? null), Coerce::str($o['origin'] ?? null), Coerce::str($o['placements'] ?? null) ?: 'no placement'), array_slice($others, 0, 5)))
                .(count($others) > 5 ? ' (+'.(count($others) - 5).' more)' : ''),
            'twin_count' => count($others),
            'evidence' => $evidence,
            'proposal' => $proposal,
        ];
    }
}
