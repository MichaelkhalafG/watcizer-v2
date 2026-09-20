<?php

namespace App\Domain\Catalog;

use App\Domain\Activity\ActivityLog;
use App\Models\Catalog\Product;
use App\Storefront\StorefrontCache;
use App\Support\Coerce;
use App\Support\ManageText;
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
        'family', 'brand_id', 'grade_id', 'wa_code', 'sku', 'hs_code',
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

            // No diff on a creation: there is no `before`, and logging forty fields as "changed
            // from nothing" would drown the updates that carry the real answers.
            ActivityLog::record('catalog_products', $id, ActivityLog::CREATED, label: $this->labelFor($id));

            return $id;
        });
    }

    /**
     * What to call this product in the log.
     *
     * Captured at write time and stored on the row, because a log entry has to outlive its subject:
     * six months later "catalog_products 512" is unreadable and "512 — Rolex Submariner" is the
     * answer somebody was looking for. Arabic first, since that is the dashboard's language.
     */
    private function labelFor(int $productId): ?string
    {
        $title = DB::table('catalog_product_translations')
            ->where('product_id', $productId)
            ->orderByRaw("FIELD(locale, 'ar', 'en')")
            ->value('title');

        return is_string($title) && $title !== '' ? $title : null;
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

            $this->assertVisibleProductStaysComplete($productId, $data);

            $family = $this->familyFor($data, $productId);
            $row = $this->scalarColumns($data, $family);
            $row['updated_by'] = $actorId;
            $row['updated_at'] = now();

            /*
             * The BEFORE snapshot, taken inside the transaction and limited to the columns this
             * update actually writes. Reading the whole row would log forty fields to report two,
             * and reading it outside the transaction would race the very write it describes.
             *
             * `updated_by` / `updated_at` are excluded: they change on every save by construction,
             * so logging them would put a "changed" row against a save that changed nothing — the
             * shape that turns an audit trail into something nobody reads.
             */
            $audited = array_diff_key($row, ['updated_by' => true, 'updated_at' => true]);

            /*
             * ── The snapshot covers the WHOLE edit, and is taken before ANY of it (B4)
             *
             * This used to audit `catalog_products` alone, and it recorded the row immediately
             * after that one UPDATE — before `writeTranslations()`, `writeSpecs()`, `writePivots()`
             * and `writeImages()` had run. Those four write the title, the description, the
             * specification block, the categories, the colours and the images: in other words,
             * nearly everything an operator actually changes on this form. None of it was logged.
             *
             * It was invisible because a second defect filled the gap with noise: `ActivityLog::same()`
             * read the database's `0` as different from the payload's `false`, so every save produced
             * a phantom `is_active` row and the log looked busy. Fix that alone and a description
             * edit logs NOTHING AT ALL — the audit trail goes from wrong to silent, which is worse.
             * That is why the two are one change.
             */
            $before = self::auditSnapshot($productId);

            DB::table('catalog_products')->where('id', $productId)->update($row);

            $this->writeTranslations($productId, $data);
            $this->writeSpecs($productId, $family, $data);
            $this->writePivots($productId, $data);
            $this->writeImages($productId, $data);

            // AFTER all five writes, so one save is one row and it names what actually moved.
            ActivityLog::record(
                'catalog_products',
                $productId,
                ActivityLog::UPDATED,
                $before,
                self::auditSnapshot($productId),
                label: $this->labelFor($productId),
            );

            // A price change has to reach every storefront row, because `effective_price` is a
            // maintained mirror of the catalog price while the override gate is off (§2.4, D3).
            // Forgetting this is how a storefront keeps selling at yesterday's price.
            $this->refreshEffectivePrices($productId);
            $this->indexer->reindex($productId);
            $this->flush($productId);
        });
    }

    /**
     * The `catalog_products` columns the audit snapshot carries.
     *
     * Exactly what {@see self::scalarColumns()} writes, minus the two it always moves
     * (`updated_by`, `updated_at`) and minus the two that belong to the LEDGER (`stock_express`,
     * `stock_market` — `InventoryService` records those against this same subject type, with the
     * movement that caused them). `model_number` is not here because nothing writes it any more.
     *
     * @var list<string>
     */
    /**
     * The translated columns the snapshot carries, per locale.
     *
     * Read off the schema rather than guessed: the first version of this asked for `description`,
     * which does not exist — the table carries `short_description` and `long_description` — and
     * every product save 500'd with a 1054 until `ProductFormTest` said so.
     *
     * `is_machine` is not here on purpose: it is a MARKER about a translation's provenance, moved
     * by the importer and by the machine-translation badge, not something an operator edits.
     *
     * @var list<string>
     */
    private const AUDITED_TRANSLATION_COLUMNS = [
        'title', 'short_description', 'long_description', 'model_name', 'country', 'stone',
        'meta_title', 'meta_description',
    ];

    private const AUDITED_COLUMNS = [
        'family', 'brand_id', 'grade_id', 'wa_code', 'sku', 'hs_code',
        'purchase_price', 'selling_price', 'sale_price', 'currency',
        'low_stock_threshold', 'warranty_years', 'is_active', 'search_keywords', 'specs',
    ];

    /**
     * Everything one product save can change, as one flat map (B4, 2026-09-20).
     *
     * Read twice per update — once before the writes, once after — which is two extra indexed reads
     * on a screen the team saves a few hundred times a day. That is the price of an audit trail that
     * answers the question it exists for; the alternative measured for a month as "who changed this
     * description?" having no answer.
     *
     * ── What is in it, and what is deliberately NOT ──────────────────────────────────
     *
     * The scalar columns, both titles and both descriptions, the specification block, and a COUNT
     * or a sorted id list for the things that are sets. A set is summarised rather than expanded
     * because the useful audit fact is "the colours changed, from these three to those two" — and
     * `2,7,9 → 2,9` says that in a cell somebody can read, where forty rows of pivot diff would not.
     *
     * `updated_by` / `updated_at` are excluded for the reason they always were: they move on every
     * save by construction, so including them would log a change against a save that changed
     * nothing. `stock_express` / `stock_market` are excluded because they are the LEDGER's, recorded
     * against this same subject type by `InventoryService` with the movement that caused them —
     * two writers on one fact is how a log starts contradicting itself.
     *
     * @return array<string, mixed>
     */
    private static function auditSnapshot(int $productId): array
    {
        $row = DB::table('catalog_products')->where('id', $productId)->first();
        if (! is_object($row)) {
            return [];
        }
        $product = Row::cast($row);

        $out = [];
        foreach (self::AUDITED_COLUMNS as $column) {
            $value = $product->{$column} ?? null;
            $out[$column] = is_scalar($value) ? $value : null;
        }

        // Both languages: an edit that touched only the English is still an edit, and a snapshot
        // carrying one locale would report it as nothing happening.
        foreach (['ar', 'en'] as $locale) {
            $translation = DB::table('catalog_product_translations')
                ->where('product_id', $productId)->where('locale', $locale)
                ->first(self::AUDITED_TRANSLATION_COLUMNS);

            $fields = is_object($translation) ? Row::cast($translation) : null;
            foreach (self::AUDITED_TRANSLATION_COLUMNS as $field) {
                $out[$field.'_'.$locale] = $fields === null ? null : Row::nstr($fields, $field);
            }
        }

        // The specification block, as its own columns — this is the tab the team spends the most
        // time in, and "the movement changed" is exactly what somebody comes to the log to find.
        $specs = DB::table(SpecBlocks::WATCH_SPECS_TABLE)->where('product_id', $productId)->first();
        if (is_object($specs)) {
            foreach ((array) $specs as $column => $value) {
                if ($column === 'product_id' || $column === 'id') {
                    continue;
                }
                $out['spec_'.$column] = is_scalar($value) ? $value : null;
            }
        }

        // The sets, as sorted id lists. Sorted so a re-save in a different order is not a change.
        $out['features'] = self::idList('catalog_product_feature', 'feature_id', $productId);
        $out['genders'] = self::idList('catalog_product_gender', 'gender_id', $productId);
        $out['colours'] = self::idList('catalog_product_color', 'color_id', $productId);

        // Images: the count and the cover, which is what changes visibly. The full list would be
        // a wall of filenames nobody reads.
        $out['image_count'] = DB::table('catalog_product_images')->where('product_id', $productId)->count();
        $out['cover_image'] = Coerce::nstr(
            DB::table('catalog_product_images')->where('product_id', $productId)
                ->where('is_cover', 1)->value('path')
        );

        return $out;
    }

    /** One pivot as a sorted, comma-joined id list — `2,7,9`, which reads as a diff. */
    private static function idList(string $table, string $column, int $productId): string
    {
        $ids = [];
        foreach (DB::table($table)->where('product_id', $productId)->pluck($column) as $id) {
            $ids[] = Coerce::int($id);
        }
        sort($ids);

        return implode(',', $ids);
    }

    /**
     * Refuse a REPLACE payload that would strip a live product of a field it needs to stay on sale.
     *
     * ── What this is for ────────────────────────────────────────────────────────────────────
     *
     * `update()` is a full-replace contract: {@see self::writeTranslations()} reads every column in
     * {@see self::TRANSLATED} out of the payload and writes `null` for any that is absent. That is
     * correct for the form — it is how an operator empties a field — and it is how a five-field
     * script silently erases the other thirty-five.
     *
     * Since 2026-09-18 the consequence got worse: those descriptions gate visibility, so blanking
     * them does not merely lose text, it takes the product off the storefront. The form is
     * unaffected because it always sends every field. What this stops is a caller that should have
     * been on the PARTIAL path ({@see ProductPatcher}) and was not.
     *
     * It lives on the writer rather than in a controller deliberately (developer, 2026-09-18):
     * *"a future caller should hit it wherever it comes from."*
     *
     * Only VISIBLE products are guarded. A hidden product has nothing to lose by being emptied, and
     * refusing there would block the legitimate act of clearing a field on a draft.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws RuntimeException naming the fields it would have removed
     */
    private function assertVisibleProductStaysComplete(int $productId, array $data): void
    {
        $visibleOn = DB::table('storefront_product')
            ->where('product_id', $productId)->where('is_visible', true)->count();
        if ($visibleOn === 0) {
            return;
        }

        $labels = [
            'title' => ManageText::t('products.field_title', 'العنوان'),
            'short_description' => ManageText::t('products.field_short_description', 'وصف مختصر'),
            'long_description' => ManageText::t('products.field_long_description', 'الوصف الكامل'),
        ];
        $locales = [
            'ar' => ManageText::t('common.arabic', 'عربي'),
            'en' => ManageText::t('common.english', 'إنجليزي'),
        ];

        $rows = DB::table('catalog_product_translations')
            ->where('product_id', $productId)
            ->get(array_merge(['locale'], PlacementWriter::REQUIRED_TRANSLATED));

        $losing = [];
        $firstColumn = null;
        foreach ($rows as $raw) {
            $row = Row::cast($raw);
            $locale = Row::str($row, 'locale');
            if (! isset($locales[$locale])) {
                continue;
            }

            foreach (PlacementWriter::REQUIRED_TRANSLATED as $column) {
                $held = trim(Row::nstr($row, $column) ?? '') !== '';
                $incoming = trim(Coerce::str(Coerce::arr($data[$column] ?? null)[$locale] ?? null));
                if ($held && $incoming === '') {
                    $losing[] = $labels[$column].' — '.$locales[$locale];
                    $firstColumn ??= $column;
                }
            }
        }

        if ($losing === []) {
            return;
        }

        // Declares its field, so the message lands beside a box rather than at the top of a form
        // whose fields all look fine ({@see FieldRefusal}).
        throw new FieldRefusal($firstColumn ?? 'short_description', ManageText::t(
            'products.replace_would_hide',
            'هذا الطلب سيمسح حقولًا يحتاجها المنتج ليبقى ظاهرًا، وسيختفي من المتجر: :fields. أرسل الحقول كاملة، أو استخدم مسار التحديث الجزئي.',
            ['fields' => implode(ManageText::t('common.list_separator', '، '), $losing)],
        ));
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

            ActivityLog::record(
                'catalog_products', $productId, ActivityLog::DELETED,
                label: $this->labelFor($productId),
            );

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

            ActivityLog::record(
                'catalog_products', $productId, ActivityLog::RESTORED,
                label: $this->labelFor($productId),
            );
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
            /*
             * `model_number` is no longer written (item 4, 2026-09-19). It and `sku` held the same
             * thing, the dashboard asked for it twice, and the merge migration moved the 59 values
             * that only existed here into `sku`.
             *
             * The COLUMN stays, and is deliberately left untouched rather than nulled: fifteen
             * products carry a `model_number` that disagrees with their `sku`, and in fourteen of
             * them it is the truer manufacturer code. Clearing it would destroy the only remaining
             * copy of something nobody has reconciled — see the migration's own note and
             * `docs/wave4d/sku-model-conflicts.tsv`.
             */
            'hs_code' => Coerce::nstr($data['hs_code'] ?? null),
            'purchase_price' => Coerce::float($data['purchase_price'] ?? null),
            'selling_price' => $selling,
            'sale_price' => $sale,
            'currency' => strtoupper(Coerce::str($data['currency'] ?? null, 'EGP')),
            /*
             * ── The default is 0 — "no alert" — since 2026-09-19 (W-2) ──────────────────────
             *
             * It was 5, and 5 turned out to be an assertion nobody had made: **7,578 of the
             * 7,713 live products carry it untouched**, while almost all stock sits between 0 and
             * 3 units. So the low-stock alert fired on 97.5% of the shop — every product was
             * permanently "below its threshold", which is the same as no alert at all, except it
             * is also an alarm the team learns to ignore.
             *
             * A default of 0 says the honest thing: nobody has decided a reorder point for this
             * product yet, so it is not claiming to be low. A product that DOES have a reorder
             * point gets one typed in, or set in bulk from the list — which is the other half of
             * W-2 and the reason this change is safe to make: before it, correcting the 7,578
             * would have meant 7,578 individual form saves.
             *
             * The 7,578 existing rows are NOT rewritten by this. A default only applies to a
             * payload that omits the field, and the column default in the schema is still 5 for
             * anything that writes around this class. Changing 7,578 live rows is the team's
             * decision to make with the bulk action, not a migration's to make for them.
             */
            'low_stock_threshold' => Coerce::int($data['low_stock_threshold'] ?? null, 0),
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
                throw new RuntimeException(ManageText::t(
                    'products.locale_needs_title',
                    'اللغة [:locale] بها بيانات بدون عنوان. أدخل العنوان أو اترك اللغة فارغة تمامًا.',
                    ['locale' => $locale],
                ));
            }

            /*
             * ── The machine-translation badge clears on a CHANGE, not on a save (A-BUG-1) ─────
             *
             * The requirement is still what it always was — the badge *"disappears when a human
             * edits that translation"* — and the door is still the thing that enforces it, not a
             * screen remembering to send a flag. What was wrong was the trigger: this wrote
             * `is_machine = 0` on EVERY pass, whether or not a single character of the translation
             * had moved.
             *
             * That made the badge drain through work that has nothing to do with Arabic. Measured
             * by the review on 2026-09-17:
             *
             *   • changing ONLY `selling_price` on product 635 cleared the flag, Arabic provably
             *     identical;
             *   • a bulk deactivate of 25 products — the form never opened — cleared 25, taking the
             *     review queue from 7,087 to 7,061.
             *
             * `flag=machine_ar` is the queue for 7,087 imported Arabic titles, and it was emptying
             * itself through price edits and bulk actions. Nothing recorded why: the activity log
             * holds `is_active`, so the rows simply stopped appearing.
             *
             * So the write compares first. If every translated column for this locale is byte-
             * identical to what is stored, the row is left as it is — including its badge. A
             * genuinely new locale row has no stored value to match and is a human's writing by
             * definition, so it starts cleared; the importer sets its own flag afterwards, in the
             * one place that is allowed to.
             */
            $stored = DB::table('catalog_product_translations')
                ->where('product_id', $productId)->where('locale', $locale)
                ->first(array_merge(self::TRANSLATED, ['is_machine']));

            $unchanged = $stored !== null;
            if ($stored !== null) {
                foreach (self::TRANSLATED as $column) {
                    $was = $stored->{$column} ?? null;
                    if (Coerce::nstr($was) !== ($values[$column] ?? null)) {
                        $unchanged = false;
                        break;
                    }
                }
            }

            DB::table('catalog_product_translations')->updateOrInsert(
                ['product_id' => $productId, 'locale' => $locale],
                $unchanged
                    // Nothing moved: preserve the badge exactly as it was, including when it is
                    // already 0. Writing the stored value back rather than omitting the column
                    // keeps this one statement the single writer of the row.
                    ? $values + ['is_machine' => Coerce::int($stored->is_machine ?? 0) === 1 ? 1 : 0]
                    : $values + ['is_machine' => 0],
            );
        }

        if (! DB::table('catalog_product_translations')->where('product_id', $productId)->where('locale', 'ar')->exists()) {
            throw new RuntimeException(ManageText::t(
                'products.arabic_title_required',
                'العنوان العربي مطلوب: الترجمة الاحتياطية مُعطّلة، والمنتج بدون عربي لا يصلح للعرض.',
            ));
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

        /*
         * ── A payload that does not MENTION the specs does not touch them (2026-09-20) ──
         *
         * `writePivots()` has always been guarded this way and this was not, and the asymmetry was
         * a live trap: `SpecBlocks::watchColumns()` maps an absent key to a full set of nulls, so
         * any caller omitting `specs` silently blanked TEN columns — case size, the materials, the
         * movement, the water resistance — on a product that was merely being re-saved.
         *
         * It had not bitten because the only caller is the form, and `ProductController::currentPayload()`
         * happens to carry the block. It surfaced the day the audit snapshot grew to cover the spec
         * table (B4) and a "changed nothing" save reported ten columns going to null.
         *
         * The guard belongs HERE rather than at the call sites, so an importer, a CLI command or a
         * bulk action nobody has written yet is covered by construction. A caller that genuinely
         * means "clear the specifications" sends `specs => []`, which is present, empty, and
         * unambiguous — the same contract `feature_ids` and `colors` already have.
         */
        if (! array_key_exists('specs', $data)) {
            return;
        }

        $columns = SpecBlocks::watchColumns(Coerce::arr($data['specs']));
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

            /*
             * A human just put an image on this row, so whatever `media:verify` found wrong with the
             * old one is no longer true (M1s). Cleared here for the same reason `is_machine` is
             * cleared on a human edit: the marker exists to ask somebody to act, and it has to stop
             * asking the moment they have.
             *
             * If the NEW file also encodes badly, nothing broken is served — the pipeline removes an
             * empty rendition rather than recording it — and the next `media:verify --mark` marks
             * the row again. Re-flagging it here would need the upload's `skipped` list to survive a
             * round trip through the browser, which is a lot of plumbing for a case that is already
             * both harmless and detected.
             */
            $row['renditions_failed'] = null;

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
    /**
     * Invalidate every cached payload that carries this product.
     *
     * PUBLIC since wave 4D so {@see ProductPatcher} can call the same invalidation rather than
     * keep a second copy of it — the per-storefront forget AND the catalogue-wide fallback for a
     * product with no placement yet. Two copies of a cache-invalidation rule is how one of them
     * quietly stops invalidating something.
     */
    public function flush(int $productId): void
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
