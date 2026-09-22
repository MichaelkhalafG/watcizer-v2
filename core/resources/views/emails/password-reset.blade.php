<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <title>إعادة تعيين كلمة المرور — Watchizer</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f4;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:6px;overflow:hidden;">
                @include('emails.partials.header')

                <tr>
                    <td style="padding:32px;font-family:'Segoe UI',Tahoma,Arial,sans-serif;color:#111111;">
                        <p dir="rtl" style="margin:0 0 12px;font-size:18px;font-weight:700;">مرحباً {{ $name }}</p>
                        <p dir="rtl" style="margin:0 0 20px;font-size:15px;line-height:1.8;color:#444444;">
                            وصلنا طلب لإعادة تعيين كلمة المرور الخاصة بحسابك. اضغط على الزر أدناه لاختيار كلمة مرور جديدة.
                        </p>

                        <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 20px;">
                            <tr>
                                <td style="background:#111111;border-radius:4px;">
                                    <a href="{{ $url }}" style="display:inline-block;padding:14px 32px;font-family:'Segoe UI',Tahoma,Arial,sans-serif;font-size:15px;font-weight:700;color:#C8A45C;text-decoration:none;">
                                        إعادة تعيين كلمة المرور
                                    </a>
                                </td>
                            </tr>
                        </table>

                        <p dir="rtl" style="margin:0 0 6px;font-size:13px;line-height:1.8;color:#666666;">
                            هذا الرابط صالح لمدة {{ $expiresMinutes }} دقيقة فقط.
                        </p>
                        {{-- The sentence that matters most on this e-mail: somebody who did NOT ask
                             for this needs to know that ignoring it is enough, and that no change
                             has been made to their account yet. --}}
                        <p dir="rtl" style="margin:0 0 20px;font-size:13px;line-height:1.8;color:#666666;">
                            إذا لم تطلب إعادة تعيين كلمة المرور، تجاهل هذه الرسالة — لم يتغيّر أي شيء في حسابك.
                        </p>

                        <div style="height:1px;background:#eeeeee;margin:24px 0;font-size:0;line-height:0;">&nbsp;</div>

                        <p dir="ltr" style="margin:0 0 10px;font-size:14px;line-height:1.7;color:#444444;text-align:left;">
                            Hello {{ $name }} — we received a request to reset your Watchizer password.
                            Use the button above, or the address below, to choose a new one. The link is
                            valid for {{ $expiresMinutes }} minutes.
                            <strong>If you did not ask for this, ignore this email — nothing has changed.</strong>
                        </p>

                        {{-- The raw address, because some mail clients strip the button and because a
                             customer should always be able to see where a link goes before using it. --}}
                        <p dir="ltr" style="margin:0;font-size:12px;line-height:1.6;color:#888888;word-break:break-all;text-align:left;">
                            {{ $url }}
                        </p>
                    </td>
                </tr>

                @include('emails.partials.footer')
            </table>
        </td>
    </tr>
</table>
</body>
</html>
