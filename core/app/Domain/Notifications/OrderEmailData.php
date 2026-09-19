<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Storefront\ImageUrl;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The flat, template-ready array the three ported order e-mails render — built from CORE's clean
 * tables (prerequisite (a), 2026-09-13).
 *
 * ── What this class is a port of, and what it deliberately is not ────────────────────────────
 *
 * The legacy app builds this array in `App\Mail\Concerns\BuildsOrderEmailData`, from Eloquent
 * models with translatable relations (`$item->product->translate('ar')->product_title`). Core has
 * no `Order` model and no Astrotomic; it has query builders over `catalog_*`. So the TEMPLATES
 * are ported byte-for-byte and this BUILDER is rewritten against core's schema, producing the
 * same keys with the same meanings. The key list is the contract between the two, and
 * `OrderEmailContractTest` asserts it against the legacy trait's own `return` statement so a
 * drift shows up as a failing test rather than as a blank line in a customer's e-mail.
 *
 * Two keys the legacy array carries are deliberately absent:
 *
 *   - `order` — the Eloquent model itself. No template reads it, and core has no model to put
 *     there. Passing a fabricated stdClass would invite a template to start using it.
 *   - `logo` — `config('watchizer.brand.logo')`, a URL on the legacy host. No template reads it
 *     either: `partials/header.blade.php` renders the wordmark as TEXT on purpose (its own
 *     comment: the dark logo was invisible on the black bar and Gmail strips the CSS filter that
 *     would recolour it). So the ported e-mails load NO image from the legacy host for branding —
 *     the only remote images are the product thumbnails, and those are built below through
 *     core's own `ImageUrl`, from core's own `storefront.asset_base`.
 *
 * ── Where the URLs point ─────────────────────────────────────────────────────────────────────
 *
 * `trackUrl` is the ORDER'S OWN storefront (`storefronts.domain`), not one global `FRONTEND_URL`
 * as in legacy. That is a deliberate improvement, not a port: the whole point of the clean core
 * is two storefronts, and mailing a Brand Fashion customer a watchizereg.com tracking link is the
 * defect the legacy shape guarantees. `dashboardUrl` is core's own order screen
 * (`manage.orders.show`), so the admin notification's button opens the dashboard the operator
 * actually uses after the switch.
 */
