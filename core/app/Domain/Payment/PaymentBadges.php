<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Storefront\ImageUrl;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;

/**
 * The payment badges a storefront shows in its footer, on the cart and at checkout
 * (the twelve updates, Part 7, 2026-11-05).
 *
 * ── Two questions, two owners ─────────────────────────────────────────────────────────────────
 *
 * WHICH badges exist is not typed by anybody: it is {@see CheckoutMethods::offered()} — the list the
 * checkout itself offers on this storefront, i.e. the enabled methods of an enabled, complete contract.
 * A method the shop cannot take has no row there and therefore no badge here; switching a method off
 * in Payments removes its badge with it. That is the whole point of the design: the site cannot
 * advertise a way to pay that it does not offer.
 *
 * HOW each badge looks is the team's: one picture per method key, uploaded and replaced on the
 * Payments screen (`storefront_payment_badges`). A picture stored for a method the storefront does not
 * offer is kept and not shown — it cannot make a badge appear. A method with no picture is served
 * with `image: null` and the storefronts print its name instead.
 *
 * One badge per METHOD key, in the checkout's own order: two providers offering `card` are one badge.
 * Ordering by WhatsApp is a way to ORDER, not a way to pay, so it never becomes a badge.
 */
final class PaymentBadges
{
    public function __construct(private readonly CheckoutMethods $methods) {}

    /**
     * @return list<array{method: string, label: array{ar: string, en: string}, image_path: string|null, image: array{src: string}|null}>
     */
    public function forStorefront(int $storefrontId): array
    {
        $pictures = self::pictures($storefrontId);

        $out = [];
        $seen = [];
        foreach ($this->methods->offered($storefrontId) as $row) {
            $method = $row['method'];
            if ($method === OfflineProvider::WHATSAPP || isset($seen[$method])) {
                continue;
            }
            $seen[$method] = true;
            $path = $pictures[$method] ?? null;
            $out[] = [
                'method' => $method,
                'label' => $row['label'],
                'image_path' => $path,
                'image' => $path === null ? null : ['src' => ImageUrl::srcFor($storefrontId, $path)],
            ];
        }

        return $out;
    }

    /**
     * Store, replace or remove (null) the picture of one method on one storefront.
     */
    public static function set(int $storefrontId, string $method, ?string $imagePath, ?int $userId): void
    {
        $path = $imagePath === null || trim($imagePath) === '' ? null : trim($imagePath);
        if ($path === null) {
            DB::table('storefront_payment_badges')->where('storefront_id', $storefrontId)->where('method', $method)->delete();

            return;
        }

        DB::table('storefront_payment_badges')->updateOrInsert(
            ['storefront_id' => $storefrontId, 'method' => $method],
            ['image_path' => $path, 'updated_by' => $userId, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    /**
     * Every stored picture of a storefront, by method key — including ones whose method is not offered now.
     *
     * @return array<string, string>
     */
    public static function pictures(int $storefrontId): array
    {
        $out = [];
        foreach (DB::table('storefront_payment_badges')->where('storefront_id', $storefrontId)->get(['method', 'image_path']) as $raw) {
            $out[Row::str($raw, 'method')] = Row::str($raw, 'image_path');
        }

        return $out;
    }
}
