<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <title>Payment needs attention — {{ $brandName }}</title>
</head>
{{-- The shop's alert that a customer was CHARGED and the order does not show it (2026-11-05, order
     000092). Same layout and blocks as the unpaid-order copy. One sentence per situation, in both
     languages, and what to do — the reader is the person who has to act today.
     $finding: callback_on_terminal_order | amount_mismatch | late_payment_with_newer_order
     $refused: why the order could not be brought back (insufficient_stock, cancelled_by_operator, …) or null --}}
<body style="margin:0;padding:0;background:#f5f5f5;-webkit-text-size-adjust:100%;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f5f5f5;padding:28px 14px;font-family:'Segoe UI',Tahoma,Arial,sans-serif;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" role="presentation" style="max-width:600px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;">

    @include('emails.partials.header')

    <tr><td style="padding:28px 32px 8px;text-align:center;">
        <span style="display:inline-block;background:#B42318;color:#fff;font-size:10px;font-weight:700;letter-spacing:0.18em;text-transform:uppercase;padding:5px 14px;border-radius:20px;">Payment needs attention</span>
        <h1 style="margin:14px 0 0;font-size:22px;font-weight:600;color:#111;">
            @if($finding === 'late_payment_with_newer_order')
                A late payment brought this order back — and the customer has a newer order
            @elseif($finding === 'amount_mismatch')
                A payment arrived with a different amount
            @else
                The customer was charged, and this order is {{ strtolower($statusEn) }}
            @endif
        </h1>
        <p style="margin:6px 0 0;font-size:13px;color:rgba(0,0,0,0.5);">#{{ $orderNumber }} &middot; placed {{ $createdAt }}@if($transaction) &middot; transaction {{ $transaction }}@endif</p>

        <p style="margin:12px 0 0;font-size:13px;color:#333;line-height:1.7;">
            @if($finding === 'late_payment_with_newer_order')
                The payment succeeded after the order had been cancelled. Its stock was reserved again and the order is now paid.
                The same customer has since placed {{ implode(', ', array_map(fn ($n) => '#'.$n, $newerOrders)) }}: check that they are not about to receive, or pay for, the same items twice.
            @elseif($finding === 'amount_mismatch')
                The provider reports a successful payment whose amount does not equal this order's total. The order was left untouched. Compare the transaction in the provider's portal with the order, then confirm or refund.
            @elseif($refused === 'insufficient_stock')
                The payment succeeded after the order had been cancelled, and the stock is no longer available, so the order could NOT be brought back. Source the item or refund the customer — and call them today.
            @elseif($refused === 'no_longer_sellable')
                The payment succeeded after the order had been cancelled, and a product on it has been withdrawn from sale since, so the order could NOT be brought back. Decide with the customer, then refund or fulfil by hand.
            @elseif($refused === 'cancelled_by_operator')
                The payment succeeded on an order that was cancelled from the dashboard. Nothing was changed. Refund the customer, or bring the order back if the cancellation was a mistake.
            @else
                The provider reports a successful payment for an order that had already finished or been cancelled. Nothing was changed. Check the transaction in the provider's portal and refund or fulfil.
            @endif
        </p>
        <p dir="rtl" style="margin:10px 0 0;font-size:13px;color:#333;line-height:1.8;">
            @if($finding === 'late_payment_with_newer_order')
                نجح الدفع بعد إلغاء الطلب. أُعيد حجز المخزون وأصبح الطلب مدفوعًا.
                لدى العميل نفسه طلب أحدث ({{ implode('، ', array_map(fn ($n) => '#'.$n, $newerOrders)) }}): تأكد أنه لن يستلم أو يدفع ثمن المنتجات نفسها مرتين.
            @elseif($finding === 'amount_mismatch')
                أبلغ مزود الدفع عن دفعة ناجحة بمبلغ لا يساوي إجمالي هذا الطلب. لم يتغير الطلب. قارن العملية في لوحة مزود الدفع بالطلب، ثم أكّد أو استرجع المبلغ.
            @elseif($refused === 'insufficient_stock')
                نجح الدفع بعد إلغاء الطلب، والمخزون لم يعد متاحًا، لذلك لم يُعَد الطلب. وفّر المنتج أو استرجع المبلغ للعميل — واتصل به اليوم.
            @elseif($refused === 'no_longer_sellable')
                نجح الدفع بعد إلغاء الطلب، وأحد منتجاته أُوقف بيعه منذ ذلك الحين، لذلك لم يُعَد الطلب. اتفق مع العميل ثم استرجع المبلغ أو نفّذ الطلب يدويًا.
            @elseif($refused === 'cancelled_by_operator')
                نجح الدفع على طلب أُلغي من لوحة التحكم. لم يتغير شيء. استرجع المبلغ للعميل، أو أعد الطلب إن كان الإلغاء خطأ.
            @else
                أبلغ مزود الدفع عن دفعة ناجحة لطلب منتهٍ أو ملغي. لم يتغير شيء. راجع العملية في لوحة مزود الدفع ثم استرجع المبلغ أو نفّذ الطلب.
            @endif
        </p>
    </td></tr>

    <tr><td style="padding:18px 32px 0;">
        <p style="font-size:10px;text-transform:uppercase;letter-spacing:0.2em;font-weight:700;color:#888;margin:0 0 8px;border-bottom:2px solid #eee;padding-bottom:5px;">Customer</p>
        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f8f8f8;border-radius:8px;">
            <tr><td style="padding:12px 16px;font-size:13px;color:#333;">
                <p style="margin:4px 0;"><strong style="color:#111;">Name:</strong> {{ $customerName }}</p>
                <p style="margin:4px 0;"><strong style="color:#111;">Phone:</strong> {{ $customerPhone ?: '—' }}</p>
                <p style="margin:4px 0;"><strong style="color:#111;">Email:</strong> {{ $customerEmail ?: '—' }}</p>
                <p style="margin:4px 0;"><strong style="color:#111;">City:</strong> {{ $cityEn ?: '—' }}{{ $cityAr ? ' / ' . $cityAr : '' }}</p>
            </td></tr>
        </table>
        @if($telUrl)
        <p style="margin:12px 0 0;text-align:center;">
            <a href="{{ $telUrl }}" style="display:inline-block;background:#111;color:#fff;text-decoration:none;padding:11px 26px;border-radius:6px;font-size:12px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;margin:0 4px 6px;">Call</a>
            <a href="{{ $waUrl }}" style="display:inline-block;background:#25D366;color:#fff;text-decoration:none;padding:11px 26px;border-radius:6px;font-size:12px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;margin:0 4px 6px;">WhatsApp</a>
        </p>
        @endif
    </td></tr>

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
        <a href="{{ $dashboardUrl }}" style="display:inline-block;background:#B42318;color:#fff;text-decoration:none;padding:13px 34px;border-radius:6px;font-size:12px;font-weight:700;letter-spacing:0.16em;text-transform:uppercase;">Open Order</a>
    </td></tr>

    @include('emails.partials.footer')

</table>
</td></tr>
</table>
</body>
</html>
