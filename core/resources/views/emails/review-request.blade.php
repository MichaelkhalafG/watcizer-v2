<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <title>كيف وجدت طلبك؟ · How was your order? — {{ $brandName }}</title>
</head>
{{-- The review request (SEO Phase 5, 2026-11-14): a few days after delivery, one button to the shop's review page for the
     order. Arabic first, English beneath, the way the order e-mails pair them. No incentive is offered for a review. --}}
<body style="margin:0;padding:0;background:#f5f5f5;-webkit-text-size-adjust:100%;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f5f5f5;padding:28px 14px;font-family:'Segoe UI',Tahoma,Arial,sans-serif;">
<tr><td align="center">

<table width="600" cellpadding="0" cellspacing="0" role="presentation" style="max-width:600px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;">

    @include('emails.partials.header')

    <tr><td style="padding:34px 32px 6px;text-align:center;">
        <h1 style="margin:0;font-size:22px;font-weight:600;color:#111;" dir="rtl">كيف وجدت طلبك؟</h1>
        <p style="margin:8px 0 0;font-size:13px;color:rgba(0,0,0,0.5);" dir="ltr">How was your order?</p>
    </td></tr>

    <tr><td style="padding:20px 32px 0;text-align:center;">
        <p style="margin:0;font-size:15px;color:#111;line-height:1.7;" dir="rtl">
            رأيك يساعد غيرك على الاختيار. اكتب لنا في دقيقة كيف وجدت ما اشتريته، وأضف صورة إن أحببت.
        </p>
        <p style="margin:8px 0 0;font-size:13px;color:rgba(0,0,0,0.6);line-height:1.7;" dir="ltr">
            Your opinion helps other shoppers choose. Tell us in a minute how you find what you bought, and add a photo if you like.
        </p>
        @if (count($products) > 0)
            <p style="margin:16px 0 0;font-size:13px;color:#111;line-height:1.8;" dir="ltr">
                @foreach ($products as $name)
                    {{ $name }}@if (! $loop->last)<br>@endif
                @endforeach
            </p>
        @endif
    </td></tr>

    <tr><td style="padding:24px 32px 30px;text-align:center;">
        <a href="{{ $url }}" style="display:inline-block;padding:13px 28px;background:#111;color:#ffffff;text-decoration:none;border-radius:8px;font-size:15px;font-weight:600;">
            اكتب رأيك · Write a review
        </a>
        <p style="margin:14px 0 0;font-size:12px;color:rgba(0,0,0,0.45);line-height:1.6;">
            الرابط خاص بطلبك · This link is for your order only
        </p>
    </td></tr>

    @include('emails.partials.footer')

</table>
</td></tr>
</table>
</body>
</html>
