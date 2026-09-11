<?php

namespace App\Domain\Catalog;

use App\Models\Catalog\Product;
use App\Storefront\StorefrontCache;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The one writer of a product's own record: `catalog_products`, its translations, its family
 * block, its images and its attribute pivots.
 *
 * ── What it deliberately does NOT write ──────────────────────────────────────────────────────
 *
 *  • **Stock.** `stock_express`, `stock_market` and `in_stock` belong to `InventoryService`
 *    (AGENTS §2.5). They are not in {@see self::COLUMNS}, so a payload carrying them is ignored
 *    rather than obeyed — and `StockWriteGuard` would catch it outside production anyway.
 *  • **`family`.** Derived from the product's category through {@see FamilyForCategory}, never
 *    taken from the request. A form field for the family is how the legacy dashboard ended up
 *    with hard-coded category ids in the first place: two sources of the same truth.
 *  • **Per-storefront columns.** Visibility, placement, sort, featured and slug live on
 *    `storefront_product` and belong to {@see PlacementWriter}.
 *
 * ── Explicit column lists, never `$fillable` ─────────────────────────────────────────────────
 *
 * AGENTS §2.9. `Product::$fillable` contains `hs_code`, which exists in the clean schema and in no
 * legacy table, and it contains `specs`, which this class writes only through
 * {@see SpecBlocks}. Every write below names its columns.
 */
final class ProductWriter
{
    /**
     * Columns the dashboard may write on `catalog_products`.
     *
     * Stock is absent by construction. `family` is present because THIS class computes it, not
     * because a caller may pass it.
     *
     * @var list<string>
     */
    public const COLUMNS = [
        'family', 'brand_id', 'grade_id', 'wa_code', 'sku', 'model_number', 'hs_code',
        'purchase_price', 'selling_price', 'sale_price', 'currency', 'low_stock_threshold',
        'warranty_years', 'is_active', 'search_keywords', 'specs',
    ];

    /** Translated columns of `catalog_product_translations` the form edits. */
    public const TRANSLATED = [
        'title', 'short_description', 'long_description', 'model_name', 'country', 'stone',
        'meta_title', 'meta_description',
    ];

    public function __construct(
        private readonly FamilyForCategory $families,
        private readonly StorefrontCache $cache,
        private readonly ProductIndexer $indexer,
    ) {}

    /**
     * Create a product. Returns the new id.
     *
     * @param  array<string, mixed>  $data  validated payload
     */
    public function create(array $data, ?int $actorId): int
    {
        // Before anything is written: may the dashboard create a product AT ALL right now?
        // Refused until the write-switch, because the rebuild would delete it (developer decision
        // 2026-09-11 on wave 4B's flag 1; the reasoning is in {@see PreSwitch}). Asserted HERE
        // rather than in the controller, so a future importer hits the same door.
        PreSwitch::assertMayCreate('product');

        return DB::transaction(function () use ($data, $actorId): int {
            $family = $this->familyFor($data, null);

            $row = $this->scalarColumns($data, $family);
            $row['created_by'] = $actorId;
            $row['updated_by'] = $actorId;
            $row['created_at'] = now();
            $row['updated_at'] = now();

            // The stock columns are NOT named here, and that is deliberate. The first version set
            // them to 0 "because that is the default" and `StockWriteGuard` refused the INSERT —
            // correctly: a statement that carries a stock column is a stock write, whatever the
            // value. M1 declares all three `DEFAULT 0`/`DEFAULT false`, so omitting them gives a
            // new product zero stock AND makes it impossible for this class to write one.
            $id = (int) DB::table('catalog_products')->insertGetId($row);

            $this->writeTranslations($id, $data);
            $this->writeSpecs($id, $family, $data);
            $this->writePivots($id, $data);
            $this->writeImages($id, $data);
            // The searchable words changed, so the FULLTEXT index has to change with them —
            // step 21 builds it from LEGACY names and knows nothing about a row created here.
            $this->indexer->reindex($id);
            $this->flush($id);

            return $id;
        });
    }

