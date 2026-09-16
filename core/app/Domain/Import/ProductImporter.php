<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Catalog\PlacementWriter;
use App\Domain\Catalog\PreSwitch;
use App\Domain\Catalog\ProductWriter;
use App\Domain\Catalog\SpecBlocks;
use App\Domain\Catalog\VariantWriter;
use App\Domain\Inventory\Actor;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use App\Models\Storefront\Storefront;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Turns {@see SourceRow}s into products, through the SAME doors the dashboard uses (wave 4D).
 *
 * ── Not one line of this class writes a table directly ───────────────────────────────────────
 *
 * `ProductWriter::create()`, `PlacementWriter::save()/place()`, `VariantWriter::create()`,
 * `InventoryService::set()`. That is the whole point of having doors: an importer that wrote
 * `catalog_products` itself would be a second implementation of every rule — the family
 * derivation, the Arabic requirement, the effective-price mirror, the FULLTEXT reindex, the cache
 * bump, the stock ledger — and the first one to drift silently.
 *
 * It also means the importer inherits the refusals, which is why the run needs an explicit
 * exemption: `ProductWriter::create()` calls `PreSwitch::assertMayCreate('product')`, and before
 * the write-switch that is a refusal. {@see PreSwitch::allowing()} opens it for the duration of
 * the command and closes it in a `finally` — the developer's condition: *explicit and reversible,
 * never a permanent hole.*
 *
 * ── What "never drop a row" means in code ────────────────────────────────────────────────────
 *
 * Six things can be missing and none of them refuses a row (developer, 2026-09-14):
 *
 * | missing | what the importer does |
 * |---|---|
 * | SKU | imports with `sku = NULL`, marks `sku` |
 * | a DUPLICATE SKU | first row keeps it, the rest import with `sku = NULL` and are reported |
 * | brand | uses the `Generic` brand, marks `brand` |
 * | Arabic | machine-translates it, flags the TRANSLATION as machine (never marks `arabic`) |
 * | category | imports unplaced, marks `category` — and the product cannot be made visible |
 * | image | imports without one, marks `image` — and the product cannot be made visible |
 * | price | imports at 0, marks `price` |
 *
 * The last two are not this class's opinion: `PlacementWriter::save()` refuses to make a product
 * visible without Arabic, an image and a placement (task 4.2), and that refusal is load-bearing —
 * it is the reason an incomplete import cannot reach a customer. So a marked row lands HIDDEN, and
 * the marker is how the team finds it.
 */
final class ProductImporter
{
    /** Stock arrives as a quantity, never as a column: `import` is a declared ledger reason. */
    private const STOCK_REASON = 'import';

    /** @var array<string, int> gender name => id */
    private array $genders = [];

    /** @var array<string, int> material name => id */
    private array $materials = [];

    /** @var array<string, int> size label => id */
    private array $sizes = [];

    /** @var array<string, true> SKUs already taken, so the SECOND row with one does not collide. */
    private array $takenSkus = [];

    public function __construct(
        private readonly ProductWriter $products,
        private readonly PlacementWriter $placements,
        private readonly VariantWriter $variants,
        private readonly CategoryMerger $merger,
        private readonly BrandResolver $brands,
        private readonly CoverImages $covers,
        private readonly InventoryService $inventory,
        private readonly ExistingCatalogue $catalogue,
    ) {}

