<?php

declare(strict_types=1);

namespace App\Domain\Import\BrandFashion;

use App\Domain\Catalog\FieldRefusal;
use App\Domain\Catalog\LookupWriter;
use App\Domain\Catalog\PlacementWriter;
use App\Domain\Catalog\ProductWriter;
use App\Domain\Catalog\SpecBlocks;
use App\Domain\Catalog\VariantWriter;
use App\Domain\Import\CategoryMap;
use App\Domain\Import\CategoryMerger;
use App\Domain\Inventory\Actor;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use App\Domain\Media\MediaStore;
use App\Models\Storefront\Storefront;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Step 1B (2026-11-11): the old Brand Fashion catalogue, from the file `bf:legacy-extract` wrote,
 * into core — through the dashboard's own doors, on Brand Fashion only.
 *
 * ── Identity, and the one thing this never does: create a duplicate ───────────────────────────
 *
 * A legacy product is the SAME as an existing live product when, in this order:
 *   1. the existing row was imported from the SAME WordPress post (`import_ref = woo:<id>`);
 *   2. it has the same SKU (trimmed, spaces collapsed, case-blind) — product or variant SKU;
 *   3. neither has a SKU, the names match ({@see LegacyText::nameKey()}) AND the old products' first
 *      photo is the same file — two old products under one name with different photos are two products
 *      (the review of 2026-11-11, O-4: 107 sheet copies "duplicated" only by a generic name).
 * A match is NOT created and NOT changed: it is listed with what the existing product is and a
 * proposal, and the developer decides. Inside the file the same rules apply — the first row of a
 * SKU/name wins, the rest are skipped as `duplicate_in_source`.
 *
 * ── What is skipped rather than guessed ─────────────────────────────────────────────────────
 *
 * No price, a price out of range, no title in either language: skipped and listed. Everything else
 * imports, and anything that keeps it off the shop (no image yet, no gender, a description missing)
 * leaves it HIDDEN with the reason — {@see PlacementWriter::missingForVisibility()} decides, not
 * this class.
 *
 * ── The keys it leaves behind ──────────────────────────────────────────────────────────────
 *
 * `import_ref = bfwp:<legacy id>` (unique, and what makes a second run skip a row) and
 * `wa_code = BFL-<legacy id>`. The legacy URL and slug stay in the extract file and in
 * `imported.csv` beside the new product id — the redirect map (step 2) is built from them.
 */
final class LegacyImporter
{
    public const REF_PREFIX = 'bfwp:';

    public const CODE_PREFIX = 'BFL-';

    public const STOCK_LEDGER = 'ledger';

    public const STOCK_ZERO = 'zero';

    /**
     * Old brand names that ARE an existing brand, confirmed by Michael (2026-11-11, D-10), keyed by
     * {@see brandKey()}: the old site's abbreviations and one misspelling.
     */
    public const BRAND_ALIASES = [
        'ap' => 'audemarspiguet',
        'carti' => 'cartier',
        'karl' => 'karllagerfeld',
        'patekphilip' => 'patekphilippe',
    ];

    /** Old brand names that are not brands and stay on Generic (D-10): "H" and "X". */
    public const BRAND_NEVER_CREATED = ['h', 'x'];

    /** @var array<string, int> skuKey => product id (live products and their variants) */
    private array $bySku = [];

    /** @var array<string, int> nameKey => product id (live products with no SKU) */
    private array $byName = [];

    /**
     * The same two indexes over the September SHEET copies only. They are asked LAST: when the old
     * product also matches a product the team made (the 342 dashboard twins, D-11), the old URL must
     * lead to that visible product, not to its hidden sheet copy.
     *
     * @var array<string, int>
     */
    private array $sheetBySku = [];

    /** @var array<string, int> */
    private array $sheetByName = [];

    /** @var array<int, int> WordPress post id => product id, from the sheet import's `woo:<id>` */
    private array $byWooId = [];

    /**
     * WordPress post id => its first photo's file (`2024/10/x.jpg`), read from the extract before the
     * indexes are built: the name identity (rule 3) carries it. Empty when no extract was given — every
     * photo then reads '' and rule 3 is the name alone, as before.
     *
     * @var array<int, string>
     */
    private array $photos = [];

    /** @var array<int, int> WordPress post id => product id this importer already created */
    private array $imported = [];

    /** @var array<string, array<string, int>> lookup list => lower-case EN name => id */
    private array $lookups = [];

    /** @var array<string, int> normalised brand name => id */
    private array $brands = [];

    private ?int $genericBrand = null;

    /** @var array<string, array{id: int, active: bool}|null> category path => node on Brand Fashion */
    private array $nodes = [];

