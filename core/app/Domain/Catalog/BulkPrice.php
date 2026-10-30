<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Activity\ActivityLog;
use App\Support\LocalisedName;
use App\Support\ManageText;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A BULK PRICE CHANGE on the products list (M1v, 2026-10-30, Michael's item 2): plan it, show it, apply it, undo it.
 *
 * ── What can be asked ────────────────────────────────────────────────────────────────────────
 *
 *  • mode: raise or lower by an amount or a percentage, or set a fixed price;
 *  • target: the regular price (`selling_price`), the sale price (`sale_price`), or clear the sale price;
 *  • rounding: to the nearest 5 / 10 / 50 / 100 EGP, or none.
 *
 * A sale-price change starts from the product's valid sale price, else from its regular price — "10% off as a sale" on a
 * product with no sale creates one at 90% of the price.
 *
 * ── The safety rules, all enforced HERE (the screen only shows them) ─────────────────────────
 *
 *  • the PLAN is recomputed on apply from the same request; the browser never sends a price;
 *  • at most {@see self::MAX} products per action — more is refused, with the count, before anything is written;
 *  • no price can become zero or negative: such a row is REFUSED and left unchanged, and said so in the plan;
 *  • a sale price must stay below the regular price (the price contract every storefront and the checkout guard
 *    use): a sale target that would reach it is refused; a regular-price change that falls to or under the stored
 *    sale price clears that sale, and the plan says so;
 *  • every product is written through {@see ProductPatcher} (which refreshes each storefront's effective price, the
 *    search row and the caches) and gets its own activity-log row, user + old → new; the batch is recorded for undo;
 *  • UNDO restores a product only where its prices are still exactly what the batch set — a product changed since is
 *    skipped and named, never overwritten.
 */
final class BulkPrice
{
    /** The most products one action may change. Above it the screen asks for a narrower filter (D-B1). */
    public const MAX = 300;

    /** @var list<string> */
    public const MODES = ['increase_amount', 'decrease_amount', 'increase_percent', 'decrease_percent', 'set'];

    /** @var list<string> */
    public const TARGETS = ['selling', 'sale', 'clear_sale'];

    /** @var list<int> */
    public const ROUNDING = [0, 5, 10, 50, 100];

    public function __construct(private readonly ProductPatcher $patcher) {}

    /**
     * The plan: one row per product, the prices before and after, and why a row will not change.
     *
     * @param  list<int>  $ids
     * @return list<array{id: int, title: string, sku: string|null, wa_code: string|null, old_selling: float, old_sale: float|null, new_selling: float, new_sale: float|null, change: bool, note: string|null}>
     */
    public function plan(array $ids, string $mode, ?float $value, string $target, int $rounding): array
    {
        self::assertRequest($mode, $value, $target, $rounding);
        if ($ids === []) {
            return [];
        }

        $rows = [];
        foreach (DB::table('catalog_products as p')
            ->leftJoin('catalog_product_translations as ar', fn (JoinClause $j) => $j->on('ar.product_id', '=', 'p.id')->where('ar.locale', '=', 'ar'))
            ->leftJoin('catalog_product_translations as en', fn (JoinClause $j) => $j->on('en.product_id', '=', 'p.id')->where('en.locale', '=', 'en'))
            ->whereIn('p.id', $ids)->whereNull('p.deleted_at')->orderBy('p.id')
            ->get(['p.id', 'p.sku', 'p.wa_code', 'p.selling_price', 'p.sale_price', 'ar.title as ar_title', 'en.title as en_title']) as $raw) {
            $row = Row::cast($raw);
            $selling = (float) Row::money($row, 'selling_price');
            $storedSale = Row::nmoney($row, 'sale_price');
            $sale = $storedSale === null ? null : (float) $storedSale;
            $validSale = $sale !== null && $sale > 0 && $sale < $selling ? $sale : null;

            [$newSelling, $newSale, $note] = self::price($selling, $validSale, $mode, (float) ($value ?? 0), $target, $rounding);
            $change = $note === null || str_starts_with($note, 'cleared:');
            $rows[] = [
                'id' => Row::int($row, 'id'),
                'title' => LocalisedName::pick(Row::nstr($row, 'ar_title'), Row::nstr($row, 'en_title'), '#'.Row::int($row, 'id')),
                'sku' => Row::nstr($row, 'sku'),
                'wa_code' => Row::nstr($row, 'wa_code'),
                'old_selling' => $selling,
                'old_sale' => $validSale,
                'new_selling' => $change ? $newSelling : $selling,
                'new_sale' => $change ? $newSale : $validSale,
                'change' => $change && (round($newSelling, 2) !== round($selling, 2) || $newSale !== $validSale),
                'note' => $note === null ? null : self::noteText($note),
            ];
        }

        return $rows;
    }

    /**
     * Apply the plan: write every changed product, log each one, record the batch. Returns the batch id and counts.
     *
     * @param  list<int>  $ids
     * @return array{batch_id: int|null, changed: int, refused: int, unchanged: int}
     */
    public function apply(array $ids, string $mode, ?float $value, string $target, int $rounding, ?int $storefrontId, ?int $actorId): array
    {
        if (count($ids) > self::MAX) {
            throw new InvalidArgumentException(self::overCapMessage(count($ids)));
        }
        $plan = $this->plan($ids, $mode, $value, $target, $rounding);
        $changing = array_values(array_filter($plan, fn (array $r): bool => $r['change']));
        $refused = count(array_filter($plan, fn (array $r): bool => ! $r['change'] && $r['note'] !== null));
        if ($changing === []) {
            return ['batch_id' => null, 'changed' => 0, 'refused' => $refused, 'unchanged' => count($plan) - $refused];
        }

        return ActivityLog::within(ActivityLog::CONTEXT_BULK_PRICE, fn (): array => DB::transaction(function () use ($changing, $plan, $refused, $mode, $value, $target, $rounding, $storefrontId, $actorId): array {
            $batchId = (int) DB::table('core_price_batches')->insertGetId([
                'storefront_id' => $storefrontId,
                'user_id' => $actorId,
                'mode' => $mode,
                'target' => $target,
                'value' => $value === null ? null : number_format($value, 2, '.', ''),
                'rounding' => $rounding,
                'product_count' => count($changing),
                'created_at' => now(),
            ]);
            foreach ($changing as $r) {
                $this->write($r['id'], $r['old_selling'], $r['old_sale'], $r['new_selling'], $r['new_sale'], $actorId,
                    ManageText::t('products.bulk_price_label', ':name — تغيير أسعار جماعي #:batch', ['name' => $r['title'], 'batch' => $batchId]));
                DB::table('core_price_batch_items')->insert([
                    'batch_id' => $batchId,
                    'product_id' => $r['id'],
                    'old_selling' => number_format($r['old_selling'], 2, '.', ''),
                    'old_sale' => $r['old_sale'] === null ? null : number_format($r['old_sale'], 2, '.', ''),
                    'new_selling' => number_format($r['new_selling'], 2, '.', ''),
                    'new_sale' => $r['new_sale'] === null ? null : number_format($r['new_sale'], 2, '.', ''),
                ]);
            }

            return ['batch_id' => $batchId, 'changed' => count($changing), 'refused' => $refused,
                'unchanged' => count($plan) - count($changing) - $refused];
        }));
    }

    /**
     * Undo a batch: each product goes back to its old prices ONLY if its prices are still the ones the batch set.
     *
     * @return array{restored: int, skipped: list<int>, already: bool}
     */
    public function undo(int $batchId, ?int $actorId): array
    {
        $batch = DB::table('core_price_batches')->where('id', $batchId)->first(['id', 'undone_at']);
        if (! is_object($batch)) {
            throw new InvalidArgumentException('No such price batch.');
        }
        if (Row::nstr(Row::cast($batch), 'undone_at') !== null) {
            return ['restored' => 0, 'skipped' => [], 'already' => true];
        }

        return ActivityLog::within(ActivityLog::CONTEXT_BULK_PRICE, fn (): array => DB::transaction(function () use ($batchId, $actorId): array {
            $restored = 0;
            $skipped = [];
            foreach (DB::table('core_price_batch_items as i')->join('catalog_products as p', 'p.id', '=', 'i.product_id')
                ->where('i.batch_id', $batchId)->orderBy('i.id')
                ->get(['i.product_id', 'i.old_selling', 'i.old_sale', 'i.new_selling', 'i.new_sale', 'p.selling_price', 'p.sale_price']) as $raw) {
                $row = Row::cast($raw);
                $productId = Row::int($row, 'product_id');
                $nowSelling = (float) Row::money($row, 'selling_price');
                $nowSale = Row::nmoney($row, 'sale_price') === null ? null : (float) Row::nmoney($row, 'sale_price');
                $setSelling = (float) Row::money($row, 'new_selling');
                $setSale = Row::nmoney($row, 'new_sale') === null ? null : (float) Row::nmoney($row, 'new_sale');
                if (round($nowSelling, 2) !== round($setSelling, 2) || self::cents($nowSale) !== self::cents($setSale)) {
                    $skipped[] = $productId;           // changed since the batch: never overwritten

                    continue;
                }
                $oldSale = Row::nmoney($row, 'old_sale') === null ? null : (float) Row::nmoney($row, 'old_sale');
                $this->write($productId, $setSelling, $setSale, (float) Row::money($row, 'old_selling'), $oldSale, $actorId,
                    ManageText::t('products.bulk_price_undo_label', ':name — تراجع عن تغيير الأسعار #:batch', ['name' => self::titleOf($productId), 'batch' => $batchId]));
                $restored++;
            }
            DB::table('core_price_batches')->where('id', $batchId)->update(['undone_at' => now(), 'undone_by' => $actorId]);

            return ['restored' => $restored, 'skipped' => $skipped, 'already' => false];
        }));
    }

    /** The sentence the screen shows above the cap. */
    public static function overCapMessage(int $count): string
    {
        return ManageText::t('products.bulk_price_over_cap', 'هذا التغيير يشمل :count منتجًا، والحد الأقصى :max في المرة الواحدة. ضيّق التصفية (الماركة أو التصنيف أو نطاق السعر) ثم أعد المحاولة.',
            ['count' => $count, 'max' => self::MAX]);
    }

    private function write(int $productId, float $oldSelling, ?float $oldSale, float $newSelling, ?float $newSale, ?int $actorId, string $label): void
    {
        $this->patcher->patch($productId, [
            'selling_price' => number_format($newSelling, 2, '.', ''),
            'sale_price' => $newSale === null ? null : number_format($newSale, 2, '.', ''),
        ], $actorId);
        // one row per PRODUCT, so its own history answers "who changed this price, and from what"
        ActivityLog::record('catalog_products', $productId, ActivityLog::UPDATED,
            ['selling_price' => number_format($oldSelling, 2, '.', ''), 'sale_price' => $oldSale === null ? null : number_format($oldSale, 2, '.', '')],
            ['selling_price' => number_format($newSelling, 2, '.', ''), 'sale_price' => $newSale === null ? null : number_format($newSale, 2, '.', '')],
            label: $label);
    }

    /**
     * The new (selling, sale) and a refusal/notice code, or null when the row simply changes.
     *
     * @return array{0: float, 1: float|null, 2: string|null}
     */
    public static function price(float $selling, ?float $sale, string $mode, float $value, string $target, int $rounding): array
    {
        if ($target === 'clear_sale') {
            return $sale === null ? [$selling, null, 'no_sale'] : [$selling, null, null];
        }
        $base = $target === 'sale' ? ($sale ?? $selling) : $selling;
        $new = match ($mode) {
            'increase_amount' => $base + $value,
            'decrease_amount' => $base - $value,
            'increase_percent' => $base * (1 + $value / 100),
            'decrease_percent' => $base * (1 - $value / 100),
            default => $value,                  // set
        };
        $new = self::round($new, $rounding);
        if ($new <= 0.0) {
            return [$selling, $sale, 'not_positive'];
        }
        if ($target === 'sale') {
            return $new >= $selling ? [$selling, $sale, 'sale_not_below'] : [$selling, $new, null];
        }
        // the regular price: a stored sale at or above the new price is no longer a sale (the price contract)
        if ($sale !== null && $sale >= $new) {
            return [$new, null, 'cleared:sale'];
        }

        return [$new, $sale, null];
    }

    public static function round(float $amount, int $step): float
    {
        return $step > 0 ? round(round($amount / $step) * $step, 2) : round($amount, 2);
    }

    private static function titleOf(int $productId): string
    {
        $ar = DB::table('catalog_product_translations')->where('product_id', $productId)->where('locale', 'ar')->value('title');
        $en = DB::table('catalog_product_translations')->where('product_id', $productId)->where('locale', 'en')->value('title');

        return LocalisedName::pick(is_string($ar) ? $ar : null, is_string($en) ? $en : null, '#'.$productId);
    }

    private static function cents(?float $amount): ?int
    {
        return $amount === null ? null : (int) round($amount * 100);
    }

    private static function noteText(string $code): string
    {
        return match ($code) {
            'not_positive' => ManageText::t('products.bulk_price_not_positive', 'لن يتغيّر: السعر الناتج صفر أو أقل.'),
            'sale_not_below' => ManageText::t('products.bulk_price_sale_not_below', 'لن يتغيّر: سعر التخفيض لازم يكون أقل من السعر الأصلي.'),
            'no_sale' => ManageText::t('products.bulk_price_no_sale', 'لا يوجد سعر تخفيض لمسحه.'),
            'cleared:sale' => ManageText::t('products.bulk_price_sale_cleared', 'سيُمسح سعر التخفيض: لم يعد أقل من السعر الجديد.'),
            default => $code,
        };
    }

    private static function assertRequest(string $mode, ?float $value, string $target, int $rounding): void
    {
        if (! in_array($mode, self::MODES, true) || ! in_array($target, self::TARGETS, true) || ! in_array($rounding, self::ROUNDING, true)) {
            throw new InvalidArgumentException('Unknown bulk price request.');
        }
        if ($target !== 'clear_sale' && ($value === null || $value < 0)) {
            throw new InvalidArgumentException('A bulk price change needs a value of 0 or more.');
        }
    }
}