    /**
     * Import one row into one storefront. Returns the product id, or null when nothing was written.
     *
     * @param  list<int>  $storefrontIds  every storefront this product should appear on
     */
    public function import(
        SourceRow $row,
        array $storefrontIds,
        ImportReport $report,
        bool $withImages,
        bool $allowCompatVariants = false,
    ): ?int {
        /*
         * The gate, checked ONCE and loudly, before anything else.
         *
         * `ProductWriter::create()` refuses without the exemption, and this method reports a failed
         * creation per row — which is right for a bad row and badly wrong for a shut gate: an
         * import run without `--allow-preswitch` would have written 7 308 identical "refused" lines
         * into a report instead of stopping on the first one. A closed door is not data.
         */
        PreSwitch::assertMayCreate('product');

        $this->hydrateLookups();

        $existing = DB::table('catalog_products')->where('import_ref', $row->ref)->value('id');
        if (is_numeric($existing)) {
            // A second run of the same file. Nothing is duplicated — that is what `import_ref`
            // UNIQUE is for — and the row is reported so the operator knows why the number is
            // smaller than the file.
            $report->count('already_imported');

            return (int) $existing;
        }

        /*
         * ── THE DUPLICATE GATE ──────────────────────────────────────────────────────────────
         *
         * `import_ref` above answers "did WE already import this row". This answers the different
         * and more important question: "does Watchizer already SELL this product". The first real
         * run imported 43 watches the catalogue already had — `BF-14874` beside `1791400`, the
         * same Tommy Hilfiger twice, the new row worse in every respect.
         *
         * SKIP AND REPORT, decided 2026-09-14 after measuring the alternative: a merge that filled
         * only missing fields would have filled ZERO across all 43 pairs — no cover, no title, no
         * model number, no price, no storefront — because the existing rows are complete. Its only
         * effect would have been to add the supplier's category nodes on top of the team's own,
         * which is noise, and to put a write path near the primary placement, which is the one
         * field with real blast radius.
         *
         * The match is written to the DURABLE report — both codes and the rule that fired —
         * because the residual false-positive risk cannot be argued to zero and that file is the
         * only way anybody would ever find out.
         */
        /*
         * ── AN UNKNOWN CATEGORY REFUSES THE ROW (decision 2026-09-15, option b) ─────────────
         *
         * Before the duplicate gate, because a row we cannot place is a row we should not be
         * reasoning about at all. The source leaf is not in `CategoryMap`, so there is no honest
         * answer to "where does this go" — and the two dishonest ones were both on the table:
         * guess a placement, or teach the reconciliation to skip imported rows. The second is
         * worse than the first; an audit that looks away stops being an audit.
         *
         * A leaf the map answers with `none` (their `Uncategorized`) or `gender` is NOT unknown —
         * those are decisions already taken, and they still import and still carry the
         * missing-data marker.
         */
        if ($row->unmappedLeaves !== []) {
            $report->count('refused_unmapped_category');
            foreach ($row->unmappedLeaves as $leaf) {
                $report->note('unmapped_category', $leaf);
            }
            $report->row(
                $row->ref,
                $row->titleEn,
                'refused',
                'category not in the map: '.implode(', ', $row->unmappedLeaves)
                .' — add it to CategoryMap::LEAVES, or decide it maps to nothing, then re-run.',
            );

            return null;
        }

        $duplicate = $this->catalogue->match($row);
        if ($duplicate !== null) {
            $report->count('duplicate_of_existing');
            $report->count('duplicate_by_'.str_replace('-', '_', $duplicate['rule']));
            $report->row(
                $row->ref,
                $row->titleEn,
                'duplicate',
                sprintf(
                    'already in the catalogue as %s (sku %s) — matched by %s on "%s" — existing title: %s',
                    $duplicate['wa_code'],
                    $duplicate['sku'] ?? 'none',
                    $duplicate['rule'],
                    $duplicate['code'],
                    $duplicate['title'] === '' ? '(no Arabic title)' : $duplicate['title'],
                ),
            );

            return null;
        }

        $missing = [];

        /*
         * ── brand ───────────────────────────────────────────────────────────────────────────
         *
         * A source that KNOWS its brand is believed; only a source that does not has it read out
         * of the product title. Both paths end at a real `catalog_brands` row, and only the
         * guessing path can fail and mark the product.
         */
        $brand = $row->brand !== null && trim($row->brand) !== ''
            ? $this->brands->named($row->brand)
            : $this->brands->forTitle($row->titleEn);

        if (! $brand['matched']) {
            $missing[] = ImportReport::MISSING_BRAND;
        }

        // ── the Arabic title: machine, and marked as machine ────────────────────────────────
        $arabic = ArabicTitle::from($row->titleEn, $brand['matched'] ? $brand['name'] : null);
        if ($arabic === null) {
            // Nothing to build from. The English is used so the row is not nameless, and it is
            // marked — a product whose Arabic IS English is exactly what a reviewer must see.
            $arabic = $row->titleEn;
            $missing[] = ImportReport::MISSING_ARABIC;
        }

        // ── SKU, first-come-first-served ────────────────────────────────────────────────────
        $sku = $row->sku;
        if ($sku === null || $sku === '') {
            $sku = null;
            $missing[] = ImportReport::MISSING_SKU;
        } elseif (isset($this->takenSkus[$sku]) || $this->skuExists($sku)) {
            $report->row($row->ref, $row->titleEn, 'duplicate_sku', "SKU [{$sku}] already belongs to another product; imported without one.");
            $sku = null;
            $missing[] = ImportReport::MISSING_SKU;
        }

        if (! $row->hasPrice()) {
            $missing[] = ImportReport::MISSING_PRICE;
        }

        /*
         * ── the categories, BEFORE the product ──────────────────────────────────────────────
         *
         * `ProductWriter::familyFor()` asks the PRIMARY CATEGORY what family this is, and a
         * product that does not exist yet has no placement to ask — so the node ids have to be
         * resolved first and the primary passed in the payload. Placing afterwards and hoping
         * left every imported product on the fallback family: a Tommy Hilfiger watch arrived as
         * `fashion`, which is what the end-to-end test caught.
         */
        $nodesByStorefront = [];
        $primaryByStorefront = [];
        $materialOnly = false;

        foreach ($storefrontIds as $storefrontId) {
            [$ids, $primary, $wasMaterialOnly] = $this->placementFor($storefrontId, $row, $report);
            $nodesByStorefront[$storefrontId] = $ids;
            $primaryByStorefront[$storefrontId] = $primary;
            $materialOnly = $materialOnly || $wasMaterialOnly;
        }

        $primaryNode = null;
        foreach ($primaryByStorefront as $primary) {
            if ($primary !== null) {
                $primaryNode = $primary;
                break;
            }
        }

        if ($materialOnly) {
            /*
             * The exception, reported every time it fires: this product's only placement is a
             * MATERIAL node, so that node had to take the primary — a placed product cannot be
             * primary-less. Its breadcrumb will read "Shop by material → Satin" until somebody
             * gives it a real section, which is what this report line is for. Measured on the
             * file: 53 Satin rows, 0 Leather.
             */
            $report->row($row->ref, $row->titleEn, 'material_only_primary',
                'the only category this row names is a MATERIAL, so the material node is its primary placement. '
                .'Give it a real section.');
            $report->count('material_only_primary');
        }

        // ── the product itself ──────────────────────────────────────────────────────────────
        $payload = [
            'brand_id' => $brand['id'],
            'wa_code' => $this->waCode($row),
            'sku' => $sku,
            'model_number' => $row->modelNumber,
            'selling_price' => $row->sellingPrice,
            'sale_price' => $row->salePrice,
            'purchase_price' => $row->purchasePrice,
            'currency' => 'EGP',
            'is_active' => true,
            // The reader's family is the FALLBACK, for a row that landed in no category at all.
            // When there is a category, the writer's own derivation wins — one rule for the shop.
            'family' => $row->family,
            'primary_category_id' => $primaryNode,
            'title' => ['ar' => $arabic, 'en' => $row->titleEn],
            'long_description' => ['ar' => null, 'en' => $row->description],
            'gender_ids' => $this->idsFor($row->genders, $this->genders),
        ];

        /*
         * The material has exactly one home in our schema and it is on a WATCH: `case_material_id`,
         * `glass_material_id`, `band_material_id`. There is no material field on a bag or a wallet,
         * so "Leather" on a handbag has nowhere to go — it is reported rather than invented into a
         * column that does not exist. On a watch it is the BAND: a leather watch in this catalogue
         * means a leather strap.
         */
        $materialIds = $this->idsFor($row->materials, $this->materials);
        if ($materialIds !== []) {
            /*
             * A watch records the material on its BAND — a leather watch in this catalogue means a
             * leather strap. Everything else records it in its own spec block, where wave 4D gave
             * `material_id` a home for bags, wallets and fashion goods; before that a leather
             * handbag had nowhere at all to say it was leather.
             */
            $field = $row->family === 'watch' ? 'band_material_id' : 'material_id';

            if ($row->family === 'watch' || SpecBlocks::hasField($row->family, 'material_id')) {
                // NESTED under `specs`, which is where both halves of the writer look — the watch
                // columns and the JSON block. A top-level key is silently ignored.
                $payload['specs'] = [$field => $materialIds[0]];
            } else {
                // Still true for `perfume` and `electronics`: no material field, so it is reported
                // rather than invented into a column that does not exist.
                $report->count('material_no_home');
            }
        }

        try {
            $productId = $this->products->create($payload, null);
        } catch (Throwable $e) {
            // A row that cannot be created at all is REPORTED, never swallowed: the operator must
            // be able to see the count of products the file has and the count we have.
            $report->row($row->ref, $row->titleEn, 'refused', mb_substr($e->getMessage(), 0, 200));
            $report->count('refused');

            return null;
        }

        if ($sku !== null) {
            $this->takenSkus[$sku] = true;
        }

        DB::table('catalog_products')->where('id', $productId)->update(['import_ref' => $row->ref]);
        $this->markMachineTranslation($productId);

        /*
         * ── the order of the rest is chosen by what a FAILURE leaves behind ─────────────────
         *
         * Placement first: it writes rows that `discard()` can remove. Stock second: a ledger
         * movement is HISTORY, and history is never undone by an importer — so a crash between the
         * two used to strand a product that could not be cleaned up (32 of them after the first
         * parallel run, all with a movement and no storefront row). Everything that can be undone
         * now happens before the one thing that cannot.
         */
        $placed = false;
        foreach ($nodesByStorefront as $storefrontId => $nodeIds) {
            if ($nodeIds !== []) {
                // Every node the row named, with the PRIMARY chosen by the map rather than by depth.
                $this->placements->place($storefrontId, $productId, $nodeIds, $primaryByStorefront[$storefrontId] ?? null);
                $placed = true;
            }
        }

        // ── stock, through the one door ─────────────────────────────────────────────────────
        if ($row->stock !== null && $row->stock > 0 && $row->variants === []) {
            $this->inventory->set(
                StockTarget::product($productId),
                InventoryService::BUCKET_EXPRESS,
                $row->stock,
                self::STOCK_REASON,
                null,
                Actor::system(),
                null,
                $row->ref,
            );
        }

        /*
         * ── variants, and the ONE storefront where they have a consequence ──────────────────
         *
         * `CompatVariantInvariantTest` states the rule: **no product reachable through the compat
         * layer sells through variants**, because the legacy Next.js cart has no variant field and
         * would decrement the product while the units live on a size. Watchizer (storefront 1) is
         * the storefront the compat layer serves.
         *
         * Both compat doors already REFUSE such a product — `add_to_cart` answers 422 "This product
         * requires selecting an option" and the checkout answers 422 "One of the items is no longer
         * available" — so nothing oversells. What happens instead is that the product is browsable
         * on the legacy frontend and cannot be bought, which on a PRODUCTION host before the switch
         * is a shop advertising something it will not sell.
         *
         * So this is a door rather than a rule: closed by default, opened by name
         * (`--allow-compat-variants`), and the run prints that it is open. On a rehearsal copy with
         * no live frontend behind it, opening it is how the v2 shape gets exercised before the
         * night it matters (developer, 2026-09-14). After the write-switch the question disappears:
         * `PreSwitch::completed()` makes the door irrelevant.
         */
        $deferVariants = ! PreSwitch::completed()
            && ! $allowCompatVariants
            && in_array(Storefront::WATCHIZER_ID, $storefrontIds, true);

        if ($deferVariants && $row->variants !== []) {
            $report->row($row->ref, $row->titleEn, 'variants_deferred',
                count($row->variants).' variant(s) not imported: the legacy storefront cannot SELL a variant product '
                .'before the write-switch (both compat doors answer 422). Re-import with --allow-compat-variants, '
                .'or add them after the switch.');
            $report->count('variants_deferred');
        }

        // ── variants ────────────────────────────────────────────────────────────────────────
        foreach ($deferVariants ? [] : $row->variants as $variant) {
            try {
                $this->variants->create($productId, [
                    'label' => $variant['label'],
                    'size_id' => $this->sizeId($variant['size'], $report),
                    'price_delta' => $variant['price'] === null ? 0 : round($variant['price'] - $row->sellingPrice, 2),
                    'stock_express' => $variant['stock'] ?? 0,
                    'is_active' => true,
                ], null);
                $report->count('variants');
            } catch (Throwable $e) {
                $report->row($row->ref, $row->titleEn, 'variant_refused', mb_substr($e->getMessage(), 0, 160));
            }
        }

        // ── the cover image ─────────────────────────────────────────────────────────────────
        $hasImage = false;
        if ($withImages && $row->imageUrl !== null) {
            $hasImage = $this->covers->attach($productId, $row->imageUrl, $row->titleEn, $report);
        }
        if (! $hasImage) {
            $missing[] = ImportReport::MISSING_IMAGE;
        }

        /*
         * ── visibility, LAST, because it is the only step that needs the whole row ──────────
         *
         * `PlacementWriter::save()` refuses to make a product visible without Arabic, an image and
         * a placement (task 4.2), and that refusal is load-bearing here: it is the reason an
         * incomplete import cannot reach a customer. So the attempt is made with what the row
         * actually has, and a refusal lands the product HIDDEN with its markers.
         */
        foreach ($nodesByStorefront as $storefrontId => $nodeIds) {
            $wantsVisible = $row->isVisible && $placed && $hasImage;

            try {
                $this->placements->save($storefrontId, $productId, ['is_visible' => $wantsVisible]);
            } catch (Throwable $e) {
                $this->placements->save($storefrontId, $productId, ['is_visible' => false]);
                $report->row($row->ref, $row->titleEn, 'hidden', mb_substr($e->getMessage(), 0, 160));
            }
        }

        if (! $placed) {
            $missing[] = ImportReport::MISSING_CATEGORY;
        }

        if ($missing !== []) {
            $report->row($row->ref, $row->titleEn, 'missing_data', implode(' ', $missing));
        }
        $report->count('imported');

        return $productId;
    }

