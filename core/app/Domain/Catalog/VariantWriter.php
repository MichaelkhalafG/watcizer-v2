<?php

namespace App\Domain\Catalog;

use App\Domain\Inventory\Actor;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use App\Support\Coerce;
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

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly ConversionGuard $conversion,
        private readonly ProductWriter $products,
    ) {}

    /**
     * Add a variant.
     *
     * @param  array<string, mixed>  $data  validated payload; `stock_express`/`stock_market` are optional opening quantities
     */
    public function create(int $productId, array $data, ?int $actorId): int
    {
        // Two doors, in order of how absolute they are. First: may the dashboard create a
        // variant row at all before the write-switch? (No — legacy `product_variants` is empty, so
        // the rebuild deletes it and re-levels its ledger rows.) Then: is THIS product allowed to
        // have variants, which is wave 3.5's conversion rule.
        PreSwitch::assertMayCreate('variant');
        $this->conversion->assertMayConvert($productId);

        $variantId = DB::transaction(function () use ($productId, $data): int {
            $row = [
                'product_id' => $productId,
                'sku' => Coerce::nstr($data['sku'] ?? null),
                'label' => Coerce::str($data['label'] ?? null),
                'color_id' => Coerce::nint($data['color_id'] ?? null),
                'size_id' => Coerce::nint($data['size_id'] ?? null),
                'price_delta' => Coerce::float($data['price_delta'] ?? null),
                'is_active' => Coerce::bool($data['is_active'] ?? null, true),
                'sort' => Coerce::nint($data['sort'] ?? null) ?? $this->nextSort($productId),
                // No stock columns: M1 defaults them to 0, and naming them — even as 0 — is a
                // stock write that `StockWriteGuard` refuses. The opening quantities below go
                // through `InventoryService`, which is the only door.
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if ($row['label'] === '') {
                throw new RuntimeException('اسم الصف (المقاس/اللون) مطلوب.');
            }

            return (int) DB::table('catalog_product_variants')->insertGetId($row);
        });

        // Opening quantities are MOVEMENTS, outside the row's own transaction, because
        // `InventoryService::apply()` owns its locks and its transaction (the single lock order of
        // the 2026-09-10 review). Nesting it inside ours would put the product lock in the wrong
        // place in the sequence.
        $this->applyStock($productId, $variantId, $data, $actorId);

        // A product's FIRST variant flips which level is authoritative, so the aggregate and the
        // in_stock flag both have to be re-derived from the variants that now exist.
        $this->afterStructureChange($productId);

        return $variantId;
    }

    /**
     * Edit a variant: its attributes, its stock, and its active flag.
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
                'price_delta' => Coerce::float($data['price_delta'] ?? null),
                'is_active' => Coerce::bool($data['is_active'] ?? null, true),
                'sort' => Coerce::int($data['sort'] ?? null),
                'updated_at' => now(),
            ];
            if ($row['label'] === '') {
                throw new RuntimeException('اسم الصف (المقاس/اللون) مطلوب.');
            }

            DB::table('catalog_product_variants')
                ->where('id', $variantId)->where('product_id', $productId)
                ->update($row);
        });

        $this->applyStock($productId, $variantId, $data, $actorId);

        $nowActive = Coerce::bool($data['is_active'] ?? null, true);
        if ($nowActive !== $wasActive) {
            // Rule 3, and the reason it is a call and not a trigger: no units moved, so nothing
            // in the ledger would have fired this.
            $this->inventory->recomputeInStock($productId);
        }
        $this->products->refreshEffectivePrices($productId);
    }

    /**
     * Reorder the panel.
     *
     * @param  list<int>  $orderedIds
     */
    public function reorder(int $productId, array $orderedIds): int
    {
        return DB::transaction(function () use ($productId, $orderedIds): int {
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
     * @return array{deleted: bool, reason: string}
     */
    public function delete(int $productId, int $variantId): array
    {
        $variant = $this->requireVariant($productId, $variantId);

        $orderLines = DB::table('order_items')->where('variant_id', $variantId)->count();
        if ($orderLines > 0) {
            return [
                'deleted' => false,
                'reason' => "لا يمكن الحذف: {$orderLines} سطر طلب يشير إلى هذا الصف. "
                    .'حذفه يترك الطلب معلّقًا على صف غير موجود ويمنع إرجاع الكمية عند الإلغاء. عطّله بدلًا من ذلك.',
            ];
        }

        $units = Row::int($variant, 'stock_express') + Row::int($variant, 'stock_market');
        if ($units !== 0) {
            return [
                'deleted' => false,
                'reason' => "لا يمكن الحذف: الصف يحمل {$units} وحدة. صفّر الكمية أولًا (عبر حقل المخزون، ليُسجَّل في الدفتر) ثم احذفه.",
            ];
        }

        $movements = DB::table('inventory_movements')->where('variant_id', $variantId)->count();
        if ($movements > 0) {
            // See the class docblock: the FK is ON DELETE SET NULL, so this would not erase the
            // history — it would RE-LEVEL it onto the product and break the reconciliation.
            return [
                'deleted' => false,
                'reason' => "لا يمكن الحذف: للصف {$movements} حركة في دفتر المخزون. حذفه لا يمسح الحركات بل ينقلها "
                    .'إلى مستوى المنتج، فتصبح الأرقام غير مطابقة ويظهر ذلك في inventory:verify إلى الأبد. '
                    .'عطّل الصف بدلًا من حذفه — هذا بالضبط سبب وجود التعطيل.',
            ];
        }

        DB::transaction(function () use ($productId, $variantId): void {
            DB::table('catalog_product_variants')->where('id', $variantId)->where('product_id', $productId)->delete();
        });

        $this->afterStructureChange($productId);

        return ['deleted' => true, 'reason' => 'تم حذف الصف.'];
    }

    /**
     * What the panel needs to render: the rows, their stock, and whether each one may be deleted —
     * with the reason when it may not, so the disabled button explains itself.
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

        foreach ($rows as $raw) {
            $row = Row::cast($raw);
            $id = Row::int($row, 'id');
            $lines = Coerce::int($orderCounts[$id] ?? null);
            $movements = Coerce::int($movementCounts[$id] ?? null);
            $units = Row::int($row, 'stock_express') + Row::int($row, 'stock_market');

            $out[] = [
                'id' => $id,
                'sku' => Row::nstr($row, 'sku'),
                'label' => Row::str($row, 'label'),
                'color_id' => Row::nint($row, 'color_id'),
                'size_id' => Row::nint($row, 'size_id'),
                'price_delta' => Row::money($row, 'price_delta'),
                'stock_express' => Row::int($row, 'stock_express'),
                'stock_market' => Row::int($row, 'stock_market'),
                'is_active' => Row::bool($row, 'is_active'),
                'sort' => Row::int($row, 'sort'),
                'order_lines' => $lines,
                'movements' => $movements,
                'may_delete' => $lines === 0 && $units === 0 && $movements === 0,
                'delete_blocked_reason' => match (true) {
                    $lines > 0 => "مرتبط بـ {$lines} سطر طلب",
                    $units !== 0 => "يحمل {$units} وحدة",
                    $movements > 0 => "له {$movements} حركة مخزون — عطّله بدلًا من حذفه",
                    default => null,
                },
            ];
        }

        return $out;
    }

    // ── internals ────────────────────────────────────────────────────────────────────────────

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
                throw new RuntimeException('الكمية يجب أن تكون صفرًا أو أكثر.');
            }

            $this->inventory->set(
                target: $target,
                bucket: $bucket,
                quantity: $quantity,
                reason: 'manual',
                actor: $actor,
                note: 'لوحة التحكم — تعديل كمية صف',
            );
        }
    }

    /**
     * After a variant is added or removed, the product's level may have changed.
     *
     * `recomputeInStock()` is safe at either level since the 2026-09-10 review, so it is called
     * unconditionally; the aggregate columns are maintained by `InventoryService` on every
     * movement, so nothing else needs recomputing here. The prices are refreshed because a
     * variant carries a `price_delta`.
     */
    private function afterStructureChange(int $productId): void
    {
        $this->inventory->recomputeInStock($productId);
        $this->products->refreshEffectivePrices($productId);
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
