<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Promotions\PersonalCodes;
use App\Domain\Promotions\PromotionRules;
use App\Storefront\StorefrontUrls;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * THE ABANDONED-CART E-MAIL (Michael, 2026-11-24; plan item 1, decisions D1, D3-D5).
 *
 * ── Who ─────────────────────────────────────────────────────────────────────────────────────
 * Every cart with products whose owner we can e-mail: a signed-in customer (the account's address) or a guest who typed
 * an address at the checkout (`core_checkout_contacts`). No opt-in step (D1); every e-mail carries a one-click
 * unsubscribe, and an unsubscribed address is never e-mailed again on that shop.
 *
 * ── When ────────────────────────────────────────────────────────────────────────────────────
 * Once the cart has been idle for the delay set on the dashboard (48 hours by default) and for at most 7 days more - so
 * switching the feature on never e-mails carts left weeks ago. `cart-reminders run`, hourly.
 *
 * ── Never ───────────────────────────────────────────────────────────────────────────────────
 *  - when the customer ordered on that shop since the cart's last activity (by account or by e-mail);
 *  - when the cart is empty now;
 *  - twice for one cart (`core_cart_reminders.cart_id` is unique);
 *  - more than once per address in 30 days, on either shop;
 *  - to an unsubscribed address;
 *  - while the shop's switch is off (it is off until the team switches it on).
 * Everything is checked again at SEND time (the outbox may hold a row for minutes): a cart emptied, an order placed or an
 * unsubscribe in between means the e-mail is not sent, and the row says why.
 *
 * ── The code ────────────────────────────────────────────────────────────────────────────────
 * One single-use code per e-mail, valid only for that address, for the days set (PersonalCodes) - issued at send time.
 * One offer per order: a cart holding an item on a live campaign rejects it, and the e-mail says so. Where the shop's money
 * rewards are off no code can work, so the e-mail goes without one.
 */
final class CartReminders
{
    public const KIND = 'cart_reminder';

    public const KIND_TEST = 'cart_reminder_test';

    /** How long after the delay a cart is still reminded: never a cart left weeks ago. */
    public const WINDOW_DAYS = 7;

    /** At most one reminder per address in this many days, on either shop. */
    public const PER_ADDRESS_DAYS = 30;

    /** The cart button's link lives this long (D4). */
    public const RESTORE_DAYS = 14;

    /** @return array{enabled: bool, delay_hours: int, with_code: bool, percent: float, cap: float|null, valid_days: int} */
    public static function settings(int $storefrontId): array
    {
        $raw = DB::table('core_cart_reminder_settings')->where('storefront_id', $storefrontId)->first();
        $row = $raw === null ? null : Row::cast($raw);

        return [
            'enabled' => $row !== null && Row::bool($row, 'enabled'),
            'delay_hours' => $row === null ? 48 : max(1, Row::int($row, 'delay_hours')),
            'with_code' => $row === null || Row::bool($row, 'with_code'),
            'percent' => $row === null ? 10.0 : (float) Row::str($row, 'percent'),
            'cap' => $row === null ? 500.0 : Row::nfloat($row, 'cap'),
            'valid_days' => $row === null ? 7 : max(1, Row::int($row, 'valid_days')),
        ];
    }

    /** @param  array{enabled: bool, delay_hours: int, with_code: bool, percent: float, cap: float|null, valid_days: int}  $s */
    public static function saveSettings(int $storefrontId, array $s, ?int $userId): void
    {
        DB::table('core_cart_reminder_settings')->updateOrInsert(['storefront_id' => $storefrontId], [
            'enabled' => $s['enabled'], 'delay_hours' => $s['delay_hours'], 'with_code' => $s['with_code'],
            'percent' => number_format($s['percent'], 2, '.', ''), 'cap' => $s['cap'] === null ? null : number_format($s['cap'], 2, '.', ''),
            'valid_days' => $s['valid_days'], 'updated_by' => $userId, 'updated_at' => now(),
        ]);
    }

    /**
     * The carts due now on a shop, with the address each would go to. Read only.
     *
     * @return list<array{cart_id: int, email: string, user_id: int|null, last_activity: string}>
     */
    public static function due(int $storefrontId, ?Carbon $now = null): array
    {
        $now ??= now();
        $s = self::settings($storefrontId);
        $latest = $now->copy()->subHours($s['delay_hours']);
        $earliest = $latest->copy()->subDays(self::WINDOW_DAYS);
        $carts = DB::table('carts as c')
            ->join('cart_items as i', 'i.cart_id', '=', 'c.id')
            ->where('c.storefront_id', $storefrontId)
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('core_cart_reminders as r')->whereColumn('r.cart_id', 'c.id'))
            ->groupBy('c.id', 'c.user_id', 'c.updated_at')
            ->selectRaw('c.id, c.user_id, GREATEST(COALESCE(c.updated_at, \'1970-01-01\'), MAX(COALESCE(i.updated_at, i.created_at, \'1970-01-01\'))) AS last_activity')
            ->havingRaw('last_activity <= ? AND last_activity >= ?', [$latest->toDateTimeString(), $earliest->toDateTimeString()])
            ->orderBy('c.id')->get();
        $out = [];
        foreach ($carts as $raw) {
            $c = Row::cast($raw);
            $cartId = Row::int($c, 'id');
            $userId = Row::nint($c, 'user_id');
            $email = $userId !== null
                ? Coerce::nstr(DB::table('users')->where('id', $userId)->value('email'))
                : Coerce::nstr(DB::table('core_checkout_contacts')->where('cart_id', $cartId)->value('email'));
            $email = $email === null ? null : mb_strtolower(trim($email));
            if ($email === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            $out[] = ['cart_id' => $cartId, 'email' => $email, 'user_id' => $userId, 'last_activity' => Row::str($c, 'last_activity')];
        }

        return $out;
    }

    /** Why this cart is not e-mailed now, or null. The same checks at planning and at send time. */
    public static function skipReason(int $storefrontId, int $cartId, string $email, ?int $userId, string $lastActivity, ?int $exceptReminder = null): ?string
    {
        if (DB::table('cart_items')->where('cart_id', $cartId)->doesntExist()) {
            return 'cart_empty';
        }
        if (ReEngagement::optedOut($storefrontId, $email)) {
            return 'unsubscribed';
        }
        $ordered = DB::table('orders')->where('storefront_id', $storefrontId)->where('created_at', '>=', $lastActivity)
            ->where(function (Builder $q) use ($email, $userId): void {
                $q->whereRaw('LOWER(guest_email) = ?', [$email]);
                if ($userId !== null) {
                    $q->orWhere('user_id', $userId);
                }
            })->exists();
        if ($ordered) {
            return 'ordered_since';
        }
        $recent = DB::table('core_cart_reminders')->where('email', $email)->where('status', '<>', 'skipped')
            ->where('created_at', '>=', now()->subDays(self::PER_ADDRESS_DAYS))
            ->when($exceptReminder !== null, fn (Builder $q) => $q->where('id', '<>', $exceptReminder))->exists();
        if ($recent) {
            return 'reminded_in_30_days';
        }

        return null;
    }

    /**
     * The hourly run: every shop whose switch is on; each due cart gets ONE row (queued, or skipped with the reason) and,
     * when queued, its e-mail in the bulk outbox. Returns the counts.
     *
     * @return array{queued: int, skipped: int}
     */
    public static function run(BulkMailer $mailer, ?Carbon $now = null): array
    {
        $out = ['queued' => 0, 'skipped' => 0];
        foreach (DB::table('storefronts')->where('is_active', 1)->pluck('id') as $rawId) {
            $sf = Coerce::int($rawId);
            if (! self::settings($sf)['enabled']) {
                continue;
            }
            foreach (self::due($sf, $now) as $cart) {
                $why = self::skipReason($sf, $cart['cart_id'], $cart['email'], $cart['user_id'], $cart['last_activity']);
                try {
                    $id = (int) DB::table('core_cart_reminders')->insertGetId([
                        'storefront_id' => $sf, 'cart_id' => $cart['cart_id'], 'email' => $cart['email'], 'user_id' => $cart['user_id'],
                        'status' => $why === null ? 'queued' : 'skipped', 'skip_reason' => $why, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                } catch (QueryException $e) {
                    if (in_array($e->errorInfo[1] ?? null, [1062, 19], true)) {
                        continue;                                   // another run took this cart a moment ago
                    }
                    throw $e;
                }
                if ($why !== null) {
                    $out['skipped']++;

                    continue;
                }
                $mailer->enqueue(self::KIND, $cart['email'], ['reminder_id' => $id, 'storefront_id' => $sf, 'last_activity' => $cart['last_activity']], 'cart_reminder:'.$id);
                $out['queued']++;
            }
        }

        return $out;
    }

    /**
     * What the e-mail shows, built at SEND time - null (and the row marked skipped with the reason) when it must not go.
     *
     * @return array{storefront_id: int, email: string, products: list<array<string, mixed>>, code: array{code: string, percent: float, cap: float|null, expires_at: string}|null, cart_url: string, unsubscribe_url: string}|null
     */
    public static function mailData(int $reminderId, string $lastActivity): ?array
    {
        $raw = DB::table('core_cart_reminders')->where('id', $reminderId)->first();
        if ($raw === null) {
            return null;
        }
        $r = Row::cast($raw);
        $sf = Row::int($r, 'storefront_id');
        $cartId = Row::int($r, 'cart_id');
        $email = Row::str($r, 'email');
        $userId = Row::nint($r, 'user_id');
        $why = ! self::settings($sf)['enabled'] ? 'switched_off' : self::skipReason($sf, $cartId, $email, $userId, $lastActivity, $reminderId);
        $ids = array_values(array_map(fn ($v): int => Coerce::int($v), DB::table('cart_items')->where('cart_id', $cartId)->orderBy('id')->pluck('product_id')->unique()->all()));
        $products = EmailProducts::cards($sf, $ids);
        if ($why === null && $products === []) {
            $why = 'nothing_on_sale';
        }
        if ($why !== null) {
            DB::table('core_cart_reminders')->where('id', $reminderId)->update(['status' => 'skipped', 'skip_reason' => $why, 'updated_at' => now()]);

            return null;
        }
        $qty = [];
        foreach (DB::table('cart_items')->where('cart_id', $cartId)->get(['product_id', 'quantity']) as $item) {
            $i = Row::cast($item);
            $qty[Row::int($i, 'product_id')] = ($qty[Row::int($i, 'product_id')] ?? 0) + max(1, Row::int($i, 'quantity'));
        }
        $products = array_map(fn (array $p): array => $p + ['quantity' => $qty[$p['id']] ?? 1], $products);
        $s = self::settings($sf);
        $code = null;
        if ($s['with_code'] && PromotionRules::moneyRewardsEnabled($sf)) {
            $existing = Row::nint($r, 'code_id');
            $issued = $existing === null ? PersonalCodes::issue($sf, $email, 'cart_reminder', $reminderId, $s['percent'], $s['cap'], $s['valid_days']) : null;
            $codeRow = Row::cast(DB::table('core_personal_codes')->where('id', $existing ?? ($issued['id'] ?? 0))->first() ?? new \stdClass);
            if ($issued !== null) {
                DB::table('core_cart_reminders')->where('id', $reminderId)->update(['code_id' => $issued['id'], 'updated_at' => now()]);
            }
            $code = ['code' => Row::str($codeRow, 'code'), 'percent' => $s['percent'], 'cap' => $s['cap'], 'expires_at' => Row::str($codeRow, 'expires_at')];
        }
        $cart = Row::cast(DB::table('carts')->where('id', $cartId)->first(['user_id', 'guest_token']) ?? new \stdClass);
        $host = StorefrontUrls::frontend($sf);
        $cartUrl = Row::nint($cart, 'user_id') !== null || Row::nstr($cart, 'guest_token') === null
            ? $host.'/cart'
            : $host.'/cart?restore='.$reminderId.'&s='.self::restoreSignature($reminderId);

        return [
            'storefront_id' => $sf, 'email' => $email, 'products' => $products, 'code' => $code, 'cart_url' => $cartUrl,
            'unsubscribe_url' => MarketingLinks::unsubscribeUrl($sf, $email, self::KIND),
        ];
    }

    /** The row is sent (the outbox row went out). */
    public static function markSent(int $reminderId): void
    {
        DB::table('core_cart_reminders')->where('id', $reminderId)->update(['status' => 'sent', 'sent_at' => now(), 'updated_at' => now()]);
    }

    public static function restoreSignature(int $reminderId): string
    {
        return substr(hash_hmac('sha256', 'cart-restore:'.$reminderId, config()->string('app.key')), 0, 40);
    }

    /**
     * The guest cart's token for the e-mail's button (D4), when the link is the reminder's own, younger than 14 days, on
     * this shop, and the cart is still a guest cart. Null otherwise.
     */
    public static function restoreToken(int $storefrontId, int $reminderId, string $signature): ?string
    {
        if (! hash_equals(self::restoreSignature($reminderId), $signature)) {
            return null;
        }
        $raw = DB::table('core_cart_reminders as r')->join('carts as c', 'c.id', '=', 'r.cart_id')
            ->where('r.id', $reminderId)->where('r.storefront_id', $storefrontId)->whereNull('c.user_id')
            ->where('r.created_at', '>=', now()->subDays(self::RESTORE_DAYS))->first(['c.guest_token']);

        return $raw === null ? null : Row::nstr(Row::cast($raw), 'guest_token');
    }

    /**
     * A sample for the team: the shop's newest products as if in a cart, a sample code (not issued), sent to the signed-in
     * staff address. Nothing about a customer.
     *
     * @return array{storefront_id: int, email: string, products: list<array<string, mixed>>, code: array{code: string, percent: float, cap: float|null, expires_at: string}|null, cart_url: string, unsubscribe_url: string}
     */
    public static function sample(int $storefrontId, string $staffEmail): array
    {
        $ids = array_values(array_map(fn ($v): int => Coerce::int($v), DB::table('storefront_product as sp')->join('catalog_products as p', 'p.id', '=', 'sp.product_id')
            ->where('sp.storefront_id', $storefrontId)->where('sp.is_visible', 1)->where('p.is_active', 1)->where('p.in_stock', 1)->whereNull('p.deleted_at')
            ->orderByDesc('p.id')->limit(2)->pluck('p.id')->all()));
        $s = self::settings($storefrontId);
        $products = array_map(fn (array $p): array => $p + ['quantity' => 1], EmailProducts::cards($storefrontId, $ids));

        return [
            'storefront_id' => $storefrontId, 'email' => $staffEmail, 'products' => $products,
            'code' => $s['with_code'] ? ['code' => 'CART-SAMP-LE00', 'percent' => $s['percent'], 'cap' => $s['cap'], 'expires_at' => now()->addDays($s['valid_days'])->endOfDay()->toDateTimeString()] : null,
            'cart_url' => StorefrontUrls::frontend($storefrontId).'/cart',
            'unsubscribe_url' => StorefrontUrls::frontend($storefrontId),
        ];
    }

    /**
     * The dashboard's numbers for a shop: reminders sent, codes used, skipped by reason, unsubscribed.
     *
     * @return array{sent: int, used: int, skipped: array<string, int>, unsubscribed: int}
     */
    public static function totals(int $storefrontId): array
    {
        $skipped = [];
        foreach (DB::table('core_cart_reminders')->where('storefront_id', $storefrontId)->where('status', 'skipped')->groupBy('skip_reason')->selectRaw('skip_reason, COUNT(*) AS n')->get() as $raw) {
            $r = Row::cast($raw);
            $skipped[Row::str($r, 'skip_reason')] = Row::int($r, 'n');
        }

        return [
            'sent' => DB::table('core_cart_reminders')->where('storefront_id', $storefrontId)->where('status', 'sent')->count(),
            'used' => DB::table('core_personal_codes')->where('storefront_id', $storefrontId)->where('purpose', 'cart_reminder')->whereNotNull('used_order_id')->count(),
            'skipped' => $skipped,
            'unsubscribed' => DB::table('core_marketing_optouts')->where('storefront_id', $storefrontId)->count(),
        ];
    }
}
