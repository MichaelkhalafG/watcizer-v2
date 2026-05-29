<!DOCTYPE html>
<html lang="{{ isset($type) && $type === 'admin' ? 'en' : 'ar' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Watchizer Order</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background-color: #f5f5f5; }
        .container { max-width: 650px; margin: auto; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }

        /* ── Header ── */
        .header { padding: 24px; text-align: center; }
        .header-customer { background: #262626; }
        .header-admin    { background: #b71c1c; }
        .header img { width: 75px; }
        .header h1  { color: #fff; font-size: 20px; margin: 10px 0 0; }
        .header p   { color: rgba(255,255,255,0.8); font-size: 13px; margin: 4px 0 0; }

        /* ── Body ── */
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

        /* ── Table ── */
        table { width:100%; border-collapse:collapse; margin:12px 0 20px; }
        th { background:#262626; color:#fff; padding:10px; font-size:13px; }
        td { padding:10px; border-bottom:1px solid #eee; font-size:13px; color:#333; vertical-align:middle; }
        td img { width:45px; height:auto; border-radius:4px; }
        .color-dot { display:inline-block; width:16px; height:16px; border-radius:50%; border:1px solid #ccc; vertical-align:middle; }

        /* ── Totals ── */
        .totals-box { background:#f8f8f8; border-radius:8px; padding:14px 16px; margin-bottom:20px; }
        .totals-box p { margin:5px 0; font-size:14px; color:#555; }
        .totals-box .grand { font-size:18px; font-weight:bold; color:#262626; margin-top:8px; }

        /* ── Divider between AR and EN ── */
        .lang-divider { border:none; border-top:3px dashed #ddd; margin:30px 0; }

        /* ── RTL section ── */
        .rtl { direction:rtl; text-align:right; }
        .ltr { direction:ltr; text-align:left; }

        /* ── Footer ── */
        .footer { background:#f8f8f8; padding:18px 24px; text-align:center; font-size:13px; color:#777; border-top:1px solid #eee; }
        .footer a { color:#262626; font-weight:bold; text-decoration:none; }

        @media(max-width:600px){
            th,td { font-size:11px; padding:7px; }
            .body { padding:16px; }
        }
    </style>
</head>
<body>
<div class="container">

@php
    // ── Customer info ─────────────────────────────────────────────────────────
    $customerName = $order->user
        ? trim(($order->user->first_name ?? '') . ' ' . ($order->user->last_name ?? ''))
        : ($order->guest_name ?? null);

    $customerEmail = $order->user
        ? $order->user->email
        : ($order->guest_email ?? null);

    // ── City names — مؤمَّن ضد null ──────────────────────────────────────────
    $shippingCity = optional(optional($order->address)->shipping_city);

    try {
        $cityAr = $shippingCity->translate('ar')->city_name ?? $shippingCity->city_name ?? '';
    } catch (\Exception $e) {
        $cityAr = $shippingCity->city_name ?? '';
    }

    try {
        $cityEn = $shippingCity->translate('en')->city_name ?? $shippingCity->city_name ?? '';
    } catch (\Exception $e) {
        $cityEn = $shippingCity->city_name ?? '';
    }

    // ── Costs ─────────────────────────────────────────────────────────────────
    $shippingCost = $shippingCity->shipping_cost ?? 0;
    $subtotal     = $order->total_price_for_order - $shippingCost;

    // ── Payment labels ────────────────────────────────────────────────────────
    $paymentAr = match($order->payment_method) {
        'cash'     => 'الدفع عند الاستلام',
        'paymob'   => 'بطاقة / محفظة إلكترونية',
        'whatsapp' => 'واتساب',
        default    => $order->payment_method,
    };
    $paymentEn = match($order->payment_method) {
        'cash'     => 'Cash on Delivery',
        'paymob'   => 'Card / E-Wallet (Paymob)',
        'whatsapp' => 'WhatsApp Order',
        default    => $order->payment_method,
    };
@endphp

{{-- ════════════════════════════════════════════════════════
     ADMIN EMAIL
     ════════════════════════════════════════════════════════ --}}
@if(isset($type) && $type === 'admin')

    <div class="header header-admin">
        <img src="https://dash.watchizereg.com/DashAssets/img/logo.webp" alt="Watchizer">
        <h1>🔔 New Order Received!</h1>
        <p>Order #{{ $order->order_number }} — {{ $order->created_at->format('d M Y, H:i') }}</p>
    </div>

    <div class="body ltr">

        <div class="section-title">Order Information</div>
        <div class="info-box">
            <p><strong>Order #:</strong> {{ $order->order_number }}</p>
            <p><strong>Date:</strong> {{ $order->created_at->format('d-m-Y H:i') }}</p>
            <p><strong>Payment:</strong> {{ $paymentEn }}</p>
            <p><strong>Status:</strong>
                <span class="badge {{ $order->status === 'processing' ? 'badge-processing' : 'badge-pending' }}">
                    {{ ucfirst($order->status) }}
                </span>
            </p>
        </div>

        <div class="section-title">Customer Information</div>
        <div class="info-box">
            <p><strong>Name:</strong> {{ $customerName ?: 'Guest' }}</p>
            <p><strong>Phone:</strong> {{ optional($order->address)->phone_number_one ?? '—' }}</p>
            <p><strong>Email:</strong> {{ $customerEmail ?? '—' }}</p>
            <p><strong>Type:</strong> {{ $order->user ? 'Registered User' : 'Guest' }}</p>
        </div>

        <div class="section-title">Shipping Address</div>
        <div class="info-box">
            <p><strong>Address:</strong> {{ optional($order->address)->address_line ?? '—' }}</p>
            <p><strong>City:</strong> {{ $cityEn ?: '—' }}</p>
            <p><strong>Shipping Cost:</strong> {{ $shippingCost }} EGP</p>
        </div>

        <div class="section-title">Ordered Products</div>
        <table>
            <thead>
                <tr>
                    <th style="text-align:left">Photo</th>
                    <th style="text-align:left">Product</th>
                    <th style="text-align:left">Code</th>
                    <th style="text-align:left">Type</th>
                    <th style="text-align:left">Dial</th>
                    <th style="text-align:left">Band</th>
                    <th style="text-align:left">Qty</th>
                    <th style="text-align:left">Price</th>
                    <th style="text-align:left">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($order->order_item as $item)
                <tr>
                    @if($item->product)
                        <td><img src="https://dash.watchizereg.com/Uploads_Images/Product/{{ $item->product->image }}"></td>
                        <td>{{ optional($item->product->translate('en'))->product_title ?? optional($item->product->translate('ar'))->product_title ?? 'N/A' }}</td>
                        <td>{{ $item->product->wa_code ?? '—' }}</td>
                    @elseif($item->offer)
                        <td><img src="https://dash.watchizereg.com/Uploads_Images/Offer/{{ $item->offer->image }}"></td>
                        <td>{{ optional($item->offer->translate('en'))->offer_name ?? optional($item->offer->translate('ar'))->offer_name ?? 'N/A' }}</td>
                        <td>{{ $item->offer->wa_code ?? '—' }}</td>
                    @else
                        <td>—</td><td>—</td><td>—</td>
                    @endif
                    <td>{{ $item->type_stock ?? '—' }}</td>
                    <td>
                        @if($item->color_dial)
                            <span class="color-dot" style="background:{{ $item->color_dial }}"></span>
                        @else
                            —
                        @endif
                    </td>
                    <td>
                        @if($item->color_band)
                            <span class="color-dot" style="background:{{ $item->color_band }}"></span>
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ $item->quantity }}</td>
                    <td>{{ $item->piece_price * 1 }} EGP</td>
                    <td>{{ $item->total_price * 1 }} EGP</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <div class="totals-box">
            <p>Subtotal: {{ $subtotal }} EGP</p>
            <p>Shipping: {{ $shippingCost }} EGP</p>
            <p class="grand">Grand Total: {{ $order->total_price_for_order }} EGP</p>
        </div>

    </div>

    <div class="footer">
        Please process this order from the <strong>Watchizer Dashboard</strong>.
    </div>


{{-- ════════════════════════════════════════════════════════
     CUSTOMER EMAIL (Arabic + English)
     ════════════════════════════════════════════════════════ --}}
@else

    {{-- ── Arabic Section ── --}}
    <div class="header header-customer">
        <img src="https://dash.watchizereg.com/DashAssets/img/logo.webp" alt="Watchizer">
        <h1>تأكيد طلبك من Watchizer 🛍️</h1>
    </div>

    <div class="body rtl">

        <p style="font-size:17px;font-weight:bold;color:#262626;margin-bottom:6px;">
            مرحباً {{ $customerName ?: 'عزيزنا العميل' }} 👋
        </p>
        <p style="color:#555;line-height:1.7;margin-bottom:20px;">
            شكراً لطلبك من Watchizer.<br>
            تم استلام طلبك بنجاح وجاري مراجعته الآن. سيتواصل معك فريقنا قريباً لتأكيد الطلب.
        </p>

        <div class="info-box">
            <p><strong>رقم الطلب:</strong> #{{ $order->order_number }}</p>
            <p><strong>تاريخ الطلب:</strong> {{ $order->created_at->format('d-m-Y') }}</p>
            <p><strong>طريقة الدفع:</strong> {{ $paymentAr }}</p>
            <p><strong>حالة الطلب:</strong> <span class="badge badge-processing">قيد المعالجة ⏳</span></p>
        </div>

        <div class="info-box">
            <p><strong>عنوان الشحن:</strong> {{ optional($order->address)->address_line ?? '—' }}</p>
            <p><strong>المحافظة:</strong> {{ $cityAr ?: '—' }}</p>
            <p><strong>رقم الهاتف:</strong> {{ optional($order->address)->phone_number_one ?? '—' }}</p>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="text-align:right">الصورة</th>
                    <th style="text-align:right">المنتج</th>
                    <th style="text-align:right">الكمية</th>
                    <th style="text-align:right">السعر</th>
                    <th style="text-align:right">الإجمالي</th>
                </tr>
            </thead>
            <tbody>
                @foreach($order->order_item as $item)
                <tr>
                    @if($item->product)
                        <td><img src="https://dash.watchizereg.com/Uploads_Images/Product/{{ $item->product->image }}"></td>
                        <td>{{ optional($item->product->translate('ar'))->product_title ?? 'N/A' }}</td>
                    @elseif($item->offer)
                        <td><img src="https://dash.watchizereg.com/Uploads_Images/Offer/{{ $item->offer->image }}"></td>
                        <td>{{ optional($item->offer->translate('ar'))->offer_name ?? 'N/A' }}</td>
                    @else
                        <td>—</td><td>—</td>
                    @endif
                    <td>{{ $item->quantity }}</td>
                    <td>{{ $item->piece_price * 1 }} ج.م</td>
                    <td>{{ $item->total_price * 1 }} ج.م</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <div class="totals-box">
            <p>الشحن: {{ $shippingCost }} ج.م</p>
            <p class="grand">الإجمالي الكلي: {{ $order->total_price_for_order }} ج.م</p>
        </div>

    </div>

    {{-- ── Divider ── --}}
    <hr class="lang-divider">

    {{-- ── English Section ── --}}
    <div class="body ltr">

        <p style="font-size:17px;font-weight:bold;color:#262626;margin-bottom:6px;">
            Hello {{ $customerName ?: 'Valued Customer' }} 👋
        </p>
        <p style="color:#555;line-height:1.7;margin-bottom:20px;">
            Thank you for shopping with Watchizer.<br>
            Your order has been successfully received and is currently being processed. Our team will contact you shortly.
        </p>

        <div class="info-box">
            <p><strong>Order Number:</strong> #{{ $order->order_number }}</p>
            <p><strong>Order Date:</strong> {{ $order->created_at->format('d-m-Y') }}</p>
            <p><strong>Payment Method:</strong> {{ $paymentEn }}</p>
            <p><strong>Order Status:</strong> <span class="badge badge-processing">Processing ⏳</span></p>
        </div>

        <div class="info-box">
            <p><strong>Shipping Address:</strong> {{ optional($order->address)->address_line ?? '—' }}</p>
            <p><strong>City:</strong> {{ $cityEn ?: '—' }}</p>
            <p><strong>Phone:</strong> {{ optional($order->address)->phone_number_one ?? '—' }}</p>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="text-align:left">Photo</th>
                    <th style="text-align:left">Product</th>
                    <th style="text-align:left">Qty</th>
                    <th style="text-align:left">Unit Price</th>
                    <th style="text-align:left">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($order->order_item as $item)
                <tr>
                    @if($item->product)
                        <td><img src="https://dash.watchizereg.com/Uploads_Images/Product/{{ $item->product->image }}"></td>
                        <td>{{ optional($item->product->translate('en'))->product_title ?? optional($item->product->translate('ar'))->product_title ?? 'N/A' }}</td>
                    @elseif($item->offer)
                        <td><img src="https://dash.watchizereg.com/Uploads_Images/Offer/{{ $item->offer->image }}"></td>
                        <td>{{ optional($item->offer->translate('en'))->offer_name ?? optional($item->offer->translate('ar'))->offer_name ?? 'N/A' }}</td>
                    @else
                        <td>—</td><td>—</td>
                    @endif
                    <td>{{ $item->quantity }}</td>
                    <td>{{ $item->piece_price * 1 }} EGP</td>
                    <td>{{ $item->total_price * 1 }} EGP</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <div class="totals-box">
            <p>Shipping: {{ $shippingCost }} EGP</p>
            <p class="grand">Grand Total: {{ $order->total_price_for_order }} EGP</p>
        </div>

    </div>

    <div class="footer">
        <p>شكراً لثقتك بنا 💙 — Thank you for choosing Watchizer 💙</p>
        <p>📞 01551096234 &nbsp;|&nbsp; 📧 <a href="mailto:Watchizer303@gmail.com">Watchizer303@gmail.com</a></p>
        <p>🔗 <a href="https://watchizereg.com">watchizereg.com</a></p>
    </div>

@endif

</div>
</body>
</html>