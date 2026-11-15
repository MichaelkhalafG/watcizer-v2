<?php

declare(strict_types=1);

namespace App\Domain\Pickup;

use App\Domain\Inventory\InventoryService;
use App\Domain\Payment\CheckoutMethods;
use App\Domain\Payment\OfflineProvider;
use App\Domain\Wheel\Wheel;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;

/**
 * A PICKUP order at the checkout (click & collect, 2026-11-14) — the rules StorefrontCheckout asks before it writes
 * anything, the reservation it writes inside the order's transaction, and the facts the confirmation and the e-mail
 * show.
 *
 * i18n-exempt-file: shopper-facing STOREFRONT text (the refusals), both languages returned side by side for the page to
 * pick — the same contract as CouponStatus::messages().
 *
 * Before anything is written (`prepare`): the shop's switch is ON; the store is one of ITS active stores; the contact
 * phone is an Egyptian mobile (the order has no address to carry one); the payment suits a pickup — online (a card
 * method) or AT THE STORE (the `store` method row, its amount limit already checked by CheckoutMethods) — never cash on
 * delivery or WhatsApp; and a phone holds at most `max_open_store_pay` OPEN pay-at-store reservations.
 *
 * Written with the order (`record`): the store's snapshot, a 4-digit pickup code, the ready window the slowest line
 * decides (market if any line is sourced first), `reserved` now. Stock is held by the order itself, exactly as for a
 * delivery (CompatCheckout, through the ledger).
 */
final class PickupCheckout
{
    public const TABLE = 'order_pickups';

    public const UNAVAILABLE = 'pickup_unavailable';

    public const STORE = 'pickup_store';

    public const LIMIT = 'pickup_limit';

    public function __construct(private readonly CheckoutMethods $methods) {}

    /**
     * Null-free answer: either a refusal (`status` + `body`, nothing written) or what the order needs.
     *
     * @param  array<string, mixed>  $data  the validated body (pickup_store_id, phone, guest_phone, payment_method_id)
     * @return array{refusal: array{status: int, body: array<string, mixed>}}|array{store: array{id: int, position: int, name_en: string, name_ar: string, address_en: string, address_ar: string, hours_en: string|null, hours_ar: string|null, map_url: string|null, phone: string|null, whatsapp: string|null, is_active: bool}, settings: array{enabled: bool, hold_hours: int, extend_hours: int, express_days: array{0: int, 1: int}, market_days: array{0: int, 1: int}, max_open_store_pay: int}, phone: string, pay_at_store: bool}
     */
    public function prepare(int $storefrontId, array $data, string $legacyMethod, ?string $postedMethodId): array
    {
        $settings = Pickup::settings($storefrontId);
        if (! $settings['enabled']) {
            return self::refuse(self::UNAVAILABLE, [
                'en' => 'Pick up in store is not available right now. Choose delivery to place your order.',
                'ar' => 'الاستلام من الفرع غير متاح الآن. اختر التوصيل لإتمام طلبك.',
            ]);
        }
        $storeId = is_numeric($data['pickup_store_id'] ?? null) ? (int) $data['pickup_store_id'] : 0;
        $store = $storeId > 0 ? PickupStores::activeStore($storefrontId, $storeId) : null;
        if ($store === null) {
            return self::refuse(self::STORE, [
                'en' => 'This store is not taking pickups right now. Choose another store, or delivery.',
                'ar' => 'هذا الفرع لا يستقبل طلبات استلام الآن. اختر فرعًا آخر أو التوصيل.',
            ]);
        }
        $raw = is_string($data['phone'] ?? null) && $data['phone'] !== '' ? $data['phone'] : ($data['guest_phone'] ?? null);
        $phone = Wheel::normalizePhone(is_string($raw) ? $raw : null);
        if ($phone === null) {
            return ['refusal' => ['status' => 422, 'body' => ['error' => 'invalid', 'fields' => ['phone']]]];
        }

        $legacy = mb_strtolower(trim($legacyMethod));
        $method = $this->methods->methodOf($storefrontId, $postedMethodId);
        $payAtStore = $legacy === 'cash' && $method === OfflineProvider::STORE;
        $online = in_array($legacy, ['card', 'paymob'], true);
        if (! $payAtStore && ! $online) {
            // cash on delivery, WhatsApp, or a cash order naming no store row: not a way to pay for a pickup
            $u = CheckoutMethods::unavailable();

            return ['refusal' => ['status' => 422, 'body' => ['error' => $u['code'], 'messages' => $u['messages']]]];
        }
        if ($payAtStore) {
            $open = DB::table(self::TABLE)->where('storefront_id', $storefrontId)->where('phone_key', Wheel::phoneKey($phone))
                ->where('pay_at_store', true)->whereIn('status', PickupStores::OPEN)->count();
            if ($open >= $settings['max_open_store_pay']) {
                $n = $settings['max_open_store_pay'];

                return self::refuse(self::LIMIT, [
                    'en' => "This number already has {$n} reservation(s) to pay at the store. Collect one first, or pay online now.",
                    'ar' => "لهذا الرقم {$n} حجز للدفع في الفرع بالفعل. استلم أحدها أولًا، أو ادفع أونلاين الآن.",
                ]);
            }
        }

        return ['store' => $store, 'settings' => $settings, 'phone' => $phone, 'pay_at_store' => $payAtStore];
    }