final class OrderEmailData
{
    /**
     * Everything the three templates render, or NULL when the order does not exist.
     *
     * One call = five queries, whatever the order's size (the order + storefront, the user, the
     * address + city, the product lines, the offer lines). No per-item query: an e-mail built in
     * a checkout request is on the shopper's clock.
     *
     * @return array<string, mixed>|null
     */
    public static function for(int $orderId): ?array
    {
        $raw = DB::table('orders as o')
            ->leftJoin('storefronts as s', 's.id', '=', 'o.storefront_id')
            ->where('o.id', $orderId)
            ->first([
                'o.id', 'o.order_number', 'o.status', 'o.total_price_for_order', 'o.payment_method',
                'o.paid_via_provider', 'o.paid_via_method', 'o.note', 'o.guest_name', 'o.guest_email',
                'o.guest_phone', 'o.user_id', 'o.address_id', 'o.storefront_id', 'o.created_at',
                's.name as storefront_name', 's.domain as storefront_domain',
            ]);

        if (! is_object($raw)) {
            return null;
        }
        $order = Row::cast($raw);

        $user = self::user(Row::nint($order, 'user_id'));
        $address = self::address(Row::nint($order, 'address_id'));

        [$items, $grossSubtotal] = self::items($orderId);

        $shippingCost = $address['shipping_cost'];
        $total = (float) Row::money($order, 'total_price_for_order');

        /*
         * The legacy arithmetic, ported exactly: the stored total INCLUDES shipping, so the
         * subtotal is derived by subtracting it, and the discount is whatever the list prices add
         * up to beyond that. Recomputing the subtotal from the lines instead would make the
         * e-mail disagree with the order whenever a line was priced by hand.
         */
        $subtotal = max(0.0, round($total - $shippingCost, 2));
        $discount = max(0.0, round($grossSubtotal - $subtotal, 2));

        $status = Row::str($order, 'status');
        $paymentMethod = Row::nstr($order, 'payment_method') ?? '';

        return [
            'orderNumber' => Row::str($order, 'order_number'),
            'orderId' => Row::int($order, 'id'),
            'createdAt' => self::createdAt(Row::nstr($order, 'created_at')),
            'status' => $status,
            'statusEn' => self::statusLabel($status, 'en'),
            'statusAr' => self::statusLabel($status, 'ar'),

            'customerName' => self::customerName($order, $user),
            'customerEmail' => self::customerEmail($order, $user),
            // The legacy order of preference, exactly: the address's first phone, then the
            // guest's, then the address's second. A `??` chain would not do it — an absent
            // address yields '' rather than null, so the fallbacks are explicit.
            'customerPhone' => self::firstNonEmpty([
                $address['phone'], Row::nstr($order, 'guest_phone'), $address['phone_alt'],
            ]),
            'isGuest' => Row::nint($order, 'user_id') === null,

            'addressLine' => $address['line'],
            'cityEn' => $address['city_en'],
            'cityAr' => $address['city_ar'],

            'items' => $items,
            'subtotal' => $subtotal,
            'shippingCost' => $shippingCost,
            'discount' => $discount,
            'total' => $total,
            'note' => Row::nstr($order, 'note'),

            'paymentEn' => self::paymentLabel($paymentMethod, 'en'),
            'paymentAr' => self::paymentLabel($paymentMethod, 'ar'),
            'paymentStatus' => self::paymentStatusLabel($orderId, $paymentMethod),

            // Branding + links, all from CORE's own config and CORE's own tables.
            'brandName' => Row::nstr($order, 'storefront_name') ?? 'Watchizer',
            'copyright' => config()->string('notifications.brand.copyright'),
            'whatsappUrl' => self::whatsappSupportUrl(),
            'trackUrl' => self::trackUrl(Row::nstr($order, 'storefront_domain')),
            'dashboardUrl' => self::dashboardUrl(Row::int($order, 'id')),
        ];
    }

    /**
     * The keys every template may read. Public because the contract test asserts on it, and
     * because a key missing from a Blade view is a silent empty string, not an error.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return [
            'orderNumber', 'orderId', 'createdAt', 'status', 'statusEn', 'statusAr',
            'customerName', 'customerEmail', 'customerPhone', 'isGuest',
            'addressLine', 'cityEn', 'cityAr',
            'items', 'subtotal', 'shippingCost', 'discount', 'total', 'note',
            'paymentEn', 'paymentAr', 'paymentStatus',
            'brandName', 'copyright', 'whatsappUrl', 'trackUrl', 'dashboardUrl',
        ];
    }

    // ── the pieces ───────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed>|null */
    private static function user(?int $userId): ?array
    {
        if ($userId === null) {
            return null;
        }
        $row = DB::table('users')->where('id', $userId)->first(['first_name', 'last_name', 'email']);
        if (! is_object($row)) {
            return null;
        }
        $user = Row::cast($row);

        return [
            'name' => trim((Row::nstr($user, 'first_name') ?? '').' '.(Row::nstr($user, 'last_name') ?? '')),
            'email' => Row::nstr($user, 'email'),
        ];
    }

