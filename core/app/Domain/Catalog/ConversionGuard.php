<?php

namespace App\Domain\Catalog;

use App\Domain\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * THE HARD RULE OF WAVE 3.5, enforced where a human acts.
 *
 * **A LIVE product may not be converted to sell through variants before the write-switch**
 * (AGENTS §3, study §3.10.9 flag 1, wave-3.5 review 🟠-4). Wave 3.5 built the mechanism and said
 * out loud that `inventory:verify` only REPORTS the divergence; wave 4B is the wave that must
 * REFUSE it, because the dashboard is the only thing that can perform the conversion.
 *
 * ── Why it is dangerous, in one paragraph ────────────────────────────────────────────────────
 *
 * Until the switch both applications write stock: the legacy Blade dashboard and the legacy
 * checkout decrement legacy `products.stock`, and the transform mirrors that column into
 * `catalog_products.stock_*`. The moment a product has variants, core treats those columns as a
 * maintained AGGREGATE of its variants — while the legacy app keeps selling against the same
 * column with no variant behind it. Worse, the transform stops reconciling the stock mirror for a
 * variant product (`catalog_products[stock mirror]` excludes variant product ids), so nothing
 * catches the divergence afterwards. Stock silently becomes fiction on the live storefront.
 *
 * ── What counts as LIVE ──────────────────────────────────────────────────────────────────────
 *
 * "The legacy application knows this product and is still writing its stock column." The signal is
 * core-side and needs no legacy read: every product the transform created carries a
 * `reason = 'transform'` baseline movement in `inventory_movements` (step 20). A product created
 * in this dashboard has none, which is exactly the safe path the rule names — "a NEW product that
 * has variants from birth".
 *
 * A baseline movement is used rather than "does legacy have this id" for two reasons: it is a read
 * of a CORE table (§2.9.6 rule 5 keeps legacy reads inside `LegacySource`), and it survives a
 * rehearsal restore in the same shape.
 *
 * ── Fail-safe by construction ────────────────────────────────────────────────────────────────
 *
 * `config('transform.write_switch_completed')` defaults to FALSE. A flag nobody remembered to flip
 * refuses the conversion; there is no path where forgetting something ALLOWS it.
 */
final class ConversionGuard
{
    public function __construct(private readonly InventoryService $inventory) {}

    /** Has the write-switch happened? While false, core is not the only writer of a stock column. */
    public static function writeSwitchCompleted(): bool
    {
        return (bool) config('transform.write_switch_completed', false);
    }

    /**
     * Did the transform create this product — i.e. does the legacy application know it?
     *
     * The baseline movement is written once per product per bucket by step 20 and never deleted
     * (the ledger is append-only), so its presence is a permanent fact about the row's origin.
     */
    public static function isLegacyBacked(int $productId): bool
    {
        return DB::table('inventory_movements')
            ->where('product_id', $productId)
            ->where('reason', 'transform')
            ->exists();
    }

    /**
     * May this product gain its FIRST variant?
     *
     * Adding a second variant to a product that already has one is NOT a conversion — the product
     * is already variant-authoritative and the legacy app is already out of the picture for it —
     * so only the first one is gated.
     *
     * @return array{allowed: bool, reason: string}
     */
    public function mayConvert(int $productId): array
    {
        if ($this->inventory->hasVariants($productId)) {
            return ['allowed' => true, 'reason' => 'المنتج يعمل بالمقاسات/الألوان بالفعل، فإضافة صف آخر ليست تحويلاً.'];
        }

        if (self::writeSwitchCompleted()) {
            return ['allowed' => true, 'reason' => 'تم التحويل النهائي للكتابة، والنواة هي الكاتب الوحيد للمخزون.'];
        }

        if (! self::isLegacyBacked($productId)) {
            return ['allowed' => true, 'reason' => 'منتج جديد أُنشئ من هذه اللوحة، فلا يوجد مخزون قديم يُكتب من تطبيقين.'];
        }

        return [
            'allowed' => false,
            'reason' => 'ممنوع قبل التحويل النهائي: هذا منتج قديم ما زال تطبيق الداشبورد القديم يخصم مخزونه مباشرة. '
                .'تحويله الآن يجعل النواة تعامل مخزونه كمجموع للمقاسات بينما يبيع التطبيق القديم من نفس العمود — '
                .'والتحويل لا يوفّق هذا العمود بعد ذلك، فلا شيء يكتشف الفرق. '
                .'الطريقان الآمنان: منتج جديد بمقاسات من البداية، أو التحويل بعد ليلة التحويل.',
        ];
    }

    /**
     * Refuse, loudly, unless the conversion is allowed.
     *
     * @throws RuntimeException
     */
    public function assertMayConvert(int $productId): void
    {
        $verdict = $this->mayConvert($productId);
        if (! $verdict['allowed']) {
            throw new RuntimeException($verdict['reason']);
        }
    }

    /**
     * What the product form needs to render the panel honestly: whether the button is live, and
     * the sentence explaining why not. The screen must never show an enabled control that the
     * server will refuse — and it must never rely on hiding it either, which is why
     * {@see self::assertMayConvert()} runs again on the write path.
     *
     * @return array{has_variants: bool, may_convert: bool, reason: string, write_switch_completed: bool, legacy_backed: bool}
     */
    public function state(int $productId): array
    {
        $verdict = $this->mayConvert($productId);

        return [
            'has_variants' => $this->inventory->hasVariants($productId),
            'may_convert' => $verdict['allowed'],
            'reason' => $verdict['reason'],
            'write_switch_completed' => self::writeSwitchCompleted(),
            'legacy_backed' => self::isLegacyBacked($productId),
        ];
    }
}