    /**
     * The reservation, written INSIDE the order's transaction (a refused order leaves none).
     *
     * @param  array{id: int, name_en: string, name_ar: string, address_en: string, address_ar: string, hours_en: string|null, hours_ar: string|null, map_url: string|null, phone: string|null, whatsapp: string|null}  $store
     * @param  array{express_days: array{0: int, 1: int}, market_days: array{0: int, 1: int}}  $settings
     * @param  list<array<string, mixed>>  $priced  the order's lines (their `type_stock` decides the window)
     */
    public function record(int $orderId, int $storefrontId, array $store, array $settings, array $priced, string $phone, bool $payAtStore): void
    {
        $buckets = [];
        foreach ($priced as $line) {
            $buckets[] = InventoryService::bucketForTypeStock(is_string($line['type_stock'] ?? null) ? $line['type_stock'] : null);
        }
        $window = Pickup::readyWindow($buckets, $settings);
        DB::table(self::TABLE)->insert([
            'order_id' => $orderId,
            'storefront_id' => $storefrontId,
            'store_id' => $store['id'],
            'store_name_en' => $store['name_en'], 'store_name_ar' => $store['name_ar'],
            'store_address_en' => $store['address_en'], 'store_address_ar' => $store['address_ar'],
            'store_hours_en' => $store['hours_en'], 'store_hours_ar' => $store['hours_ar'],
            'store_map_url' => $store['map_url'], 'store_phone' => $store['phone'], 'store_whatsapp' => $store['whatsapp'],
            'status' => 'reserved',
            'code' => str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT),
            'pay_at_store' => $payAtStore,
            'phone_key' => Wheel::phoneKey($phone),
            'stock_kind' => $window['kind'],
            'ready_min_days' => $window['min'],
            'ready_max_days' => $window['max'],
            'reserved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * What the customer is shown about a pickup order — the confirmation page, the e-mails — or null for a delivery.
     *
     * @return array{status: string, code: string, pay_at_store: bool, store: array{name: array{en: string, ar: string}, address: array{en: string, ar: string}, hours: array{en: string|null, ar: string|null}, map_url: string|null, phone: string|null, whatsapp: string|null}, ready: array{kind: string, min: int, max: int, sentence: array{en: string, ar: string}}, ready_at: string|null, expires_at: string|null}|null
     */
    public static function facts(int $orderId): ?array
    {
        $raw = DB::table(self::TABLE)->where('order_id', $orderId)->first();
        if ($raw === null) {
            return null;
        }
        $row = Row::cast($raw);
        $window = ['kind' => Row::str($row, 'stock_kind'), 'min' => Row::int($row, 'ready_min_days'), 'max' => Row::int($row, 'ready_max_days')];

        return [
            'status' => Row::str($row, 'status'),
            'code' => Row::str($row, 'code'),
            'pay_at_store' => (bool) Row::int($row, 'pay_at_store'),
            'store' => [
                'name' => ['en' => Row::str($row, 'store_name_en'), 'ar' => Row::str($row, 'store_name_ar')],
                'address' => ['en' => Row::str($row, 'store_address_en'), 'ar' => Row::str($row, 'store_address_ar')],
                'hours' => ['en' => Row::nstr($row, 'store_hours_en'), 'ar' => Row::nstr($row, 'store_hours_ar')],
                'map_url' => Row::nstr($row, 'store_map_url'),
                'phone' => Row::nstr($row, 'store_phone'),
                'whatsapp' => Row::nstr($row, 'store_whatsapp'),
            ],
            'ready' => $window + ['sentence' => Pickup::readySentence($window)],
            'ready_at' => Row::nstr($row, 'ready_at'),
            'expires_at' => Row::nstr($row, 'expires_at'),
        ];
    }

    /**
     * @param  array{en: string, ar: string}  $messages
     * @return array{refusal: array{status: int, body: array<string, mixed>}}
     */
    private static function refuse(string $code, array $messages): array
    {
        return ['refusal' => ['status' => 422, 'body' => ['error' => $code, 'messages' => $messages]]];
    }
}
