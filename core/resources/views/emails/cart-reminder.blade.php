<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <title>{{ $brandName }}</title>
</head>
{{-- The abandoned-cart e-mail (2026-11-24): the cart's products at today's price, a button back to the cart, the
     customer's own code with its terms said plainly - including that it does not apply to items already on offer - and
     a one-click unsubscribe. Arabic first with English beneath, like the order e-mails. --}}
<body style="margin:0;padding:0;background:#f5f5f5;-webkit-text-size-adjust:100%;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f5f5f5;padding:28px 14px;font-family:'Segoe UI',Tahoma,Arial,sans-serif;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" role="presentation" style="max-width:600px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;">

    @include('emails.partials.header')

    <tr><td style="padding:32px 32px 8px;text-align:center;">
        <h1 style="margin:0;font-size:22px;font-weight:600;color:#111;">تركت شيئًا في سلتك</h1>
        <p style="margin:6px 0 0;font-size:15px;color:#111;" dir="ltr">You left something in your cart</p>
        <p style="margin:10px 0 0;font-size:13px;color:rgba(0,0,0,0.55);">منتجاتك ما زالت في انتظارك · <span dir="ltr">Your picks are still waiting</span></p>
    </td></tr>

    <tr><td style="padding:16px 22px 0;">
        <table width="100%" cellpadding="0" cellspacing="0" role="presentation">
        @foreach ($products as $p)
            <tr>
                <td width="96" valign="middle" style="padding:10px;">
                    <a href="{{ $p['url'] }}">
                        @if ($p['image'])
                            <img src="{{ $p['image'] }}" alt="{{ $p['name_en'] }}" width="80" style="width:80px;height:auto;border-radius:8px;background:#fafafa;">
                        @endif
                    </a>
                </td>
                <td valign="middle" style="padding:10px;">
                    <a href="{{ $p['url'] }}" style="text-decoration:none;color:#111;">
                        <p style="margin:0;font-size:13px;line-height:1.5;">{{ $p['name_ar'] }}</p>
                        <p style="margin:2px 0 0;font-size:12px;line-height:1.5;color:rgba(0,0,0,0.55);" dir="ltr">{{ $p['name_en'] }}</p>
                    </a>
                    <p style="margin:6px 0 0;font-size:14px;font-weight:800;color:#111;" dir="ltr">EGP {{ number_format($p['price']) }}{{ $p['quantity'] > 1 ? ' × '.$p['quantity'] : '' }}</p>
                </td>
            </tr>
        @endforeach
        </table>
    </td></tr>

    <tr><td style="padding:22px 32px 6px;text-align:center;">
        <a href="{{ $cartUrl }}" style="display:inline-block;background:#111;color:#fff;text-decoration:none;font-size:14px;font-weight:700;padding:13px 34px;border-radius:6px;">أكمل طلبك · Back to your cart</a>
    </td></tr>

    @if ($code)
    <tr><td style="padding:18px 32px 0;">
        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border:1px dashed #C8A45C;border-radius:8px;">
            <tr><td style="padding:16px;text-align:center;">
                <p style="margin:0;font-size:13px;color:#111;">كود خاص بك: خصم {{ rtrim(rtrim(number_format($code['percent'], 2, '.', ''), '0'), '.') }}٪{{ $code['cap'] ? ' حتى '.number_format($code['cap']).' ج.م' : '' }}</p>
                <p style="margin:2px 0 0;font-size:12px;color:rgba(0,0,0,0.6);" dir="ltr">Your own code: {{ rtrim(rtrim(number_format($code['percent'], 2, '.', ''), '0'), '.') }}% off{{ $code['cap'] ? ' up to EGP '.number_format($code['cap']) : '' }}</p>
                <p style="margin:10px 0;font-size:22px;font-weight:800;letter-spacing:3px;color:#111;" dir="ltr">{{ $code['code'] }}</p>
                <p style="margin:0;font-size:11px;line-height:1.7;color:rgba(0,0,0,0.55);">صالح لطلب واحد حتى {{ \Illuminate\Support\Carbon::parse($code['expires_at'])->format('Y-m-d') }}، لهذا البريد فقط. لا يُطبَّق على المنتجات التي عليها عرض بالفعل (Hot Deals / Flash Sale)، ولا يُجمع مع عرض آخر.</p>
                <p style="margin:2px 0 0;font-size:11px;line-height:1.7;color:rgba(0,0,0,0.55);" dir="ltr">One order, until {{ \Illuminate\Support\Carbon::parse($code['expires_at'])->format('j M Y') }}, for this e-mail address only. Not valid on items already on offer (Hot Deals / Flash Sale), and not combined with another offer.</p>
            </td></tr>
        </table>
    </td></tr>
    @endif

    <tr><td style="padding:16px 32px 0;text-align:center;">
        <p style="margin:0;font-size:11px;color:rgba(0,0,0,0.45);line-height:1.7;">الأسعار والمخزون كما هي وقت الإرسال وقد تتغير · <span dir="ltr">Prices and stock are as of sending and may change.</span></p>
    </td></tr>

    @include('emails.partials.marketing-footer')

</table>
</td></tr>
</table>
</body>
</html>
