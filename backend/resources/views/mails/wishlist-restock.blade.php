@php
    $frontend = rtrim(config('services.frontend_url', 'https://watchizereg.com'), '/');
    $imgBase  = 'https://dash.watchizereg.com/Uploads_Images/Product/';
    $brand    = optional($product->brand)->brand_name;
    $sale     = (float) $product->sale_price_after_discount;
    $price    = (float) $product->selling_price;
    $hasSale  = $sale > 0 && $sale < $price;
@endphp
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:Georgia,'Times New Roman',serif;">

<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:32px 16px;">
<tr><td align="center">

<table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;max-width:600px;width:100%;">

<!-- Header -->
<tr>
<td style="background:#0a0a0a;padding:24px 32px;text-align:center;">
  <img src="https://dash.watchizereg.com/DashAssets/img/logo.webp" alt="Watchizer" width="120" style="display:block;margin:0 auto;">
</td>
</tr>

<!-- Gold line -->
<tr>
<td style="background:linear-gradient(to right,#C8A45C,#E8D5A3,#C8A45C);height:3px;font-size:0;line-height:0;">&nbsp;</td>
</tr>

<!-- Content -->
<tr>
<td style="padding:40px 32px 8px;text-align:center;">
  <p style="font-size:11px;color:#C8A45C;text-transform:uppercase;letter-spacing:0.25em;margin:0 0 12px;">
    Back in Stock
  </p>
  <h1 style="font-size:22px;font-weight:300;color:#111;margin:0 0 8px;font-family:Georgia,serif;">
    Great news, {{ $user->first_name }}!
  </h1>
  <p style="font-size:14px;color:rgba(0,0,0,0.5);margin:0 0 28px;">
    An item from your wishlist is available again
  </p>
</td>
</tr>

<!-- Product -->
<tr>
<td style="padding:0 32px 32px;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f9f9f9;border-radius:10px;overflow:hidden;">
  <tr>
    <td style="padding:24px;text-align:center;">
      @if($product->image)
      <img src="{{ $imgBase . $product->image }}"
           alt="{{ $product->product_title }}"
           width="180" height="180"
           style="object-fit:contain;display:block;margin:0 auto 16px;">
      @endif
      @if($brand)
      <p style="font-size:10px;color:#C8A45C;text-transform:uppercase;letter-spacing:0.2em;margin:0 0 6px;">
        {{ $brand }}
      </p>
      @endif
      <p style="font-size:16px;font-weight:500;color:#111;margin:0 0 8px;">
        {{ $product->product_title }}
      </p>
      <p style="font-size:18px;font-weight:700;color:#111;margin:0;">
        @if($hasSale)
          {{ number_format($sale) }} EGP
          <span style="font-size:13px;color:rgba(0,0,0,0.35);text-decoration:line-through;font-weight:400;">
            {{ number_format($price) }} EGP
          </span>
        @else
          {{ number_format($price) }} EGP
        @endif
      </p>
    </td>
  </tr>
  </table>
</td>
</tr>

<!-- CTA -->
<tr>
<td style="padding:0 32px 40px;text-align:center;">
  <a href="{{ $frontend }}/products/{{ $product->id }}"
     style="display:inline-block;background:#111;color:#fff;text-decoration:none;
            padding:15px 48px;border-radius:6px;font-size:12px;font-weight:700;
            letter-spacing:0.2em;text-transform:uppercase;">
    SHOP NOW
  </a>
  <p style="font-size:11px;color:rgba(0,0,0,0.35);margin:16px 0 0;">
    Hurry — limited stock available
  </p>
</td>
</tr>

<!-- Footer -->
<tr>
<td style="background:#0a0a0a;padding:20px 32px;text-align:center;">
  <p style="font-size:10px;color:rgba(255,255,255,0.35);margin:0;">
    Watchizer — Egypt's Premier Watch Destination
  </p>
  <p style="font-size:10px;color:rgba(255,255,255,0.2);margin:6px 0 0;">
    You received this because this item is in your wishlist
  </p>
</td>
</tr>

</table>
</td></tr>
</table>
</body>
</html>