    /**
     * Update a product in place.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(int $productId, array $data, ?int $actorId): void
    {
        DB::transaction(function () use ($productId, $data, $actorId): void {
            $product = Product::query()->find($productId);
            if (! $product instanceof Product) {
                throw new RuntimeException("Product {$productId} does not exist.");
            }

            $family = $this->familyFor($data, $productId);
            $row = $this->scalarColumns($data, $family);
            $row['updated_by'] = $actorId;
            $row['updated_at'] = now();

            DB::table('catalog_products')->where('id', $productId)->update($row);

            $this->writeTranslations($productId, $data);
            $this->writeSpecs($productId, $family, $data);
            $this->writePivots($productId, $data);
            $this->writeImages($productId, $data);

            // A price change has to reach every storefront row, because `effective_price` is a
            // maintained mirror of the catalog price while the override gate is off (§2.4, D3).
            // Forgetting this is how a storefront keeps selling at yesterday's price.
            $this->refreshEffectivePrices($productId);
            $this->indexer->reindex($productId);
            $this->flush($productId);
        });
    }

    /**
     * Soft-delete. Never a hard delete: order lines, the ledger and the id map all point at the
     * row, and §2.9.6 rule 2 is that this application does not delete catalogue history.
     */
    public function archive(int $productId, ?int $actorId): void
    {
        DB::transaction(function () use ($productId, $actorId): void {
            DB::table('catalog_products')->where('id', $productId)->update([
                'deleted_at' => now(),
                'is_active' => false,
                'updated_by' => $actorId,
                'updated_at' => now(),
            ]);
            $this->flush($productId);
        });
    }

    public function restore(int $productId, ?int $actorId): void
    {
        DB::transaction(function () use ($productId, $actorId): void {
            DB::table('catalog_products')->where('id', $productId)->update([
                'deleted_at' => null,
                'updated_by' => $actorId,
                'updated_at' => now(),
            ]);
            $this->flush($productId);
        });
    }

    /**
     * The family this product belongs to — from its category, as the transform does.
     *
     * The category comes from the payload's `primary_category_id` when the form sent one (a
     * creation, or an edit that moved the product), and otherwise from the product's existing
     * primary placement.
     *
     * @param  array<string, mixed>  $data
     */
    public function familyFor(array $data, ?int $productId): string
    {
        $node = Coerce::nint($data['primary_category_id'] ?? null);
        if ($node === null && $productId !== null) {
            $node = FamilyForCategory::primaryNodeFor($productId);
        }

        // The CATEGORY decides, and the submitted specs are not consulted when there is one — see
        // `FamilyForCategory::forNode()` for the bug that taught us why (a bag moved to Perfumes
        // stayed a bag because `bag_type` was still in the payload). With no category at all the
        // JSON's key prefixes are the only signal there is, which is the legacy-import case.
        return $node === null
            ? $this->families->forProduct($productId ?? 0, Coerce::arr($data['specs'] ?? null))
            : $this->families->forNode($node);
    }

    // ── pieces ───────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function scalarColumns(array $data, string $family): array
    {
        $selling = Coerce::float($data['selling_price'] ?? null);
        $sale = Coerce::nfloat($data['sale_price'] ?? null);

        // The price contract the storefront and the frontend both enforce (D-21 / the
        // price/total contract): a sale price is a sale price only when 0 < sale < selling.
        // Anything else is stored as NULL rather than as a number that would price a cart wrong.
        if ($sale !== null && ! ($sale > 0 && $sale < $selling)) {
            $sale = null;
        }

        return [
            'family' => $family,
            'brand_id' => Coerce::nint($data['brand_id'] ?? null),
            'grade_id' => Coerce::nint($data['grade_id'] ?? null),
            'wa_code' => Coerce::str($data['wa_code'] ?? null),
            'sku' => Coerce::nstr($data['sku'] ?? null),
            'model_number' => Coerce::nstr($data['model_number'] ?? null),
            'hs_code' => Coerce::nstr($data['hs_code'] ?? null),
            'purchase_price' => Coerce::float($data['purchase_price'] ?? null),
            'selling_price' => $selling,
            'sale_price' => $sale,
            'currency' => strtoupper(Coerce::str($data['currency'] ?? null, 'EGP')),
            'low_stock_threshold' => Coerce::int($data['low_stock_threshold'] ?? null, 5),
            'warranty_years' => Coerce::nint($data['warranty_years'] ?? null),
            'is_active' => Coerce::bool($data['is_active'] ?? null),
            'search_keywords' => Coerce::nstr($data['search_keywords'] ?? null),
            'specs' => self::specsColumn($family, $data),
        ];
    }

    /**
     * The `specs` JSON column: the family's JSON block, or NULL for a family whose attributes live
     * in their own table (watch) or which has no block at all (fashion, other).
     *
     * @param  array<string, mixed>  $data
     */
    private static function specsColumn(string $family, array $data): ?string
    {
        $block = SpecBlocks::for($family);
        if ($block === null || $block['table'] !== 'specs') {
            return null;
        }
        $specs = SpecBlocks::jsonSpecs($family, Coerce::arr($data['specs'] ?? null));

        return $specs === [] ? null : (json_encode($specs, JSON_UNESCAPED_UNICODE) ?: null);
    }

