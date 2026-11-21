{{--
    The Elegance company page (eleganceeg.com /, /ar), 2026-11-21. Static: rendered from App\Elegance\EleganceContent only.
    No script (the JSON-LD is data, never executed), no inline style, no form, no input, nothing from another host: the
    page runs under EleganceHeaders' CSP (default-src 'none'). Every outbound link is a plain <a rel="noopener noreferrer">.
    Not one word here leads to the dashboard: a test reads this page for it.
--}}
@php
    $t = $c['text'];
    $ar = $c['locale'] === 'ar';
    $v = '?v='.$c['version'];
    $self = $ar ? 'https://eleganceeg.com/ar' : 'https://eleganceeg.com/';
    $ext = 'rel="noopener noreferrer"';
    $img = static function (string $key, array $widths, string $sizes, string $alt, bool $eager = false): string {
        $avif = implode(', ', array_map(fn (int $w): string => "/elegance/img/p/{$key}-{$w}.avif {$w}w", $widths));
        $webp = implode(', ', array_map(fn (int $w): string => "/elegance/img/p/{$key}-{$w}.webp {$w}w", $widths));
        $load = $eager ? 'fetchpriority="high" decoding="async"' : 'loading="lazy" decoding="async"';

        return '<picture><source type="image/avif" srcset="'.e($avif).'" sizes="'.e($sizes).'">'
            .'<img src="/elegance/img/p/'.e($key).'-800.webp" srcset="'.e($webp).'" sizes="'.e($sizes).'" width="800" height="800" alt="'.e($alt).'" '.$load.'></picture>';
    };
    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        '@id' => 'https://eleganceeg.com/#organization',
        'name' => 'Elegance',
        'alternateName' => 'إليجانس', // i18n-exempt: DATA — the company's Arabic name in its structured data
        'url' => 'https://eleganceeg.com/',
        'logo' => 'https://eleganceeg.com/elegance/logo.png',
        'image' => 'https://eleganceeg.com/elegance/og-en.jpg',
        'description' => $c['text']['description'],
        'email' => 'info@eleganceeg.com',
        'telephone' => '+20 120 011 3375',
        'address' => ['@type' => 'PostalAddress', 'streetAddress' => '90 El Thawra Street, ground floor', 'addressLocality' => 'Heliopolis, Cairo', 'addressCountry' => 'EG'],
        'subOrganization' => [
            ['@type' => 'Organization', '@id' => 'https://watchizereg.com/#organization', 'name' => 'Watchizer', 'url' => 'https://watchizereg.com'],
            ['@type' => 'Organization', '@id' => 'https://www.brandfashionegy.com/#organization', 'name' => 'Brand Fashion', 'url' => 'https://www.brandfashionegy.com'],
        ],
        'brand' => [
            ['@type' => 'Brand', 'name' => 'Watchizer', 'url' => 'https://watchizereg.com'],
            ['@type' => 'Brand', 'name' => 'Brand Fashion', 'url' => 'https://www.brandfashionegy.com'],
        ],
    ];
    $icons = [
        '<circle cx="24" cy="20" r="11"/><circle cx="24" cy="20" r="7"/><path d="M18 29 L15 42 L20 39 L24 43 L28 39 L33 42 L30 29"/>',
        '<rect x="17" y="4" width="14" height="7" rx="1"/><rect x="17" y="37" width="14" height="7" rx="1"/><circle cx="24" cy="24" r="12"/><path d="M19 24 L23 28 L30 20"/>',
        '<path d="M6 18 L10 8 H38 L42 18"/><path d="M6 18 Q10 23 14 18 Q18 23 22 18 Q26 23 30 18 Q34 23 38 18 Q40 21 42 18"/><path d="M9 21 V41 H39 V21"/><path d="M20 41 V30 H28 V41"/>',
        '<circle cx="11" cy="36" r="3"/><circle cx="37" cy="12" r="3"/><path d="M14 36 C26 36 14 22 26 22 C36 22 28 12 34 12" stroke-dasharray="2 3"/>',
        '<path d="M4 24 C11 13 37 13 44 24 C37 35 11 35 4 24 Z"/><circle cx="24" cy="24" r="6"/>',
        '<path d="M38 18 A14 14 0 1 0 38 30"/><path d="M38 9 V18 H29"/>',
    ];
