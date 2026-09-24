<?php

namespace Tests\Support;

use App\Domain\Promotions\CartLine;
use App\Domain\Promotions\CartSnapshot;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Promotion rules, built as the authoring screen will build them (wave 4D, study §3.16).
 *
 * Everything is explicit — priority, window, storefronts, conditions, rewards — because a
 * promotion test whose rule was assembled by defaults is a test about the defaults.
 */
final class PromotionFixture
{
    /**
     * A rule with its storefronts, conditions and rewards. Returns the rule id.
     *
     * @param  list<array<string, mixed>>  $conditions
     * @param  list<array<string, mixed>>  $rewards
     * @param  list<int>  $storefronts
     */
    public static function rule(
        array $conditions,
        array $rewards,
        array $storefronts = [1],
        int $priority = 0,
        bool $active = true,
        ?string $startsAt = null,
        ?string $endsAt = null,
        string $name = 'قاعدة اختبار',
    ): int {
        $id = (int) DB::table('promotion_rules')->insertGetId([
            'name' => $name,
            'priority' => $priority,
            'is_active' => $active,
            // A window is mandatory by column, not only by screen: "no end date" is an authoring
            // refusal and the schema enforces it.
            'starts_at' => $startsAt ?? now()->subDay()->format('Y-m-d H:i:s'),
            'ends_at' => $endsAt ?? now()->addMonth()->format('Y-m-d H:i:s'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($storefronts as $storefrontId) {
            DB::table('promotion_rule_storefront')->insert([
                'promotion_rule_id' => $id,
                'storefront_id' => $storefrontId,
            ]);
        }

        foreach ($conditions as $condition) {
            DB::table('promotion_rule_conditions')->insert($condition + [
                'promotion_rule_id' => $id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ($rewards as $reward) {
            DB::table('promotion_rule_rewards')->insert($reward + [
                'promotion_rule_id' => $id,
                'quantity' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $id;
    }

    /** `cart_subtotal_min` — the simplest condition to reason about in a test. */
    /** @return array<string, mixed> */
    public static function subtotalAtLeast(float $amount): array
    {
        return ['type' => 'cart_subtotal_min', 'amount' => number_format($amount, 2, '.', '')];
    }

    /** @return array<string, mixed> */
    public static function productQuantityAtLeast(int $productId, int $quantity): array
    {
        return ['type' => 'product_quantity_min', 'product_id' => $productId, 'quantity' => $quantity];
    }

    /** @return array<string, mixed> */
    public static function distinctLinesAtLeast(int $quantity): array
    {
        return ['type' => 'distinct_lines_min', 'quantity' => $quantity];
    }

    /**
     * @param  list<string>  $methods
     * @return array<string, mixed>
     */
    public static function paymentMethodIn(array $methods): array
    {
        return ['type' => 'payment_method_in', 'methods' => implode(',', $methods)];
    }

    /** @return array<string, mixed> */
    public static function freeProduct(int $productId, int $quantity = 1): array
    {
        return ['type' => 'free_product', 'product_id' => $productId, 'quantity' => $quantity];
    }

    /** A reward type the engine must refuse until wave 9 (§3.16.9). */
    /** @return array<string, mixed> */
    public static function percentDiscount(float $percent = 10.0): array
    {
        return ['type' => 'percent_discount', 'product_id' => null, 'quantity' => 1, 'amount' => number_format($percent, 2, '.', '')];
    }

    /** @return array<string, mixed> */
    public static function fixedDiscount(float $amount = 25.0): array
    {
        return ['type' => 'fixed_discount', 'product_id' => null, 'quantity' => 1, 'amount' => number_format($amount, 2, '.', '')];
    }

    /** @return array<string, mixed> */
    public static function freeShipping(): array
    {
        // No amount: it waives whatever the cart's shipping happens to be.
        return ['type' => 'free_shipping', 'product_id' => null, 'quantity' => 1, 'amount' => null];
    }

    /**
     * Turn the money-reward setting on (or off) for a storefront, and return what it was.
     *
     * The test CONSTRUCTS the condition it asserts on — it never assumes the seeded storefront
     * happens to carry the setting. That is an AGENTS §4 law here, written after a media test
     * inherited its fixture from the developer's own disk and failed when the disk changed.
     */
    public static function moneyRewards(bool $enabled, int $storefrontId = 1): void
    {
        $current = DB::table('storefronts')->where('id', $storefrontId)->value('settings');
        $settings = [];

        if (is_string($current) && $current !== '') {
            $decoded = json_decode($current, true);
            if (is_array($decoded)) {
                $settings = $decoded;
            }
        }

        $promotions = $settings['promotions'] ?? [];
        $settings['promotions'] = (is_array($promotions) ? $promotions : []) + [];
        $settings['promotions']['money_rewards'] = $enabled;

        DB::table('storefronts')->where('id', $storefrontId)->update([
            'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * A cart snapshot, priced the way the server prices one.
     *
     * @param  list<array{product: int, qty: int, price: float}>  $lines
     */
    public static function cart(array $lines, int $storefrontId = 1, string $method = 'cash', float $shipping = 50.0): CartSnapshot
    {
        $cartLines = [];
        $subtotal = 0.0;
        foreach ($lines as $line) {
            $cartLines[] = new CartLine(
                productId: $line['product'],
                variantId: null,
                offerId: null,
                quantity: $line['qty'],
                unitPrice: $line['price'],
                typeStock: 'Express',
            );
            $subtotal += $line['price'] * $line['qty'];
        }

        return new CartSnapshot(
            storefrontId: $storefrontId,
            lines: $cartLines,
            subtotal: round($subtotal, 2),
            shippingCost: $shipping,
            paymentMethod: $method,
        );
    }

    /**
     * A product with enough express stock to be given away, and its id.
     *
     * Derived from the data, never named (§4 law): a rebuild renames products but there is always
     * something with stock.
     */
    public static function giftableProduct(int $minStock = 3): int
    {
        $id = DB::table('catalog_products as p')
            ->join('storefront_product as sp', function (JoinClause $join): void {
                $join->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', '=', 1);
            })
            ->whereNull('p.deleted_at')
            ->where('p.is_active', 1)
            ->where('sp.is_visible', 1)
            ->where('p.stock_express', '>=', $minStock)
            ->orderBy('p.id')
            ->value('p.id');

        return T::int($id);
    }

    /** A visible product whose express AND market stock are both zero — an undeliverable gift. */
    public static function outOfStockProduct(): ?int
    {
        $id = DB::table('catalog_products as p')
            ->join('storefront_product as sp', function (JoinClause $join): void {
                $join->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', '=', 1);
            })
            ->whereNull('p.deleted_at')
            ->where('p.is_active', 1)
            ->where('sp.is_visible', 1)
            ->where('p.stock_express', 0)
            ->where('p.stock_market', 0)
            ->orderBy('p.id')
            ->value('p.id');

        return $id === null ? null : T::int($id);
    }
}