    /**
     * The address, its city in BOTH locales, and the shipping cost the totals are derived from.
     *
     * The city NAME lives in `shipping_city_translations` and the COST on the city row — measured,
     * not assumed (`shipping_cities` carries an id, a cost and timestamps and nothing else).
     *
     * @return array{line: string, phone: string, phone_alt: string, city_en: string, city_ar: string, shipping_cost: float}
     */
    private static function address(?int $addressId): array
    {
        $empty = ['line' => '', 'phone' => '', 'phone_alt' => '', 'city_en' => '', 'city_ar' => '', 'shipping_cost' => 0.0];
        if ($addressId === null) {
            return $empty;
        }

        $row = DB::table('addresses as a')
            ->leftJoin('shipping_cities as c', 'c.id', '=', 'a.shipping_city_id')
            ->leftJoin('shipping_city_translations as ten', function (JoinClause $join): void {
                $join->on('ten.shipping_city_id', '=', 'a.shipping_city_id')->where('ten.locale', '=', 'en');
            })
            ->leftJoin('shipping_city_translations as tar', function (JoinClause $join): void {
                $join->on('tar.shipping_city_id', '=', 'a.shipping_city_id')->where('tar.locale', '=', 'ar');
            })
            ->where('a.id', $addressId)
            ->first([
                'a.address_line', 'a.phone_number_one', 'a.phone_number_two',
                'c.shipping_cost', 'ten.city_name as city_en', 'tar.city_name as city_ar',
            ]);

        if (! is_object($row)) {
            return $empty;
        }
        $address = Row::cast($row);

        return [
            'line' => Row::nstr($address, 'address_line') ?? '',
            // Both phones, unmerged: the guest's number sits BETWEEN them in the legacy order of
            // preference, and only the caller has the order to supply it.
            'phone' => Row::nstr($address, 'phone_number_one') ?? '',
            'phone_alt' => Row::nstr($address, 'phone_number_two') ?? '',
            'city_en' => Row::nstr($address, 'city_en') ?? '',
            'city_ar' => Row::nstr($address, 'city_ar') ?? '',
            'shipping_cost' => (float) (Row::nmoney($address, 'shipping_cost') ?? '0'),
        ];
    }

    /**
     * The lines in the shape `partials/product-row.blade.php` reads, plus the gross subtotal the
     * discount line is derived from.
     *
     * An order line points at a PRODUCT or at an OFFER, never both, and offers are still on the
     * legacy-shaped `offers` table (the clean offers module is wave 4D) — so both are read, and a
     * line whose subject has since been deleted still renders with its stored price and quantity
     * rather than disappearing from the customer's own receipt.
     *
     * @return array{0: list<array<string, mixed>>, 1: float}
     */
    private static function items(int $orderId): array
    {
        $rows = DB::table('order_items as oi')
            ->leftJoin('catalog_products as p', 'p.id', '=', 'oi.product_id')
            ->leftJoin('catalog_product_translations as pen', function (JoinClause $join): void {
                $join->on('pen.product_id', '=', 'oi.product_id')->where('pen.locale', '=', 'en');
            })
            ->leftJoin('catalog_product_translations as par', function (JoinClause $join): void {
                $join->on('par.product_id', '=', 'oi.product_id')->where('par.locale', '=', 'ar');
            })
            ->leftJoin('catalog_product_variants as v', 'v.id', '=', 'oi.variant_id')
            ->leftJoin('offers as f', 'f.id', '=', 'oi.offer_id')
            ->leftJoin('offer_translations as fen', function (JoinClause $join): void {
                $join->on('fen.offer_id', '=', 'oi.offer_id')->where('fen.locale', '=', 'en');
            })
            ->leftJoin('offer_translations as far', function (JoinClause $join): void {
                $join->on('far.offer_id', '=', 'oi.offer_id')->where('far.locale', '=', 'ar');
            })
            ->where('oi.order_id', $orderId)
            ->orderBy('oi.id')
            /*
             * `select()` FIRST and then `selectRaw()`, never a column list passed to `get()`:
             * `get([...])` is silently ignored once the select list has been set, so the
             * sub-select alone would have been the whole projection. It threw on the first
             * accessor when this was run against a real order, which is how it was found.
             */
            ->select([
                'oi.id', 'oi.product_id', 'oi.offer_id', 'oi.quantity', 'oi.piece_price', 'oi.total_price',
                'oi.type_stock', 'oi.color_band', 'oi.color_dial',
                'p.wa_code', 'p.sku', 'p.selling_price',
                'pen.title as title_en', 'par.title as title_ar',
                'v.label as variant_label', 'v.sku as variant_sku',
                'f.wa_code as offer_code', 'f.image as offer_image', 'f.selling_price as offer_selling_price',
                'fen.offer_name as offer_name_en', 'far.offer_name as offer_name_ar',
            ])
            // The cover image, one sub-select in the projection rather than a query per line.
            ->selectRaw('(SELECT ci.path FROM catalog_product_images ci WHERE ci.product_id = oi.product_id AND ci.is_cover = 1 ORDER BY ci.sort, ci.id LIMIT 1) AS cover_path')
            ->get();

        $items = [];
        $gross = 0.0;

        foreach ($rows as $raw) {
            $row = Row::cast($raw);
            $isProduct = Row::nint($row, 'product_id') !== null;

            $nameEn = $isProduct ? Row::nstr($row, 'title_en') : Row::nstr($row, 'offer_name_en');
            $nameAr = $isProduct ? Row::nstr($row, 'title_ar') : Row::nstr($row, 'offer_name_ar');

            $qty = Row::int($row, 'quantity');
            $unit = (float) Row::money($row, 'piece_price');
            $lineTotal = (float) (Row::nmoney($row, 'total_price') ?? '0');
            $lineTotal = $lineTotal > 0.0 ? $lineTotal : $unit * $qty;

            // The LIST price, so the discount line says what the shopper saved. Falls back to
            // what they actually paid, which makes the saving zero rather than negative.
            $listPrice = $isProduct
                ? (float) (Row::nmoney($row, 'selling_price') ?? (string) $unit)
                : (float) (Row::nmoney($row, 'offer_selling_price') ?? (string) $unit);
            $gross += $listPrice * $qty;

            /*
             * The VARIANT is named in the English line when the order carries one — core sells a
             * size/colour the warehouse has to pick, and the legacy trait had no variant to name.
             * Appended rather than replacing, so a customer who reads Arabic still gets the
             * Arabic title unchanged on the second line.
             */
            $variant = Row::nstr($row, 'variant_label');

            $items[] = [
                'name_en' => self::itemName($nameEn, $nameAr, $variant, 'Item'),
                'name_ar' => self::itemName($nameAr, $nameEn, $variant, 'منتج'),
                'image' => self::itemImage($isProduct, Row::nstr($row, 'cover_path'), Row::nstr($row, 'offer_image')),
                'qty' => $qty,
                'unit_price' => $unit,
                'line_total' => $lineTotal,
                'code' => $isProduct
                    ? (Row::nstr($row, 'variant_sku') ?? Row::nstr($row, 'wa_code') ?? Row::nstr($row, 'sku'))
                    : Row::nstr($row, 'offer_code'),
                // One code, one column (item 4). `sku` is the survivor of the merge.
                'model' => $isProduct ? Row::nstr($row, 'sku') : null,
                'type_stock' => Row::nstr($row, 'type_stock'),
                'color_band' => Row::nstr($row, 'color_band'),
                'color_dial' => Row::nstr($row, 'color_dial'),
            ];
        }

        return [$items, round($gross, 2)];
    }

