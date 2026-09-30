<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>معاينة — Re-engagement preview</title>
</head>
<body style="margin:0;padding:24px;background:#f5f5f5;font-family:'Segoe UI',Tahoma,Arial,sans-serif;color:#111;">
<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:10px;padding:24px;">
    <h1 style="font-size:19px;margin:0 0 12px;">معاينة رسالة الأسبوع {{ $week }}</h1>
    <p style="font-size:14px;line-height:1.8;margin:0;">
        تُرسل بعد: <strong dir="ltr">{{ $send_after }}</strong><br>
        إلى: <strong>{{ $audience === 'customers' ? 'العملاء' : 'الفريق فقط' }}</strong> — <strong>{{ $recipients }}</strong> مستلم<br>
        لإيقافها قبل الإرسال: <a href="{{ $manageUrl }}">شاشة رسائل العودة في لوحة التحكم</a> ← «إيقاف مؤقت».
    </p>
    <p style="font-size:13px;line-height:1.7;color:rgba(0,0,0,0.6);margin:10px 0 0;" dir="ltr">
        Sends after {{ $send_after }} to {{ $audience === 'customers' ? 'CUSTOMERS' : 'the team only' }} — {{ $recipients }} recipient(s). To stop it, pause it on the dashboard's re-engagement screen before then.
    </p>
    @if ($sample)
        <h2 style="font-size:15px;margin:22px 0 8px;">مثال لما سيصل · A sample ({{ $sample['email'] }})</h2>
        <ul style="padding-inline-start:18px;margin:0;font-size:13px;line-height:1.8;">
            @foreach ($sample['products'] as $p)
                <li><a href="{{ $p['url'] }}">{{ $p['name'] }}</a> — {{ number_format($p['price']) }} EGP</li>
            @endforeach
        </ul>
    @else
        <p style="font-size:13px;color:#b42318;margin:16px 0 0;">لا توجد منتجات مؤهلة هذا الأسبوع · No eligible products this week (in stock, price unchanged 14 days).</p>
    @endif
</div>
</body>
</html>
