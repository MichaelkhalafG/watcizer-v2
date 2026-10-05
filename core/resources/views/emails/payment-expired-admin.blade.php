<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <title>Unpaid card order — Watchizer</title>
</head>
{{-- The admins' immediate copy of a card order that expired unpaid (2026-10-05): someone can call
     the customer the same hour. Same layout and blocks as the new-order notification. --}}
<body style="margin:0;padding:0;background:#f5f5f5;-webkit-text-size-adjust:100%;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f5f5f5;padding:28px 14px;font-family:'Segoe UI',Tahoma,Arial,sans-serif;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" role="presentation" style="max-width:600px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;">

    @include('emails.partials.header')

    <tr><td style="padding:28px 32px 8px;text-align:center;">
        <span style="display:inline-block;background:#C8A45C;color:#111;font-size:10px;font-weight:700;letter-spacing:0.18em;text-transform:uppercase;padding:5px 14px;border-radius:20px;">Payment not completed</span>
        <h1 style="margin:14px 0 0;font-size:22px;font-weight:600;color:#111;">⏱ A card order expired unpaid</h1>
        <p style="margin:6px 0 0;font-size:13px;color:rgba(0,0,0,0.5);">#{{ $orderNumber }} &middot; placed {{ $createdAt }}</p>
        <p style="margin:12px 0 0;font-size:13px;color:#333;line-height:1.7;">
            The customer reached the payment page and didn't pay. The order was cancelled and its stock returned.<br>
            They have been e-mailed a link that brings the order back (valid 7 days). A call now is the best chance to recover it.
        </p>
        <p dir="rtl" style="margin:10px 0 0;font-size:13px;color:#333;line-height:1.8;">
            وصل العميل إلى صفحة الدفع ولم يُكمل الدفع. أُلغي الطلب وعاد المخزون.<br>
            أُرسل إلى العميل رابط يعيد الطلب (صالح ٧ أيام). الاتصال الآن هو أفضل فرصة لاستعادته.
        </p>
    </td></tr>

    {{-- Customer: how to reach them --}}
    <tr><td style="padding:18px 32px 0;">
        <p style="font-size:10px;text-transform:uppercase;letter-spacing:0.2em;font-weight:700;color:#888;margin:0 0 8px;border-bottom:2px solid #eee;padding-bottom:5px;">Customer</p>
        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f8f8f8;border-radius:8px;">
            <tr><td style="padding:12px 16px;font-size:13px;color:#333;">
                <p style="margin:4px 0;"><strong style="color:#111;">Name:</strong> {{ $customerName }}</p>
                <p style="margin:4px 0;"><strong style="color:#111;">Phone:</strong> {{ $customerPhone ?: '—' }}</p>
                <p style="margin:4px 0;"><strong style="color:#111;">Email:</strong> {{ $customerEmail ?: '—' }}</p>
                <p style="margin:4px 0;"><strong style="color:#111;">City:</strong> {{ $cityEn ?: '—' }}{{ $cityAr ? ' / ' . $cityAr : '' }}</p>
                <p style="margin:4px 0;"><strong style="color:#111;">Type:</strong> {{ $isGuest ? 'Guest' : 'Registered User' }}</p>
            </td></tr>
        </table>
        @if($telUrl)
        <p style="margin:12px 0 0;text-align:center;">
            <a href="{{ $telUrl }}" style="display:inline-block;background:#111;color:#fff;text-decoration:none;padding:11px 26px;border-radius:6px;font-size:12px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;margin:0 4px 6px;">Call</a>
            <a href="{{ $waUrl }}" style="display:inline-block;background:#25D366;color:#fff;text-decoration:none;padding:11px 26px;border-radius:6px;font-size:12px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;margin:0 4px 6px;">WhatsApp</a>
        </p>
        @endif
    </td></tr>

    {{-- What they ordered --}}
    <tr><td style="padding:18px 32px 0;">
        <p style="font-size:10px;text-transform:uppercase;letter-spacing:0.2em;font-weight:700;color:#888;margin:0 0 8px;border-bottom:2px solid #eee;padding-bottom:5px;">Products</p>
        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse:collapse;">
            <thead>
                <tr>
                    <th style="background:#111;color:#fff;padding:9px 10px;font-size:11px;text-align:left;">Photo</th>
                    <th style="background:#111;color:#fff;padding:9px 10px;font-size:11px;text-align:left;">Product</th>
                    <th style="background:#111;color:#fff;padding:9px 10px;font-size:11px;text-align:center;">Qty</th>
                    <th style="background:#111;color:#fff;padding:9px 10px;font-size:11px;text-align:right;">Unit</th>
                    <th style="background:#111;color:#fff;padding:9px 10px;font-size:11px;text-align:right;">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($items as $item)
                    @include('emails.partials.product-row', ['item' => $item, 'mode' => 'admin', 'decimals' => $moneyDecimals])
                @endforeach
            </tbody>
        </table>
        <p style="margin:10px 0 0;font-size:15px;font-weight:700;color:#111;text-align:right;">Total: {{ number_format($total, $moneyDecimals) }} EGP</p>
    </td></tr>

    <tr><td style="padding:22px 32px 30px;text-align:center;">
        <a href="{{ $dashboardUrl }}" style="display:inline-block;background:#C8A45C;color:#111;text-decoration:none;padding:13px 34px;border-radius:6px;font-size:12px;font-weight:700;letter-spacing:0.16em;text-transform:uppercase;">Open Order</a>
    </td></tr>

    @include('emails.partials.footer')

</table>
</td></tr>
</table>
</body>
</html>