    /**
     * Identity keys (`sku:…` / `name:…`) already owned by a legacy row: by an earlier run's product
     * (its id) or by a row planned in this run (0 until it is created). Without the earlier runs'
     * keys, a second run would CREATE the old site's in-source duplicate of a product the first
     * run imported — found while building the redirect map, before any second run happened.
     *
     * @var array<string, int>
     */
    private array $claimed = [];

    /** @var array<string, array{file: string, folder: string, width: int, height: int, renditions: array<int, array<string, string>>}> */
    private array $imageMap = [];

    private ?string $imageMapFile = null;

    /** @var array<string, int> */
    private array $counts = [];

    /** @var array<string, array<string, int>> */
    private array $tallies = [];

    private int $storefrontId = Storefront::BRAND_FASHION_ID;

    public function __construct(
        private readonly ProductWriter $products,
        private readonly PlacementWriter $placements,
        private readonly VariantWriter $variants,
        private readonly InventoryService $inventory,
        private readonly MediaStore $media,
        private readonly LookupWriter $lookupWriter,
    ) {}

    /**
     * Read every index the plan needs, once. `$productsFile` (the extract's products.jsonl) gives each
     * old product's first photo to the name identity; without it the identity is the name alone.
     */
    public function prepare(?string $imageMapFile = null, ?string $productsFile = null): void
    {
        if ($productsFile !== null) {
            $this->learnPhotos($productsFile);
        }
        $titles = [];
        foreach (DB::table('catalog_product_translations')->where('locale', 'en')->get(['product_id', 'title']) as $t) {
            $r = Row::cast($t);
            $titles[Row::int($r, 'product_id')] = Row::str($r, 'title');
        }
        foreach (DB::table('catalog_products')->whereNull('deleted_at')->orderBy('id')->get(['id', 'sku', 'import_ref']) as $p) {
            $r = Row::cast($p);
            $id = Row::int($r, 'id');
            $ref = Row::nstr($r, 'import_ref') ?? '';
            if (str_starts_with($ref, self::REF_PREFIX)) {
                $this->imported[(int) substr($ref, strlen(self::REF_PREFIX))] = $id;
                $legacyId = (int) substr($ref, strlen(self::REF_PREFIX));
                $this->claimed[self::identityKey(Row::nstr($r, 'sku'), $titles[$id] ?? '', $this->photos[$legacyId] ?? '')] ??= $id;

                continue;
            }
            $sheet = str_starts_with($ref, CatalogueCleanup::SHEET_PREFIX);
            $wooId = $sheet ? (int) substr($ref, strlen(CatalogueCleanup::SHEET_PREFIX)) : 0;
            if ($sheet) {
                $this->byWooId[$wooId] = $id;
            }
            $sku = LegacyText::skuKey(Row::nstr($r, 'sku'));
            if ($sku !== null) {
                $sheet ? $this->sheetBySku[$sku] ??= $id : $this->bySku[$sku] ??= $id;
            } else {
                $name = LegacyText::nameKey($titles[$id] ?? '');
                if ($name !== '') {
                    // A sheet copy is keyed by its old product's photo too (rule 3); the team's own
                    // products carry no old photo and match by name alone.
                    $sheet ? $this->sheetByName[$name.'|'.($this->photos[$wooId] ?? '')] ??= $id : $this->byName[$name] ??= $id;
                }
            }
        }
        foreach (DB::table('catalog_product_variants as v')->join('catalog_products as p', 'p.id', '=', 'v.product_id')
            ->whereNull('p.deleted_at')->whereNotNull('v.sku')->get(['v.sku', 'v.product_id', 'p.import_ref']) as $v) {
            $r = Row::cast($v);
            $ref = Row::nstr($r, 'import_ref') ?? '';
            if (str_starts_with($ref, self::REF_PREFIX)) {
                continue;
            }
            $sku = LegacyText::skuKey(Row::nstr($r, 'sku'));
            if ($sku !== null) {
                str_starts_with($ref, CatalogueCleanup::SHEET_PREFIX)
                    ? $this->sheetBySku[$sku] ??= Row::int($r, 'product_id')
                    : $this->bySku[$sku] ??= Row::int($r, 'product_id');
            }
        }

        foreach (['materials' => ['catalog_material_translations', 'material_id'], 'closure_types' => ['catalog_closure_type_translations', 'closure_type_id'],
            'display_types' => ['catalog_display_type_translations', 'display_type_id'], 'movements' => ['catalog_movement_type_translations', 'movement_type_id'],
            'shapes' => ['catalog_shape_translations', 'shape_id'], 'colors' => ['catalog_color_translations', 'color_id'],
            'genders' => ['catalog_gender_translations', 'gender_id'], 'units' => ['catalog_unit_translations', 'unit_id'],
            'sizes' => ['catalog_size_translations', 'size_id']] as $list => [$table, $fk]) {
            foreach (DB::table($table)->where('locale', 'en')->get([$fk, 'name']) as $l) {
                $r = Row::cast($l);
                $this->lookups[$list][mb_strtolower(trim(Row::str($r, 'name')))] ??= Row::int($r, $fk);
            }
        }

        foreach (DB::table('catalog_brands as b')->leftJoin('catalog_brand_translations as t', fn (JoinClause $j) => $j->on('t.brand_id', '=', 'b.id')->where('t.locale', 'en'))
            ->get(['b.id', 'b.slug', 't.name']) as $b) {
            $r = Row::cast($b);
            $id = Row::int($r, 'id');
            foreach ([Row::nstr($r, 'name'), Row::str($r, 'slug')] as $name) {
                $key = self::brandKey($name ?? '');
                if ($key !== '') {
                    $this->brands[$key] ??= $id;
                }
            }
            if (Row::str($r, 'slug') === 'generic') {
                $this->genericBrand = $id;
            }
        }

        $this->imageMapFile = $imageMapFile;
        // the import's own map AND the image pass's shard maps beside it (bf:legacy-images, Block D, 2026-11-12)
        $maps = $imageMapFile === null ? [] : array_values(array_filter(
            [$imageMapFile, ...(glob(dirname($imageMapFile).'/image-map.shard-*.jsonl') ?: [])], 'is_file'));
        foreach ($maps as $map) {
            foreach (file($map, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $entry = Coerce::arr(json_decode($line, true));
                $stored = Coerce::arr($entry['stored'] ?? null);
                $file = Coerce::str($stored['file'] ?? null);
                $folder = Coerce::str($stored['folder'] ?? null);
                // Reused only while the master is really there (a prune may have taken it).
                if ($file !== '' && $folder !== '' && is_file(MediaStore::directory($folder).'/'.$file)) {
                    $renditions = [];
                    foreach (Coerce::arr($stored['renditions'] ?? null) as $width => $formats) {
                        $renditions[(int) $width] = array_map(fn (mixed $v): string => Coerce::str($v), Coerce::arr($formats));
                    }
                    $this->imageMap[Coerce::str($entry['source'] ?? null)] = [
                        'file' => $file, 'folder' => $folder,
                        'width' => Coerce::int($stored['width'] ?? null), 'height' => Coerce::int($stored['height'] ?? null),
                        'renditions' => $renditions,
                    ];
                }
            }
        }
    }

    /**
     * What will happen to this record, decided without writing anything.
     *
     * @param  array<string, mixed>  $record
     * @return array{action: string, reason: string, existing: int|null, rule: string|null}
     */
    public function plan(array $record): array
    {
        $legacyId = Coerce::int($record['legacy_id'] ?? null);

        if (isset($this->imported[$legacyId])) {
            return ['action' => 'already', 'reason' => 'imported by an earlier run', 'existing' => $this->imported[$legacyId], 'rule' => 'import_ref'];
        }

        $issue = Coerce::nstr($record['price_issue'] ?? null);
        if ($issue !== null) {
            return ['action' => 'skip', 'reason' => $issue, 'existing' => null, 'rule' => null];
        }
        if (Coerce::str($record['title_en'] ?? null) === '' && Coerce::str($record['title_ar'] ?? null) === '') {
            return ['action' => 'skip', 'reason' => 'no_title', 'existing' => null, 'rule' => null];
        }

        // The team's own products (transform, dashboard) first: a match there is the better row.
        $sku = LegacyText::skuKey(Coerce::nstr($record['sku'] ?? null));
        $name = LegacyText::nameKey(Coerce::str($record['title_en'] ?? null));
        if ($sku !== null && isset($this->bySku[$sku])) {
            return ['action' => 'match', 'reason' => 'same SKU', 'existing' => $this->bySku[$sku], 'rule' => 'sku'];
        }
        if ($sku === null && $name !== '' && isset($this->byName[$name])) {
            return ['action' => 'match', 'reason' => 'same name, neither has a SKU', 'existing' => $this->byName[$name], 'rule' => 'name'];
        }

        // Then the September sheet copies that are still live.
        if (isset($this->byWooId[$legacyId])) {
            return ['action' => 'match', 'reason' => 'same WordPress product as the sheet import', 'existing' => $this->byWooId[$legacyId], 'rule' => 'same_wordpress_id'];
        }
        if ($sku !== null && isset($this->sheetBySku[$sku])) {
            return ['action' => 'match', 'reason' => 'same SKU as a sheet copy', 'existing' => $this->sheetBySku[$sku], 'rule' => 'sheet_sku'];
        }
        $photo = self::photoOf($record);
        if ($sku === null && $name !== '' && isset($this->sheetByName[$name.'|'.$photo])) {
            return ['action' => 'match', 'reason' => 'same name and photo as a sheet copy', 'existing' => $this->sheetByName[$name.'|'.$photo], 'rule' => 'sheet_name'];
        }

        $key = self::identityKey(Coerce::nstr($record['sku'] ?? null), Coerce::str($record['title_en'] ?? null), $photo);
        if (isset($this->claimed[$key])) {
            $twin = $this->claimed[$key];

            return ['action' => 'skip', 'reason' => 'duplicate_in_source', 'existing' => $twin > 0 ? $twin : null, 'rule' => $sku !== null ? 'sku' : 'name'];
        }
        $this->claimed[$key] = 0;

        return ['action' => 'create', 'reason' => '', 'existing' => null, 'rule' => null];
    }

    /**
     * Resolve everything a creation needs against THIS database, writing nothing: brand, nodes,
     * spec lookups, sizes, images on disk. Problems are counted, not thrown.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public function resolve(array $record, ?string $imagesRoot): array
    {
        $notes = [];

        // ── brand ──
        $brandName = Coerce::nstr($record['brand'] ?? null);
        $brandId = $brandName === null ? null : ($this->brands[self::canonicalBrandKey($brandName)] ?? null);
        if ($brandId === null) {
            $notes[] = $brandName === null ? 'no_brand' : 'brand_unmatched';
            if ($brandName !== null) {
                $this->tally('brand_unmatched', $brandName);
            }
            $brandId = $this->genericBrand;
        }

        // ── categories on Brand Fashion ──
        $nodeIds = [];
        $primary = null;
        $primaryDepth = -1;
        foreach (Coerce::arr($record['category_paths'] ?? null) as $p) {
            $path = Coerce::str($p);
            $node = $this->node($path);
            if ($node === null) {
                $notes[] = 'category_missing_on_bf';
                $this->tally('category_missing_on_bf', $path);

                continue;
            }
            $nodeIds[] = $node['id'];
            if (! $node['active']) {
                $this->tally('category_inactive_on_bf', $path);
            }
            $depth = substr_count($path, '/') + 1;
            if (CategoryMap::mayBePrimary($path) && $depth > $primaryDepth) {
                $primary = $node['id'];
                $primaryDepth = $depth;
            }
        }
        if ($primary === null && $nodeIds !== []) {
            $primary = $nodeIds[0];
        }
        if ($nodeIds === []) {
            $notes[] = 'no_category';
        }
        foreach (Coerce::arr($record['unmapped_categories'] ?? null) as $u) {
            $this->tally('category_unmapped', Coerce::str($u));
        }

        // ── genders ──
        $genderIds = [];
        foreach (Coerce::arr($record['genders'] ?? null) as $g) {
            $id = $this->lookups['genders'][mb_strtolower(Coerce::str($g))] ?? null;
            if ($id !== null) {
                $genderIds[$id] = true;
            }
        }
        if ($genderIds === []) {
            $notes[] = 'no_gender';
        }

        // ── specs ──
        $specs = [];
        $colours = [];
        foreach (Coerce::arr($record['specs'] ?? null) as $s) {
            $spec = Coerce::arr($s);
            $kind = Coerce::str($spec['kind'] ?? null);
            $target = Coerce::str($spec['target'] ?? null);
            $list = Coerce::str($spec['list'] ?? null);
            $value = Coerce::str($spec['value'] ?? null);
            $label = Coerce::str($spec['label'] ?? null);

            if ($kind === LegacySpecs::LOOKUP || $kind === LegacySpecs::MATERIAL) {
                $id = $this->lookups[$list][mb_strtolower($value)] ?? null;
                if ($id === null) {
                    $this->tally('spec_value_unmatched', $label.' = '.$value);

                    continue;
                }
                $specs[$target] ??= $id;
            } elseif ($kind === LegacySpecs::MEASURE) {
                $m = LegacySpecs::measure($value);
                if ($m !== null && $m[1] === LegacySpecs::METRES && $target !== 'water_resistance') {
                    $m = null;                                  // "40 M" on a case is a typo, not 40 metres
                }
                $unitId = $m !== null && $m[1] !== null ? ($this->lookups['units'][mb_strtolower($m[1])] ?? null) : null;
                if ($m === null || $unitId === null) {
                    $this->tally('spec_value_unmatched', $label.' = '.$value);

                    continue;
                }
                $specs[$target] ??= $m[0];
                $specs[$list] ??= $unitId;
            } elseif ($kind === LegacySpecs::VOLUME) {
                $v = LegacySpecs::volume($value);
                if ($v === null) {
                    $this->tally('spec_value_unmatched', $label.' = '.$value);

                    continue;
                }
                $specs[$target] ??= $v;
            } elseif ($kind === LegacySpecs::COLOUR) {
                $id = $this->lookups['colors'][mb_strtolower($value)] ?? null;
                if ($id === null) {
                    $this->tally('spec_value_unmatched', $label.' = '.$value);

                    continue;
                }
                $colours[$target.'|'.$id] = ['color_id' => $id, 'role' => $target];
            }
        }
        foreach (Coerce::arr($record['materials'] ?? null) as $m) {
            $id = $this->lookups['materials'][mb_strtolower(Coerce::str($m))] ?? null;
            if ($id !== null) {
                $specs['material_id'] ??= $id;
            }
        }
        foreach (Coerce::arr($record['specs_unmapped'] ?? null) as $u) {
            $this->tally('spec_label_no_home', LegacySpecs::normaliseLabel(Coerce::str(Coerce::arr($u)['label'] ?? null)));
        }

        // ── images on disk ──
        $images = [];
        foreach (Coerce::arr($record['images'] ?? null) as $i) {
            $image = Coerce::arr($i);
            $file = Coerce::str($image['file'] ?? null);
            if ($file === '') {
                continue;
            }
            if (isset($this->imageMap[$file])) {
                $images[] = ['file' => $file, 'alt' => Coerce::str($image['alt'] ?? null), 'on_disk' => true];

                continue;
            }
            $onDisk = $imagesRoot !== null && is_file($imagesRoot.'/'.$file);
            if (! $onDisk) {
                $this->count('image_not_on_disk');
            }
            $images[] = ['file' => $file, 'alt' => Coerce::str($image['alt'] ?? null), 'on_disk' => $onDisk];
        }

        /*
         * The FEATURED image (the first) is the cover, or there is none. Without this, a product
         * whose own photo is missing took the first gallery file that was there — on the old site
         * that is often a brand's shared BOX photo (one sits in 684 galleries), so the card would
         * have shown a box. No images at all is the honest state: the product stays hidden and
         * says why.
         */
        if ($images !== [] && ! $images[0]['on_disk']) {
            $notes[] = 'featured_image_missing';
            $images = array_map(fn (array $i): array => ['on_disk' => false] + $i, $images);
        }

        return [
            'brand_id' => $brandId,
            'node_ids' => $nodeIds,
            'primary' => $primary,
            'gender_ids' => array_keys($genderIds),
            'specs' => $specs,
            'colours' => array_values($colours),
            'images' => $images,
            'notes' => $notes,
        ];
    }

