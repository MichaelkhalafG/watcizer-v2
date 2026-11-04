<?php

namespace App\Domain\Catalog;

use App\Domain\Inventory\Actor;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use App\Support\Coerce;
use App\Support\ManageText;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

/**
 * The variants panel's writer — where every rule wave 3.5 settled meets a human with a mouse.
 *
 * Wave 3.5 built the mechanism and said, in its own review, that the dashboard is what must
 * enforce three of its rules. All three are here, each refusing rather than reporting:
 *
 *  1. **Stock never moves except through `InventoryService`** (AGENTS §2.5). This class writes
 *     `label`, `sku`, `color_id`, `size_id`, `price_delta`, `is_active` and `sort` — and NOT
 *     `stock_express` / `stock_market`. A quantity typed into the panel becomes
 *     `InventoryService::set(StockTarget::variant(…), …, reason: 'manual')`, which locks in the
 *     one canonical order, writes a ledger row and fires `StockChanged`. {@see self::COLUMNS} is
 *     the whole list, so a payload carrying a stock column is ignored, not obeyed.
 *
 *  2. **A variant with any HISTORY is never deleted.** Three separate reasons, three separate
 *     messages:
 *       • an `order_items.variant_id` points at it — deleting it orphans a sold line and makes
 *         a cancellation unable to return the units it took;
 *       • it still holds units — the ledger's sum per variant must equal the column;
 *       • it has LEDGER MOVEMENTS. This one was found by a test rather than by reading the
 *         schema: `inventory_movements.variant_id` is `ON DELETE SET NULL`, so a delete does
 *         not remove the movements — it strips their LEVEL, turning variant-level rows into
 *         product-level rows on a product that has variants. That is precisely the state the
 *         reconciliation check `catalog_products[no product-level movement on a variant
 *         product]` exists to catch, and `inventory:verify` would report it forever after.
 *     Such a variant is DEACTIVATED instead — which is what wave 3.5 built deactivation for
 *     (rule ii): a withdrawn size stops being sellable while its units stay in the ledger.
 *
 *  3. **`is_active` changes call `recomputeInStock()`** (AGENTS §2.5). Activating or deactivating
 *     moves no units and writes no movement, but it does change whether the product is orderable:
 *     `catalog_products.in_stock` means "some ACTIVE variant has stock" for a variant product.
 *     Forgetting the call leaves a product that looks orderable with nothing buyable behind it.
 *
 * And the fourth, from {@see ConversionGuard}: **a LIVE product may not be converted to variants
 * before the write-switch.** The first variant of a legacy-backed product is refused.
 *
 * ── Part 1 (2026-11-04): the price is entered in FULL, the images belong to rows, and the
 *    product's own colour is a row ─────────────────────────────────────────────────────────────
 *
 *  • **Price.** The panel asks for the variant's full selling price and the server stores
 *    `price − selling_price` in `price_delta`, exactly as the column has always been read
 *    ({@see self::resolveDelta()}). No schema change; existing rows stay valid. The base is the
 *    SELLING price — what the customer pays — never the struck-through "was" price. Known and
 *    accepted: when the base price changes every variant moves with it by the same difference.
 *  • **Images.** `catalog_product_images.variant_id` (M1y) marks which of the product's existing
 *    images belong to a row; one image belongs to one row only, so assigning it here takes it
 *    from whichever row held it ({@see self::assignImages()}). Images belonging to no row are the
 *    product's general gallery.
 *  • **The product's own colour.** A product that gains its first colour row must keep its own
 *    colour sellable: the shopper who opened the brown wallet must be able to buy the brown.
 *    {@see self::convert()} creates that row automatically at conversion, carrying the units the
 *    product held, and {@see self::summary()} tells the panel when an already-converted product
 *    still lacks it.
 *  • **Conversion moves the units down.** Before Part 1 the first row left the product's own
 *    units in place and the aggregate drifted by exactly that amount (the demo seeder did it
 *    right; the dashboard did not). Now the product level is set to zero THROUGH THE SERVICE
 *    first — a recorded movement whose note carries the original count — and the units land on
 *    the base-colour row, or on the first row itself when it IS the base colour and the operator
 *    typed no quantity for that bucket. The explainer on the panel has always said this is what
 *    happens; now it is.
 */
