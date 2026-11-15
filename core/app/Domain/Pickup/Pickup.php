<?php

declare(strict_types=1);

namespace App\Domain\Pickup;

use App\Domain\Activity\ActivityLog;
use App\Support\Coerce;
use App\Support\ManageText;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "RESERVE ONLINE, PICK UP IN STORE" (click & collect, 2026-11-14) — the storefront-level switch and terms.
 *
 * `storefronts.settings.pickup` holds, per shop (ABSENT MEANS OFF / the defaults):
 *   enabled              the switch; OFF until the team turns it on from the dashboard (with at least one active store)
 *   hold_hours           how long a READY order is held for the customer (48): the clock starts at "ready", never at the
 *                        reservation — a reserved order is being brought to the store, which is the team's work, not the
 *                        customer's time
 *   extend_hours         the ONE extension the team may grant on the customer's request (24); not self-service
 *   express_days         [min, max] business days until "ready" for items in OUR stock (1–2: they only travel to the store)
 *   market_days          [min, max] for items SOURCED first (4–7, the delivery promise of the shops' own shipping page);
 *                        an order with any market line is promised the market window
 *   max_open_store_pay   open pay-at-store reservations one phone may hold at once (2)
 *
 * Michael's decisions of 2026-11-14: every product (express AND market) may be picked up; pay online or at the store
 * (the store method capped like cash on delivery, on the Payments screen); order number + 4-digit code at the counter,
 * the team checks the phone's last digits; both stores for both shops; e-mail + the dashboard's WhatsApp button, no SMS;
 * no branch-only accounts in this version.
 */
final class Pickup
{
    public const SETTING_BAG = 'pickup';

    public const DEFAULT_HOLD_HOURS = 48;

    public const DEFAULT_EXTEND_HOURS = 24;

    /** @var array{0: int, 1: int} */
    public const DEFAULT_EXPRESS_DAYS = [1, 2];

    /** @var array{0: int, 1: int} */
    public const DEFAULT_MARKET_DAYS = [4, 7];

    public const DEFAULT_MAX_OPEN_STORE_PAY = 2;

    public const KIND_EXPRESS = 'express';

    public const KIND_MARKET = 'market';

    /** The bounds the dashboard may set (a typo must not promise a customer 400 days). */
    public const MAX_HOLD_HOURS = 336;

    public const MAX_EXTEND_HOURS = 168;

    public const MAX_DAYS = 60;

    public const MAX_OPEN_LIMIT = 20;

    /**
     * The shop's pickup settings, the defaults where a key is absent. One primary-key read, never cached (switching it
     * OFF must bite at once).
     *
     * @return array{enabled: bool, hold_hours: int, extend_hours: int, express_days: array{0: int, 1: int}, market_days: array{0: int, 1: int}, max_open_store_pay: int}
     */
    public static function settings(int $storefrontId): array
    {
        $raw = DB::table('storefronts')->where('id', $storefrontId)->value('settings');
        $decoded = null;
        if (is_string($raw) && $raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $decoded = null;
            }
        }

        return self::settingsFrom(is_array($decoded) ? $decoded : null);
    }

    /**
     * The same answer from a DECODED settings column (the resolved storefront row a v2 request already holds).
     *
     * @return array{enabled: bool, hold_hours: int, extend_hours: int, express_days: array{0: int, 1: int}, market_days: array{0: int, 1: int}, max_open_store_pay: int}
     */
    public static function settingsFrom(mixed $settings): array
    {
        $bag = is_array($settings) ? Coerce::arr($settings[self::SETTING_BAG] ?? null) : [];
        $int = static function (mixed $v, int $default, int $min, int $max): int {
            $n = Coerce::nint($v);

            return $n === null || $n < $min || $n > $max ? $default : $n;
        };
        return [
            'enabled' => ($bag['enabled'] ?? null) === true,
            'hold_hours' => $int($bag['hold_hours'] ?? null, self::DEFAULT_HOLD_HOURS, 1, self::MAX_HOLD_HOURS),
            'extend_hours' => $int($bag['extend_hours'] ?? null, self::DEFAULT_EXTEND_HOURS, 0, self::MAX_EXTEND_HOURS),
            'express_days' => self::daysPair($bag['express_days'] ?? null, self::DEFAULT_EXPRESS_DAYS),
            'market_days' => self::daysPair($bag['market_days'] ?? null, self::DEFAULT_MARKET_DAYS),
            'max_open_store_pay' => $int($bag['max_open_store_pay'] ?? null, self::DEFAULT_MAX_OPEN_STORE_PAY, 1, self::MAX_OPEN_LIMIT),
        ];
    }

    public static function enabled(int $storefrontId): bool
    {
        return self::settings($storefrontId)['enabled'];
    }

    /**
     * The switch. Turning it ON needs at least one ACTIVE store — a checkout offering "pick up in store" with no store to
     * choose would be a promise nobody can keep — and OPENING HOURS, in both languages, on every active store (Michael,
     * 2026-11-14: the customer must know when to come).
     *
     * @throws ValidationException on `enabled`
     */
    public static function setEnabled(int $storefrontId, bool $enabled, string $storefrontName = ''): void
    {
        $before = self::enabled($storefrontId);
        if ($enabled) {
            $active = PickupStores::forStorefront($storefrontId, activeOnly: true);
            if ($active === []) {
                throw ValidationException::withMessages(['enabled' => ManageText::t('pickup.needs_store', 'أضف فرعًا واحدًا مفعّلًا على الأقل قبل تشغيل الاستلام من الفرع.')]);
            }
            $missing = PickupStores::withoutHours($active);
            $comma = '، '; // i18n-exempt: DATA — the Arabic comma joining the store names in the sentence
            if ($missing !== []) {
                throw ValidationException::withMessages(['enabled' => ManageText::t('pickup.needs_hours', 'لا يمكن التشغيل قبل كتابة مواعيد العمل (عربي وإنجليزي) لكل فرع مفعّل. ناقصة في: :stores.', ['stores' => implode($comma, $missing)])]);
            }
        }
        self::writeBag($storefrontId, ['enabled' => $enabled]);
        if ($before !== $enabled) {
            ActivityLog::record('storefronts', $storefrontId, ActivityLog::UPDATED, ['pickup_enabled' => $before], ['pickup_enabled' => $enabled],
                label: $storefrontName, storefrontId: $storefrontId);
        }
    }

    /**
     * The terms — all or nothing; every refusal lands on its own field.
     *
     * @param  array<string, mixed>  $terms  hold_hours, extend_hours, express_min, express_max, market_min, market_max, max_open_store_pay
     *
     * @throws ValidationException
     */
    public static function setTerms(int $storefrontId, array $terms, string $storefrontName = ''): void
    {
        $errors = [];
        $num = static fn (string $k): ?int => is_numeric($terms[$k] ?? null) && (float) $terms[$k] === floor((float) $terms[$k]) ? (int) $terms[$k] : null;
        $hold = $num('hold_hours');
        $extend = $num('extend_hours');
        $exMin = $num('express_min');
        $exMax = $num('express_max');
        $mkMin = $num('market_min');
        $mkMax = $num('market_max');
        $maxOpen = $num('max_open_store_pay');
        if ($hold === null || $hold < 1 || $hold > self::MAX_HOLD_HOURS) {
            $errors['hold_hours'] = ManageText::t('pickup.hold_range', 'مدة الحجز بالساعات من 1 إلى :max.', ['max' => self::MAX_HOLD_HOURS]);
        }
        if ($extend === null || $extend < 0 || $extend > self::MAX_EXTEND_HOURS) {
            $errors['extend_hours'] = ManageText::t('pickup.extend_range', 'التمديد بالساعات من 0 إلى :max (0 = بدون تمديد).', ['max' => self::MAX_EXTEND_HOURS]);
        }
        foreach ([['express_min', 'express_max', $exMin, $exMax], ['market_min', 'market_max', $mkMin, $mkMax]] as [$minKey, $maxKey, $lo, $hi]) {
            if ($lo === null || $lo < 0 || $lo > self::MAX_DAYS) {
                $errors[$minKey] = ManageText::t('pickup.days_range', 'عدد أيام العمل من 0 إلى :max.', ['max' => self::MAX_DAYS]);
            }
            if ($hi === null || $hi < 0 || $hi > self::MAX_DAYS) {
                $errors[$maxKey] = ManageText::t('pickup.days_range', 'عدد أيام العمل من 0 إلى :max.', ['max' => self::MAX_DAYS]);
            } elseif ($lo !== null && $hi < $lo) {
                $errors[$maxKey] = ManageText::t('pickup.days_order', 'الحد الأقصى لا يقل عن الحد الأدنى.');
            }
        }
        if ($maxOpen === null || $maxOpen < 1 || $maxOpen > self::MAX_OPEN_LIMIT) {
            $errors['max_open_store_pay'] = ManageText::t('pickup.max_open_range', 'عدد الحجوزات المفتوحة للرقم الواحد من 1 إلى :max.', ['max' => self::MAX_OPEN_LIMIT]);
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $before = self::settings($storefrontId);
        $patch = [
            'hold_hours' => $hold,
            'extend_hours' => $extend,
            'express_days' => [$exMin, $exMax],
            'market_days' => [$mkMin, $mkMax],
            'max_open_store_pay' => $maxOpen,
        ];
        self::writeBag($storefrontId, $patch);
        $old = array_intersect_key($before, $patch);
        if ($old != $patch) {
            ActivityLog::record('storefronts', $storefrontId, ActivityLog::UPDATED, ['pickup' => $old], ['pickup' => $patch],
                label: $storefrontName, storefrontId: $storefrontId);
        }
    }

    /**
     * The ready window an order is promised: the market window when ANY of its lines is sourced first (market), else the
     * express window — the slowest line decides, because the order is collected whole.
     *
     * @param  list<string>  $buckets  each line's stock bucket: InventoryService::BUCKET_EXPRESS or BUCKET_MARKET
     * @param  array{express_days: array{0: int, 1: int}, market_days: array{0: int, 1: int}}  $settings
     * @return array{kind: string, min: int, max: int}
     */
    public static function readyWindow(array $buckets, array $settings): array
    {
        $market = in_array(self::KIND_MARKET, $buckets, true);
        [$min, $max] = $market ? $settings['market_days'] : $settings['express_days'];

        return ['kind' => $market ? self::KIND_MARKET : self::KIND_EXPRESS, 'min' => $min, 'max' => $max];
    }

    /**
     * The customer's sentence for a ready window, in both languages: "Ready for pickup in 1–2 business days" /
     * "جاهز للاستلام خلال ١–٢ يوم عمل". The same words at the checkout, on the confirmation and in the e-mail. // i18n-exempt: an example of the customer sentence in a comment
     *
     * @param  array{min: int, max: int}  $window
     * @return array{en: string, ar: string}
     */
    public static function readySentence(array $window): array
    {
        $min = $window['min'];
        $max = $window['max'];
        $ar = static fn (int $n): string => strtr((string) $n, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']); // i18n-exempt: DATA — Arabic-Indic digits
        if ($max === 0) {
            return ['en' => 'Ready for pickup today', 'ar' => 'جاهز للاستلام اليوم']; // i18n-exempt: customer text, both languages carried together like the wheel's messages
        }
        $en = $min === $max ? "{$max}" : "{$min}–{$max}";
        $arRange = $min === $max ? $ar($max) : $ar($min).'–'.$ar($max);
        $enDays = $max === 1 ? 'business day' : 'business days';
        $arDays = $max <= 2 || $max >= 11 ? 'يوم عمل' : 'أيام عمل'; // i18n-exempt: customer text (Arabic counting: 3–10 take the plural)

        return [
            'en' => "Ready for pickup in {$en} {$enDays}",
            'ar' => "جاهز للاستلام خلال {$arRange} {$arDays}", // i18n-exempt: customer text, both languages carried together
        ];
    }

    /**
     * A stored [min, max] of business days, or the default when it is missing, reversed or out of bounds.
     *
     * @param  array{0: int, 1: int}  $default
     * @return array{0: int, 1: int}
     */
    private static function daysPair(mixed $value, array $default): array
    {
        $pair = is_array($value) ? array_values($value) : [];
        $lo = Coerce::nint($pair[0] ?? null);
        $hi = Coerce::nint($pair[1] ?? null);
        if ($lo === null || $hi === null || $lo < 0 || $hi < $lo || $hi > self::MAX_DAYS) {
            return $default;
        }

        return [$lo, $hi];
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
        $bag = Coerce::arr($settings[self::SETTING_BAG] ?? null);
        foreach ($patch as $k => $v) {
            $bag[$k] = $v;
        }
        $settings[self::SETTING_BAG] = $bag;
        DB::table('storefronts')->where('id', $storefrontId)->update(['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
    }
}
