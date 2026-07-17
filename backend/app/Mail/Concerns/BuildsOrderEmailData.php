<?php

namespace App\Mail\Concerns;

use App\Models\Order;

/**
 * Normalises an Order into a flat, template-ready array so the Blade email
 * templates never have to touch model quirks (translatable relations, guest
 * fallbacks, camelCase vs snake_case shipping-city relation, etc.).
 *
 * Shared by OrderConfirmation, AdminOrderNotification and OrderStatusUpdate.
 */
trait BuildsOrderEmailData
{
    protected function orderEmailData(Order $order): array
    {
        // Eager-load everything the templates render so the mail build never
        // lazy-loads (or errors) mid-render.
        $order->loadMissing([
            'order_item.product.translations',
            'order_item.offer.translations',
            'address.shippingCity.translations',
            'user',
            'paymentStatus',
        ]);

        // ── Customer identity (guest-safe) ──────────────────────────────────
        $customerName = $order->user
            ? trim(($order->user->first_name ?? '') . ' ' . ($order->user->last_name ?? ''))
            : (string) ($order->guest_name ?? '');
        $customerName = $customerName !== '' ? $customerName : 'Guest';

        $customerEmail = $order->user ? $order->user->email : ($order->guest_email ?? null);
        $customerPhone = optional($order->address)->phone_number_one
            ?? $order->guest_phone
            ?? optional($order->address)->phone_number_two
            ?? '';

        // ── City (camelCase shippingCity relation) ──────────────────────────
        $city   = optional(optional($order->address)->shippingCity);
        $cityAr = $this->cityName($city, 'ar');
        $cityEn = $this->cityName($city, 'en');
        $shippingCost = (float) ($city->shipping_cost ?? 0);

        // ── Items (normalised) ──────────────────────────────────────────────
        $items         = [];
        $grossSubtotal = 0.0; // sum of original list prices — for the discount line
        foreach ($order->order_item as $item) {
            $isProduct = (bool) $item->product;
            $entity    = $item->product ?: $item->offer;

            $nameEn = $isProduct
                ? optional(optional($item->product)->translate('en'))->product_title
                : optional(optional($item->offer)->translate('en'))->offer_name;
            $nameAr = $isProduct
                ? optional(optional($item->product)->translate('ar'))->product_title
                : optional(optional($item->offer)->translate('ar'))->offer_name;

            $qty       = (int) $item->quantity;
            $unit      = (float) $item->piece_price;
            $lineTotal = (float) ($item->total_price ?: $unit * $qty);

            $original       = $entity ? (float) ($entity->selling_price ?? $unit) : $unit;
            $grossSubtotal += $original * $qty;

            $items[] = [
                'name_en'    => $nameEn ?: ($nameAr ?: 'Item'),
                'name_ar'    => $nameAr ?: ($nameEn ?: 'منتج'),
                'image'      => $this->itemImage($item),
                'qty'        => $qty,
                'unit_price' => $unit,
                'line_total' => $lineTotal,
                'code'       => $isProduct
                    ? (optional($item->product)->wa_code ?? optional($item->product)->sku_unique ?? null)
                    : (optional($item->offer)->wa_code ?? null),
                'model'      => $isProduct ? (optional($item->product)->model_number ?? null) : null,
                'type_stock' => $item->type_stock,
                'color_band' => $item->color_band,
                'color_dial' => $item->color_dial,
            ];
        }

        $total    = (float) $order->total_price_for_order;
        $subtotal = max(0.0, round($total - $shippingCost, 2));
        $discount = max(0.0, round($grossSubtotal - $subtotal, 2));

        return [
            'order'         => $order,
            'orderNumber'   => $order->order_number,
            'orderId'       => $order->id,
            'createdAt'     => optional($order->created_at)->format('d M Y, H:i') ?? '',
            'status'        => $order->status,
            'statusEn'      => $this->statusLabel($order->status, 'en'),
            'statusAr'      => $this->statusLabel($order->status, 'ar'),

            'customerName'  => $customerName,
            'customerEmail' => $customerEmail,
            'customerPhone' => $customerPhone,
            'isGuest'       => is_null($order->user_id),

            'addressLine'   => optional($order->address)->address_line ?? '',
            'cityEn'        => $cityEn,
            'cityAr'        => $cityAr,

            'items'         => $items,
            'subtotal'      => $subtotal,
            'shippingCost'  => $shippingCost,
            'discount'      => $discount,
            'total'         => $total,
            'note'          => $order->note,

            'paymentEn'     => $this->paymentLabel($order, 'en'),
            'paymentAr'     => $this->paymentLabel($order, 'ar'),
            'paymentStatus' => $this->paymentStatusLabel($order),

            // Branding + links (from config/watchizer.php).
            'logo'          => config('watchizer.brand.logo'),
            'brandName'     => config('watchizer.brand.name'),
            'copyright'     => config('watchizer.brand.copyright'),
            'whatsappUrl'   => $this->whatsappSupportUrl(),
            'trackUrl'      => config('watchizer.urls.frontend') . '/order-list',
            'dashboardUrl'  => config('watchizer.urls.dashboard') . '/admin/order/' . $order->id,
        ];
    }

