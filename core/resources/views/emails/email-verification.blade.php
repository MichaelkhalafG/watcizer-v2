<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <title>تأكيد بريدك الإلكتروني — Watchizer</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f4;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:6px;overflow:hidden;">
                @include('emails.partials.header')

                <tr>
                    <td style="padding:32px;font-family:'Segoe UI',Tahoma,Arial,sans-serif;color:#111111;">
                        <p dir="rtl" style="margin:0 0 12px;font-size:18px;font-weight:700;">أهلاً بك يا {{ $name }}</p>
                        <p dir="rtl" style="margin:0 0 20px;font-size:15px;line-height:1.8;color:#444444;">
                            تم إنشاء حسابك على Watchizer. اضغط على الزر أدناه لتأكيد بريدك الإلكتروني.
                        </p>

                        <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 20px;">
                            <tr>
                                <td style="background:#111111;border-radius:4px;">
                                    <a href="{{ $url }}" style="display:inline-block;padding:14px 32px;font-family:'Segoe UI',Tahoma,Arial,sans-serif;font-size:15px;font-weight:700;color:#C8A45C;text-decoration:none;">
                                        تأكيد البريد الإلكتروني
                                    </a>
                                </td>
                            </tr>
                        </table>

                        {{-- Verification is not a gate on shopping here, and saying so avoids the
                             support question "I cannot order until I confirm?" --}}
                        <p dir="rtl" style="margin:0 0 20px;font-size:13px;line-height:1.8;color:#666666;">
                            إذا لم تنشئ هذا الحساب، تجاهل هذه الرسالة.
                        </p>

                        <div style="height:1px;background:#eeeeee;margin:24px 0;font-size:0;line-height:0;">&nbsp;</div>

                        <p dir="ltr" style="margin:0 0 10px;font-size:14px;line-height:1.7;color:#444444;text-align:left;">
                            Welcome {{ $name }} — please confirm your email address using the button
                            above, or the address below.
                            <strong>If you did not create this account, ignore this email.</strong>
                        </p>

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
