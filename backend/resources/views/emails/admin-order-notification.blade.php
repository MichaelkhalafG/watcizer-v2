<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <title>New Order — Watchizer</title>
</head>
<body style="margin:0;padding:0;background:#f5f5f5;-webkit-text-size-adjust:100%;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f5f5f5;padding:28px 14px;font-family:'Segoe UI',Tahoma,Arial,sans-serif;">
<tr><td align="center">

<table width="600" cellpadding="0" cellspacing="0" role="presentation" style="max-width:600px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;">

    @include('emails.partials.header')

    {{-- Banner --}}
    <tr><td style="padding:28px 32px 8px;text-align:center;">
        <span style="display:inline-block;background:#C8A45C;color:#111;font-size:10px;font-weight:700;letter-spacing:0.18em;text-transform:uppercase;padding:5px 14px;border-radius:20px;">New Order</span>
        <h1 style="margin:14px 0 0;font-size:22px;font-weight:600;color:#111;">🛒 New Order Received</h1>
        <p style="margin:6px 0 0;font-size:13px;color:rgba(0,0,0,0.5);">#{{ $orderNumber }} &middot; {{ $createdAt }}</p>
    </td></tr>

    {{-- Order info --}}
    <tr><td style="padding:18px 32px 0;">
        <p style="font-size:10px;text-transform:uppercase;letter-spacing:0.2em;font-weight:700;color:#888;margin:0 0 8px;border-bottom:2px solid #eee;padding-bottom:5px;">Order Information</p>
        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f8f8f8;border-radius:8px;">
            <tr><td style="padding:12px 16px;font-size:13px;color:#333;">
                <p style="margin:4px 0;"><strong style="color:#111;">Order #:</strong> {{ $orderNumber }}</p>
                <p style="margin:4px 0;"><strong style="color:#111;">Date:</strong> {{ $createdAt }}</p>
                <p style="margin:4px 0;"><strong style="color:#111;">Payment:</strong> {{ $paymentEn }}</p>
                <p style="margin:4px 0;"><strong style="color:#111;">Payment status:</strong> {{ $paymentStatus }}</p>
                <p style="margin:4px 0;"><strong style="color:#111;">Order status:</strong> {{ $statusEn }}</p>
            </td></tr>
        </table>
    </td></tr>

    {{-- Customer --}}
    <tr><td style="padding:18px 32px 0;">
        <p style="font-size:10px;text-transform:uppercase;letter-spacing:0.2em;font-weight:700;color:#888;margin:0 0 8px;border-bottom:2px solid #eee;padding-bottom:5px;">Customer</p>
        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f8f8f8;border-radius:8px;">
            <tr><td style="padding:12px 16px;font-size:13px;color:#333;">
                <p style="margin:4px 0;"><strong style="color:#111;">Name:</strong> {{ $customerName }}</p>
                <p style="margin:4px 0;"><strong style="color:#111;">Email:</strong> {{ $customerEmail ?: '—' }}</p>
                <p style="margin:4px 0;"><strong style="color:#111;">Phone:</strong> {{ $customerPhone ?: '—' }}</p>
                <p style="margin:4px 0;"><strong style="color:#111;">Type:</strong> {{ $isGuest ? 'Guest' : 'Registered User' }}</p>
            </td></tr>
        </table>
    </td></tr>

    {{-- Shipping --}}
    <tr><td style="padding:18px 32px 0;">
        <p style="font-size:10px;text-transform:uppercase;letter-spacing:0.2em;font-weight:700;color:#888;margin:0 0 8px;border-bottom:2px solid #eee;padding-bottom:5px;">Shipping Address</p>
        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f8f8f8;border-radius:8px;">
            <tr><td style="padding:12px 16px;font-size:13px;color:#333;">
                <p style="margin:4px 0;"><strong style="color:#111;">Address:</strong> {{ $addressLine ?: '—' }}</p>
                <p style="margin:4px 0;"><strong style="color:#111;">City:</strong> {{ $cityEn ?: '—' }}{{ $cityAr ? ' / ' . $cityAr : '' }}</p>
                <p style="margin:4px 0;"><strong style="color:#111;">Phone:</strong> {{ $customerPhone ?: '—' }}</p>
                <p style="margin:4px 0;"><strong style="color:#111;">Shipping cost:</strong> {{ number_format($shippingCost) }} EGP</p>
            </td></tr>
        </table>
    </td></tr>

    {{-- Products --}}
    <tr><td style="padding:18px 32px 0;">
        <p style="font-size:10px;text-transform:uppercase;letter-spacing:0.2em;font-weight:700;color:#888;margin:0 0 8px;border-bottom:2px solid #eee;padding-bottom:5px;">Ordered Products</p>
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
                    @include('emails.partials.product-row', ['item' => $item, 'mode' => 'admin'])
                @endforeach
            </tbody>
        </table>
    </td></tr>

    {{-- Totals --}}
    <tr><td style="padding:18px 32px 0;">
        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f8f8f8;border-radius:8px;">
            <tr><td style="padding:14px 16px;">
                <table width="100%" cellpadding="0" cellspacing="0" role="presentation">
                    <tr>
                        <td style="font-size:13px;color:#555;padding:3px 0;">Subtotal</td>
                        <td style="font-size:13px;color:#111;text-align:right;padding:3px 0;">{{ number_format($subtotal) }} EGP</td>
                    </tr>
                    @if($discount > 0)
                    <tr>
                        <td style="font-size:13px;color:#16a34a;padding:3px 0;">Discount</td>
                        <td style="font-size:13px;color:#16a34a;text-align:right;padding:3px 0;">- {{ number_format($discount) }} EGP</td>
                    </tr>
                    @endif
                    <tr>
                        <td style="font-size:13px;color:#555;padding:3px 0;">Shipping</td>
                        <td style="font-size:13px;color:#111;text-align:right;padding:3px 0;">{{ number_format($shippingCost) }} EGP</td>
                    </tr>
                    <tr><td colspan="2" style="border-top:1px solid rgba(0,0,0,0.1);padding-top:8px;font-size:0;line-height:0;">&nbsp;</td></tr>
                    <tr>
                        <td style="font-size:16px;font-weight:700;color:#111;padding:3px 0;">Grand Total</td>
                        <td style="font-size:18px;font-weight:800;color:#111;text-align:right;padding:3px 0;">{{ number_format($total) }} EGP</td>
                    </tr>
                </table>
            </td></tr>
        </table>
    </td></tr>

    @if($note)
    {{-- Notes --}}
    <tr><td style="padding:18px 32px 0;">
        <p style="font-size:10px;text-transform:uppercase;letter-spacing:0.2em;font-weight:700;color:#888;margin:0 0 8px;border-bottom:2px solid #eee;padding-bottom:5px;">Order Notes</p>
        <p style="font-size:13px;color:#333;margin:0;background:#fff8e8;border-radius:8px;padding:12px 16px;">{{ $note }}</p>
    </td></tr>
    @endif

    {{-- CTA --}}
    <tr><td style="padding:22px 32px 30px;text-align:center;">
        <a href="{{ $dashboardUrl }}" style="display:inline-block;background:#C8A45C;color:#111;text-decoration:none;padding:14px 40px;border-radius:6px;font-size:12px;font-weight:700;letter-spacing:0.18em;text-transform:uppercase;">Open Order in Dashboard</a>
    </td></tr>

    @include('emails.partials.footer')

</table>
</td></tr>
</table>
</body>
</html>