    /**
     * Create one product. Returns [product id, visible, what keeps it hidden].
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $resolved
     * @return array{id: int, visible: bool, missing: list<string>, slug: string, slug_kept: bool}
     */
    public function create(array $record, array $resolved, string $stockMode, ?string $imagesRoot): array
    {
        $legacyId = Coerce::int($record['legacy_id'] ?? null);
        $titleEn = Coerce::str($record['title_en'] ?? null);
        $titleAr = Coerce::str($record['title_ar'] ?? null);
        $seo = Coerce::arr($record['seo'] ?? null);

        // ── images first: a file that will not decode is dropped before any row exists ──
        $imagesPayload = [];
        foreach (Coerce::arr($resolved['images'] ?? null) as $index => $i) {
            $image = Coerce::arr($i);
            if (! Coerce::bool($image['on_disk'] ?? false)) {
                continue;
            }
            $stored = $this->storeImage(Coerce::str($image['file'] ?? null), $imagesRoot);
            if ($stored === null) {
                continue;
            }
            $alt = Coerce::str($image['alt'] ?? null);
            $imagesPayload[] = [
                'path' => $stored['folder'].'/'.$stored['file'],
                'is_cover' => $imagesPayload === [],
                'alt_en' => mb_substr($alt !== '' ? $alt : $titleEn, 0, 255),
                'alt_ar' => $titleAr === '' ? null : mb_substr($titleAr, 0, 255),
                'width' => $stored['width'],
                'height' => $stored['height'],
                'renditions' => $stored['renditions'],
            ];
        }

        $primary = Coerce::nint($resolved['primary'] ?? null);
        $family = $this->products->familyFor(['primary_category_id' => $primary], null);
        $specs = $this->specsFor($family, Coerce::arr($resolved['specs'] ?? null));
        $colours = Coerce::arr($resolved['colours'] ?? null);
        if ($family !== 'watch') {
            // Dial and band are watch roles; on anything else the colour is the product's own.
            $colours = array_map(fn (mixed $c): array => ['color_id' => Coerce::int(Coerce::arr($c)['color_id'] ?? null), 'role' => 'main'], $colours);
        }

        $sku = Coerce::nstr($record['sku'] ?? null);
        $payload = [
            'brand_id' => Coerce::nint($resolved['brand_id'] ?? null),
            'wa_code' => self::CODE_PREFIX.$legacyId,
            'sku' => $sku !== null && mb_strlen($sku) <= 64 ? $sku : null,
            'selling_price' => Coerce::float($record['regular'] ?? null),
            'sale_price' => Coerce::nfloat($record['sale'] ?? null),
            'purchase_price' => 0,
            'currency' => 'EGP',
            'is_active' => true,
            'family' => $family,
            'primary_category_id' => $primary,
            'title' => ['ar' => $titleAr !== '' ? $titleAr : $titleEn, 'en' => $titleEn !== '' ? $titleEn : $titleAr],
            'short_description' => ['ar' => Coerce::str($record['short_ar'] ?? null), 'en' => Coerce::str($record['short_en'] ?? null)],
            'long_description' => ['ar' => Coerce::str($record['long_ar'] ?? null), 'en' => Coerce::str($record['long_en'] ?? null)],
            'meta_title' => ['ar' => '', 'en' => Coerce::str($seo['title'] ?? null)],
            'meta_description' => ['ar' => '', 'en' => Coerce::str($seo['description'] ?? null)],
            'gender_ids' => Coerce::intList($resolved['gender_ids'] ?? null),
            'colors' => $colours,
            'specs' => $specs,
            'images' => $imagesPayload,
        ];

        $productId = $this->products->create($payload, null);

        try {
            DB::table('catalog_products')->where('id', $productId)->update(['import_ref' => self::REF_PREFIX.$legacyId]);
            if ($titleAr === '') {
                // The Arabic title IS the English one: marked as machine so the screen flags it.
                DB::table('catalog_product_translations')->where('product_id', $productId)->where('locale', 'ar')->update(['is_machine' => 1]);
            }

            // ── placement (undoable) ──
            $nodeIds = Coerce::intList($resolved['node_ids'] ?? null);
            if ($nodeIds !== []) {
                $this->placements->place($this->storefrontId, $productId, $nodeIds, $primary);
            }

            // ── stock (history — after everything undoable) ──
            $variants = Coerce::arr($record['variants'] ?? null);
            $stock = Coerce::arr($record['stock'] ?? null);
            if ($stockMode === self::STOCK_LEDGER && $variants === []) {
                $qty = Coerce::int($stock['quantity'] ?? null);
                if (Coerce::bool($stock['managed'] ?? false) && $qty > 0) {
                    $this->inventory->set(StockTarget::product($productId), InventoryService::BUCKET_EXPRESS, $qty, 'import',
                        null, Actor::system(), $this->storefrontId, self::REF_PREFIX.$legacyId, 'Brand Fashion legacy import — opening balance');
                    $this->count('stock_units', $qty);
                }
            }

            // ── variants ──
            foreach ($variants as $v) {
                $variant = Coerce::arr($v);
                $qty = $stockMode === self::STOCK_LEDGER && Coerce::bool($variant['managed'] ?? false) ? max(0, Coerce::int($variant['quantity'] ?? null)) : 0;
                $this->variants->create($productId, [
                    'label' => Coerce::str($variant['label'] ?? null),
                    'sku' => Coerce::nstr($variant['sku'] ?? null),
                    'size_id' => $this->sizeId(Coerce::nstr($variant['size'] ?? null)),
                    'color_id' => $this->lookups['colors'][mb_strtolower(Coerce::str($variant['colour'] ?? null))] ?? null,
                    'price_delta' => Coerce::float($variant['price_delta'] ?? null),
                    'stock_express' => $qty,
                    'is_active' => true,
                ], null);
                $this->count('variants_created');
                $this->count('stock_units', $qty);
            }

            // ── visibility and the URL, last ──
            $missing = $this->placements->missingForVisibility($productId);
            if ($nodeIds === []) {
                $missing[] = 'category';
            }
            if (! Coerce::bool($record['catalog_visible'] ?? true)) {
                $missing[] = 'hidden on the old site';
            }
            $visible = $missing === [];

            $slug = Coerce::str($record['slug'] ?? null);
            $slugKept = true;
            try {
                $this->placements->save($this->storefrontId, $productId, ['is_visible' => $visible, 'slug' => $slug, 'is_featured' => false]);
            } catch (FieldRefusal $e) {
                // The legacy slug is taken on Brand Fashion (or will not fit): a slug from the
                // title instead, and the redirect map carries the old URL to the new one.
                $slugKept = false;
                $this->placements->save($this->storefrontId, $productId, ['is_visible' => $visible, 'is_featured' => false]);
            }
            $finalSlug = Coerce::str(DB::table('storefront_product')->where('storefront_id', $this->storefrontId)->where('product_id', $productId)->value('slug'));

            $this->imported[$legacyId] = $productId;
            $this->claimed[self::identityKey($sku, $titleEn, self::photoOf($record))] = $productId;

            return ['id' => $productId, 'visible' => $visible, 'missing' => array_map(fn (mixed $m): string => Coerce::str($m), $missing), 'slug' => $finalSlug, 'slug_kept' => $slugKept && $finalSlug === $slug];
        } catch (Throwable $e) {
            $this->discard($productId);

            throw $e;
        }
    }

