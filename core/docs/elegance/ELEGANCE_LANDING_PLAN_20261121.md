# The Elegance page on eleganceeg.com, plan for approval (21 November 2026)

Nothing is built until Michael approves. Design preview: `checkpoints/2026-11-21_elegance/preview` (direction.html,
page-en.html, page-ar.html; shots in preview/shots: desktop 1440 and phone 390, EN and AR). Security comes first:
where it meets the design, security wins and it is said below (§4).

## 1. What "/" does today (measured live, 21 Nov, read-only GETs; checkpoints/2026-11-21_elegance/before)

| URL on eleganceeg.com | Today |
|---|---|
| `/` and `www.` `/` | 302 to `/manage`, and a `watchizer-session` cookie (the redirect sits in the dashboard's session group) |
| `/manage` | 302 to `/manage/login`, `watchizer-session` cookie (secure, httponly, samesite=lax, 8 h) |
| `/api`, `/api/catalog/meta` | 404 (Apache, the dashboard-host rule) |
| `/robots.txt` | 200, the static file SHARED with the API hosts: `Allow: /Uploads_Images/`, `Disallow: /` |
| `/sitemap.xml` | 404 |
| `/favicon.ico` | 200, the shared static icon |
| a missing page | 404, the existing error page, no stack trace |
| every response | `server: hcdn` and `content-security-policy: upgrade-insecure-requests` from Hostinger's CDN; PHP responses also `x-powered-by: PHP/8.3.37`; NO `strict-transport-security` |

API hosts today: `api.watchizereg.com` `/api/catalog/meta` 401, `/manage` 404, `/` 404; `api.brandfashionegy.com` the same
(401, 404). The dashboard's own HTML already carries `<meta name="robots" content="noindex, nofollow">`.

The only route change: GET `/` stops redirecting and shows the page in English; GET `/ar` (new) shows it in Arabic.
Nothing else in the route list changes (proved by a before/after `route:list`, §5). Staff who open the bare
eleganceeg.com see the company page, with no way into the dashboard from it; they keep `/manage`. Every staff
e-mail already links `/manage/...` (MANAGE_URL + path), never the bare root.

## 2. Design - PREVIEW 2 (21 Nov, after Michael's feedback; preview 1 was "far too empty" and its wordmarks rejected)

Preview: `checkpoints/2026-11-21_elegance/preview/v2/index-en.html` and `index-ar.html` (served locally at
http://127.0.0.1:8090/v2/index-en.html); shots in `preview/shots/v2` (desktop 1440 and phone 390, EN and AR, each
the full page stitched from real 900 / 844 px viewports); the signatures in `preview/wordmarks/index.html`.

A luxury house's front, built from our own catalogue. The ground is warm onyx engraved with guilloché - the fine
sunburst of a watch dial, drawn with a CSS gradient (no image); the photography sits in porcelain display cases (the
products' white backgrounds disappear into the porcelain by a multiply blend, CSS only); gold is the family's own
(#C8A45C) with gold-gradient buttons and frames. Sections, in order:

1. Hero, the full screen: the signature written in (one CSS reveal, left to right; right to left in Arabic), the
   line "Original watches and accessories", the approved about text, two buttons (Our brands, Contact us); beside it
   an arched boutique window in porcelain with a gold double frame holding a Tory Burch bag, a Hugo Boss watch and a
   Michael Kors bangle, and a slow light sweeping across the glass every few seconds.
2. A ribbon of four facts between gold lozenges.
3. Our brands: one large panel per shop, mirrored - a display case of five of its pieces, its logo, its story line,
   three facts, and "Visit watchizereg.com" / "Visit brandfashionegy.com".
4. The brands we carry: a porcelain tray of 19 names with their logos, each logo cleaned into one ink.
5. Why shop with us: six commitments, each with an engraved gold line icon (inline SVG).
6. Contact: a porcelain address card (the ink signature, the address, Open in Google Maps), the phone and e-mail,
   both stores with their addresses, map links and hours.
7. Footer: the signature, © 2026 Elegance, both shops.

Motion, CSS only and off under prefers-reduced-motion: the signature reveal, the window's light sweep, a slow zoom of
a product on hover. No JavaScript at all. Fonts as before (Cormorant Garamond, El Messiri, self-hosted, SIL OFL); the
script faces are NOT downloaded by visitors - the signature is outlined paths.

### The signature (three options; outlined to SVG with fontTools + HarfBuzz, no font needed)

| Option | Latin | Arabic | Licence |
|---|---|---|---|
| 1 Signature (on the preview) | Mrs Saint Delafield - Sudtipos (Alejandro Paul) | Aref Ruqaa, a Ruqʿa hand - Abdullah Aref, Khaled Hosny, Hermann Zapf | SIL OFL 1.1, both |
| 2 Copperplate | Pinyon Script - Nicole Fally | Amiri, a classical Naskh - Khaled Hosny, Sebastian Kosch | SIL OFL 1.1, both |
| 3 Flourish | Monsieur La Doulaise - Sudtipos (Alejandro Paul) | Gulzar, a Nastaʿlīq hand - Borna Izadpanah, Fiona Ross, Alice Savoie, Simon Cozens | SIL OFL 1.1, both |

The SIL Open Font License 1.1 allows commercial use. A logo set in an OFL font and converted to outlines is artwork,
not the Font Software, so the licence's conditions (which govern redistributing or modifying the font itself) do not
apply to it; the fonts were taken with their OFL.txt from the Google Fonts repository (`ofl/<family>/`). The chosen
option becomes: the header and footer signature (champagne and gold), the ink version on the address card, the
favicon (its script E, thickened slightly to hold at 32 px; SVG + 32 px and 180 px PNG) and the 1200 x 630 share image.

### Every claim and its source (as published on 21 Nov; checkpoints/2026-11-21_elegance/facts has the page texts)

| On the page | Source |
|---|---|
| Elegance: an Egyptian retail group for original watches and accessories, the company behind Watchizer and Brand Fashion | Michael's approved text (21 Nov); "Watchizer and Brand Fashion are sister stores, both owned by Elegance": watchizereg.com/about-us, brandfashionegy.com/about-us (EN and AR) |
| "Original watches and accessories" (tagline, why-us title) | the approved text; both shops' "Original watches" / «ساعات أصلية» collection, linked in every footer (/original-watches) |
| Selling watches and accessories in Egypt since 2013 | watchizereg.com/about-us ("since 2013"), brandfashionegy.com/about-us, and /ar/about-us («منذ ٢٠١٣») |
| Two stores in Cairo: Arkadia Mall, 2nd floor, Corniche El Nil, Maspero extension, next to the Fairmont; 90 El-Thawra Street, Heliopolis; open every day 10:00–24:00 | both /about-us and both /warranty pages |
| Delivery to all 27 governorates, within 24 hours | watchizereg.com/shipping, brandfashionegy.com/shipping |
| Up to 3 years' warranty on watches; most carry their brand agent's warranty; the stores replace the battery and size the bracelet free within it | watchizereg.com/warranty, brandfashionegy.com/warranty |
| Watchizer is an authorized dealer for Hugo Boss | watchizereg.com/about-us, watchizereg.com/warranty |
| 900K+ follow Watchizer / 800K+ follow Brand Fashion on Facebook, Instagram and TikTok | the two /about-us pages |
| Watchizer: cash on delivery or online by card / Brand Fashion: cash when the order arrives | watchizereg.com/shipping; brandfashionegy.com/shipping |
| Inspect before you pay (cash on delivery, in front of the courier) | both /shipping and /about-us |
| 14 days to return or exchange; we pay the return shipping | both /returns and /shipping |
| Brand Fashion sells watches for men and women, bags, wallets, belts, jewellery and perfumes | brandfashionegy.com/about-us |
| Each shop has its own catalogue and phone number; they share the two stores | both /about-us |
| The office address, phone, e-mail | Michael (the brief) |
| The map links | the same maps.app.goo.gl links both shops publish for the two stores |

Nothing else is claimed: no numbers of products, no awards, no founding year for Elegance itself.

### The photography (our own catalogue, as the live shops show it; trimmed, AVIF + WebP at 480 / 800 / 1200 px)

Tory Burch bag (catalogue product 146), Pinko bag (185), Coach bag (217), Coach wallet (511), Just Cavalli watch
(530): from our own uploads (Uploads_Images/Product), brands read from the catalogue. Hugo Boss 1513671 Legacy and
1513674 Navigator, Emporio Armani AR11276, Michael Kors MK00006 and the Michael Kors ARK-BB052 bangle: the images the
live home pages showed on 21 Nov (downloaded once from the shops' own image host, 21 files, one a second). Left out on
purpose: a skeleton watch that is Patek Philippe and a Submariner-style Rolex (the hidden maisons), and the old local
home banners (placeholders, or maisons' watches).

### The brand wall (19 names)

Tissot, Michael Kors, Tommy Hilfiger, Calvin Klein, Coach, Guess, Hugo Boss, Emporio Armani, Armani Exchange,
Fossil, Lacoste, Diesel, Tory Burch, Just Cavalli, Pinko, Maserati, G-Shock, Seiko, Casio - brands both shops link
from their home pages (21 Nov), with the logos the catalogue holds (catalog_brands.logo_path). Left out: the watch
and fashion maisons that `config/catalog.php` search_exposure already keeps from making any brand claim (Rolex,
Patek Philippe, Hublot, Audemars Piguet, Richard Mille, Cartier, Omega, Vacheron Constantin, Jaeger-LeCoultre,
Breitling, IWC, Franck Muller, Louis Vuitton, Chanel, Hermès, Dior), Gucci (restricted watches), and the budget
brands. The logo files carry a baked-in transparency checkerboard in several cases (Fossil, G-Shock, Guess, Michael
Kors, Seiko, Tissot, Tommy Hilfiger, Armani Exchange): the build turns each into one ink with a clean white ground.
Calvin Klein's catalogue logo file is damaged (squashed and garbled): its tile sets the name instead; worth
re-uploading in the dashboard (Catalogue > Brands).

### Weight (phone, first view)

About 172 KB before text compression: the HTML 18 KB, the stylesheet 20 KB, the signature 10 KB, the Latin fonts
101 KB (the Arabic page adds 22 KB), the three hero images 23 KB (AVIF, 480 px). Below the fold, lazily: the ten
product images (about 210 KB at 800 px) and the 18 logos (40 KB). Lighthouse is measured on the bed before the deploy.

## 3. Content (EN / AR; nothing invented - each line below is a proposal for Michael to confirm)

- About: "Elegance is an Egyptian retail group for original watches and accessories, and the company behind two
  shops: Watchizer and Brand Fashion." / «إليجانس مجموعة تجزئة مصرية للساعات والإكسسوارات الأصلية، والشركة المالكة
  لمتجرين: واتشايزر وبراند فاشون.»
- Our brands: Watchizer - "Original watches, online and in store." / «ساعات أصلية، على الإنترنت وفي فروعنا.»;
  Brand Fashion - "Original bags, wallets and accessories, online and in store." / «حقائب ومحافظ وإكسسوارات
  أصلية، على الإنترنت وفي فروعنا.» Each with its own logo and a button "Visit watchizereg.com" / «زيارة …»
  (the Arabic page links the shops' /ar pages).
- Contact: "90 El Thawra Street, ground floor, Heliopolis, Cairo" (+ "Egypt") / «٩٠ شارع الثورة، الدور الأرضي، مصر
  الجديدة، القاهرة» (+ «مصر»); 012 00113375 (`tel:+201200113375`); info@eleganceeg.com (`mailto:`); "Open the address
  in Google Maps" - the same maps.app.goo.gl link the shops use for this address.
- Footer: "© 2026 Elegance" and links to both shops.
- Head: title "Elegance - the company behind Watchizer and Brand Fashion" / «إليجانس - الشركة المالكة لواتشايزر
  وبراند فاشون», description, canonical (`https://eleganceeg.com/` and `/ar`; www. serves the same page with the same
  canonical, no redirect - a redirect would be another .htaccess change), hreflang en / ar / x-default, og:title,
  og:description, og:image (absolute), og:locale; Organization JSON-LD: name Elegance, alternateName إليجانس, url,
  logo, address (PostalAddress, Heliopolis, Cairo, EG), telephone +20 120 011 3375, email, and Watchizer and Brand
  Fashion as `subOrganization` (and `brand`) with their own urls - the same @id their own sites give them, so the
  graphs join. Indexable.

## 4. Security (each point proved; §5)

| Requirement | How |
|---|---|
| Static only | One invokable controller that renders one Blade view by a route DEFAULT (`en` / `ar`), never from input: no request, query, header or cookie is read; no database query (a test asserts zero queries); no form, input, script or POST. |
| No session, no cookie | Both routes drop the whole session group (`EncryptCookies`, `AddQueuedCookiesToResponse`, `StartSession`, `ShareErrorsFromSession`, the CSRF check, Inertia, the dashboard locale) - the same list the sitemap routes already drop (B8). Tests: no `Set-Cookie` on GET or HEAD of `/` and `/ar`, also when a session cookie is sent in. /manage's Set-Cookie (name and flags) is compared before and after. |
| No hint of the dashboard | Not in the page, its JSON-LD, the sitemap or robots.txt (`manage`, `login`, `sign`, `dashboard`, `/api`, `admin` absent - a test greps the HTML; the deploy greps the live page). |
| robots.txt (THE CONFLICT) | The static robots.txt is shared with the API hosts and says `Disallow: /`: as it is, the page cannot be indexed. Changing that file changes the API hosts' robots.txt (not allowed). So this host gets its own static file through a two-line rewrite in .htaccess (below). Its content allows only the page and its files and disallows the rest - WITHOUT naming /manage: `Allow: /$`, `Allow: /ar$`, `Allow: /elegance/`, `Disallow: /`, `Sitemap: https://eleganceeg.com/elegance/sitemap.xml`. The dashboard also keeps its own noindex meta. |
| No third parties | Fonts, logos, favicon, share image, stylesheet, sitemap and robots under `public/elegance/`, served as static files by Apache (no PHP, no cookie). No CDN, Google Fonts, analytics or iframe; the map is a plain link. Every outbound link `rel="noopener noreferrer"`. |
| Headers on the page (`/`, `/ar`) | `Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; font-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'` (stricter than asked: no script may run at all; the JSON-LD is data, never executed), `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy: camera=(), microphone=(), geolocation=()`, `Cache-Control: public, max-age=3600`, and `X-Powered-By` REMOVED on these two routes. NO HSTS: the host does not send it today (the brief: only if it already does). The CDN's own `upgrade-insecure-requests` CSP header stays (a second CSP only narrows). The static files keep Apache's headers as today. |
| Nothing else changes | Only a new route middleware on the two routes sets headers; no global middleware, no config, no .env, no composer or npm package. /manage, /api and the API hosts' headers compared before and after (§5). |
| Leaks | No version or framework in the page; the 404 page and APP_DEBUG untouched (a test: a missing path on this host is the existing 404, no trace). |

THE .htaccess CHANGE (strictly needed for robots.txt only). Inserted right after the dashboard-host rule; the three
`R=404` rules and /api shut are untouched (the deploy counts them before and after: 3 and 3):

```diff
     # ── the dashboard host: /api stays shut (eleganceeg runbook §7.2) ──────────
     RewriteCond %{HTTP_HOST} ^(www\.)?eleganceeg\.com$ [NC]
     RewriteRule ^api(/|$) - [R=404,L]
+
+    # ── the dashboard host's robots.txt (2026-11-21): the Elegance page is indexable; every other host keeps the
+    #    shared robots.txt (Disallow: /). An internal rewrite to a static file: no PHP, no cookie.
+    RewriteCond %{HTTP_HOST} ^(www\.)?eleganceeg\.com$ [NC]
+    RewriteRule ^robots\.txt$ elegance/robots.txt [L]
```

The deploy reads the live .htaccess and STOPS unless the anchor (those two existing lines) occurs exactly once and the
file has exactly three `R=404,L` lines; it inserts the block, proves the diff is exactly these lines, and the undo
puts the backup back byte for byte. The repo's copy in the runbook (§4.1.1) gets the same lines.

## 5. Proof

- Tests (Pest): both pages render (lang, dir, hreflang, canonical, the JSON-LD parses with every field), no
  Set-Cookie on GET/HEAD, the exact security headers and no X-Powered-By, zero database queries, a query string
  ignored (byte-identical body), no form / input / script / inline style / event handler, every outbound link
  `noopener noreferrer`, every asset on this host, no dashboard word, robots.txt and sitemap contents, the route list
  equal to the baseline plus `/ar` and the changed `/`, /manage still 302 to the sign-in with its cookie, the
  existing 404. ShellTest's "an admin at / goes to /manage" becomes "an admin at / sees the company page".
- A real Apache rehearsal: XAMPP's Apache 2.4.58 binary with its OWN config on a spare port (its own setup is not
  touched), the bed's public folder and a copy of the production-shaped .htaccess: Host-header checks for
  eleganceeg.com (/ the page, /robots.txt the new one, /api 404), api.watchizereg.com (/robots.txt the shared one,
  /manage 404, /elegance/* 404) and an unknown host - before and after the change.
- Lighthouse (the local harness) on the bed: performance, accessibility, best practices and SEO.
- The deploy repeats the host and header checks live and STOPS on any difference from the before-record.

## 6. Deploy (code only) and the About links

`deploy-elegance-20261121.sh` in the usual style: archive sha256, backup names, staging and php -l, the live files
proved to be the a844190 versions (the 19 Nov deploy, live: the dashboard bundle's sha256 matches), backups, the
.htaccess insertion with its checks, the one copy, caches, then the checks of §5 live. Its undo puts back the
replaced files, removes the new ones and restores .htaccess. Rehearsed on a local bed (a stop undone, a run to DONE,
its undo). No migration, no data step.

The About pages: nothing to build. Since 6f03f40 (17 Nov, live) both shops' About pages already link the sister
shop in both languages (`rel="noopener noreferrer"`) and name Elegance as plain text, with no link to eleganceeg.com -
checked on the four live pages on 21 Nov. Before shots will show it.

## 7. Questions for Michael (preview 2)

1. The signature: 1, 2 or 3?
2. The brand wall's 19 names, and the products shown: as listed in §2, or changes?
3. Anything in the page you want worded differently (every sentence and its source is in §2)?

Answered on 21 Nov (preview 1): texts approved; the .htaccess robots lines, fonttools in a throwaway environment and
www serving the same page with the canonical on eleganceeg.com approved.