    /**
     * Translations. Arabic is required for the row to exist at all; an empty locale is DELETED
     * rather than stored blank, because translation fallback is off (AGENTS §2.17) and a blank row
     * is indistinguishable from a real one on the storefront.
     *
     * @param  array<string, mixed>  $data
     */
    private function writeTranslations(int $productId, array $data): void
    {
        foreach (['ar', 'en'] as $locale) {
            /** @var array<string, string|null> $values */
            $values = [];
            $any = false;
            foreach (self::TRANSLATED as $column) {
                $value = Coerce::str(Coerce::arr($data[$column] ?? null)[$locale] ?? null);
                $values[$column] = $value === '' ? null : $value;
                if ($value !== '') {
                    $any = true;
                }
            }

            if (! $any) {
                DB::table('catalog_product_translations')
                    ->where('product_id', $productId)->where('locale', $locale)->delete();

                continue;
            }

            // `title` is NOT NULL in the schema: a locale with any content must have a title.
            if (($values['title'] ?? null) === null) {
                throw new RuntimeException("اللغة [{$locale}] بها بيانات بدون عنوان. أدخل العنوان أو اترك اللغة فارغة تمامًا.");
            }

            DB::table('catalog_product_translations')->updateOrInsert(
                ['product_id' => $productId, 'locale' => $locale],
                $values,
            );
        }

        if (! DB::table('catalog_product_translations')->where('product_id', $productId)->where('locale', 'ar')->exists()) {
            throw new RuntimeException('العنوان العربي مطلوب: الترجمة الاحتياطية مُعطّلة، والمنتج بدون عربي لا يصلح للعرض.');
        }
    }

    /**
     * The family block. A watch gets a row in `catalog_product_watch_specs`; a JSON family already
     * had its column written by {@see self::scalarColumns()}.
     *
     * Changing family away from `watch` DELETES the watch row rather than leaving it orphaned:
     * `catalog_product_watch_specs` is keyed by product id, and a stale row would keep answering
     * the detail endpoint's watch block for a bag.
     *
     * @param  array<string, mixed>  $data
     */
    private function writeSpecs(int $productId, string $family, array $data): void
    {
        $block = SpecBlocks::for($family);
        $isWatch = $block !== null && $block['table'] === 'watch_specs';

        if (! $isWatch) {
            DB::table(SpecBlocks::WATCH_SPECS_TABLE)->where('product_id', $productId)->delete();

            return;
        }

        $columns = SpecBlocks::watchColumns(Coerce::arr($data['specs'] ?? null));
        DB::table(SpecBlocks::WATCH_SPECS_TABLE)->updateOrInsert(['product_id' => $productId], $columns);
    }

    /**
     * Attribute pivots: features, genders and colours (with their role).
     *
     * Replace-in-place, not delete-then-insert-outside-a-transaction: the whole write is inside
     * `update()`'s transaction, so a failure halfway leaves the old set intact.
     *
     * @param  array<string, mixed>  $data
     */
    private function writePivots(int $productId, array $data): void
    {
        if (array_key_exists('feature_ids', $data)) {
            $ids = Coerce::intList($data['feature_ids']);
            DB::table('catalog_product_feature')->where('product_id', $productId)->delete();
            foreach ($ids as $id) {
                DB::table('catalog_product_feature')->insert(['product_id' => $productId, 'feature_id' => $id]);
            }
        }

        if (array_key_exists('gender_ids', $data)) {
            $ids = Coerce::intList($data['gender_ids']);
            DB::table('catalog_product_gender')->where('product_id', $productId)->delete();
            foreach ($ids as $id) {
                DB::table('catalog_product_gender')->insert(['product_id' => $productId, 'gender_id' => $id]);
            }
        }

        if (array_key_exists('colors', $data)) {
            $rows = [];
            foreach (Coerce::arr($data['colors']) as $entry) {
                $color = Coerce::arr($entry);
                $colorId = Coerce::nint($color['color_id'] ?? null);
                $role = Coerce::str($color['role'] ?? null, 'main');
                if ($colorId === null || ! in_array($role, ['dial', 'band', 'main'], true)) {
                    continue;
                }
                // The pivot's primary key is (product, color, role): the same colour twice in one
                // role is one row, and de-duplicating here beats a 1062 from the database.
                $rows[$colorId.'|'.$role] = ['product_id' => $productId, 'color_id' => $colorId, 'role' => $role];
            }
            DB::table('catalog_product_color')->where('product_id', $productId)->delete();
            foreach ($rows as $row) {
                DB::table('catalog_product_color')->insert($row);
            }
        }
    }