    /**
     * Undo a half-created product so a re-run gets a clean second chance — refused once it has a
     * ledger movement that is not this importer's own opening balance, or any order line.
     */
    public function discard(int $productId): bool
    {
        $foreign = DB::table('inventory_movements')->where('product_id', $productId)->where('reason', '!=', 'import')->exists();
        if ($foreign || DB::table('order_items')->where('product_id', $productId)->exists()) {
            return false;
        }
        DB::transaction(function () use ($productId): void {
            foreach (CatalogueCleanup::CHILD_TABLES as $table) {
                DB::table($table)->where('product_id', $productId)->delete();
            }
            DB::table('inventory_movements')->where('product_id', $productId)->delete();
            DB::table('catalog_products')->where('id', $productId)->delete();
        });

        return true;
    }

    /**
     * One source file through core's pipeline (master + renditions), ONCE per run and across runs:
     * the same box photo sits in 684 galleries and is stored one time.
     *
     * @return array{file: string, folder: string, width: int, height: int, renditions: array<int, array<string, string>>}|null
     */
    private function storeImage(string $file, ?string $imagesRoot): ?array
    {
        if (isset($this->imageMap[$file])) {
            $this->count('images_reused');

            return $this->imageMap[$file];
        }
        if ($imagesRoot === null) {
            return null;
        }
        try {
            $stored = $this->media->storePath($imagesRoot.'/'.$file, 'product');
        } catch (Throwable $e) {
            $this->count('image_unreadable');
            $this->tally('image_unreadable', $file.' — '.mb_substr($e->getMessage(), 0, 80));

            return null;
        }
        $entry = [
            'file' => $stored['file'], 'folder' => $stored['folder'],
            'width' => $stored['width'], 'height' => $stored['height'], 'renditions' => $stored['renditions'],
        ];
        $this->imageMap[$file] = $entry;
        if ($this->imageMapFile !== null) {
            file_put_contents($this->imageMapFile, json_encode(['source' => $file, 'stored' => $entry], JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);
        }
        $this->count('images_processed');

        return $entry;
    }

    /**
     * The resolved spec values, kept only where the product's family has the field.
     *
     * @param  array<string, mixed>  $specs
     * @return array<string, mixed>
     */
    private function specsFor(string $family, array $specs): array
    {
        $block = SpecBlocks::for($family);
        if ($block === null) {
            if ($specs !== []) {
                $this->count('specs_family_has_no_block');
            }

            return [];
        }
        $keys = [];
        foreach ($block['fields'] as $field) {
            $keys[$field['key']] = true;
            if (isset($field['unit'])) {
                $keys[$field['unit']] = true;
            }
        }
        $kept = array_intersect_key($specs, $keys);
        $this->count('spec_fields_written', count($kept));
        $this->count('spec_fields_no_home_in_family', count($specs) - count($kept));

        return $kept;
    }

    private function sizeId(?string $label): ?int
    {
        if ($label === null || trim($label) === '') {
            return null;
        }
        $key = mb_strtolower(trim($label));
        // The words are our letters — the same reading the September importer applies.
        $key = match ($key) {
            'small' => 's',
            'medium' => 'm',
            'large' => 'l',
            default => $key,
        };
        if (isset($this->lookups['sizes'][$key])) {
            return $this->lookups['sizes'][$key];
        }
        $numeric = preg_match('/^\d{2}$/', $key) === 1;
        $id = $this->lookupWriter->create('sizes', [
            'name' => ['ar' => trim($label), 'en' => trim($label)],
            'extra' => ['type' => $numeric ? 'shoes' : 'clothing', 'sort' => $numeric ? (int) $key : 99],
        ]);
        $this->lookups['sizes'][$key] = $id;
        $this->tally('size_created', trim($label));

        return $id;
    }

    /** @return array{id: int, active: bool}|null */
    private function node(string $path): ?array
    {
        if (! array_key_exists($path, $this->nodes)) {
            $id = CategoryMerger::find($this->storefrontId, $path);
            $this->nodes[$path] = $id === null ? null : [
                'id' => $id,
                'active' => (bool) DB::table('storefront_categories')->where('id', $id)->value('is_active'),
            ];
        }

        return $this->nodes[$path];
    }

    /** The identity a row claims: its SKU, or — with none — its name and its first photo (rule 3). */
    public static function identityKey(?string $sku, string $title, string $photo = ''): string
    {
        $key = LegacyText::skuKey($sku);

        return $key !== null ? 'sku:'.$key : 'name:'.LegacyText::nameKey($title).'|'.$photo;
    }

    /** The product a duplicate's twin became, once it has been created (0 = not yet). */
    public function claimant(?string $sku, string $title, string $photo = ''): int
    {
        return $this->claimed[self::identityKey($sku, $title, $photo)] ?? 0;
    }

    /**
     * An extract record's first photo file, '' without one.
     *
     * @param  array<string, mixed>  $record
     */
    public static function photoOf(array $record): string
    {
        return Coerce::str(Coerce::arr(array_values(Coerce::arr($record['images'] ?? null))[0] ?? null)['file'] ?? null);
    }

    /** Every old product's first photo, by WordPress id (rule 3). */
    public function learnPhotos(string $productsFile): void
    {
        $handle = fopen($productsFile, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot read {$productsFile}");
        }
        while (($line = fgets($handle)) !== false) {
            if (trim($line) === '') {
                continue;
            }
            $record = Coerce::arr(json_decode($line, true, 512, JSON_THROW_ON_ERROR));
            $this->photos[Coerce::int($record['legacy_id'] ?? null)] = self::photoOf($record);
        }
        fclose($handle);
    }

    public static function brandKeyOf(string $name): string
    {
        return self::brandKey($name);
    }

    /** The brand key after the confirmed aliases (D-10): `AP` reads as Audemars Piguet. */
    public static function canonicalBrandKey(string $name): string
    {
        $key = self::brandKey($name);

        return self::BRAND_ALIASES[$key] ?? $key;
    }

    private static function brandKey(string $name): string
    {
        $text = mb_strtolower(LegacyText::line($name));
        // Accents do not make a different brand: the old site's "HERMES" is the brand created as "Hermès"
        // (found in the rehearsal of 2026-11-11 — three products had gone to Generic).
        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);
            if (is_string($decomposed)) {
                $text = preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $text;
            }
        }

