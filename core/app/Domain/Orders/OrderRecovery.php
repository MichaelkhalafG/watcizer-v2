<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Compat\CompatCart;
use App\Domain\Notifications\OrderMailer;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bring back a card order nobody paid for (2026-10-05, developer: version B, "one-click recovery").
 *
 * When `orders:expire-unpaid` cancels a card order, the customer is e-mailed a link that rebuilds
 * the order as a cart, at TODAY's prices and stock, and lands them on checkout with the details they
 * typed already filled in. Before this, nothing told them and nothing could resume: core deletes the
 * server cart when the order is placed, and the tab that held the browser copy is usually gone.
 *
 * ── The link ─────────────────────────────────────────────────────────────────────────────────
 *
 * `https://{the order's own storefront}/cart/recover?t={order}.{expires}.{mac}` — an HMAC-SHA256
 * over the order id and the expiry, keyed from APP_KEY, 128 bits shown, valid DAYS days. Not a
 * Laravel signed route: that signature binds to the host of the request that builds the URL, and
 * the cron that builds this one runs on no shop's host. Unguessable without APP_KEY; the order id
 * in it is the same number the e-mail already shows.
 *
 * ── What a link exposes — to whoever holds it, forwarded or not ──────────────────────────────
 *
 * Exactly `payload()`: the order NUMBER; per line the product id, name, quantity, colours, stock
 * type and the price that was quoted beside today's; and the customer's details as the order recorded them —
 * name, e-mail, phone, delivery address line and governorate. Nothing else: no account access (it
 * signs nobody in and never touches a session or a token), no other order, no payment data (core
 * holds none), no guest token, no order total history. It works only on the order's own storefront,
 * only while the order is still the cancelled card order it was, and only until it expires.
 *
 * ACCEPTED RISK (developer, 2026-10-05): a forwarded link shows that list — name, e-mail, phone,
 * address line, governorate and the order's lines — to whoever holds it, for DAYS days, on a signed
 * link that cannot be guessed; nothing beyond it. The acceptance covers EXACTLY that list. A change
 * that adds a field to `payload()` goes back to the developer first; OrderRecoveryTest's exposure
 * test fails on it by design and must not be widened to make it pass.
 *
 * ── Who is NOT e-mailed (`shouldRemind`) ─────────────────────────────────────────────────────
 *
 * Anybody who already came back: a later order from the same account, the same guest token, the
 * same e-mail address or the same phone number (last ten digits — `01…` and `+201…` are one
 * number). Whatever happened to that later order, a "your order was cancelled" e-mail now would be
 * wrong. And anybody already sent a recovery e-mail in the last DAYS days, about any order of theirs:
 * one per shopper per week, the admin copy following it, the week sliding from the last e-mail — a
 * new visit weeks later is e-mailed again. Asserted by OrderRecoveryTest, not by this comment.
 *
 * WITHDRAWN (developer, 2026-10-05): "e-mail only from the SECOND abandoned attempt". Measured, the
 * guards above already collapse any burst to one e-mail, and that rule would have sent nothing to
 * order 000024 — a single attempt, the case this was built for. Do not revive it.
 *
 * ── A link whose shopper has since bought (`reorderedSince`) ─────────────────────────────────
 *
 * Refused (`reordered`) once a later order of the same shopper went through — not cancelled, not a
 * card order still at Paymob — so a cart they already bought is never rebuilt.
 */
final class OrderRecovery
{
    public const DAYS = 7;

    /** Line states the storefront explains, one plain sentence each. */
    public const OK = 'ok';

    public const PRICE_CHANGED = 'price_changed';

    public const REDUCED = 'reduced';

    public const SLOWER = 'slower_delivery';

    public const SOLD_OUT = 'sold_out';

    public const GONE = 'gone';

    /** The link for an order, valid DAYS days from now. */
    public static function url(int $orderId): string
    {
        $domain = DB::table('orders as o')->leftJoin('storefronts as s', 's.id', '=', 'o.storefront_id')
            ->where('o.id', $orderId)->value('s.domain');
        $domain = is_string($domain) && $domain !== '' ? $domain : 'watchizereg.com';

        return 'https://'.$domain.'/cart/recover?t='.self::token($orderId);
    }

    public static function token(int $orderId, ?Carbon $now = null): string
    {
        $expires = ($now ?? now())->copy()->addDays(self::DAYS)->getTimestamp();

        return $orderId.'.'.$expires.'.'.self::mac($orderId, $expires);
    }

    /**
     * The order a token names, or why not.
     *
     * @return array{order: int}|array{refused: 'invalid'|'expired'}
     */
    public static function verify(string $token): array
    {
        if (preg_match('/^(\d{1,12})\.(\d{9,11})\.([a-f0-9]{32})$/', $token, $m) !== 1) {
            return ['refused' => 'invalid'];
        }
        [$orderId, $expires] = [(int) $m[1], (int) $m[2]];
        // The MAC first, compared in constant time: an expired-but-forged token says "invalid".
        if (! hash_equals(self::mac($orderId, $expires), $m[3])) {
            return ['refused' => 'invalid'];
        }
        if ($expires < now()->getTimestamp()) {
            return ['refused' => 'expired'];
        }

        return ['order' => $orderId];
    }