final class VariantWriter
{
    /**
     * Columns the dashboard may write on `catalog_product_variants`.
     *
     * The absence of `stock_express` and `stock_market` is the point, not an omission.
     *
     * @var list<string>
     */
    public const COLUMNS = ['product_id', 'sku', 'label', 'color_id', 'size_id', 'price_delta', 'is_active', 'sort'];

    /**
     * The id of the base-colour row {@see self::create()} made on its own during a conversion,
     * null when it made none. Read by the controller right after `create()` so the activity log
     * records the row a human did not type but did cause.
     */
    public ?int $baseRowCreated = null;

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly ConversionGuard $conversion,
        private readonly ProductWriter $products,
    ) {}

    /**
     * Add a variant.
     *
     * @param  array<string, mixed>  $data  validated payload; `stock_express`/`stock_market` are optional opening quantities;
     *                                      `price` (full) wins over `price_delta`; `image_ids` marks the row's images
     */
    public function create(int $productId, array $data, ?int $actorId): int
    {
        $this->baseRowCreated = null;

        // Two doors, in order of how absolute they are. First: may the dashboard create a
        // variant row at all before the write-switch? (No — legacy `product_variants` is empty, so
        // the rebuild deletes it and re-levels its ledger rows.) Then: is THIS product allowed to
        // have variants, which is wave 3.5's conversion rule.
        PreSwitch::assertMayCreate('variant');
        $this->conversion->assertMayConvert($productId);

        $row = [
            'product_id' => $productId,
            'sku' => Coerce::nstr($data['sku'] ?? null),
            'label' => Coerce::str($data['label'] ?? null),
            'color_id' => Coerce::nint($data['color_id'] ?? null),
            'size_id' => Coerce::nint($data['size_id'] ?? null),
            'price_delta' => $this->resolveDelta($productId, $data),
            'is_active' => Coerce::bool($data['is_active'] ?? null, true),
            'sort' => Coerce::nint($data['sort'] ?? null),
            // No stock columns: M1 defaults them to 0, and naming them — even as 0 — is a
            // stock write that `StockWriteGuard` refuses. The opening quantities below go
            // through `InventoryService`, which is the only door.
        ];
        // Every refusal that can be known BEFORE anything is written happens here, because the
        // conversion below writes a ledger movement, and a ledger movement is history.
        if ($row['label'] === '') {
            throw new RuntimeException(ManageText::t('variants.label_required', 'اسم الصف (المقاس/اللون) مطلوب.'));
        }

        // The FIRST row converts the product: its own units move down to the rows (see the class
        // docblock), and its own colour becomes a row of its own when the new row is a different
        // colour. This has to happen while the product still has NO variants, because that is the
        // only moment the service allows a product-level movement.
        if (! $this->inventory->hasVariants($productId)) {
            $data = $this->convert($productId, $row['color_id'], $data, $actorId);
        }

        $variantId = DB::transaction(function () use ($productId, $row): int {
            $row['sort'] ??= $this->nextSort($productId);
            $row['created_at'] = now();
            $row['updated_at'] = now();

            return (int) DB::table('catalog_product_variants')->insertGetId($row);
        });

        // Opening quantities are MOVEMENTS, outside the row's own transaction, because
        // `InventoryService::apply()` owns its locks and its transaction (the single lock order of
        // the 2026-09-10 review). Nesting it inside ours would put the product lock in the wrong
        // place in the sequence.
        $this->applyStock($productId, $variantId, $data, $actorId);
        $this->applyImages($productId, $variantId, $data);

        // A product's FIRST variant flips which level is authoritative, so the aggregate and the
        // in_stock flag both have to be re-derived from the variants that now exist.
        $this->afterStructureChange($productId);

        return $variantId;
    }

    /**
     * Edit a variant: its attributes, its stock, its images, and its active flag.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(int $productId, int $variantId, array $data, ?int $actorId): void
    {
        $variant = $this->requireVariant($productId, $variantId);
        $wasActive = Row::bool($variant, 'is_active');

        DB::transaction(function () use ($productId, $variantId, $data): void {
            $row = [
                'sku' => Coerce::nstr($data['sku'] ?? null),
                'label' => Coerce::str($data['label'] ?? null),
                'color_id' => Coerce::nint($data['color_id'] ?? null),
                'size_id' => Coerce::nint($data['size_id'] ?? null),
                'price_delta' => $this->resolveDelta($productId, $data),
                'is_active' => Coerce::bool($data['is_active'] ?? null, true),
                'sort' => Coerce::int($data['sort'] ?? null),
                'updated_at' => now(),
            ];
            if ($row['label'] === '') {
                throw new RuntimeException(ManageText::t('variants.label_required', 'اسم الصف (المقاس/اللون) مطلوب.'));
            }

            DB::table('catalog_product_variants')
                ->where('id', $variantId)->where('product_id', $productId)
                ->update($row);
        });

        $this->applyStock($productId, $variantId, $data, $actorId);
        $this->applyImages($productId, $variantId, $data);

        $nowActive = Coerce::bool($data['is_active'] ?? null, true);
        if ($nowActive !== $wasActive) {
            // Rule 3, and the reason it is a call and not a trigger: no units moved, so nothing
            // in the ledger would have fired this.
            $this->inventory->recomputeInStock($productId);
        }
        $this->products->refreshEffectivePrices($productId);
        // A label, a price or a picture changed on the product page: the cached detail is stale.
        // A stock change flushes through `StockChanged`; nothing else here did until Part 1.
        $this->products->flush($productId);
    }

    /**
     * Reorder the panel.
     *
     * @param  list<int>  $orderedIds
     */
    public function reorder(int $productId, array $orderedIds): int
    {
        $moved = DB::transaction(function () use ($productId, $orderedIds): int {
            $valid = DB::table('catalog_product_variants')->where('product_id', $productId)->pluck('id')
                ->map(fn (mixed $id): int => Coerce::int($id))->all();

            $sort = 0;
            $moved = 0;
            foreach ($orderedIds as $id) {
                if (! in_array($id, $valid, true)) {
                    continue;
                }
                DB::table('catalog_product_variants')->where('id', $id)->update(['sort' => $sort++, 'updated_at' => now()]);
                $moved++;
            }

            return $moved;
        });
        if ($moved > 0) {
            $this->products->flush($productId);
        }

        return $moved;
    }

    /**
     * Delete a variant — refused when an order line references it, or when it still holds units.
     *
     * Two separate refusals with two different reasons:
     *
     *  • **An order references it.** `order_items.variant_id` would become a dangling id, and a
     *    later cancellation could not return the units it took. Deactivate instead.
     *  • **It still holds stock.** The ledger's sum per variant must equal the column; deleting a
     *    row with units would leave the product's maintained aggregate above the sum of its parts
     *    and `inventory:verify` would (correctly) start failing. Bring it to zero first — through
     *    the service, so the movement is recorded — and then delete.
     *
     * Its images are handed back to the product by the schema (`cpi_variant_fk` is ON DELETE SET
     * NULL), so nothing here has to remember them.
     *
     * @return array{deleted: bool, reason: string}
     */
    public function delete(int $productId, int $variantId): array
    {
        $variant = $this->requireVariant($productId, $variantId);

        $orderLines = DB::table('order_items')->where('variant_id', $variantId)->count();
        if ($orderLines > 0) {
            return [
                'deleted' => false,
                'reason' => ManageText::t(
                    'variants.delete_blocked_order_lines',
                    'لا يمكن الحذف: :count سطر طلب يشير إلى هذا الصف. حذفه يترك الطلب معلّقًا على صف غير موجود ويمنع إرجاع الكمية عند الإلغاء. عطّله بدلًا من ذلك.',
                    ['count' => $orderLines],
                ),
            ];
        }

        $units = Row::int($variant, 'stock_express') + Row::int($variant, 'stock_market');
        if ($units !== 0) {
            return [
                'deleted' => false,
                'reason' => ManageText::t(
                    'variants.delete_blocked_units',
                    'لا يمكن الحذف: الصف يحمل :count وحدة. صفّر الكمية أولًا (عبر حقل المخزون، ليُسجَّل في الدفتر) ثم احذفه.',
                    ['count' => $units],
                ),
            ];
        }

        $movements = DB::table('inventory_movements')->where('variant_id', $variantId)->count();
        if ($movements > 0) {
            // See the class docblock: the FK is ON DELETE SET NULL, so this would not erase the
            // history — it would RE-LEVEL it onto the product and break the reconciliation.
            return [
                'deleted' => false,
                'reason' => ManageText::t(
                    'variants.delete_blocked_movements',
                    'لا يمكن الحذف: للصف :count حركة في دفتر المخزون. حذفه لا يمسح الحركات بل ينقلها إلى مستوى المنتج، فتصبح الأرقام غير مطابقة ويظهر ذلك في inventory:verify إلى الأبد. عطّل الصف بدلًا من حذفه — هذا بالضبط سبب وجود التعطيل.',
                    ['count' => $movements],
                ),
            ];
        }

        DB::transaction(function () use ($productId, $variantId): void {
            DB::table('catalog_product_variants')->where('id', $variantId)->where('product_id', $productId)->delete();
        });

        $this->afterStructureChange($productId);

        return ['deleted' => true, 'reason' => ManageText::t('variants.deleted', 'تم حذف الصف.')];
    }

    /**
     * What the panel needs to render: the rows, their stock, their full price, their images, and
     * whether each one may be deleted — with the reason when it may not, so the disabled button
     * explains itself.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(int $productId): array
    {
        // `line_count`, not `lines`: LINES is a reserved word in MariaDB (LOAD DATA … LINES) and
        // the alias came back as a 1064 the first time this ran.
        $orderCounts = DB::table('order_items')
            ->whereNotNull('variant_id')
            ->where('product_id', $productId)
            ->select('variant_id', DB::raw('COUNT(*) as line_count'))
            ->groupBy('variant_id')
            ->pluck('line_count', 'variant_id');

        $out = [];
        $rows = DB::table('catalog_product_variants')
            ->where('product_id', $productId)
            ->orderBy('sort')->orderBy('id')
            ->get(['id', 'sku', 'label', 'color_id', 'size_id', 'price_delta', 'stock_express', 'stock_market', 'is_active', 'sort']);

        $movementCounts = DB::table('inventory_movements')
            ->where('product_id', $productId)
            ->whereNotNull('variant_id')
            ->select('variant_id', DB::raw('COUNT(*) as movement_count'))
            ->groupBy('variant_id')
            ->pluck('movement_count', 'variant_id');

        $imageIds = self::imageIdsByVariant($productId);
        $base = $this->sellingPrice($productId);

        foreach ($rows as $raw) {
            $row = Row::cast($raw);
            $id = Row::int($row, 'id');
            $lines = Coerce::int($orderCounts[$id] ?? null);
            $movements = Coerce::int($movementCounts[$id] ?? null);
            $units = Row::int($row, 'stock_express') + Row::int($row, 'stock_market');
            $delta = Row::money($row, 'price_delta');

            $out[] = [
                'id' => $id,
                'sku' => Row::nstr($row, 'sku'),
                'label' => Row::str($row, 'label'),
                'color_id' => Row::nint($row, 'color_id'),
                'size_id' => Row::nint($row, 'size_id'),
                'price_delta' => $delta,
                // The FULL price, which is what the form shows and asks for; the delta above is
                // what the column stores (see the class docblock).
                'price' => self::money($base + (float) $delta),
                'image_ids' => $imageIds[$id] ?? [],
                'stock_express' => Row::int($row, 'stock_express'),
                'stock_market' => Row::int($row, 'stock_market'),
                'is_active' => Row::bool($row, 'is_active'),
                'sort' => Row::int($row, 'sort'),
                'order_lines' => $lines,
                'movements' => $movements,
                'may_delete' => $lines === 0 && $units === 0 && $movements === 0,
                'delete_blocked_reason' => match (true) {
                    $lines > 0 => ManageText::t('variants.blocked_by_order_lines', 'مرتبط بـ :count سطر طلب', ['count' => $lines]),
                    $units !== 0 => ManageText::t('variants.holds_units', 'يحمل :count وحدة', ['count' => $units]),
                    $movements > 0 => ManageText::t('variants.has_movements', 'له :count حركة مخزون — عطّله بدلًا من حذفه', ['count' => $movements]),
                    default => null,
                },
            ];
        }

        return $out;
    }

    /**
     * The panel's facts about the PRODUCT: the base price every row's price is read against, and
     * the product's own colour with whether a row carries it yet.
     *
     * `own_color.has_row` false on a product that HAS variants is the state the brown wallet was
     * in — the only option on the page was red — and the panel offers the one-click fix.
     *
     * @return array{base_price: string, own_color: array{id: int, name: string, has_row: bool}|null}
     */
    public function summary(int $productId): array
    {
        $own = $this->ownColor($productId);
        if ($own !== null) {
            $own['has_row'] = DB::table('catalog_product_variants')
                ->where('product_id', $productId)->where('color_id', $own['id'])->exists();
        }

        return ['base_price' => self::money($this->sellingPrice($productId)), 'own_color' => $own];
    }

    /**
     * The product's OWN colour — the one its attribute pivots describe — as the storefront reads
     * it: a fashion product's single colour sits under `band` (the schema wart recorded
     * 2026-10-23), a watch's strap colour too, `main` next, the dial last.
     *
     * @return array{id: int, name: string}|null
     */
    public function ownColor(int $productId): ?array
    {
        $raw = DB::table('catalog_product_color as pc')
            ->where('pc.product_id', $productId)
            ->orderByRaw("FIELD(pc.role, 'band', 'main', 'dial')")
            ->orderBy('pc.position')->orderBy('pc.color_id')
            ->first(['pc.color_id']);
        if ($raw === null) {
            return null;
        }
        $colorId = Row::int(Row::cast($raw), 'color_id');
        $name = Coerce::nstr(DB::table('catalog_color_translations')
            ->where('color_id', $colorId)
            ->orderByRaw("FIELD(locale, 'ar', 'en')")
            ->value('name')) ?? ('#'.$colorId);

        return ['id' => $colorId, 'name' => $name];
    }

    // ── internals ────────────────────────────────────────────────────────────────────────────

    /**
     * The conversion: the product's own units move down, and its own colour becomes a row.
     *
     * Runs ONLY while the product has no variants — the service refuses a product-level movement
     * the moment one exists, which is also why this runs before the requested row is inserted.
     *
     * Returns the payload for the requested row, with the carried units filled into the buckets
     * the operator left blank WHEN that row is itself the product's own colour (there is no
     * separate base row then — the row they are typing IS the base).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function convert(int $productId, ?int $newRowColorId, array $data, ?int $actorId): array
    {
        $raw = DB::table('catalog_products')->where('id', $productId)->first(['stock_express', 'stock_market']);
        if ($raw === null) {
            throw new RuntimeException("Product {$productId} does not exist.");
        }
        $product = Row::cast($raw);
        $actor = Actor::user($actorId);

        $carry = [];
        foreach (['express' => 'stock_express', 'market' => 'stock_market'] as $bucket => $column) {
            $have = Row::int($product, $column);
            $carry[$bucket] = $have;
            if ($have > 0) {
                $this->inventory->set(
                    target: StockTarget::product($productId),
                    bucket: $bucket,
                    quantity: 0,
                    reason: 'manual',
                    actor: $actor,
                    // NOT interface text: stored in `inventory_movements.note`, forward-only
                    // history (see applyStock). The original count rides in the note so the
                    // movement explains itself in the ledger.
                    note: 'لوحة التحكم — تحويل المنتج إلى صفوف؛ الكمية قبل التحويل: '.$have, // i18n-exempt: written to inventory_movements.note; stored history, not interface text.
                );
            }
        }

        $own = $newRowColorId === null ? null : $this->ownColor($productId);
        if ($own === null) {
            // No colour axis, or a product with no recorded colour: nothing to make a row of. The
            // operator's rows carry what they typed; the ledger carries what the product held.
            return $data;
        }

        if ($own['id'] === $newRowColorId) {
            // The first row IS the base colour. Its blank buckets inherit the product's units, so
            // the brown wallet's twelve browns are still twelve browns after the click.
            foreach (['express' => 'stock_express', 'market' => 'stock_market'] as $bucket => $field) {
                if (! array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
                    $data[$field] = $carry[$bucket];
                }
            }

            return $data;
        }

        // A different colour is being added: the product's own colour becomes row 0, at the
        // product's own price, holding the units the product held — selectable by default on
        // the storefront, and the one the shopper actually opened.
        $baseId = DB::transaction(fn (): int => (int) DB::table('catalog_product_variants')->insertGetId([
            'product_id' => $productId,
            'sku' => null,
            'label' => $own['name'],
            'color_id' => $own['id'],
            'size_id' => null,
            'price_delta' => '0.00',
            'is_active' => 1,
            'sort' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        foreach ($carry as $bucket => $units) {
            if ($units > 0) {
                $this->inventory->set(
                    target: StockTarget::variant($productId, $baseId),
                    bucket: $bucket,
                    quantity: $units,
                    reason: 'manual',
                    actor: $actor,
                    note: 'لوحة التحكم — كمية المنتج انتقلت إلى صف لونه الأصلي عند التحويل', // i18n-exempt: written to inventory_movements.note; stored history, not interface text.
                );
            }
        }
        $this->baseRowCreated = $baseId;

        return $data;
    }

    /**
     * The stored difference from the payload: a FULL `price` when the panel sent one (Part 1),
     * else the `price_delta` an importer or an older caller still sends, else zero.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveDelta(int $productId, array $data): string
    {
        $price = $data['price'] ?? null;
        if ($price !== null && $price !== '' && is_numeric($price)) {
            return self::money((float) $price - $this->sellingPrice($productId));
        }

        return self::money(Coerce::float($data['price_delta'] ?? null));
    }

    private function sellingPrice(int $productId): float
    {
        $raw = DB::table('catalog_products')->where('id', $productId)->value('selling_price');

        return is_numeric($raw) ? (float) $raw : 0.0;
    }

    private static function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * Mark which of the product's images belong to this row — only when the payload says so.
     *
     * The payload's list is the WHOLE set for this row: every image the row held and the list
     * omits goes back to the product's general gallery, and every image the list names comes to
     * this row from wherever it was (one image, one row — the rule is the schema's, this just
     * reads it the obvious way). An id that is not one of this product's images is ignored, not
     * obeyed: the controller validates ownership, and the query below checks it again.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyImages(int $productId, int $variantId, array $data): void
    {
        if (! array_key_exists('image_ids', $data)) {
            return;
        }
        $this->assignImages($productId, $variantId, Coerce::intList($data['image_ids']));
    }

    /**
     * @param  list<int>  $imageIds
     */
    public function assignImages(int $productId, int $variantId, array $imageIds): void
    {
        DB::transaction(function () use ($productId, $variantId, $imageIds): void {
            DB::table('catalog_product_images')
                ->where('product_id', $productId)->where('variant_id', $variantId)
                ->update(['variant_id' => null, 'updated_at' => now()]);
            if ($imageIds !== []) {
                DB::table('catalog_product_images')
                    ->where('product_id', $productId)->whereIn('id', $imageIds)
                    ->update(['variant_id' => $variantId, 'updated_at' => now()]);
            }
        });
    }

    /**
     * variant id => its image ids, in gallery order (cover first, then sort, then id).
     *
     * @return array<int, list<int>>
     */
    public static function imageIdsByVariant(int $productId): array
    {
        $out = [];
        $rows = DB::table('catalog_product_images')
            ->where('product_id', $productId)->whereNotNull('variant_id')
            ->orderByDesc('is_cover')->orderBy('sort')->orderBy('id')
            ->get(['id', 'variant_id']);
        foreach ($rows as $r) {
            $out[Row::int($r, 'variant_id')][] = Row::int($r, 'id');
        }

        return $out;
    }

    /**
     * Set the two buckets through the single door, and only when the payload asked to.
     *
     * `set()` (absolute) rather than `adjust()` (relative) because the panel shows a quantity and
     * the team edits that quantity; `set()` also returns null when the value is unchanged, so
     * saving a form without touching stock grows the ledger by nothing.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyStock(int $productId, int $variantId, array $data, ?int $actorId): void
    {
        $target = StockTarget::variant($productId, $variantId);
        $actor = Actor::user($actorId);

        foreach (['express' => 'stock_express', 'market' => 'stock_market'] as $bucket => $field) {
            if (! array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
                continue;
            }
            $quantity = Coerce::nint($data[$field]);
            if ($quantity === null || $quantity < 0) {
                throw new RuntimeException(ManageText::t('variants.quantity_not_negative', 'الكمية يجب أن تكون صفرًا أو أكثر.'));
            }

            $this->inventory->set(
                target: $target,
                bucket: $bucket,
                quantity: $quantity,
                reason: 'manual',
                actor: $actor,
                /*
                 * NOT interface text. This string is INSERTED into `inventory_movements.note` and
                 * stays there: the ledger is append-only history, so putting it on the translation
                 * seam would make a row's stored note depend on the locale of whoever happened to
                 * be looking at a screen when the movement was written.
                 */
                note: 'لوحة التحكم — تعديل كمية صف', // i18n-exempt: written to inventory_movements.note; stored history, not interface text.
            );
        }
    }

    /**
     * After a variant is added or removed, the product's level may have changed.
     *
     * `recomputeInStock()` is safe at either level since the 2026-09-10 review, so it is called
     * unconditionally; the aggregate columns are maintained by `InventoryService` on every
     * movement, so nothing else needs recomputing here. The prices are refreshed because a
     * variant carries a `price_delta`, and the product's cached detail is flushed because its
     * picker changed shape.
     */
    private function afterStructureChange(int $productId): void
    {
        $this->inventory->recomputeInStock($productId);
        $this->products->refreshEffectivePrices($productId);
        $this->products->flush($productId);
    }

    private function requireVariant(int $productId, int $variantId): stdClass
    {
        $variant = DB::table('catalog_product_variants')
            ->where('id', $variantId)->where('product_id', $productId)
            ->first(['id', 'is_active', 'stock_express', 'stock_market']);

        if ($variant === null) {
            // Scoped to the product on purpose: a variant id belonging to another product is
            // "not there" rather than "not yours" (study §3.11.14).
            throw new RuntimeException("Variant {$variantId} does not belong to product {$productId}.");
        }

        return Row::cast($variant);
    }

    private function nextSort(int $productId): int
    {
        return Coerce::int(DB::table('catalog_product_variants')->where('product_id', $productId)->max('sort')) + 1;
    }
}
