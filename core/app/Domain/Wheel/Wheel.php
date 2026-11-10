<?php

declare(strict_types=1);

namespace App\Domain\Wheel;

use App\Domain\Activity\ActivityLog;
use App\Domain\Promotions\CartSnapshot;
use App\Domain\Promotions\CouponStatus;
use App\Domain\Promotions\PromotionMoney;
use App\Domain\Promotions\PromotionScopes;
use App\Domain\Promotions\PromotionWriter;
use App\Support\Coerce;
use App\Transform\Row;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * "SPIN THE WHEEL" (Watchizer, 2026-11-07; the team's prizes 2026-11-08; the client's prize list 2026-11-09).
 *
 * A shopper spins once per phone number and per device PER ROUND; the prize is decided HERE with `random_int()` over
 * the odds of the prizes that can be drawn today; the storefront only animates to the segment it is told. Not every
 * spin wins: "better luck" is a segment too.
 *
 * ── Not a parallel discount system ─────────────────────────────────────────────────────────────────────
 *
 * Every prize that gives something is an ordinary PROMOTION RULE made through the dashboard's own writer, one per
 * set of terms per storefront (`WHEEL-<SHOP>-<hash>`, `issuer = wheel`): a percentage (optionally on one category
 * and its children, off every eligible item or off the cheapest one — "10 % off the 2nd watch"), an amount, free
 * shipping, or a free product (a gift). The engine prices and records it as it prices every rule; the wheel adds the
 * gates a spin needs (the phone, the expiry, one order; for an invite: a friend's first order). A prize rule's own
 * code is never typeable: the engine reaches it only through a spin's code ({@see spinByCode()}).
 *
 * ── Invite a friend ────────────────────────────────────────────────────────────────────────────────────
 *
 * A referral prize gives the winner an INVITE code (`FRIEND-…`, seven days). A friend's FIRST order with it gets the
 * prize's percentage off; when that order is DELIVERED, the winner gets a code of their own (48 h, their phone) —
 * one per friend — and an e-mail if the shop knows one ({@see WheelReferrals}).
 *
 * ── The phone ──────────────────────────────────────────────────────────────────────────────────────────
 *
 * Normalised to the Egyptian mobile form `01xxxxxxxxx` (Arabic-Indic digits and a +20 prefix accepted); the key the
 * binding compares is the LAST TEN DIGITS — the same key `PromotionDiscounts::customerKeys()` writes as `p:…`, so
 * "0101…" and "+20 101…" are one number on both sides. It is personal data: the dashboard shows {@see self::mask()}
 * and nothing writes it to a log. The device id and the address are stored only as HMACs.
 */
final class Wheel
{
    public const TABLE = 'storefront_wheel_spins';

    public const ROUND_KEY = 'round';

    public const ROUND_STARTED_KEY = 'round_started_at';

    public const DEFAULT_ROUND = 1;

    /** `storefronts.settings.wheel` — the switch and the storefront-level terms. ABSENT MEANS OFF / the defaults. */
    public const SETTING_KEY = 'enabled';

    public const DEFAULT_TTL_HOURS = 48;

    /** A referral REWARD (the winner's own code once a friend's order is delivered) lives this long. */
    public const REWARD_TTL_HOURS = 48;

    /** `promotion_rules.issuer` of a prize rule. */
    public const ISSUER = 'wheel';

    public const CODE_PREFIX = 'SPIN';

    public const INVITE_PREFIX = 'FRIEND';

    /** Code letters: no 0/O, 1/I/L — a shopper reads it off a phone screen. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const STATE_ACTIVE = 'active';

    public const STATE_USED = 'used';

    public const STATE_EXPIRED = 'expired';

    /** "better luck": a spin without a prize. */
    public const STATE_NONE = 'none';

    public const KIND_CODE = 'code';

    public const KIND_INVITE = 'invite';

    // ── the switch and the terms ───────────────────────────────────────────────────────────────────────

    /**
     * The storefront's wheel settings — `enabled`, `ttl_hours`, `min_order`, the `round` and its start, and the round's
     * `campaign` key — from the row, with the defaults where a key is absent. One primary-key read, never cached (an
     * operator turning it OFF must bite at once).
     *
     * @return array{enabled: bool, ttl_hours: int, min_order: float|null, round: int, round_started_at: string|null, campaign: string}
     */
    public static function settings(int $storefrontId): array
    {
        $settings = DB::table('storefronts')->where('id', $storefrontId)->value('settings');
        $decoded = null;
        if (is_string($settings) && $settings !== '') {
            try {
                $decoded = json_decode($settings, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $decoded = null;
            }
        }

        return self::settingsFrom(is_array($decoded) ? $decoded : null);
    }

    /**
     * The same answer from a DECODED settings column (the resolved storefront row a v2 request already holds).
     *
     * @return array{enabled: bool, ttl_hours: int, min_order: float|null, round: int, round_started_at: string|null, campaign: string}
     */
    public static function settingsFrom(mixed $settings): array
    {
        $wheel = is_array($settings) ? ($settings['wheel'] ?? null) : null;
        $bag = is_array($wheel) ? $wheel : [];
        $ttl = Coerce::nint($bag['ttl_hours'] ?? null);
        $min = Coerce::nfloat($bag['min_order'] ?? null);
        $round = Coerce::nint($bag[self::ROUND_KEY] ?? null);
        $round = $round === null || $round < 1 ? self::DEFAULT_ROUND : $round;

        return [
            'enabled' => ($bag[self::SETTING_KEY] ?? null) === true,
            'ttl_hours' => $ttl === null || $ttl < 1 ? self::DEFAULT_TTL_HOURS : $ttl,
            'min_order' => $min === null || $min <= 0 ? null : $min,
            'round' => $round,
            'round_started_at' => Coerce::nstr($bag[self::ROUND_STARTED_KEY] ?? null),
            'campaign' => self::campaignKey($round),
        ];
    }

    /** The key a round's spins carry in `campaign`. */
    public static function campaignKey(int $round): string
    {
        return 'round-'.$round;
    }

    /** The current round's key on a storefront (one settings read). */
    public static function campaign(int $storefrontId): string
    {
        return self::settings($storefrontId)['campaign'];
    }

    /** The round number a `campaign` key names, for the dashboard's per-round table; null for a key of another shape. */
    public static function roundOf(string $campaign): ?int
    {
        return preg_match('/^round-(\d+)$/', $campaign, $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * START A NEW ROUND: everybody may spin once again; codes of earlier rounds keep their terms and expiry (they are on
     * their rows and their rules). Logged with the round before and after. Returns the new round number.
     */
    public static function startNewRound(int $storefrontId, ?string $storefrontName = null): int
    {
        $before = self::settings($storefrontId);
        $next = $before['round'] + 1;
        self::writeBag($storefrontId, [self::ROUND_KEY => $next, self::ROUND_STARTED_KEY => now()->toIso8601String()]);
        ActivityLog::record('storefronts', $storefrontId, ActivityLog::UPDATED,
            ['wheel_round' => $before['round'], 'wheel_round_started_at' => $before['round_started_at']],
            ['wheel_round' => $next, 'wheel_round_started_at' => self::settings($storefrontId)['round_started_at']],
            label: $storefrontName ?? '', storefrontId: $storefrontId);

        return $next;
    }

    public static function enabled(int $storefrontId): bool
    {
        return self::settings($storefrontId)['enabled'];
    }

    public static function enabledFromSettings(mixed $settings): bool
    {
        return self::settingsFrom($settings)['enabled'];
    }

    /** `settings` with `wheel.enabled` set and everything else left alone — the only writer of the switch. */
    public static function setEnabled(int $storefrontId, bool $enabled): void
    {
        self::writeBag($storefrontId, [self::SETTING_KEY => $enabled]);
    }

    /** `settings` with the validity and the minimum set — the writer's companion to {@see self::setEnabled()}. */
    public static function setTerms(int $storefrontId, int $ttlHours, ?float $minOrder): void
    {
        self::writeBag($storefrontId, ['ttl_hours' => $ttlHours, 'min_order' => $minOrder === null ? null : round($minOrder, 2)]);
    }

    /** @param  array<string, mixed>  $patch */
    private static function writeBag(int $storefrontId, array $patch): void
    {
        $current = DB::table('storefronts')->where('id', $storefrontId)->value('settings');
        $settings = [];
        if (is_string($current) && $current !== '') {
            $decoded = json_decode($current, true);
            if (is_array($decoded)) {
                foreach ($decoded as $k => $v) {
                    $settings[(string) $k] = $v;
                }
            }
        }
        $bag = Coerce::arr($settings['wheel'] ?? null);
        foreach ($patch as $k => $v) {
            $bag[$k] = $v;
        }
        $settings['wheel'] = $bag;
        DB::table('storefronts')->where('id', $storefrontId)->update(['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
    }

    // ── the phone ──────────────────────────────────────────────────────────────────────────────────────

    /** `01xxxxxxxxx`, or null when the text is not an Egyptian mobile number. */
    public static function normalizePhone(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $digits = strtr($raw, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']); // i18n-exempt: DATA — the Arabic-Indic digits a phone may be typed with
        $digits = preg_replace('/\D+/', '', $digits) ?? '';
        if (str_starts_with($digits, '0020')) {
            $digits = '0'.substr($digits, 4);
        } elseif (str_starts_with($digits, '20') && strlen($digits) === 12) {
            $digits = '0'.substr($digits, 2);
        }

        return preg_match('/^01\d{9}$/', $digits) === 1 ? $digits : null;
    }

    /** The last ten digits — the engine's `p:` customer key. */
    public static function phoneKey(string $phone): string
    {
        return substr(preg_replace('/\D+/', '', $phone) ?? '', -10);
    }

    /** `010•••••678` */
    public static function mask(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) < 7) {
            return str_repeat('•', max(0, strlen($digits)));
        }

        return substr($digits, 0, 3).'•••••'.substr($digits, -3);
    }

    /** An HMAC of a device id or an address, keyed by the application key — stored instead of the value. */
    public static function hashOf(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);
        if ($value === '') {
            return null;
        }

        return hash_hmac('sha256', $value, config()->string('app.key'));
    }

    // ── the prize rules ────────────────────────────────────────────────────────────────────────────────

    /**
     * The code of the prize rule for one set of terms: `WHEEL-WATCHIZER-7F3A9C2E`. Never typed by a shopper.
     *
     * @param  array<string, mixed>  $terms  {@see self::termsOf()}
     */
    public static function ruleCode(string $storefrontCode, array $terms): string
    {
        $money = fn (mixed $v): string => $v === null ? '' : number_format(Coerce::float($v), 2, '.', '');
        $hash = strtoupper(substr(sha1(implode('|', [
            Coerce::str($terms['type'] ?? ''), $money($terms['value'] ?? null), $money($terms['cap'] ?? null), $money($terms['min_order'] ?? null),
            Coerce::str($terms['label_en'] ?? ''), Coerce::str($terms['label_ar'] ?? ''),
            (string) (Coerce::nint($terms['scope_category_id'] ?? null) ?? ''), (string) (Coerce::nint($terms['min_quantity'] ?? null) ?? ''),
            Coerce::str($terms['apply_mode'] ?? WheelPrizes::MODE_ORDER), (string) (Coerce::nint($terms['gift_product_id'] ?? null) ?? ''),
        ])), 0, 8));

        return strtoupper(self::ISSUER.'-'.preg_replace('/[^a-z0-9]+/i', '', $storefrontCode).'-'.$hash);
    }

    /**
     * The terms a prize is won with — the whole identity of its rule and the snapshot on the spin. `type` here is the
     * RULE's kind: a referral prize's friend code is a `referral_friend` percentage; the winner's reward a
     * `referral_reward` percentage.
     *
     * @param  array<string, mixed>  $prize  a row of {@see WheelPrizes::forStorefront()}
     * @return array{type: string, value: float|null, cap: float|null, min_order: float|null, label_en: string, label_ar: string, scope_category_id: int|null, min_quantity: int|null, apply_mode: string, gift_product_id: int|null}
     */
    public static function termsOf(array $prize, ?float $minOrder, ?int $giftProductId = null): array
    {
        $type = Coerce::str($prize['type'] ?? '');

        return [
            'type' => $type,
            'value' => in_array($type, ['percent_discount', 'fixed_discount', 'referral'], true) ? Coerce::nfloat($prize['value'] ?? null) : null,
            'cap' => in_array($type, ['percent_discount', 'referral'], true) ? Coerce::nfloat($prize['cap'] ?? null) : null,
            'min_order' => $minOrder,
            'label_en' => Coerce::str($prize['label_en'] ?? ''),
            'label_ar' => Coerce::str($prize['label_ar'] ?? ''),
            'scope_category_id' => $type === 'percent_discount' ? Coerce::nint($prize['scope_category_id'] ?? null) : null,
            'min_quantity' => $type === 'percent_discount' ? Coerce::nint($prize['min_quantity'] ?? null) : null,
            'apply_mode' => $type === 'percent_discount' ? Coerce::str($prize['apply_mode'] ?? WheelPrizes::MODE_ORDER) : WheelPrizes::MODE_ORDER,
            'gift_product_id' => $type === 'gift' ? $giftProductId : null,
        ];
    }

    /**
     * The promotion rule for one set of terms on a storefront, created through the dashboard's writer when it does not
     * exist yet. The terms are the WHOLE identity: a prize edited on the dashboard gets a new rule for its next spins,
     * and every old code keeps the rule — the terms — it was won with. `none` has no rule (null).
     *
     * @param  array<string, mixed>  $terms  {@see self::termsOf()}
     *
     * @throws ValidationException when the writer refuses (money rewards off on the storefront)
     */
    public static function ruleFor(int $storefrontId, array $terms, string $ruleType): ?int
    {
        if ($ruleType === 'none') {
            return null;
        }
        $storefrontCode = Coerce::str(DB::table('storefronts')->where('id', $storefrontId)->value('code'));
        $code = self::ruleCode($storefrontCode, ['type' => $ruleType] + $terms);
        $existing = Coerce::nint(DB::table('promotion_rules')->where('code', $code)->value('id'));
        if ($existing !== null) {
            return $existing;
        }

        $value = Coerce::nfloat($terms['value'] ?? null);
        $cap = Coerce::nfloat($terms['cap'] ?? null);
        $category = Coerce::nint($terms['scope_category_id'] ?? null);
        $minQuantity = Coerce::nint($terms['min_quantity'] ?? null);
        $mode = Coerce::str($terms['apply_mode'] ?? WheelPrizes::MODE_ORDER);
        $reward = match ($ruleType) {
            'free_shipping' => ['type' => 'free_shipping'],
            'fixed_discount' => ['type' => 'fixed_discount', 'amount' => $value],
            'gift' => ['type' => 'free_product', 'product_id' => Coerce::nint($terms['gift_product_id'] ?? null), 'quantity' => 1],
            default => ['type' => 'percent_discount', 'amount' => $value],  // percent_discount, referral_friend, referral_reward
        };
        $scopes = [];
        if ($ruleType === 'percent_discount' && $category !== null) {
            // the discount on this category and its children; the minimum counted within it too
            $scopes[] = ['role' => PromotionScopes::ROLE_TARGET, 'dimension' => 'category', 'value_id' => $category];
            if ($minQuantity !== null) {
                $scopes[] = ['role' => PromotionScopes::ROLE_QUALIFY, 'dimension' => 'category', 'value_id' => $category];
            }
        }
        $labelEn = Coerce::str($terms['label_en'] ?? '');
        $labelAr = Coerce::str($terms['label_ar'] ?? '');
        $name = match ($ruleType) {
            'referral_friend' => 'Spin the wheel — friend: '.$labelEn,
            'referral_reward' => 'Spin the wheel — invite reward: '.$labelEn,
            default => 'Spin the wheel — '.$labelEn,
        };
        $id = app(PromotionWriter::class)->save([
            'name' => $name,
            'code' => $code,
            'label_en' => 'Spin the wheel: '.$labelEn,
            'label_ar' => 'عجلة الحظ: '.$labelAr, // i18n-exempt: DATA — the rule's own Arabic label, stored on the row and shown to shoppers
            'priority' => 0,
            'is_active' => true,
            'starts_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            // the rule outlives every code it backs; a spin's own hours are the real window (refusal())
            'ends_at' => '2099-12-31 23:59:59',
            'apply_per' => $ruleType === 'percent_discount' && $mode === WheelPrizes::MODE_CHEAPEST ? PromotionMoney::PER_CHEAPEST : PromotionMoney::PER_ORDER,
            'max_discount' => in_array($ruleType, ['percent_discount', 'referral_friend', 'referral_reward'], true) ? $cap : null,
            'min_order_amount' => Coerce::nfloat($terms['min_order'] ?? null),
            'qualify_min_quantity' => $ruleType === 'percent_discount' && $category !== null ? $minQuantity : null,
            'include_sale_items' => true,
            'stackable' => false,
            'unit_floor' => 0,
            'storefronts' => [$storefrontId],
            'conditions' => [],
            'rewards' => [$reward],
            'scopes' => $scopes,
        ], null, null);
        // the writer knows nothing of issuers (it is the dashboard's payload); the column is the wheel's mark
        DB::table('promotion_rules')->where('id', $id)->update(['issuer' => self::ISSUER]);

        return $id;
    }

    // ── spinning ───────────────────────────────────────────────────────────────────────────────────────

    /**
     * Spin for a phone. Returns `['status' => 'new'|'already_spun', 'spin' => <row>, 'index' => <segment|null>]`.
     *
     * The prize is drawn over the prizes that CAN be drawn today (a gift whose list has nothing in stock is left out and
     * its odds spread over the others), with `random_int()` over their odds; a test names the roll.
     *
     * @param  array<string, string|null>  $utm  source, medium, campaign, content
     * @return array{status: string, spin: stdClass, index: int|null}
     *
     * @throws WheelRefused wheel_off | invalid_phone | device_limit
     */
    public static function spin(int $storefrontId, ?string $rawPhone, ?string $deviceId, ?string $ip, array $utm = [], string $locale = 'ar', ?int $roll = null): array
    {
        $settings = self::settings($storefrontId);
        if (! $settings['enabled']) {
            throw new WheelRefused(WheelRefused::WHEEL_OFF);
        }
        $phone = self::normalizePhone($rawPhone);
        if ($phone === null) {
            throw new WheelRefused(WheelRefused::INVALID_PHONE);
        }
        $key = self::phoneKey($phone);

        $existing = self::spinByPhone($storefrontId, $phone, $settings['campaign']);
        if ($existing !== null) {
            return ['status' => 'already_spun', 'spin' => $existing, 'index' => null];
        }

        $deviceHash = self::hashOf($deviceId);
        if ($deviceHash !== null) {
            $other = DB::table(self::TABLE)->where('storefront_id', $storefrontId)->where('campaign', $settings['campaign'])
                ->where('device_hash', $deviceHash)->where('phone_key', '!=', $key)->exists();
            if ($other) {
                throw new WheelRefused(WheelRefused::DEVICE_LIMIT);
            }
        }

        $prizes = WheelPrizes::forStorefront($storefrontId);
        $drawable = WheelPrizes::drawable($prizes);
        if ($drawable === [] || WheelPrizes::oddsTotal($prizes) !== 100) {
            // a wheel without a valid set of prizes cannot pay out: refused as "off" to the shopper, loud to the operator
            throw new WheelRefused(WheelRefused::WHEEL_OFF);
        }
        $picked = WheelPrizes::pick($drawable, $roll);
        $prize = $picked['prize'];
        $type = Coerce::str($prize['type']);
        $giftProductId = null;
        if ($type === 'gift') {
            $available = array_values(array_map(fn (mixed $id): int => Coerce::int($id), Coerce::arr($prize['gift_available'] ?? [])));
            $giftProductId = $available[random_int(0, count($available) - 1)];
        }
        $terms = self::termsOf($prize, $settings['min_order'], $giftProductId);
        $ruleType = match ($type) {
            'referral' => 'referral_friend',
            default => $type,
        };
        $ruleId = self::ruleFor($storefrontId, $terms, $ruleType);
        $now = now();
        $index = WheelPrizes::indexOf($drawable, Coerce::nint($prize['id'] ?? null)); // the wheel the page shows is the drawable list

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = $type === 'none' || $type === 'referral' ? null : self::newCode(self::CODE_PREFIX);
            $invite = $type === 'referral' ? self::newCode(self::INVITE_PREFIX) : null;
            try {
                $id = (int) DB::table(self::TABLE)->insertGetId([
                    'storefront_id' => $storefrontId,
                    'campaign' => $settings['campaign'],
                    'phone' => $phone,
                    'phone_key' => $key,
                    'code' => $code,
                    'prize_id' => Coerce::int($prize['id']),
                    'prize_label_en' => $terms['label_en'],
                    'prize_label_ar' => $terms['label_ar'],
                    'prize_type' => $type,
                    'prize_value' => $terms['value'],
                    'prize_cap' => $terms['cap'],
                    'min_order' => $terms['min_order'],
                    'prize_scope_category_id' => $terms['scope_category_id'],
                    'prize_min_quantity' => $terms['min_quantity'],
                    'prize_apply_mode' => $terms['apply_mode'],
                    'gift_product_id' => $giftProductId,
                    'color' => Coerce::str($prize['color']),
                    'promotion_rule_id' => $ruleId,
                    'expires_at' => $now->copy()->addHours($type === 'referral' ? WheelPrizes::INVITE_DAYS * 24 : $settings['ttl_hours'])->format('Y-m-d H:i:s'),
                    'invite_code' => $invite,
                    'invite_expires_at' => $invite === null ? null : $now->copy()->addDays(WheelPrizes::INVITE_DAYS)->format('Y-m-d H:i:s'),
                    'device_hash' => $deviceHash,
                    'ip_hash' => self::hashOf($ip),
                    'utm_source' => self::utm($utm, 'source'),
                    'utm_medium' => self::utm($utm, 'medium'),
                    'utm_campaign' => self::utm($utm, 'campaign'),
                    'utm_content' => self::utm($utm, 'content'),
                    'locale' => $locale === 'en' ? 'en' : 'ar',
                    'created_at' => $now,
                ]);
            } catch (QueryException $e) {
                // the phone won the race (two taps): hand back the row the first tap made; a code collision retries
                $again = self::spinByPhone($storefrontId, $phone, $settings['campaign']);
                if ($again !== null) {
                    return ['status' => 'already_spun', 'spin' => $again, 'index' => null];
                }
                if ($attempt === 4) {
                    throw $e;
                }

                continue;
            }

            $row = DB::table(self::TABLE)->where('id', $id)->first();

            return ['status' => 'new', 'spin' => Row::cast($row instanceof stdClass ? $row : new stdClass), 'index' => $index];
        }

        throw new WheelRefused('no_code');
    }

    /** `SPIN-7KQ2-M9XD` / `FRIEND-7KQ2-M9XD` — two groups of four from an alphabet with no look-alikes. */
    public static function newCode(string $prefix = self::CODE_PREFIX): string
    {
        $part = static function (): string {
            $out = '';
            for ($i = 0; $i < 4; $i++) {
                $out .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }

            return $out;
        };

        return $prefix.'-'.$part().'-'.$part();
    }

    /** @param  array<string, string|null>  $utm */
    private static function utm(array $utm, string $key): ?string
    {
        $value = trim(Coerce::str($utm[$key] ?? ''));

        return $value === '' ? null : mb_substr($value, 0, 120);
    }

    // ── reading ────────────────────────────────────────────────────────────────────────────────────────

    /** The current round's spin of a phone on a storefront (or the named round's), or null. */
    public static function spinByPhone(int $storefrontId, string $rawPhone, ?string $campaign = null): ?stdClass
    {
        $phone = self::normalizePhone($rawPhone);
        if ($phone === null) {
            return null;
        }
        $row = DB::table(self::TABLE)->where('storefront_id', $storefrontId)->where('campaign', $campaign ?? self::campaign($storefrontId))
            ->where('phone_key', self::phoneKey($phone))->first();

        return $row instanceof stdClass ? Row::cast($row) : null;
    }

    /**
     * The spin a typed code names on a storefront, whatever its round — a `SPIN-` code by its code, a `FRIEND-` code by
     * its invite — or null when the code is not the wheel's. The row carries `wheel_kind` (`code` | `invite`) so the
     * engine knows which gates apply.
     */
    public static function spinByCode(int $storefrontId, ?string $code): ?stdClass
    {
        $code = strtoupper(trim(Coerce::str($code ?? '')));
        if ($code === '') {
            return null;
        }
        if (str_starts_with($code, self::CODE_PREFIX.'-')) {
            $row = DB::table(self::TABLE)->where('storefront_id', $storefrontId)->where('code', $code)->first();
            $kind = self::KIND_CODE;
        } elseif (str_starts_with($code, self::INVITE_PREFIX.'-')) {
            $row = DB::table(self::TABLE)->where('storefront_id', $storefrontId)->where('invite_code', $code)->first();
            $kind = self::KIND_INVITE;
        } else {
            return null;
        }
        if (! $row instanceof stdClass) {
            return null;
        }
        $spin = Row::cast($row);
        $spin->wheel_kind = $kind;

        return $spin;
    }

    public static function kindOf(stdClass $spin): string
    {
        return property_exists($spin, 'wheel_kind') && $spin->wheel_kind === self::KIND_INVITE ? self::KIND_INVITE : self::KIND_CODE;
    }

    /**
     * The order that USED a spin's code — a redemption on an order that is not cancelled — or null. A cancelled
     * order gives the code back, exactly as the engine's usage limits count (`PromotionEngine::redemptions()`).
     *
     * @return array{id: int, number: string}|null
     */
    public static function usedBy(stdClass $spin): ?array
    {
        $code = Row::nstr($spin, 'code');
        if ($code === null) {
            return null;
        }
        $row = DB::table('promotion_redemptions as pr')
            ->join('orders as o', 'o.id', '=', 'pr.order_id')
            ->where('pr.code', $code)
            ->where('o.status', '!=', 'cancelled')
            ->orderBy('pr.id')
            ->first(['o.id', 'o.order_number']);
        if (! $row instanceof stdClass) {
            return null;
        }
        $order = Row::cast($row);

        return ['id' => Row::int($order, 'id'), 'number' => Row::nstr($order, 'order_number') ?? ('#'.Row::int($order, 'id'))];
    }

    public static function isExpired(stdClass $spin, ?DateTimeInterface $at = null): bool
    {
        $now = $at ?? now();

        return Row::str($spin, 'expires_at') <= Carbon::instance($now)->setTimezone(config()->string('app.timezone'))->format('Y-m-d H:i:s');
    }

    /**
     * Why the engine must NOT apply a spin's code to this cart — null when it may.
     *
     * The phone gate passes when the cart knows no phone at all (the cart page's preview, before the checkout asks
     * for one): the code is shown as applied with the note that it is bound to a number, and the checkout — which
     * always knows the phone — is where a different number is refused. The minimum order, if the wheel had one when
     * the code was won, is on the code's own rule and judged by the engine's gate. An INVITE has gates of its own
     * ({@see WheelReferrals::refusal()}).
     */
    public static function refusal(stdClass $spin, CartSnapshot $cart, DateTimeInterface $now): ?string
    {
        if (self::kindOf($spin) === self::KIND_INVITE) {
            return WheelReferrals::refusal($spin, $cart, $now);
        }
        if (self::isExpired($spin, $now)) {
            return CouponStatus::EXPIRED;
        }
        if (self::usedBy($spin) !== null) {
            return CouponStatus::WHEEL_USED;
        }
        $phones = self::cartPhones($cart);
        if ($phones !== [] && ! in_array('p:'.Row::str($spin, 'phone_key'), $phones, true)) {
            return CouponStatus::PHONE_MISMATCH;
        }

        return null;
    }

    /** @return list<string> the cart's `p:` keys */
    public static function cartPhones(CartSnapshot $cart): array
    {
        return array_values(array_filter($cart->customerKeys, fn (string $k): bool => str_starts_with($k, 'p:')));
    }

    /**
     * The facts the coupon payload carries for a spin's code, so the cart and the checkout can say whose it is and what
     * it needs.
     *
     * @return array{kind: string, phone_hint: string, expires_at: string, order_phone_hint: string|null, needs_en: string|null, needs_ar: string|null}
     */
    public static function couponFacts(stdClass $spin, ?CartSnapshot $cart = null): array
    {
        $orderPhone = null;
        foreach ($cart === null ? [] : $cart->customerKeys as $key) {
            if (str_starts_with($key, 'p:')) {
                // the key is the number's last TEN digits (PromotionDiscounts::customerKeys); an Egyptian mobile is 0 + those
                $digits = substr($key, 2);
                $orderPhone = self::mask(strlen($digits) === 10 ? '0'.$digits : $digits);

                break;
            }
        }
        $kind = self::kindOf($spin);
        $needs = self::needs($spin);

        return [
            'kind' => $kind,
            'phone_hint' => self::mask(Row::str($spin, 'phone')),
            'expires_at' => Carbon::parse(Row::str($spin, $kind === self::KIND_INVITE ? 'invite_expires_at' : 'expires_at'))->toIso8601String(),
            'order_phone_hint' => $orderPhone,
            'needs_en' => $needs['en'],
            'needs_ar' => $needs['ar'],
        ];
    }

    /**
     * What a scoped code needs in the cart, in both languages — "two or more watches", "a wallet" — from the spin's own
     * snapshot; null for a code without a scope.
     *
     * @return array{en: string|null, ar: string|null}
     */
    public static function needs(stdClass $spin): array
    {
        $category = Row::nint($spin, 'prize_scope_category_id');
        if ($category === null || Row::str($spin, 'prize_type') !== 'percent_discount') {
            return ['en' => null, 'ar' => null];
        }
        $names = ['en' => null, 'ar' => null];
        foreach (DB::table('storefront_category_translations')->where('storefront_category_id', $category)->whereIn('locale', ['en', 'ar'])->get(['locale', 'name']) as $raw) {
            $row = Row::cast($raw);
            $names[Row::str($row, 'locale')] = Row::nstr($row, 'name');
        }
        $en = $names['en'] ?? 'this category';
        $ar = $names['ar'] ?? 'هذا التصنيف'; // i18n-exempt: STOREFRONT — the shopper's sentence, both languages returned side by side for the page to pick
        $min = Row::nint($spin, 'prize_min_quantity');
        if ($min !== null && $min > 1) {
            return [
                'en' => "This code needs {$min} or more items from {$en} in your cart.",
                'ar' => "هذا الكود يحتاج {$min} قطع أو أكثر من {$ar} في سلتك.", // i18n-exempt: STOREFRONT — the shopper's sentence, both languages returned side by side for the page to pick
            ];
        }

        return [
            'en' => "This code works on {$en} only — add one to your cart.",
            'ar' => "هذا الكود يعمل على {$ar} فقط — أضف قطعة إلى سلتك.", // i18n-exempt: STOREFRONT — the shopper's sentence, both languages returned side by side for the page to pick
        ];
    }

    /** Mark a spin's code used by an order (called where the redemption is written; a no-op for any other code). */
    public static function markUsed(?string $code, int $orderId): void
    {
        $code = strtoupper(trim(Coerce::str($code ?? '')));
        if ($code === '') {
            return;
        }
        if (str_starts_with($code, self::CODE_PREFIX.'-')) {
            DB::table(self::TABLE)->where('code', $code)->whereNull('used_order_id')
                ->update(['used_order_id' => $orderId, 'used_at' => now()]);

            return;
        }
        if (str_starts_with($code, self::INVITE_PREFIX.'-')) {
            WheelReferrals::recordPlaced($code, $orderId);
        }
    }

    /**
     * What the page and the dashboard show of a spin: the code, the prize's terms as won, the phone masked, the state
     * (active / used / expired / none), the time it expires, the order that used it; for an invite, the invite and
     * what it has earned; for a gift, the product.
     *
     * @return array<string, mixed>
     */
    public static function present(stdClass $spin, ?DateTimeInterface $at = null, ?int $index = null): array
    {
        $type = Row::str($spin, 'prize_type');
        $used = self::usedBy($spin);
        $state = match (true) {
            $type === 'none' => self::STATE_NONE,
            $used !== null => self::STATE_USED,
            self::isExpired($spin, $at) => self::STATE_EXPIRED,
            default => self::STATE_ACTIVE,
        };
        $out = [
            'code' => Row::nstr($spin, 'code'),
            'campaign' => Row::str($spin, 'campaign'),
            'prize' => [
                'id' => Row::nint($spin, 'prize_id'),
                'type' => $type,
                'value' => Row::nfloat($spin, 'prize_value'),
                'cap' => Row::nfloat($spin, 'prize_cap'),
                'min_order' => Row::nfloat($spin, 'min_order'),
                'min_quantity' => Row::nint($spin, 'prize_min_quantity'),
                'apply_mode' => Row::nstr($spin, 'prize_apply_mode') ?? WheelPrizes::MODE_ORDER,
                'needs' => self::needs($spin),
                'color' => Row::str($spin, 'color'),
                'label' => ['en' => Row::str($spin, 'prize_label_en'), 'ar' => Row::str($spin, 'prize_label_ar')],
                // the segment to turn to — known for a fresh spin; an old card needs none
                'index' => $index,
            ],
            'phone_hint' => self::mask(Row::str($spin, 'phone')),
            'created_at' => Carbon::parse(Row::str($spin, 'created_at'))->toIso8601String(),
            'expires_at' => Carbon::parse(Row::str($spin, 'expires_at'))->toIso8601String(),
            'state' => $state,
            'used_order_number' => $used['number'] ?? null,
            // on an account (saved from the wheel's page, or attached at sign-in by the phone) — 2026-11-10
            'saved' => Row::nint($spin, 'user_id') !== null,
        ];
        if ($type === 'gift') {
            $out['gift'] = self::giftOf(Row::nint($spin, 'gift_product_id'));
        }
        if ($type === 'referral') {
            $out['invite'] = WheelReferrals::summary($spin, $at);
        }

        return $out;
    }

    /**
     * A signed-in customer SAVES this round's spin of the phone they spun with to their account (Michael, 2026-11-09
     * evening for the invite; every prize since 2026-11-10): the spin is linked to the account — a code, a gift or a
     * "better luck" so the account page lists it, an invite so it is ACTIVATED (the code shown and working from then on,
     * the reward mail to the account's e-mail). An account saves once; a spin another account saved is refused.
     *
     * @return array{status: string, spin: stdClass|null} status: saved | already | taken | none
     */
    public static function claim(int $storefrontId, string $rawPhone, int $userId): array
    {
        $spin = self::spinByPhone($storefrontId, $rawPhone);
        if ($spin === null) {
            return ['status' => 'none', 'spin' => null];
        }
        $owner = Row::nint($spin, 'user_id');
        if ($owner !== null && $owner !== $userId) {
            return ['status' => 'taken', 'spin' => $spin];
        }
        if ($owner === $userId) {
            return ['status' => 'already', 'spin' => $spin];
        }
        self::attachSpin(Row::int($spin, 'id'), Row::str($spin, 'prize_type'), $userId);
        $fresh = DB::table(self::TABLE)->where('id', Row::int($spin, 'id'))->first();

        return ['status' => 'saved', 'spin' => $fresh instanceof stdClass ? Row::cast($fresh) : $spin];
    }

    /**
     * At SIGN-IN (login, register, a social callback): every spin of the account's own phone number that no account
     * holds yet becomes this account's — on every storefront, since users are shared — so a code won before signing in
     * is on the account page afterwards (Michael, 2026-11-10). An invite among them is activated by it. Returns how many
     * spins were attached; never throws (a sign-in must not fail on this).
     */
    public static function attachAccount(int $userId, ?string $rawPhone): int
    {
        $phone = self::normalizePhone($rawPhone);
        if ($phone === null) {
            return 0;
        }
        $count = 0;
        try {
            foreach (DB::table(self::TABLE)->where('phone_key', self::phoneKey($phone))->whereNull('user_id')->get(['id', 'prize_type']) as $raw) {
                $row = Row::cast($raw);
                self::attachSpin(Row::int($row, 'id'), Row::str($row, 'prize_type'), $userId);
                $count++;
            }
        } catch (\Throwable) {
            return $count;
        }

        return $count;
    }

    /** Links one spin to an account; an invite is activated by it. */
    private static function attachSpin(int $spinId, string $prizeType, int $userId): void
    {
        $values = ['user_id' => $userId];
        if ($prizeType === 'referral') {
            $values['invite_activated_at'] = now();
        }
        DB::table(self::TABLE)->where('id', $spinId)->whereNull('user_id')->update($values);
    }

    /**
     * The spins an account activated on a storefront (its invites, each with its rewards), newest first — the block on
     * the account page.
     *
     * @return list<stdClass>
     */
    public static function spinsOfUser(int $storefrontId, int $userId): array
    {
        $out = [];
        foreach (DB::table(self::TABLE)->where('storefront_id', $storefrontId)->where('user_id', $userId)->orderByDesc('id')->limit(20)->get() as $raw) {
            $out[] = Row::cast($raw);
        }

        return $out;
    }

    /**
     * The gift product as the card names it.
     *
     * @return array{id: int, title: array{en: string|null, ar: string|null}, in_stock: bool}|null
     */
    public static function giftOf(?int $productId): ?array
    {
        if ($productId === null) {
            return null;
        }
        $en = null;
        $ar = null;
        foreach (DB::table('catalog_product_translations')->where('product_id', $productId)->whereIn('locale', ['en', 'ar'])->get(['locale', 'title']) as $raw) {
            $row = Row::cast($raw);
            if (Row::str($row, 'locale') === 'en') {
                $en = Row::nstr($row, 'title');
            } else {
                $ar = Row::nstr($row, 'title');
            }
        }
        $stock = DB::table('catalog_products')->where('id', $productId)->first(['stock_express', 'stock_market']);
        $inStock = $stock instanceof stdClass && (Row::int(Row::cast($stock), 'stock_express') > 0 || Row::int(Row::cast($stock), 'stock_market') > 0);

        return ['id' => $productId, 'title' => ['en' => $en, 'ar' => $ar], 'in_stock' => $inStock];
    }
}
