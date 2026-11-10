<?php

declare(strict_types=1);

namespace App\Domain\Promotions;

/**
 * The money of ONE money reward over the cart lines it targets (M1u, 2026-10-30) — pure arithmetic, no database.
 *
 * Everything is in integer CENTS, so a split never loses or invents a piastre: the per-line amounts always add up
 * to the rule's discount exactly (largest-remainder allocation), which is what the order's discount record and a
 * later refund rely on.
 *
 *  - `item` + fixed: the value off EACH eligible unit ("EGP 1,000 off every watch": two watches = 2,000).
 *  - `order` + fixed: the value ONCE, spread over the eligible lines in proportion to what each can still give.
 *  - percent (either `apply_per`): the rate of each eligible line's remaining value — "per item" and "per order"
 *    are the same number for a percentage; only the cap below can tell them apart, and it applies to the rule.
 *  - `max_discount` caps the rule's total; the lines are scaled down to it.
 *  - `unit_floor`: no unit may end below it. A line's HEADROOM is `qty × (unit − floor)` minus what an earlier
 *    (stacked) rule already took; nothing takes more than the headroom, so no line, and no order, goes below zero.
 */
final class PromotionMoney
{
    public const PER_ORDER = 'order';

    public const PER_ITEM = 'item';

    /** Off ONE unit — the cheapest eligible line's (the wheel's "10 % off the 2nd watch", 2026-11-09). */
    public const PER_CHEAPEST = 'cheapest';

    /** @var list<string> */
    public const PER = [self::PER_ORDER, self::PER_ITEM, self::PER_CHEAPEST];

    /**
     * @param  array<int, array{unit: float, quantity: int}>  $lines  the ELIGIBLE lines, by cart index
     * @param  array<int, float>  $taken  what earlier rules already took off each line (stacking)
     * @return array<int, float> cart index => discount on that line (only lines with a discount)
     */
    public static function allocate(array $lines, array $taken, string $type, float $value, string $per,
        ?float $cap, float $floor): array
    {
        $headroom = [];
        $base = [];
        foreach ($lines as $index => $line) {
            $quantity = max(0, $line['quantity']);
            $unitCents = self::cents($line['unit']);
            $floorCents = max(0, self::cents($floor));
            $takenCents = self::cents($taken[$index] ?? 0.0);
            $headroom[$index] = max(0, $quantity * max(0, $unitCents - $floorCents) - $takenCents);
            $base[$index] = max(0, $quantity * $unitCents - $takenCents);
        }

        $wanted = [];
        if ($type === 'percent_discount') {
            $rate = max(0.0, min(100.0, $value)) / 100;
            foreach ($headroom as $index => $room) {
                $wanted[$index] = min($room, (int) round($base[$index] * $rate));
            }
        } elseif ($type === 'fixed_discount' && $per === self::PER_ITEM) {
            $valueCents = max(0, self::cents($value));
            foreach ($headroom as $index => $room) {
                $wanted[$index] = min($room, $valueCents * max(0, $lines[$index]['quantity']));
            }
        } elseif ($type === 'fixed_discount') {
            $wanted = self::spread(max(0, self::cents($value)), $headroom);
        } else {
            return [];
        }

        $total = array_sum($wanted);
        if ($cap !== null && $cap >= 0.0 && $total > self::cents($cap)) {
            // scale every line to the cap, in proportion to what it wanted (never above its own headroom)
            $wanted = self::spread(self::cents($cap), $wanted);
        }

        $out = [];
        foreach ($wanted as $index => $cents) {
            if ($cents > 0) {
                $out[$index] = round($cents / 100, 2);
            }
        }

        return $out;
    }

    /**
     * `$amount` cents over the weights, each share at most its weight, the shares summing to min(amount, Σweights).
     *
     * @param  array<int, int>  $weights
     * @return array<int, int>
     */
    private static function spread(int $amount, array $weights): array
    {
        $sum = array_sum($weights);
        if ($sum <= 0 || $amount <= 0) {
            return array_map(fn (): int => 0, $weights);
        }
        if ($amount >= $sum) {
            return $weights;
        }
        $shares = [];
        $remainders = [];
        foreach ($weights as $index => $weight) {
            $exact = $amount * $weight / $sum;
            $shares[$index] = (int) floor($exact);
            $remainders[$index] = $exact - $shares[$index];
        }
        $left = $amount - array_sum($shares);
        arsort($remainders);
        foreach (array_keys($remainders) as $index) {
            if ($left <= 0) {
                break;
            }
            if ($shares[$index] < $weights[$index]) {
                $shares[$index]++;
                $left--;
            }
        }

        return $shares;
    }

    private static function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
