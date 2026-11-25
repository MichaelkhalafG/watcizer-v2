{{-- The footer of the abandoned-cart e-mail and of every campaign (2026-11-24): the one-click unsubscribe in both
     languages, then the shop's own wordmark and WhatsApp. Not the order e-mails' footer, which is pinned byte for byte
     to their legacy original (OrderMailContractTest). --}}
<tr><td style="padding:18px 32px 24px;text-align:center;">
    <p style="margin:0;font-size:11px;color:rgba(0,0,0,0.45);line-height:1.8;" dir="rtl">
        <a href="{{ $unsubscribeUrl }}" style="color:rgba(0,0,0,0.55);">إلغاء الاشتراك في هذه الرسائل</a> - رسائل طلباتك تصل كالمعتاد.
    </p>
    <p style="margin:0;font-size:11px;color:rgba(0,0,0,0.45);line-height:1.8;" dir="ltr">
        <a href="{{ $unsubscribeUrl }}" style="color:rgba(0,0,0,0.55);">Unsubscribe from these e-mails</a> - your order e-mails still arrive.
    </p>
</td></tr>
<tr>
    <td style="background:#111111;padding:28px 32px;text-align:center;font-family:'Segoe UI',Tahoma,Arial,sans-serif;">
        <img src="{{ $wordmarkUrl }}" width="140" height="43" alt="{{ $brandName }}" style="display:block;margin:0 auto 16px;width:140px;height:43px;border:0;outline:none;text-decoration:none;">
        <a href="{{ $whatsappUrl }}" style="display:inline-block;text-decoration:none;color:#C8A45C;font-size:14px;font-weight:600;line-height:1.5;">
            <span style="font-size:16px;">&#128241;</span> واتساب · WhatsApp
        </a>
        <div style="height:1px;background:rgba(255,255,255,0.12);margin:18px auto 14px;max-width:220px;font-size:0;line-height:0;">&nbsp;</div>
        <p style="margin:0;font-size:11px;color:rgba(255,255,255,0.4);">{{ $copyright }}</p>
    </td>
</tr>