@endphp
<!doctype html>
<html lang="{{ $c['locale'] }}" dir="{{ $c['dir'] }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $t['title'] }}</title>
<meta name="description" content="{{ $t['description'] }}">
<link rel="canonical" href="{{ $self }}">
<link rel="alternate" hreflang="en" href="https://eleganceeg.com/">
<link rel="alternate" hreflang="ar" href="https://eleganceeg.com/ar">
<link rel="alternate" hreflang="x-default" href="https://eleganceeg.com/">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Elegance">
<meta property="og:title" content="{{ $t['title'] }}">
<meta property="og:description" content="{{ $t['description'] }}">
<meta property="og:url" content="{{ $self }}">
<meta property="og:image" content="https://eleganceeg.com/elegance/og-{{ $c['locale'] }}.jpg">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:locale" content="{{ $t['og_locale'] }}">
<meta name="twitter:card" content="summary_large_image">
<meta name="theme-color" content="#12100d">
<link rel="icon" href="/elegance/favicon.svg{{ $v }}" type="image/svg+xml">
<link rel="icon" href="/elegance/favicon-32.png{{ $v }}" sizes="32x32" type="image/png">
<link rel="apple-touch-icon" href="/elegance/apple-touch-icon.png{{ $v }}">
<link rel="stylesheet" href="/elegance/elegance.css{{ $v }}">
<script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
</head>
<body>
<a class="skip" href="#main">{{ $t['skip'] }}</a>
<header class="top">
  <div class="wrap">
    <img class="sig" src="/elegance/signature.svg{{ $v }}" alt="Elegance" width="190" height="73">
    <nav class="sections" aria-label="{{ $t['nav_label'] }}">
      @foreach ($t['nav'] as $id => $label)<a href="#{{ $id }}">{{ $label }}</a>@endforeach
    </nav>
    <nav class="lang" aria-label="{{ $t['lang_label'] }}">
      <a class="en" href="/" hreflang="en" lang="en" @if (! $ar) aria-current="page" @endif>English</a>
      <a class="ar" href="/ar" hreflang="ar" lang="ar" @if ($ar) aria-current="page" @endif>العربية</a>{{-- i18n-exempt: a language name is data, shown in its own language --}}
    </nav>
  </div>
</header>