    /**
     * Whether this expiry e-mails anybody. False when the shopper already came back and ordered, or
     * when they were already sent a recovery e-mail in the last DAYS days (developer, 2026-10-05: one
     * per shopper per week; inside that week the earlier link still works). The window SLIDES from the
     * last e-mail actually sent, so a shopper who hesitates again three weeks later hears from us
     * again — a new visit, not a repeat. The admin copy follows the same answer.
     */
    public static function shouldRemind(int $orderId): bool
    {
        $created = DB::table('orders')->where('id', $orderId)->value('created_at');
        if (! is_string($created)) {
            return false;
        }

        // 1. Came back and ordered: any order placed since this one (normally the hour before it
        //    expired — a handful at most).
        $later = DB::table('orders')->where('id', '>', $orderId)->where('created_at', '>=', $created)->pluck('id')->all();
        if (self::sameShopper($orderId, self::ids($later)) !== []) {
            return false;
        }

        // 2. Already e-mailed this week, about any order of theirs.
        $mailed = DB::table('integration_outbox')->where('aggregate_type', 'orders')
            ->where('event', OrderMailer::EVENT_EXPIRED)->where('created_at', '>=', now()->subDays(self::DAYS))
            ->where('aggregate_id', '!=', $orderId)->distinct()->pluck('aggregate_id')->all();

        return self::sameShopper($orderId, self::ids($mailed)) === [];
    }

    /**
     * True when the same shopper placed an order AFTER this one that went through — not cancelled,
     * and not a card order still waiting at Paymob. The link is refused then: rebuilding a cart for
     * someone who already bought is how the same thing gets ordered twice (J5b, 2026-10-05).
     */
    public static function reorderedSince(int $orderId): bool
    {
        $later = DB::table('orders')->where('id', '>', $orderId)->where('status', '!=', 'cancelled')
            ->where(fn (Builder $q) => $q->where('status', '!=', 'pending')->orWhere('payment_method', '!=', 'paymob'))
            ->pluck('id')->all();

        return self::sameShopper($orderId, self::ids($later)) !== [];
    }

    /**
     * Which of `$candidates` belong to the same shopper as `$orderId`: the same account, the same
     * guest token, the same e-mail (case-insensitive) or the same phone (last ten digits — `01…` and
     * `+201…` are one number). Misses only a shopper who types a different e-mail AND a different
     * phone; may join two people who share a phone or an address, which only ever means fewer e-mails.
     *
     * @param  list<int>  $candidates
     * @return list<int>
     */
    private static function sameShopper(int $orderId, array $candidates): array
    {
        $order = DB::table('orders')->where('id', $orderId)->first(['user_id', 'guest_token']);
        if (! is_object($order) || $candidates === []) {
            return [];
        }
        $customer = OrderCustomer::of($orderId);
        $email = $customer?->email !== null ? mb_strtolower(trim($customer->email)) : null;
        $phones = array_values(array_filter([self::phoneKey($customer?->phone), self::phoneKey($customer?->phoneAlt)]));
        $userId = Row::nint($order, 'user_id');
        $token = Row::nstr($order, 'guest_token');

        $rows = [];
        foreach (DB::table('orders')->whereIn('id', $candidates)->get(['id', 'user_id', 'guest_token']) as $row) {
            $rows[Row::int($row, 'id')] = $row;
        }
        $same = [];
        foreach (OrderCustomer::forOrders($candidates) as $otherId => $other) {
            $row = $rows[$otherId] ?? null;
            if ($row === null || $otherId === $orderId) {
                continue;
            }
            $theirPhones = array_filter([self::phoneKey($other->phone), self::phoneKey($other->phoneAlt)]);
            if (($userId !== null && Row::nint($row, 'user_id') === $userId)
                || ($token !== null && $token !== '' && Row::nstr($row, 'guest_token') === $token)
                || ($email !== null && $other->email !== null && mb_strtolower(trim($other->email)) === $email)
                || array_intersect($theirPhones, $phones) !== []) {
                $same[] = $otherId;
            }
        }

        return $same;
    }

    /**
     * @param  array<mixed>  $values
     * @return list<int>
     */
    private static function ids(array $values): array
    {
        $out = [];
        foreach ($values as $v) {
            $out[] = (int) (is_numeric($v) ? $v : 0);
        }

        return $out;
    }

