<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Pickup slip #{{ $number }}</title>
    {{-- Click & collect (2026-11-14): the slip the store keeps with the parcel. Printed from the order page; A5 or A4. --}}
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 24px; background: #f4f4f5; color: #111; font-family: 'Segoe UI', Tahoma, Arial, sans-serif; font-size: 13px; }
        .slip { max-width: 720px; margin: 0 auto; background: #fff; border: 1px solid #d4d4d8; border-radius: 8px; padding: 24px; }
        .row { display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
        h1 { font-size: 20px; margin: 0; }
        .muted { color: #52525b; }
        .code { font-size: 34px; font-weight: 800; letter-spacing: 0.3em; direction: ltr; unicode-bidi: isolate; }
        .box { border: 2px solid #111; border-radius: 6px; padding: 10px 16px; text-align: center; }
        table { width: 100%; border-collapse: collapse; margin-top: 14px; }
        th, td { border-bottom: 1px solid #e4e4e7; padding: 7px 4px; text-align: start; vertical-align: top; }
        th { font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; color: #52525b; }
        .num { direction: ltr; unicode-bidi: isolate; white-space: nowrap; }
        .section { margin-top: 18px; }
        .sign { margin-top: 28px; display: flex; gap: 24px; }
        .sign div { flex: 1; border-top: 1px solid #111; padding-top: 6px; font-size: 11px; color: #52525b; }
        .actions { max-width: 720px; margin: 0 auto 12px; display: flex; justify-content: flex-end; }
        .actions button { font: inherit; padding: 8px 18px; border-radius: 6px; border: 1px solid #111; background: #111; color: #fff; cursor: pointer; }
        @media print {
            body { background: #fff; padding: 0; }
            .slip { border: 0; border-radius: 0; max-width: none; padding: 0; }
            .actions { display: none; }
        }
    </style>
</head>
<body>
<div class="actions"><button type="button" onclick="window.print()">طباعة · Print</button></div>
<div class="slip">
    <div class="row">
        <div>
            <h1>إيصال استلام من الفرع <span class="muted">· Pickup slip</span></h1>
            <div class="muted">{{ $storefront }} · {{ $pickup['store']['name']['ar'] }} — {{ $pickup['store']['name']['en'] }}</div>
        </div>
        <div class="box">
            <div class="muted">رقم الطلب · Order</div>
            <div style="font-size:20px;font-weight:700;" class="num">#{{ $number }}</div>
        </div>
    </div>

    <div class="row section">
        <div>
            <div class="muted">العميل · Customer</div>
            <div style="font-size:15px;font-weight:600;"><bdi>{{ $customer['name'] ?? '—' }}</bdi></div>
            <div><span class="num">{{ $customer['phone'] ?? '—' }}</span></div>
            <div class="muted" style="margin-top:6px;">تحقق من آخر أرقام هاتف العميل قبل التسليم · Check the last digits of the customer's phone</div>
        </div>
        <div class="box">
            <div class="muted">كود الاستلام · Pickup code</div>
            <div class="code">{{ $pickup['code'] }}</div>
        </div>
    </div>

    <div class="row section">
        <div><span class="muted"><bdi>الحالة · Status</bdi>:</span> {{ $status }}</div>
        <div><span class="muted"><bdi>تاريخ الطلب · Placed</bdi>:</span> <span class="num">{{ $placed }}</span></div>
        @if($readyAt)<div><span class="muted"><bdi>جاهز منذ · Ready</bdi>:</span> <span class="num">{{ $readyAt }}</span></div>@endif
        @if($collectBy)<div><span class="muted"><bdi>يُستلم قبل · Collect by</bdi>:</span> <span class="num">{{ $collectBy }}</span></div>@endif
    </div>

    <table>
        <thead>
            <tr><th>المنتج · Item</th><th>الكمية · Qty</th><th>الإجمالي · Total</th></tr>
        </thead>
        <tbody>
        @foreach($items as $item)
            <tr>
                <td><bdi>{{ $item['title'] }}</bdi>@if($item['variant'])<div class="muted"><bdi>{{ $item['variant'] }}</bdi></div>@endif</td>
                <td class="num">{{ $item['quantity'] }}</td>
                <td class="num">{{ $item['total'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="row section" style="font-size:16px;font-weight:700;">
        @if($pickup['pay_at_store'])
        <div>المطلوب تحصيله في الفرع · To collect at the store</div>
        @else
        <div>مدفوع أونلاين · Paid online <span class="muted" style="font-size:13px;font-weight:400;">— لا يُحصَّل شيء · nothing to collect</span></div>
        @endif
        <div class="num">{{ $totals['total'] }} EGP</div>
    </div>

    @if($note)
    <div class="section"><span class="muted"><bdi>ملاحظة العميل · Note</bdi>:</span> <bdi>{{ $note }}</bdi></div>
    @endif

    <div class="sign">
        <div>توقيع العميل · Customer signature</div>
        <div>الموظف · Staff</div>
        <div>التاريخ · Date</div>
    </div>
    <div class="muted" style="margin-top:14px;font-size:11px;"><bdi>طُبع · Printed</bdi> <span class="num">{{ $printed }}</span></div>
</div>
</body>
</html>
