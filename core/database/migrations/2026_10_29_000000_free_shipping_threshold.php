<?php

use App\Domain\Shipping\FreeShipping;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * FREE SHIPPING OVER A THRESHOLD, per storefront (2026-10-29, the client via Michael: "free shipping on orders over
 * 10,000 EGP", both storefronts) — App\Domain\Shipping\FreeShipping.
 *
 *  1. `orders.free_shipping_over` DECIMAL(12,2) NULL: the threshold that waived THIS order's delivery, written by the
 *     checkout when it applies; NULL for every order before this and for every order that paid delivery. It is how
 *     OrderTotals (the dashboard, both e-mails) tells a free delivery from an unexplained gap, and it keeps the
 *     threshold that applied when the order was placed after the dashboard changes it.
 *  2. `storefronts.settings.shipping.free_over` = 10000 on every storefront that does not name one yet — written
 *     explicitly so the dashboard shows the figure that applies (an absent key means the same default).
 *
 * Guarded and additive; forward-only in production — the hand-written rollback SQL is in the runbook
 * (core/docs/deploy/FREE_SHIPPING_20261029.md). down() is the same rollback, for a local or scratch copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'free_shipping_over')) {
            Schema::table('orders', function (Blueprint $t): void {
                $t->decimal('free_shipping_over', 12, 2)->nullable()->after('total_price_for_order');
            });
        }

        foreach (DB::table('storefronts')->select(['id', 'settings'])->orderBy('id')->get() as $row) {
            $settings = self::settings($row->settings);
            $shipping = is_array($settings['shipping'] ?? null) ? $settings['shipping'] : [];
            if (array_key_exists('free_over', $shipping)) {
                continue; // an operator's figure (or 0, off) is never overwritten by a re-run
            }
            $shipping['free_over'] = FreeShipping::DEFAULT_OVER;
            $settings['shipping'] = $shipping;
            DB::table('storefronts')->where('id', $row->id)->update(['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('storefronts')->select(['id', 'settings'])->orderBy('id')->get() as $row) {
            $settings = self::settings($row->settings);
            if (! is_array($settings['shipping'] ?? null)) {
                continue;
            }
            $shipping = $settings['shipping'];
            unset($shipping['free_over']);
            if ($shipping === []) {
                unset($settings['shipping']); // this release is the only thing that ever put `shipping` there
            } else {
                $settings['shipping'] = $shipping;
            }
            DB::table('storefronts')->where('id', $row->id)
                ->update(['settings' => $settings === [] ? null : json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        }

        if (Schema::hasColumn('orders', 'free_shipping_over')) {
            Schema::table('orders', function (Blueprint $t): void {
                $t->dropColumn('free_shipping_over');
            });
        }
    }

    /** @return array<string, mixed> */
    private static function settings(mixed $raw): array
    {
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $out = [];
        foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }
};