    /**
     * Undo a row that failed part way through, so a re-run gets a clean second chance.
     *
     * ── Why this is needed, and why it is safe ───────────────────────────────────────────────
     *
     * `import_ref` is written as soon as the product row exists — it is the duplicate guard, so it
     * cannot wait until the end. Anything that fails AFTER it therefore leaves a half-product that
     * the next run skips as "already imported": measured on `joyroom:JR-HG2`, which lost a lock
     * race with a parallel worker and stayed in the catalogue with two translations, one image and
     * no placement at all.
     *
     * Safe because of what it refuses: a product with a ledger movement or an order line is NEVER
     * discarded — that is history, and history is not undone by an importer. Those are the only two
     * things a half-imported row could have that matter, and a row that has them is not half
     * imported, it is sold.
     *
     * @return bool whether anything was removed
     */
    public function discard(string $ref): bool
    {
        $id = DB::table('catalog_products')->where('import_ref', $ref)->value('id');
        if (! is_numeric($id)) {
            return false;
        }
        $productId = (int) $id;

        /*
         * History is somebody ELSE'S: a sale, a cancellation, a hand correction. The opening
         * balance this import set is not — it is the row the importer wrote itself, on a product
         * nobody has touched since, and refusing to undo it would mean a corrected mapping could
         * never be re-applied without a full rebuild.
         *
         * So: any movement whose reason is NOT `import`, or any order line, and the product stays.
         */
        $foreignMovement = DB::table('inventory_movements')
            ->where('product_id', $productId)
            ->where('reason', '!=', self::STOCK_REASON)
            ->exists();

        if ($foreignMovement || DB::table('order_items')->where('product_id', $productId)->exists()) {
            return false;
        }

        DB::transaction(function () use ($productId): void {
            foreach (['catalog_product_search', 'catalog_product_images', 'catalog_product_variants',
                'catalog_product_translations', 'catalog_product_watch_specs', 'catalog_product_feature',
                'catalog_product_gender', 'catalog_product_color', 'storefront_category_product',
                'storefront_product'] as $table) {
                DB::table($table)->where('product_id', $productId)->delete();
            }

            /*
             * The opening balance goes with the product. Deleting a ledger row is not something
             * this application does lightly — but the alternative is movements pointing at a
             * product id that no longer exists, and `inventory:verify` compares the ledger with
             * the product's own columns, both of which are about to be gone. Only `import`
             * movements can be here: anything else was refused above.
             */
            DB::table('inventory_movements')->where('product_id', $productId)->delete();

            DB::table('catalog_products')->where('id', $productId)->delete();
        });

        return true;
    }