        return trim(preg_replace('/[^\p{L}\p{N}]+/u', '', $text) ?? $text);
    }

    public function count(string $name, int $by = 1): void
    {
        $this->counts[$name] = ($this->counts[$name] ?? 0) + $by;
    }

    public function tally(string $name, string $value): void
    {
        $this->tallies[$name][$value] = ($this->tallies[$name][$value] ?? 0) + 1;
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        ksort($this->counts);

        return $this->counts;
    }

    /** @return array<string, array<string, int>> */
    public function tallies(): array
    {
        $out = [];
        foreach ($this->tallies as $k => $v) {
            arsort($v);
            $out[$k] = $v;
        }

        return $out;
    }

    /**
     * The existing product a match points at, for the match list.
     *
     * @return array<string, string>
     */
    public function existing(int $id): array
    {
        $p = DB::table('catalog_products as cp')
            ->leftJoin('catalog_product_translations as t', fn (JoinClause $j) => $j->on('t.product_id', '=', 'cp.id')->where('t.locale', 'en'))
            ->where('cp.id', $id)->first(['cp.wa_code', 'cp.sku', 'cp.import_ref', 'cp.is_active', 'cp.stock_express', 'cp.stock_market', 't.title']);
        if ($p === null) {
            throw new RuntimeException("Product {$id} vanished during the run.");
        }
        $r = Row::cast($p);
        $ref = Row::nstr($r, 'import_ref') ?? '';
        $origin = str_starts_with($ref, CatalogueCleanup::SHEET_PREFIX) ? 'sheet'
            : (DB::connection('legacy')->table('products')->where('id', $id)->exists() ? 'transform' : 'dashboard');
        $placements = [];
        foreach (DB::table('storefront_product')->where('product_id', $id)->get(['storefront_id', 'is_visible']) as $sp) {
            $s = Row::cast($sp);
            $placements[Row::int($s, 'storefront_id')] = Row::bool($s, 'is_visible') ? 'visible' : 'hidden';
        }

        return [
            'wa_code' => Row::str($r, 'wa_code'),
            'sku' => Row::nstr($r, 'sku') ?? '',
            'origin' => $origin,
            'title' => Row::nstr($r, 'title') ?? '',
            'units' => (string) (Row::int($r, 'stock_express') + Row::int($r, 'stock_market')),
            'watchizer' => $placements[Storefront::WATCHIZER_ID] ?? 'not placed',
            'brandfashion' => $placements[Storefront::BRAND_FASHION_ID] ?? 'not placed',
        ];
    }
}