    /** @param  list<string|null>  $candidates */
    private static function firstNonEmpty(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if ($candidate !== null && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return '';
    }

    private static function itemName(?string $preferred, ?string $fallback, ?string $variant, string $last): string
    {
        $name = $preferred ?? $fallback ?? $last;
        if ($name === '') {
            $name = $fallback ?? $last;
        }

        return $variant === null || $variant === '' ? $name : $name.' — '.$variant;
    }

    /**
     * The thumbnail, through CORE's URL builder.
     *
     * `catalog_product_images.path` already carries its folder (`Product/x.webp`), which is why
     * `ImageUrl::src()` is the right door; an offer still stores a bare filename in the legacy
     * `Offer` folder, so that one is prefixed. Both resolve against `storefront.asset_base`, so
     * pointing the shared `Uploads_Images` tree at core on switch night moves these URLs with it
     * and nothing in this file changes.
     */
    private static function itemImage(bool $isProduct, ?string $coverPath, ?string $offerImage): ?string
    {
        if ($isProduct) {
            return $coverPath === null || $coverPath === '' ? null : ImageUrl::src($coverPath);
        }

        return $offerImage === null || $offerImage === '' ? null : ImageUrl::src('Offer/'.$offerImage);
    }

    /** `d M Y, H:i`, the legacy format exactly — the string a customer compares with their bank. */
    private static function createdAt(?string $timestamp): string
    {
        if ($timestamp === null || $timestamp === '') {
            return '';
        }

        $time = strtotime($timestamp);

        return $time === false ? '' : date('d M Y, H:i', $time);
    }

    /** @param  array<string, mixed>|null  $user */
    private static function customerName(stdClass $order, ?array $user): string
    {
        $name = $user === null
            ? (Row::nstr($order, 'guest_name') ?? '')
            : trim(is_string($user['name'] ?? null) ? $user['name'] : '');

        return $name !== '' ? $name : 'Guest';
    }

    /** @param  array<string, mixed>|null  $user */
    private static function customerEmail(stdClass $order, ?array $user): ?string
    {
        if ($user !== null && is_string($user['email'] ?? null) && $user['email'] !== '') {
            return $user['email'];
        }

        return Row::nstr($order, 'guest_email');
    }

    /**
     * The six labels, in both languages — the legacy map verbatim, including the two values M1i
     * added to the enum on 2026-09-12.
     *
     * `completed` keeps its legacy wording here ("Completed" / "مكتمل") on purpose, even though
     * core's own dashboard now labels it "مغلق": this is the CUSTOMER's copy, and it is the copy
     * `order-status-update.blade.php` was written around. The dashboard label describes the shop's
     * workflow; this one describes the customer's order.
     */
    private static function statusLabel(string $status, string $locale): string
    {
        $map = [
            'pending' => ['en' => 'Pending', 'ar' => 'قيد الانتظار'],
            'processing' => ['en' => 'Processing', 'ar' => 'قيد التجهيز'],
            'shipped' => ['en' => 'Shipped', 'ar' => 'تم الشحن'],
            'delivered' => ['en' => 'Delivered', 'ar' => 'تم التسليم'],
            'completed' => ['en' => 'Completed', 'ar' => 'مكتمل'],
            'cancelled' => ['en' => 'Cancelled', 'ar' => 'ملغي'],
        ];

        return $map[$status][$locale] ?? ucfirst($status);
    }

    private static function paymentLabel(string $method, string $locale): string
    {
        $en = ['cash' => 'Cash on Delivery', 'paymob' => 'Paid Online (Card / Wallet)', 'whatsapp' => 'WhatsApp Order'];
        $ar = ['cash' => 'الدفع عند الاستلام', 'paymob' => 'بطاقة / محفظة إلكترونية', 'whatsapp' => 'طلب عبر واتساب'];
        $map = $locale === 'ar' ? $ar : $en;

        return $map[$method] ?? $method;
    }

    /**
     * "Paid" / "Pending", for the admin notification's Order Information block.
     *
     * Legacy read `$order->paymentStatus->success` — the hasOne, i.e. whichever row the relation
     * happened to return. Core asks the question the operator means: is there a SUCCESSFUL attempt
     * on this order? A declined first attempt followed by a successful retry reads "Paid" here and
     * read "Pending" in legacy, which was a defect in an e-mail an operator uses to decide whether
     * to pack a parcel.
     */
    private static function paymentStatusLabel(int $orderId, string $method): string
    {
        if ($method === 'cash') {
            return 'Cash on Delivery (unpaid)';
        }
        if ($method === 'whatsapp') {
            return 'Pending (WhatsApp)';
        }

        $paid = DB::table('payment_statuses')
            ->where('order_id', $orderId)
            ->where('success', 'true')
            ->exists();

        return $paid ? 'Paid' : 'Pending';
    }

    private static function whatsappSupportUrl(): string
    {
        $number = config()->string('notifications.whatsapp_support');
        $text = rawurlencode('للاستعلام عن طلبك تواصل معنا على واتساب');

        return "https://wa.me/{$number}?text={$text}";
    }

    /** The order's OWN storefront, falling back to the primary one's domain. */
    private static function trackUrl(?string $domain): string
    {
        $domain = $domain === null || $domain === '' ? 'watchizereg.com' : $domain;

        return 'https://'.$domain.'/order-list';
    }

    private static function dashboardUrl(int $orderId): string
    {
        return route('manage.orders.show', ['order' => $orderId]);
    }
}