    /**
     * Everything the recovery page shows — and the whole of what a link exposes (see the class note).
     * Null when the order is not a cancelled card order of this storefront any more.
     *
     * @return array{order_number: string, lines: list<array<string, mixed>>, details: array{name: string, email: string, phone: string, address_line: string, shipping_city_id: ?int}}|null
     */
    public static function payload(int $orderId, CompatCart $cart, int $storefrontId): ?array
    {
        $order = DB::table('orders')->where('id', $orderId)
            ->where('storefront_id', $storefrontId)->where('status', 'cancelled')->where('payment_method', 'paymob')
            ->first(['order_number', 'address_id']);
        if (! is_object($order)) {
            return null;
        }

        // Promotion gifts are not lines the customer chose: checkout grants them again if they still apply.
        $lines = DB::table('order_items')->where('order_id', $orderId)->where('is_reward', 0)->orderBy('id')
            ->get(['product_id', 'offer_id', 'quantity', 'piece_price', 'type_stock', 'color_band', 'color_dial'])->all();
        $productIds = array_values(array_filter(array_map(fn (object $l): ?int => Row::nint($l, 'product_id'), $lines)));
        $now = $cart->catalog($productIds, [])['products'];
        $visible = array_flip(array_map(fn (mixed $v): int => (int) (is_numeric($v) ? $v : 0), $productIds === [] ? [] : DB::table('storefront_product')
            ->where('storefront_id', $storefrontId)->where('is_visible', 1)->whereIn('product_id', $productIds)->pluck('product_id')->all()));
        $names = self::names($productIds);

        $out = [];
        foreach ($lines as $line) {
            $productId = Row::nint($line, 'product_id');
            $qty = Row::int($line, 'quantity');
            $quoted = (float) Row::money($line, 'piece_price');
            $entry = [
                'product_id' => $productId,
                'name' => $productId === null ? null : ($names[$productId] ?? null),
                'quantity' => $qty,
                'quoted_price' => $quoted,
                'color_band' => Row::nstr($line, 'color_band'),
                'color_dial' => Row::nstr($line, 'color_dial'),
            ];
            $p = $productId === null ? null : ($now[$productId] ?? null);
            // An offer line (offers are retired), a product gone, hidden here, or sold through
            // variants the compat cart cannot choose: nothing to put back.
            if ($p === null || ! isset($visible[(int) $productId]) || $p['has_variants']) {
                $out[] = $entry + ['state' => [self::GONE], 'price' => null, 'type_stock' => null, 'available' => 0];

                continue;
            }
            $type = $p['express'] > 0 ? 'Express' : 'Market';
            $available = $type === 'Express' ? $p['express'] : $p['market'];
            $price = CompatCart::catalogPrice($p['selling'], $p['sale']);
            $state = [];
            if ($available <= 0) {
                $state[] = self::SOLD_OUT;
            } else {
                if ($available < $qty) {
                    $state[] = self::REDUCED;
                }
                if (abs($price - $quoted) >= 0.01) {
                    $state[] = self::PRICE_CHANGED;
                }
                if (Row::nstr($line, 'type_stock') === 'Express' && $type === 'Market') {
                    $state[] = self::SLOWER;
                }
            }
            $out[] = $entry + [
                'state' => $state === [] ? [self::OK] : $state,
                'price' => $price,
                'type_stock' => $type,
                'available' => max(0, $available),
            ];
        }

        $customer = OrderCustomer::of($orderId);
        $address = Row::nint($order, 'address_id') === null ? null
            : DB::table('addresses')->where('id', Row::nint($order, 'address_id'))->first(['address_line', 'shipping_city_id']);

        return [
            'order_number' => Row::str($order, 'order_number'),
            'lines' => $out,
            'details' => [
                'name' => $customer->name ?? '',
                'email' => $customer->email ?? '',
                'phone' => $customer->phone ?? '',
                'address_line' => is_object($address) ? (Row::nstr($address, 'address_line') ?? '') : '',
                'shipping_city_id' => is_object($address) ? Row::nint($address, 'shipping_city_id') : null,
            ],
        ];
    }

    private static function mac(int $orderId, int $expires): string
    {
        $key = hash_hmac('sha256', 'order-recovery', config()->string('app.key'), true);

        return substr(hash_hmac('sha256', "recover|{$orderId}|{$expires}", $key), 0, 32);
    }

    /** A phone number as its last ten digits — "01551096234" and "+201551096234" are one number. */
    private static function phoneKey(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        return strlen($digits) >= 8 ? substr($digits, -10) : null;
    }

    /**
     * Each product's name in both languages; the page shows the shopper's.
     *
     * @param  list<int>  $ids
     * @return array<int, array{en: ?string, ar: ?string}>
     */
    private static function names(array $ids): array
    {
        $out = [];
        if ($ids === []) {
            return $out;
        }
        foreach (DB::table('catalog_product_translations')->whereIn('product_id', $ids)->whereIn('locale', ['en', 'ar'])->get(['product_id', 'locale', 'title']) as $row) {
            $id = Row::int($row, 'product_id');
            $out[$id] ??= ['en' => null, 'ar' => null];
            $out[$id][Row::str($row, 'locale') === 'ar' ? 'ar' : 'en'] = Row::nstr($row, 'title');
        }

        return $out;
    }
}
