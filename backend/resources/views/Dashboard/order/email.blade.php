<!DOCTYPE html>
<html lang="{{ isset($type) && $type === 'admin' ? 'en' : 'en' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Watchizer Order</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background-color: #f5f5f5; }
        .container { max-width: 650px; margin: auto; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }

        /* ── Admin header ── */
        .header { padding: 24px; text-align: center; }
        .header-admin    { background: #b71c1c; }
        .header img { width: 75px; }
        .header h1  { color: #fff; font-size: 20px; margin: 10px 0 0; }
        .header p   { color: rgba(255,255,255,0.8); font-size: 13px; margin: 4px 0 0; }

        .body { padding: 24px; }
        .section-title {
            font-size: 12px; font-weight: bold; text-transform: uppercase;
            letter-spacing: 1px; padding-bottom: 5px; margin: 22px 0 10px;
            border-bottom: 2px solid #eee; color: #888;
        }
        .info-box { background: #f8f8f8; border-radius: 8px; padding: 14px 16px; margin-bottom: 16px; }
        .info-box p { margin: 5px 0; font-size: 14px; color: #333; }
        .info-box strong { color: #111; }
        .badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:bold; }
        .badge-processing { background:#e8f5e9; color:#2e7d32; }
        .badge-pending    { background:#e3f2fd; color:#1565c0; }
        table { width:100%; border-collapse:collapse; margin:12px 0 20px; }
        th { background:#262626; color:#fff; padding:10px; font-size:13px; }
        td { padding:10px; border-bottom:1px solid #eee; font-size:13px; color:#333; vertical-align:middle; }
        td img { width:45px; height:auto; border-radius:4px; }
        .color-dot { display:inline-block; width:16px; height:16px; border-radius:50%; border:1px solid #ccc; vertical-align:middle; }
        .totals-box { background:#f8f8f8; border-radius:8px; padding:14px 16px; margin-bottom:20px; }
        .totals-box p { margin:5px 0; font-size:14px; color:#555; }
        .totals-box .grand { font-size:18px; font-weight:bold; color:#262626; margin-top:8px; }
        .footer { background:#f8f8f8; padding:18px 24px; text-align:center; font-size:13px; color:#777; border-top:1px solid #eee; }
        .footer a { color:#262626; font-weight:bold; text-decoration:none; }
        @media(max-width:600px){ th,td { font-size:11px; padding:7px; } .body { padding:16px; } }
    </style>
</head>
<body style="margin:0;padding:0;background:#f5f5f5;">

@php
    // ── Customer info ─────────────────────────────────────────────────────────
    $customerName = $order->user
        ? trim(($order->user->first_name ?? '') . ' ' . ($order->user->last_name ?? ''))
        : ($order->guest_name ?? null);

    $customerEmail = $order->user ? $order->user->email : ($order->guest_email ?? null);
    $customerPhone = optional($order->address)->phone_number_one ?? $order->guest_phone ?? '';

    // ── City names — uses the camelCase shippingCity relation (the snake_case
    //    `shipping_city` used before never resolved, so city/shipping were blank).
    $shippingCity = optional(optional($order->address)->shippingCity);
    try { $cityAr = $shippingCity->translate('ar')->city_name ?? $shippingCity->city_name ?? ''; }
    catch (\Exception $e) { $cityAr = $shippingCity->city_name ?? ''; }
    try { $cityEn = $shippingCity->translate('en')->city_name ?? $shippingCity->city_name ?? ''; }
    catch (\Exception $e) { $cityEn = $shippingCity->city_name ?? ''; }

    $shippingCost = (float) ($shippingCity->shipping_cost ?? 0);
    $subtotal     = (float) $order->total_price_for_order - $shippingCost;

    $paymentAr = match($order->payment_method) {
        'cash' => 'الدفع عند الاستلام', 'paymob' => 'بطاقة / محفظة إلكترونية',
        'whatsapp' => 'واتساب', default => $order->payment_method,
    };
    $paymentEn = match($order->payment_method) {
        'cash' => 'Cash on Delivery', 'paymob' => 'Paid Online (Card / Wallet)',
        'whatsapp' => 'WhatsApp Order', default => $order->payment_method,
    };

    $trackUrl = rtrim(config('services.frontend_url', 'https://watchizereg.com'), '/') . '/order-list';

    // Resolve a display name + image URL for a line item.
    $lineName = function ($item) {
        if ($item->product) {
            return optional($item->product->translate('en'))->product_title
                ?? optional($item->product->translate('ar'))->product_title ?? 'Product';
        }
        if ($item->offer) {
            return optional($item->offer->translate('en'))->offer_name
                ?? optional($item->offer->translate('ar'))->offer_name ?? 'Offer';
        }
        return 'Item';
    };
    $lineImg = function ($item) {
        if ($item->product && $item->product->image) {
            return 'https://dash.watchizereg.com/Uploads_Images/Product/' . $item->product->image;
        }
        if ($item->offer && $item->offer->image) {
            return 'https://dash.watchizereg.com/Uploads_Images/Offer/' . $item->offer->image;
        }
        return null;
    };
@endphp

{{-- ════════════════════ ADMIN EMAIL (fulfillment) ════════════════════ --}}
@if(isset($type) && $type === 'admin')

    <div class="container">
    <div class="header header-admin">
        <img src="https://dash.watchizereg.com/DashAssets/img/logo.webp" alt="Watchizer">
        <h1>🔔 New Order Received!</h1>
        <p>Order #{{ $order->order_number }} — {{ $order->created_at->format('d M Y, H:i') }}</p>
    </div>

    <div class="body">
        <div class="section-title">Order Information</div>
        <div class="info-box">
            <p><strong>Order #:</strong> {{ $order->order_number }}</p>
            <p><strong>Date:</strong> {{ $order->created_at->format('d-m-Y H:i') }}</p>
            <p><strong>Payment:</strong> {{ $paymentEn }}</p>
            <p><strong>Status:</strong>
                <span class="badge {{ $order->status === 'processing' ? 'badge-processing' : 'badge-pending' }}">{{ ucfirst($order->status) }}</span>
            </p>
        </div>

        <div class="section-title">Customer</div>
        <div class="info-box">
            <p><strong>Name:</strong> {{ $customerName ?: 'Guest' }}</p>
            <p><strong>Phone:</strong> {{ $customerPhone ?: '—' }}</p>
            <p><strong>Email:</strong> {{ $customerEmail ?? '—' }}</p>
            <p><strong>Type:</strong> {{ $order->user ? 'Registered User' : 'Guest' }}</p>
        </div>

        <div class="section-title">Shipping Address</div>
        <div class="info-box">
            <p><strong>Address:</strong> {{ optional($order->address)->address_line ?? '—' }}</p>
            <p><strong>City:</strong> {{ $cityEn ?: '—' }}</p>
            <p><strong>Shipping Cost:</strong> {{ number_format($shippingCost) }} EGP</p>
        </div>

        <div class="section-title">Ordered Products</div>
        <table>
            <thead><tr>
                <th style="text-align:left">Photo</th><th style="text-align:left">Product</th>
                <th style="text-align:left">Code</th><th style="text-align:left">Type</th>
                <th style="text-align:left">Dial</th><th style="text-align:left">Band</th>
                <th style="text-align:left">Qty</th><th style="text-align:left">Price</th><th style="text-align:left">Total</th>
            </tr></thead>
            <tbody>
                @foreach($order->order_item as $item)
                <tr>
                    <td>@if($lineImg($item))<img src="{{ $lineImg($item) }}">@endif</td>
                    <td>{{ $lineName($item) }}</td>
                    <td>{{ $item->product->wa_code ?? $item->offer->wa_code ?? '—' }}</td>
                    <td>{{ $item->type_stock ?? '—' }}</td>
                    <td>@if($item->color_dial)<span class="color-dot" style="background:{{ $item->color_dial }}"></span>@else—@endif</td>
                    <td>@if($item->color_band)<span class="color-dot" style="background:{{ $item->color_band }}"></span>@else—@endif</td>
                    <td>{{ $item->quantity }}</td>
                    <td>{{ number_format($item->piece_price) }} EGP</td>
                    <td>{{ number_format($item->total_price) }} EGP</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <div class="totals-box">
            <p>Subtotal: {{ number_format($subtotal) }} EGP</p>
            <p>Shipping: {{ number_format($shippingCost) }} EGP</p>
            <p class="grand">Grand Total: {{ number_format($order->total_price_for_order) }} EGP</p>
        </div>
    </div>

    <div class="footer">Please process this order from the <strong>Watchizer Dashboard</strong>.</div>
    </div>

{{-- ════════════════════ CUSTOMER EMAIL (premium) ════════════════════ --}}
@else

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f5f5f5;padding:32px 16px;font-family:Georgia,'Times New Roman',serif;">
<tr><td align="center">

<table width="600" cellpadding="0" cellspacing="0" role="presentation" style="background:#ffffff;border-radius:8px;overflow:hidden;max-width:600px;width:100%;">

    {{-- Dark header --}}
    <tr><td style="background:#0a0a0a;padding:26px 32px;text-align:center;">
        <img src="https://dash.watchizereg.com/DashAssets/img/logo.webp" alt="Watchizer" width="120" style="display:block;margin:0 auto;">
    </td></tr>

    {{-- Gold accent line --}}
    <tr><td style="background:linear-gradient(to right,#C8A45C,#E8D5A3,#C8A45C);height:3px;font-size:0;line-height:0;">&nbsp;</td></tr>

    {{-- Success header --}}
    <tr><td style="padding:38px 32px 20px;text-align:center;">
        <div style="width:56px;height:56px;border-radius:50%;background:#f0fdf4;margin:0 auto 16px;line-height:56px;font-size:28px;color:#16a34a;">&#10003;</div>
        <h1 style="margin:0;font-size:24px;font-weight:400;color:#111;letter-spacing:-0.01em;">Order Confirmed &middot; تم تأكيد الطلب</h1>
        <p style="margin:8px 0 0;font-size:13px;color:rgba(0,0,0,0.45);">Thank you for your purchase &middot; شكراً لطلبك</p>
    </td></tr>

    {{-- Order number --}}
    <tr><td style="padding:0 32px 24px;text-align:center;">
        <table cellpadding="0" cellspacing="0" role="presentation" style="margin:0 auto;background:#f9f9f9;border-radius:6px;">
        <tr><td style="padding:12px 28px;text-align:center;">
            <div style="font-size:10px;color:rgba(0,0,0,0.4);text-transform:uppercase;letter-spacing:0.15em;">Order Number &middot; رقم الطلب</div>
            <div style="font-size:20px;font-weight:700;color:#111;padding-top:4px;font-family:Arial,sans-serif;">{{ $order->order_number }}</div>
        </td></tr>
        </table>
    </td></tr>

    {{-- Greeting --}}
    <tr><td style="padding:0 32px 8px;">
        <p style="margin:0;font-size:15px;color:#111;">Hello {{ $customerName ?: 'Valued Customer' }},</p>
        <p style="margin:6px 0 0;font-size:13px;color:rgba(0,0,0,0.55);line-height:1.7;">
            Your order has been received and is being processed. Our team will contact you shortly to confirm.<br>
            <span dir="rtl" style="display:inline-block;margin-top:4px;">تم استلام طلبك وجاري مراجعته. سيتواصل معك فريقنا قريباً لتأكيده.</span>
        </p>
    </td></tr>

    <tr><td style="padding:20px 32px 0;"><div style="border-top:1px solid rgba(0,0,0,0.06);"></div></td></tr>

    {{-- Items --}}
    <tr><td style="padding:20px 32px 4px;">
        <p style="font-size:10px;text-transform:uppercase;letter-spacing:0.2em;font-weight:700;color:#111;margin:0 0 16px;">Your Items &middot; مشترياتك</p>
        @foreach($order->order_item as $item)
        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-bottom:16px;">
        <tr>
            <td width="60" style="vertical-align:top;">
                @if($lineImg($item))
                <img src="{{ $lineImg($item) }}" alt="" width="56" height="56" style="border-radius:6px;background:#f5f5f5;object-fit:contain;display:block;">
                @endif
            </td>
            <td style="vertical-align:top;padding-left:12px;">
                <p style="margin:0;font-size:13px;font-weight:500;color:#111;font-family:Arial,sans-serif;">{{ $lineName($item) }}</p>
                <p style="margin:4px 0 0;font-size:11px;color:rgba(0,0,0,0.4);font-family:Arial,sans-serif;">Qty {{ $item->quantity }} &times; {{ number_format($item->piece_price) }} EGP</p>
            </td>
            <td style="vertical-align:top;text-align:right;white-space:nowrap;">
                <p style="margin:0;font-size:14px;font-weight:700;color:#111;font-family:Arial,sans-serif;">{{ number_format($item->total_price ?: $item->piece_price * $item->quantity) }} EGP</p>
            </td>
        </tr>
        </table>
        @endforeach
    </td></tr>

    <tr><td style="padding:0 32px;"><div style="border-top:1px solid rgba(0,0,0,0.06);"></div></td></tr>

    {{-- Totals --}}
    <tr><td style="padding:18px 32px;">
        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="font-family:Arial,sans-serif;">
        <tr>
            <td style="font-size:13px;color:rgba(0,0,0,0.55);padding:4px 0;">Subtotal</td>
            <td style="font-size:13px;color:#111;text-align:right;padding:4px 0;">{{ number_format($subtotal) }} EGP</td>
        </tr>
        <tr>
            <td style="font-size:13px;color:rgba(0,0,0,0.55);padding:4px 0;">Shipping{{ $cityEn ? ' · ' . $cityEn : '' }}</td>
            <td style="font-size:13px;color:#111;text-align:right;padding:4px 0;">{{ number_format($shippingCost) }} EGP</td>
        </tr>
        <tr><td colspan="2" style="border-top:1px solid rgba(0,0,0,0.08);padding-top:10px;"></td></tr>
        <tr>
            <td style="font-size:16px;font-weight:700;color:#111;padding:4px 0;">Total</td>
            <td style="font-size:20px;font-weight:800;color:#111;text-align:right;padding:4px 0;">{{ number_format($order->total_price_for_order) }} EGP</td>
        </tr>
        </table>
    </td></tr>

    <tr><td style="padding:0 32px;"><div style="border-top:1px solid rgba(0,0,0,0.06);"></div></td></tr>

    {{-- Shipping + payment --}}
    <tr><td style="padding:18px 32px;">
        <p style="font-size:10px;text-transform:uppercase;letter-spacing:0.2em;font-weight:700;color:#111;margin:0 0 8px;">Shipping To &middot; عنوان الشحن</p>
        <p style="font-size:13px;color:rgba(0,0,0,0.65);margin:0;line-height:1.6;font-family:Arial,sans-serif;">
            {{ optional($order->address)->address_line ?? '' }}<br>
            {{ $cityEn }}{{ $cityAr ? ' / ' . $cityAr : '' }}<br>
            @if($customerPhone) Phone: {{ $customerPhone }} @endif
        </p>
        <p style="font-size:10px;text-transform:uppercase;letter-spacing:0.2em;font-weight:700;color:#111;margin:18px 0 8px;">Payment &middot; طريقة الدفع</p>
        <p style="font-size:13px;color:rgba(0,0,0,0.65);margin:0;font-family:Arial,sans-serif;">{{ $paymentEn }} &middot; {{ $paymentAr }}</p>
    </td></tr>

    {{-- CTA --}}
    <tr><td style="padding:8px 32px 32px;text-align:center;">
        <a href="{{ $trackUrl }}" style="display:inline-block;background:#111;color:#fff;text-decoration:none;padding:14px 40px;border-radius:6px;font-size:12px;font-weight:700;letter-spacing:0.2em;text-transform:uppercase;font-family:Arial,sans-serif;">Track Your Order</a>
    </td></tr>

    {{-- Dark footer --}}
    <tr><td style="background:#0a0a0a;padding:24px 32px;text-align:center;font-family:Arial,sans-serif;">
        <p style="margin:0;font-size:11px;color:rgba(255,255,255,0.45);">Watchizer — Egypt's Premier Watch Destination</p>
        <p style="margin:8px 0 0;font-size:11px;color:rgba(255,255,255,0.3);">📞 01551096234 &nbsp;|&nbsp; <a href="mailto:Watchizer303@gmail.com" style="color:#C8A45C;text-decoration:none;">Watchizer303@gmail.com</a></p>
        <p style="margin:8px 0 0;"><a href="https://wa.me/201274550956" style="color:#C8A45C;text-decoration:none;font-size:11px;">WhatsApp Support</a></p>
    </td></tr>

</table>
</td></tr>
</table>

@endif

</body>
</html>