    /**
     * Every node this row belongs to on one storefront, and which of them is the PRIMARY.
     *
     * ── Why the primary is not simply "the deepest" any more ─────────────────────────────────
     *
     * It was, and that was right while every node was a product TYPE: `Fashion → Bags → Tote Bags`
     * is a better breadcrumb than `Fashion`. Then the developer's review (2026-09-14) added
     * material nodes — `fashion/materials/leather` is depth 3, deeper than `fashion/bags`, and
     * would have won. A leather handbag would have carried a material for a breadcrumb, and
     * `FamilyForCategory` reads the primary, so it would have been a `fashion` product with no bag
     * spec block.
     *
     * So the rule gains one clause: **the deepest node that the map allows to be primary**. If the
     * row named nothing else — 53 Satin products do exactly that — the material node takes it,
     * because a placed product cannot be primary-less, and the caller reports it.
     *
     * @return array{0: list<int>, 1: int|null, 2: bool} ids, the primary, and whether the primary
     *                                                   had to be a material node
     */
    private function placementFor(int $storefrontId, SourceRow $row, ImportReport $report): array
    {
        $ids = [];
        $eligible = [];

        foreach ($row->categoryPaths as $path) {
            try {
                $before = count($this->merger->created());
                $id = $this->merger->node($storefrontId, $path);
                if (count($this->merger->created()) > $before) {
                    $report->note('category_created', $storefrontId.':'.$path);
                    $report->count('categories_created');
                }

                $ids[$id] = true;
                if (CategoryMap::mayBePrimary($path)) {
                    $eligible[$id] = true;
                }
            } catch (Throwable $e) {
                $report->row($row->ref, $row->titleEn, 'category_failed', $path.' — '.mb_substr($e->getMessage(), 0, 140));
            }
        }

        /** @var list<int> $all */
        $all = array_keys($ids);
        if ($all === []) {
            return [[], null, false];
        }

        /** @var list<int> $eligibleIds */
        $eligibleIds = array_keys($eligible);

        $primary = self::deepestOf($eligibleIds);
        $materialOnly = $primary === null;

        return [$all, $primary ?? self::deepestOf($all), $materialOnly];
    }