<main id="main">
  <section class="hero" aria-labelledby="name">
    <div class="wrap hero-grid">
      <div>
        <h1 id="name"><img class="signature" src="/elegance/signature.svg{{ $v }}" alt="Elegance, إليجانس" width="760" height="292" fetchpriority="high"></h1>
        <p class="tagline">{{ $t['tagline'] }}</p>
        <p class="about">{{ $t['about'] }}</p>
        <div class="entries">
          <a class="entry" href="{{ $c['shops']['watchizer'] }}" {!! $ext !!}>
            <img src="/elegance/watchizer.webp{{ $v }}" alt="{{ $t['watchizer'] }}" width="72" height="48">
            <span class="what">{{ $t['entry_watchizer'] }}</span>
            <span class="go">{{ $t['visit'] }} <bdi>watchizereg.com</bdi></span>
          </a>
          <a class="entry" href="{{ $c['shops']['brandfashion'] }}" {!! $ext !!}>
            <img src="/elegance/brandfashion.svg{{ $v }}" alt="{{ $t['brandfashion'] }}" width="158" height="48">
            <span class="what">{{ $t['entry_brandfashion'] }}</span>
            <span class="go">{{ $t['visit'] }} <bdi>brandfashionegy.com</bdi></span>
          </a>
        </div>
      </div>
      <div class="window">
        @foreach ($c['window'] as $i => $p)
          <a class="piece p{{ $i + 1 }}" href="{{ $p['url'] }}" {!! $ext !!}>{!! $img($p['key'], $p['widths'], '(max-width: 64rem) 60vw, 22rem', $p['caption'], true) !!}</a>
        @endforeach
        <span class="label">{{ $t['window'] }}</span>
        <span class="glint"></span>
      </div>
    </div>
    <div class="ribbon"><ul class="wrap">@foreach ($t['ribbon'] as $fact)<li>{{ $fact }}</li>@endforeach</ul></div>
  </section>

  @foreach (['watchizer', 'brandfashion'] as $n => $house)
    @php $h = $c['houses'][$house]; @endphp
    <section class="section {{ $n === 1 ? 'alt' : '' }}" id="{{ $house }}" aria-label="{{ $t[$house] }}">
      <div class="wrap house {{ $n === 1 ? 'mirror' : '' }}">
        <div>
          @if ($house === 'watchizer')
            <img class="logo" src="/elegance/watchizer.webp{{ $v }}" alt="{{ $t['watchizer'] }}" width="90" height="60" loading="lazy">
          @else
            <img class="logo wide" src="/elegance/brandfashion.svg{{ $v }}" alt="{{ $t['brandfashion'] }}" width="198" height="60" loading="lazy">
          @endif
          <p class="story">{{ $t[$house.'_story'] }}</p>
          <ul class="facts">@foreach ($t[$house.'_facts'] as $fact)<li>{{ $fact }}</li>@endforeach</ul>
          <h3>{{ $t['shop_by'] }}</h3>
          <ul class="chips">@foreach ($h['chips'] as $chip)<li><a href="{{ $chip['url'] }}" {!! $ext !!}>{{ $chip['label'] }}</a></li>@endforeach</ul>
          <a class="btn" href="{{ $h['home'] }}" {!! $ext !!}>{{ $t['visit'] }} <bdi>{{ $house === 'watchizer' ? 'watchizereg.com' : 'brandfashionegy.com' }}</bdi></a>
        </div>
        <div class="vitrine">
          @foreach ($h['pieces'] as $i => $p)
            @php $cls = $i === 0 ? 'big' : ($i >= 5 ? 'wide' : ''); $sizes = $i === 0 ? '(max-width: 40rem) 92vw, (max-width: 64rem) 60vw, 34rem' : '(max-width: 40rem) 46vw, (max-width: 64rem) 30vw, 17rem'; @endphp
            <a class="tile {{ $cls }}" href="{{ $p['url'] }}" {!! $ext !!}>{!! $img($p['key'], $p['widths'], $sizes, $p['caption']) !!}<span class="cap">{{ $p['caption'] }}</span></a>
          @endforeach
        </div>
      </div>
    </section>
  @endforeach

  <section class="section" id="categories" aria-labelledby="categories-h">
    <div class="wrap">
      <div class="section-head"><h2 id="categories-h">{{ $t['categories_h'] }}</h2><p>{{ $t['categories_p'] }}</p></div>
      <ul class="cats">
        @foreach ($c['categories'] as $cat)
          <li><a class="cat" href="{{ $cat['url'] }}" {!! $ext !!}>
            <span class="photo">{!! $img($cat['image'], [480, 800], '(max-width: 40rem) 46vw, (max-width: 64rem) 24vw, 17rem', '') !!}</span>
            <span class="name"><strong>{{ $cat['label'] }}</strong><small>{{ $cat['shop'] === 'wz' ? $t['at_watchizer'] : $t['at_brandfashion'] }}</small></span>
          </a></li>
        @endforeach
      </ul>
    </div>
  </section>

  <section class="section alt" id="brands" aria-labelledby="brands-h">
    <div class="wrap">
      <div class="section-head"><h2 id="brands-h">{{ $t['brands_h'] }}</h2><p>{{ $t['brands_p'] }}</p></div>
      <ul class="wall">
        @foreach ($c['brands'] as $b)
          <li>
            @if ($b['url'] !== null)<a href="{{ $b['url'] }}" {!! $ext !!}>@else<span class="plain">@endif
              @if ($b['logo'] !== null)<picture><source type="image/avif" srcset="/elegance/img/brands/{{ $b['slug'] }}.avif{{ $v }}"><img src="/elegance/img/brands/{{ $b['slug'] }}.webp{{ $v }}" alt="" width="{{ $b['logo'] }}" height="48" loading="lazy" decoding="async"></picture><span lang="en">{{ $b['name'] }}</span>
              @else<b class="typeset" lang="en">{{ $b['name'] }}</b>@endif
            @if ($b['url'] !== null)</a>@else</span>@endif
          </li>
        @endforeach
      </ul>
    </div>
  </section>

  <section class="section" id="why" aria-labelledby="why-h">
    <div class="wrap">
      <div class="section-head"><h2 id="why-h">{{ $t['why_h'] }}</h2></div>
      <ul class="why">
        @foreach ($t['why'] as $i => [$title, $line])
          <li><svg viewBox="0 0 48 48" aria-hidden="true" fill="none" stroke="#c9a45e" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round">{!! $icons[$i] !!}</svg><h3>{{ $title }}</h3><p>{{ $line }}</p></li>
        @endforeach
      </ul>
    </div>
  </section>

  <section class="section alt" id="contact" aria-labelledby="contact-h">
    <div class="wrap">
      <div class="section-head"><h2 id="contact-h">{{ $t['contact_h'] }}</h2><p>{{ $t['contact_p'] }}</p></div>
      <div class="contact">
        <div class="card">
          <img class="sig" src="/elegance/signature-ink.svg{{ $v }}" alt="" width="260" height="100" loading="lazy">
          <address>@foreach ($t['address'] as $line){{ $line }}@if (! $loop->last)<br>@endif @endforeach</address>
          <a class="map" href="{{ $c['maps']['heliopolis'] }}" {!! $ext !!}>{{ $t['map'] }}</a>
        </div>
        <div class="reach">
          <dl>
            <dt>{{ $t['phone'] }}</dt><dd><a href="tel:+201200113375"><bdi dir="ltr">012 00113375</bdi></a></dd>
            <dt>{{ $t['email'] }}</dt><dd><a href="mailto:info@eleganceeg.com"><bdi>info@eleganceeg.com</bdi></a></dd>
          </dl>
          <div class="store"><h3>{{ $t['arkadia'][0] }}</h3><p>{{ $t['arkadia'][1] }}</p><a href="{{ $c['maps']['arkadia'] }}" {!! $ext !!}>{{ $t['store_map'] }}</a></div>
          <div class="store"><h3>{{ $t['heliopolis'][0] }}</h3><p>{{ $t['heliopolis'][1] }}</p><a href="{{ $c['maps']['heliopolis'] }}" {!! $ext !!}>{{ $t['store_map'] }}</a></div>
          <p class="hours">{{ $t['hours'] }}</p>
        </div>
      </div>
    </div>
  </section>
</main>

<footer>
  <div class="wrap">
    <img class="sig" src="/elegance/signature.svg{{ $v }}" alt="" width="190" height="73" loading="lazy">
    <span>{{ $t['footer'] }}</span>
    <nav aria-label="{{ $t['footer_nav'] }}"><a href="{{ $c['shops']['watchizer'] }}" {!! $ext !!}>{{ $t['watchizer'] }}</a><a href="{{ $c['shops']['brandfashion'] }}" {!! $ext !!}>{{ $t['brandfashion'] }}</a></nav>
  </div>
</footer>
</body>
</html>