    /**
     * Images: the gallery in its given order, with exactly one cover.
     *
     * The payload is the FULL desired list (filenames the media endpoint already stored), so
     * reordering, removing and adding are one operation and the screen never has to issue three
     * requests to move an image.
     *
     * `is_cover` is enforced to be exactly one row: the read layer picks the cover with
     * `ORDER BY is_cover DESC, sort` and two covers make the choice arbitrary between two
     * requests. If the payload names none, the first image becomes it.
     *
     * @param  array<string, mixed>  $data
     */
    private function writeImages(int $productId, array $data): void
    {
        if (! array_key_exists('images', $data)) {
            return;
        }

        $incoming = [];
        foreach (Coerce::arr($data['images']) as $index => $entry) {
            $image = Coerce::arr($entry);
            $path = Coerce::nstr($image['path'] ?? null);
            if ($path === null) {
                continue;
            }
            $renditions = Coerce::arr($image['renditions'] ?? null);
            $incoming[] = [
                'id' => Coerce::nint($image['id'] ?? null),
                'path' => $path,
                'is_cover' => Coerce::bool($image['is_cover'] ?? null),
                'sort' => Coerce::int($index),
                'alt_ar' => Coerce::nstr($image['alt_ar'] ?? null),
                'alt_en' => Coerce::nstr($image['alt_en'] ?? null),
                'renditions' => $renditions === [] ? null : (json_encode($renditions) ?: null),
                'width' => Coerce::nint($image['width'] ?? null),
                'height' => Coerce::nint($image['height'] ?? null),
            ];
        }

        // Exactly one cover.
        $coverIndex = null;
        foreach ($incoming as $index => $image) {
            if ($image['is_cover']) {
                $coverIndex = $index;
                break;
            }
        }
        if ($coverIndex === null && $incoming !== []) {
            $coverIndex = 0;
        }

        $keptIds = [];
        foreach ($incoming as $index => $image) {
            $row = [
                'product_id' => $productId,
                'path' => $image['path'],
                'is_cover' => $index === $coverIndex,
                'sort' => $index,
                'alt_ar' => $image['alt_ar'],
                'alt_en' => $image['alt_en'],
                'width' => $image['width'],
                'height' => $image['height'],
                'updated_at' => now(),
            ];
            if ($image['renditions'] !== null) {
                $row['renditions'] = $image['renditions'];
            }

            if ($image['id'] !== null && DB::table('catalog_product_images')->where('id', $image['id'])->where('product_id', $productId)->exists()) {
                DB::table('catalog_product_images')->where('id', $image['id'])->update($row);
                $keptIds[] = $image['id'];

                continue;
            }

            $row['created_at'] = now();
            $keptIds[] = (int) DB::table('catalog_product_images')->insertGetId($row);
        }

        // Rows the payload dropped. The FILE is left alone — `media:prune` is the only thing that
        // deletes from the shared tree, and it is dry-run by default for exactly this reason.
        DB::table('catalog_product_images')
            ->where('product_id', $productId)
            ->when($keptIds !== [], fn ($q) => $q->whereNotIn('id', $keptIds))
            ->delete();
    }

    /**
     * `storefront_product.effective_price` / `effective_sale_price` mirror the catalog price while
     * the override gate is off (AGENTS §2.4). The transform's step 18 writes them; so must an
     * edit here, or the storefront prices from a stale mirror.
     */
    public function refreshEffectivePrices(int $productId): void
    {
        $raw = DB::table('catalog_products')->where('id', $productId)->first(['selling_price', 'sale_price']);
        if ($raw === null) {
            return;
        }
        $product = Row::cast($raw);
        $selling = (float) Row::money($product, 'selling_price');
        $sale = Row::nmoney($product, 'sale_price') === null ? null : (float) Row::nmoney($product, 'sale_price');
        if ($sale !== null && ! ($sale > 0 && $sale < $selling)) {
            $sale = null;
        }

        DB::table('storefront_product')->where('product_id', $productId)->update([
            'effective_price' => $selling,
            'effective_sale_price' => $sale,
            'updated_at' => now(),
        ]);
    }

    /**
     * Every storefront that carries this product goes stale: the product DTO, the listing counts
     * and the compat payloads are all derived (study §3.7.6). The transform bumps the version on
     * every real run for the same reason; an edit made through the dashboard is no different.
     */
    private function flush(int $productId): void
    {
        $storefronts = DB::table('storefront_product')->where('product_id', $productId)->pluck('storefront_id');
        $ids = [];
        foreach ($storefronts as $id) {
            if (is_numeric($id)) {
                $ids[(int) $id] = true;
            }
        }
        // A product with no storefront row yet (just created) still has to invalidate the
        // catalogue-wide caches of every ACTIVE storefront, because listing counts include it the
        // moment it is placed.
        if ($ids === []) {
            foreach (DB::table('storefronts')->where('is_active', true)->pluck('id') as $id) {
                if (is_numeric($id)) {
                    $ids[(int) $id] = true;
                }
            }
        }

        foreach (array_keys($ids) as $storefrontId) {
            $this->cache->forgetProduct($storefrontId, $productId);
            $this->cache->flush($storefrontId);
        }
    }
}