    /**
     * The deepest of these nodes — the one a breadcrumb should end on.
     *
     * @param  list<int>  $ids
     */
    private static function deepestOf(array $ids): ?int
    {
        $best = null;
        $bestDepth = -1;

        foreach ($ids as $id) {
            $depth = self::depthOf($id);
            if ($depth > $bestDepth) {
                $best = $id;
                $bestDepth = $depth;
            }
        }

        return $best;
    }

    private static function depthOf(int $nodeId): int
    {
        $depth = DB::table('storefront_categories')->where('id', $nodeId)->value('depth');

        return is_numeric($depth) ? (int) $depth : 0;
    }

    /**
     * `wa_code` is NOT NULL and UNIQUE and the sources have no equivalent, so the import mints one
     * from its own reference: `woo:15826` → `BF-15826`. It is stable across runs (the same source
     * row always produces the same code) and it is obviously not a legacy code, which is what a
     * person reading the catalogue needs to know.
     */
    private function waCode(SourceRow $row): string
    {
        [$source, $id] = array_pad(explode(':', $row->ref, 2), 2, '');
        $prefix = $source === 'woo' ? 'BF' : strtoupper(mb_substr($source, 0, 2));
        $code = $prefix.'-'.preg_replace('/[^A-Za-z0-9_-]+/', '', $id);

        return mb_substr($code, 0, 64);
    }

