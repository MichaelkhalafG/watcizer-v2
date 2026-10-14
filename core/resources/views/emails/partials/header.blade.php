{{-- Brand bar: the STOREFRONT's wordmark on black + gold accent line (W2, 2026-10-13).
     `$wordmarkUrl` is the storefront's own PNG on its own asset host (StorefrontIdentity — the
     storefront row, never a constant), 400x122 on an opaque #111 ground shown at 200x61 so a mail
     client that drops cell backgrounds cannot turn it white-on-white; `$brandName` is the alt text.
     This used to be a TEXT wordmark, WATCH+IZER, because Watchizer's dark logo vanished on the bar
     and Gmail strips CSS filters — the opaque PNG answers both without naming one shop. --}}
<tr>
    <td style="background:#111111;padding:24px 32px;text-align:center;">
        <img src="{{ $wordmarkUrl }}" width="200" height="61" alt="{{ $brandName }}" style="display:block;margin:0 auto;width:200px;height:61px;border:0;outline:none;text-decoration:none;">
    </td>
</tr>
<tr>
    <td style="background:#C8A45C;background:linear-gradient(to right,#C8A45C,#E8D5A3,#C8A45C);height:3px;font-size:0;line-height:0;">&nbsp;</td>
</tr>
