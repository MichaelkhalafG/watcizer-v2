<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Compat\CompatCart;
use App\Storefront\ImageUrl;
use App\Support\LegacySlug;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "E-mail me when it's back" (2026-10-01; decisions of 2026-09-29 and 2026-09-30).
 *
 *  - A shopper subscribes on an OUT-OF-STOCK product page: a signed-in customer with one tap (their
 *    account's address), a guest by typing an address. One row per storefront, product and address;
 *    subscribing twice is harmless.
 *  - When units come back (`StockChanged` with a positive delta → `restocked()`), up to 5 waiting
 *    shoppers PER UNIT restocked — oldest first — are marked `notified` and each gets one e-mail,
 *    through the bulk channel (`BulkMailer`), so the batch uses only the bulk budget and never an
 *    order confirmation's place. Everyone else keeps waiting for the next restock.
 *  - At SEND time the product is checked again: sold out again before the e-mail went → the e-mail
 *    is dropped and the shopper goes back to waiting (nobody is told "it's back" when it isn't).
 *  - Kept 30 days after the e-mail, 180 days unanswered (`prune()`, daily).
 */
final class StockAlerts
{
    public const WAITING = 'waiting';

    public const NOTIFIED = 'notified';

    public const CANCELLED = 'cancelled';

    public const KIND = 'stock_alert';

    public function __construct(private readonly BulkMailer $mailer) {}

    /**
     * @return 'subscribed'|'already'|'in_stock'|'not_found'
     */
    public function subscribe(int $storefrontId, int $productId, string $email, ?int $userId, string $locale): string
    {
        $email = mb_strtolower(trim($email));
        $product = self::product($storefrontId, $productId, 'en');
        if ($product === null) {
            return 'not_found';
        }
        if ($product['in_stock']) {
            return 'in_stock';                                // nothing to wait for: add it to the cart
        }

        $existing = DB::table('core_stock_alerts')->where('storefront_id', $storefrontId)->where('product_id', $productId)->where('email', $email)->first(['id', 'status']);
        if (is_object($existing) && Row::str(Row::cast($existing), 'status') === self::WAITING) {
            return 'already';
        }
        $values = [
            'user_id' => $userId,
            'locale' => $locale === 'en' ? 'en' : 'ar',
            'status' => self::WAITING,
            'created_at' => now(),
            'notified_at' => null,
        ];
        if (is_object($existing)) {
            // Told once and asking again, or cancelled and back: waiting again, from now.
            DB::table('core_stock_alerts')->where('id', Row::int(Row::cast($existing), 'id'))->update($values);
        } else {
            DB::table('core_stock_alerts')->insertOrIgnore($values + [
                'storefront_id' => $storefrontId,
                'product_id' => $productId,
                'email' => $email,
                'token' => Str::random(40),
            ]);
        }

        return 'subscribed';
    }

    /**
     * Units of a product came back: notify up to `per_unit` waiting shoppers per unit, oldest first.
     *
     * @return int how many shoppers were queued an e-mail
     */
    public function restocked(int $productId, int $units): int
    {
        if ($units <= 0) {
            return 0;
        }
        $limit = $units * config()->integer('notifications.stock_alerts.per_unit');

        return DB::transaction(function () use ($productId, $limit): int {
            $rows = DB::table('core_stock_alerts')->where('product_id', $productId)->where('status', self::WAITING)
                ->orderBy('created_at')->orderBy('id')->limit($limit)->lockForUpdate()->get(['id', 'email']);
            $now = now();
            foreach ($rows as $raw) {
                $row = Row::cast($raw);
                $id = Row::int($row, 'id');
                DB::table('core_stock_alerts')->where('id', $id)->update(['status' => self::NOTIFIED, 'notified_at' => $now]);
                $this->mailer->enqueue(self::KIND, Row::str($row, 'email'), ['alert_id' => $id], 'stock_alert:'.$id.':'.$now->timestamp);
            }

            return count($rows);
        });
    }

    /**
     * What the e-mail needs, checked at SEND time. Null when there is nothing to send: the alert is
     * gone, or the product is out of stock again — then the shopper goes back to waiting.
     *
     * @return array{email: string, locale: string, token: string, product: array{name: string, price: float, image: ?string, url: string}}|null
     */
    public function mailData(int $alertId): ?array
    {
        $raw = DB::table('core_stock_alerts')->where('id', $alertId)->first();
        if (! is_object($raw)) {
            return null;
        }
        $alert = Row::cast($raw);
        $product = self::product(Row::int($alert, 'storefront_id'), Row::int($alert, 'product_id'), Row::str($alert, 'locale'));
        if ($product === null || ! $product['in_stock']) {
            DB::table('core_stock_alerts')->where('id', $alertId)->where('status', self::NOTIFIED)
                ->update(['status' => self::WAITING, 'notified_at' => null]);

            return null;
        }
        unset($product['in_stock']);

        return [
            'email' => Row::str($alert, 'email'),
            'locale' => Row::str($alert, 'locale'),
            'token' => Row::str($alert, 'token'),
            'product' => $product,
        ];
    }

    /** The e-mail's "stop" link: cancels this one alert. True when there was one to cancel. */
    public static function cancel(string $token): bool
    {
        return DB::table('core_stock_alerts')->where('token', $token)->where('status', self::WAITING)
            ->update(['status' => self::CANCELLED, 'notified_at' => now()]) > 0;
    }

    /**
     * How many shoppers are waiting, per product (the dashboard's number).
     *
     * @param  list<int>  $productIds
     * @return array<int, int>
     */
    public static function waitingCounts(array $productIds, ?int $storefrontId = null): array
    {
        if ($productIds === []) {
            return [];
        }
        $query = DB::table('core_stock_alerts')->whereIn('product_id', $productIds)->where('status', self::WAITING);
        if ($storefrontId !== null) {
            $query->where('storefront_id', $storefrontId);
        }
        $out = [];
        foreach ($query->groupBy('product_id')->selectRaw('product_id, COUNT(*) AS n')->get() as $raw) {
            $row = Row::cast($raw);
            $out[Row::int($row, 'product_id')] = Row::int($row, 'n');
        }

        return $out;
    }

    /**
     * Delete what is past keeping: notified and cancelled rows 30 days on, waiting rows 180 days on.
     *
     * @return array{notified: int, waiting: int}
     */
    public static function prune(): array
    {
        $notified = DB::table('core_stock_alerts')->whereIn('status', [self::NOTIFIED, self::CANCELLED])
            ->where('notified_at', '<', now()->subDays(config()->integer('notifications.stock_alerts.keep_notified_days')))->delete();
        $waiting = DB::table('core_stock_alerts')->where('status', self::WAITING)
            ->where('created_at', '<', now()->subDays(config()->integer('notifications.stock_alerts.keep_waiting_days')))->delete();

        return ['notified' => $notified, 'waiting' => $waiting];
    }

    /**
     * A product as the e-mail shows it, on one storefront: visible there, its name in `$locale`
     * (English when the Arabic is missing), the price the shopper pays, the cover, and its page.
     * Null when it is not visible on that storefront.
     *
     * @return array{name: string, price: float, image: ?string, url: string, in_stock: bool}|null
     */
    private static function product(int $storefrontId, int $productId, string $locale): ?array
    {
        $raw = DB::table('catalog_products as p')
            ->join('storefront_product as sp', function (JoinClause $j) use ($storefrontId): void {
                $j->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', '=', $storefrontId)->where('sp.is_visible', '=', 1);
            })
            ->where('p.id', $productId)->whereNull('p.deleted_at')->where('p.is_active', 1)
            ->first(['p.id', 'p.selling_price', 'p.sale_price', 'p.stock_express', 'p.stock_market']);
        if (! is_object($raw)) {
            return null;
        }
        $p = Row::cast($raw);
        $titles = DB::table('catalog_product_translations')->where('product_id', $productId)->pluck('title', 'locale');
        $en = is_string($titles['en'] ?? null) ? $titles['en'] : '';
        $name = is_string($titles[$locale] ?? null) && $titles[$locale] !== '' ? $titles[$locale] : $en;
        $cover = DB::table('catalog_product_images')->where('product_id', $productId)->orderByDesc('is_cover')->orderBy('sort')->value('path');
        $domain = DB::table('storefronts')->where('id', $storefrontId)->value('domain');
        $slug = LegacySlug::make($en);
        $path = '/product/'.($slug !== '' ? $slug : (string) $productId);

        return [
            'name' => $name,
            'price' => CompatCart::catalogPrice(Row::str($p, 'selling_price'), Row::nstr($p, 'sale_price')),
            'image' => is_string($cover) && $cover !== '' ? ImageUrl::src($cover) : null,
            'url' => 'https://'.(is_string($domain) ? $domain : 'watchizereg.com').($locale === 'ar' ? '/ar' : '').$path,
            'in_stock' => Row::int($p, 'stock_express') + Row::int($p, 'stock_market') > 0,
        ];
    }
}