    /** The machine-translation badge, on the row a human will edit. */
    private function markMachineTranslation(int $productId): void
    {
        DB::table('catalog_product_translations')
            ->where('product_id', $productId)->where('locale', 'ar')
            ->update(['is_machine' => 1]);
    }

    private function skuExists(string $sku): bool
    {
        return DB::table('catalog_products')->where('sku', $sku)->exists();
    }

    /**
     * @param  list<string>  $names
     * @param  array<string, int>  $lookup
     * @return list<int>
     */
    private function idsFor(array $names, array $lookup): array
    {
        $out = [];
        foreach ($names as $name) {
            $id = $lookup[mb_strtolower($name)] ?? null;
            if ($id !== null) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /**
     * The size lookup row for a variant label, created when the shoe size is one we do not stock
     * yet (the export uses 35–47; our lookup has 40–45).
     */
    private function sizeId(?string $label, ImportReport $report): ?int
    {
        if ($label === null || trim($label) === '') {
            return null;
        }
        $key = mb_strtolower(trim($label));

        // "Medium"/"Large" are our M/L.
        $key = match ($key) {
            'medium' => 'm',
            'large' => 'l',
            'small' => 's',
            default => $key,
        };

        if (isset($this->sizes[$key])) {
            return $this->sizes[$key];
        }

        $numeric = preg_match('/^\d{2}$/', $key) === 1;
        $type = $numeric ? 'shoes' : 'clothing';

        PreSwitch::assertMayCreate('lookup');
        $id = (int) DB::table('catalog_sizes')->insertGetId([
            'type' => $type,
            'sort' => $numeric ? (int) $key : 99,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach (['ar', 'en'] as $locale) {
            DB::table('catalog_size_translations')->insert([
                'size_id' => $id,
                'locale' => $locale,
                'name' => strtoupper(trim($label)),
            ]);
        }

        $report->note('size_created', strtoupper(trim($label)));
        $report->count('sizes_created');

        return $this->sizes[$key] = $id;
    }

    /** Read once per run: the lookups every row resolves against. */
    private function hydrateLookups(): void
    {
        if ($this->genders !== []) {
            return;
        }

        foreach ([['catalog_genders', 'catalog_gender_translations', 'gender_id', 'genders'],
            ['catalog_materials', 'catalog_material_translations', 'material_id', 'materials']] as [$table, $translations, $key, $property]) {
            $rows = DB::table($table.' as l')
                ->join($translations.' as t', function (JoinClause $join) use ($key): void {
                    $join->on('t.'.$key, '=', 'l.id')->where('t.locale', '=', 'en');
                })
                ->get(['l.id', 't.name']);

            $map = [];
            foreach ($rows as $raw) {
                $row = Row::cast($raw);
                $name = mb_strtolower(Row::nstr($row, 'name') ?? '');
                if ($name !== '') {
                    $map[$name] = Row::int($row, 'id');
                }
            }
            $this->{$property} = $map;
        }

        foreach (DB::table('catalog_sizes as s')
            ->join('catalog_size_translations as t', function (JoinClause $join): void {
                $join->on('t.size_id', '=', 's.id')->where('t.locale', '=', 'en');
            })
            ->get(['s.id', 't.name']) as $raw) {
            $row = Row::cast($raw);
            $name = mb_strtolower(trim(Row::nstr($row, 'name') ?? ''));
            if ($name !== '') {
                $this->sizes[$name] = Row::int($row, 'id');
            }
        }
    }

    /**
     * The brand names that matched nothing, into the report.
     *
     * Called once at the end of a run rather than per row: the useful artefact is the ranked list
     * ("Mini Focus × 237"), which is a decision the developer makes once, not 8 000 log lines.
     */
    public function recordUnmatchedBrands(ImportReport $report): void
    {
        foreach ($this->brands->unmatched() as $name => $count) {
            $report->note('brand_unmatched', $name, $count);
        }
    }

    /** Materials the map needs and the lookup lacks, created once (e.g. Satin). */
    public function ensureMaterials(ImportReport $report): void
    {
        $this->hydrateLookups();

        foreach (CategoryMap::NEW_MATERIALS as $name => $names) {
            if (isset($this->materials[mb_strtolower($name)])) {
                continue;
            }

            PreSwitch::assertMayCreate('lookup');
            $id = (int) DB::table('catalog_materials')->insertGetId(['created_at' => now(), 'updated_at' => now()]);
            foreach ($names as $locale => $value) {
                DB::table('catalog_material_translations')->insert([
                    'material_id' => $id,
                    'locale' => $locale,
                    'name' => $value,
                ]);
            }
            $this->materials[mb_strtolower($name)] = $id;
            $report->count('materials_created');
        }
    }
}
