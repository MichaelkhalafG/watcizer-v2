@php($whatsappUrl = $whatsappUrl ?? 'https://wa.me/201551096234')
@php($copyright = $copyright ?? config('watchizer.brand.copyright'))
{{-- Shared dark footer: WhatsApp support + copyright + WATCHIZER wordmark.
     Text wordmark (not <img>) so it stays visible on the #111 background — see
     header.blade.php. Developer credit is intentionally NOT here — frontend only. --}}
<tr>
    <td style="background:#111111;padding:28px 32px;text-align:center;font-family:'Segoe UI',Tahoma,Arial,sans-serif;">
        <span style="display:block;margin:0 auto 16px;font-size:18px;font-weight:700;letter-spacing:3px;color:#ffffff;text-transform:uppercase;line-height:1;">WATCH<span style="color:#C8A45C;">IZER</span></span>

        {{-- WhatsApp support (Unicode icon — inline SVG is stripped by Gmail) --}}
        <a href="{{ $whatsappUrl }}" style="display:inline-block;text-decoration:none;color:#C8A45C;font-size:14px;font-weight:600;line-height:1.5;">
            <span style="font-size:16px;">&#128241;</span>
            <span dir="rtl" style="font-family:'Segoe UI',Tahoma,Arial,sans-serif;">للاستعلام عن طلبك تواصل معنا على واتساب</span>
        </a>
        <p style="margin:6px 0 0;font-size:11px;color:rgba(255,255,255,0.5);">Contact us on WhatsApp for order inquiries</p>

        <div style="height:1px;background:rgba(255,255,255,0.12);margin:18px auto 14px;max-width:220px;font-size:0;line-height:0;">&nbsp;</div>

        <p style="margin:0;font-size:11px;color:rgba(255,255,255,0.4);">{{ $copyright }}</p>
    </td>
</tr>