    private function cityName($city, string $locale): string
    {
        if (! $city) {
            return '';
        }
        try {
            return $city->translate($locale)->city_name ?? $city->city_name ?? '';
        } catch (\Throwable $e) {
            return $city->city_name ?? '';
        }
    }

    private function itemImage($item): ?string
    {
        $base = config('watchizer.urls.dashboard');
        if ($item->product && $item->product->image) {
            return $base . '/Uploads_Images/Product/' . $item->product->image;
        }
        if ($item->offer && $item->offer->image) {
            return $base . '/Uploads_Images/Offer/' . $item->offer->image;
        }
        return null;
    }

    private function statusLabel(?string $status, string $locale): string
    {
        // The DB enum is pending/processing/completed/cancelled; shipped/delivered
        // labels are included for forward-compatibility but map cleanly if unused.
        $map = [
            'pending'    => ['en' => 'Pending',    'ar' => 'قيد الانتظار'],
            'processing' => ['en' => 'Processing', 'ar' => 'قيد التجهيز'],
            'shipped'    => ['en' => 'Shipped',    'ar' => 'تم الشحن'],
            'delivered'  => ['en' => 'Delivered',  'ar' => 'تم التسليم'],
            'completed'  => ['en' => 'Completed',  'ar' => 'مكتمل'],
            'cancelled'  => ['en' => 'Cancelled',  'ar' => 'ملغي'],
        ];
        return $map[$status][$locale] ?? ucfirst((string) $status);
    }

    private function paymentLabel(Order $order, string $locale): string
    {
        $en = ['cash' => 'Cash on Delivery', 'paymob' => 'Paid Online (Card / Wallet)', 'whatsapp' => 'WhatsApp Order'];
        $ar = ['cash' => 'الدفع عند الاستلام', 'paymob' => 'بطاقة / محفظة إلكترونية', 'whatsapp' => 'طلب عبر واتساب'];
        $m  = $locale === 'ar' ? $ar : $en;
        return $m[$order->payment_method] ?? (string) $order->payment_method;
    }

    private function paymentStatusLabel(Order $order): string
    {
        if ($order->payment_method === 'cash') {
            return 'Cash on Delivery (unpaid)';
        }
        if ($order->payment_method === 'whatsapp') {
            return 'Pending (WhatsApp)';
        }
        return optional($order->paymentStatus)->success ? 'Paid' : 'Pending';
    }

    private function whatsappSupportUrl(): string
    {
        $num  = config('watchizer.whatsapp.support', '201551096234');
        $text = rawurlencode('للاستعلام عن طلبك تواصل معنا على واتساب');
        return "https://wa.me/{$num}?text={$text}";
    }
}
