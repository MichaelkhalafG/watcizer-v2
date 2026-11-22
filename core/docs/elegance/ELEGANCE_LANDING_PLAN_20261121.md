# The Elegance page on eleganceeg.com, plan for approval (21 November 2026)

APPROVED and BUILT on 21 Nov (Michael: "build it now, without another preview round"): what was built, the final
signature, the full link table and every proof are in §8, which supersedes §2 where they differ. Design preview: `checkpoints/2026-11-21_elegance/preview` (direction.html,
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
proved to be the a844190 version (routes/web.php by sha256: the 19 Nov deploy must be DONE first), backups, the
.htaccess insertion with its checks, the one copy, caches, then the checks of §5 live. Its undo puts back the
replaced file, removes the new ones and restores .htaccess. Rehearsed on a local bed (a stop undone, a run to DONE,
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

## 8. BUILT (21-22 Nov) - Michael: "Approved; build it now, without another preview round"

Commits on updates (as michael): 5a4287c (the page), fcadc24 (OfferBuilderTest typed for phpstan, from cd885e0),
fc073ff and 8388164 (layout shift, headings, alt text). Not pushed. Where this section and §2 differ, this one holds.

### 8.1 The signature (Michael's choice: option 3's Latin with option 1's Arabic)

| Part | Typeface | Designer | Licence |
|---|---|---|---|
| Latin "Elegance" | Monsieur La Doulaise | Sudtipos (Alejandro Paul) | SIL OFL 1.1 |
| Arabic «إليجانس» | Aref Ruqaa | Abdullah Aref, Khaled Hosny, Hermann Zapf | SIL OFL 1.1 |

Outlined to SVG paths (HarfBuzz shaping and fontTools' SVG pen, in a throwaway environment, deleted afterwards): no
font file ships for the signature, and the OFL allows commercial use and outlining in a logo (§2). Balanced by eye and
by measure: the Arabic set at 1.18 times the Latin x-height, on the same baseline, 0.16 em apart; the Latin given a
1.6-unit stroke in the same colour so the two weigh alike. One gold, #C9A45E, on the dark ground; the ink #86682F on
the porcelain address card. Checked on dark and light grounds and at 64, 32 and 16 px (preview/shots/signature-final.png).
The favicon is option 3's script E, gold on onyx (SVG, a 32 px PNG and the 180 px touch icon). Files: public/elegance/
signature.svg (10 KB), signature-ink.svg, favicon.svg, favicon-32.png, apple-touch-icon.png, logo.png (the JSON-LD
logo, 600 px) and og-en.jpg / og-ar.jpg (1200 x 630).

### 8.2 The page

Wider (88 rem) and denser than preview 2: sections 2.75-4.5 rem apart, no blank bands. The hero fills the first screen:
the signature, the tagline and the about line, an entry card per shop (logo, what it sells, "Visit ..."), and the
arched porcelain window with three pieces, each a link to its product. Then the ribbon (four published facts), a
section per shop (its story, three facts, its brand + category pages as links, a 7-piece display case, every piece a
link), "Shop by category" (10 photos, each to its category page on the shop that sells it), the brand wall (19
logos, 18 linked), why us, contact, footer. Motion is CSS only and off under prefers-reduced-motion: the signature
writes itself in (right to left in Arabic), a glint crosses the window, photos ease on hover. No script.

Changed from §2: the Just Cavalli watch is out (its slug names Roberto Cavalli); sunglasses ("inspired by" items) and
the Diver page (no product links in its listing) are not linked; the MK bangle is used only as the Bracelets photo
(the product is not in the shops' sitemaps); the Michael Kors watch links Watchizer's MK5566 page (below).

### 8.3 Every link (the rule, and what was checked)

The rule (Michael): watches and every brand's own page on watchizereg.com; bags, wallets, perfumes, jewellery, belts,
electronics and the rest of fashion, brand + category pages included, on brandfashionegy.com. A page is linked only if
it is live: 200 with no redirect, no noindex, its canonical is itself, not a hidden brand, and for a product our photo
is on the page. If the rule's shop does not have it live, the other shop's page if live (FALLBACK below); otherwise not
linked. The Arabic page links each page's /ar version (the same checks, in Arabic). Every link is a plain
`<a rel="noopener noreferrer">` (Michael asked rel="noopener"; §4 asks "noopener noreferrer" for every outbound link:
security wins, and it satisfies both). Checked read-only on 21 Nov (links/check-links.mjs, results links/checked.json,
one request every 800 ms); the shop homes and the MK5566 page re-checked on 22 Nov. The test suite holds the page to
this table: every outbound link must be in it, in the page's language, and every row must be on the page.

| # | Group | Label | Shop | English URL | Arabic URL | Status checked |
|---|---|---|---|---|---|---|
| 1 | Shop home | Watchizer | Watchizer | watchizereg.com | watchizereg.com/ar | 200, index, canonical self, EN + AR (21 Nov) |
| 2 | Shop home | Brand Fashion | Brand Fashion | www.brandfashionegy.com | www.brandfashionegy.com/ar | 200, index, canonical self, EN + AR (21 Nov) |
| 3 | Brand | Tissot | Watchizer | watchizereg.com/brand/tissot | watchizereg.com/ar/brand/tissot | 200, index, canonical self, EN + AR (21 Nov) |
| 4 | Brand | Michael Kors | Watchizer | watchizereg.com/brand/michael-kors | watchizereg.com/ar/brand/michael-kors | 200, index, canonical self, EN + AR (21 Nov) |
| 5 | Brand | Tommy Hilfiger | Watchizer | watchizereg.com/brand/tommy-hilfiger | watchizereg.com/ar/brand/tommy-hilfiger | 200, index, canonical self, EN + AR (21 Nov) |
| 6 | Brand | Calvin Klein | Watchizer | watchizereg.com/brand/calvin-klein | watchizereg.com/ar/brand/calvin-klein | 200, index, canonical self, EN + AR (21 Nov) |
| 7 | Brand | Coach | Watchizer | watchizereg.com/brand/coach | watchizereg.com/ar/brand/coach | 200, index, canonical self, EN + AR (21 Nov) |
| 8 | Brand | Guess | Watchizer | watchizereg.com/brand/guess | watchizereg.com/ar/brand/guess | 200, index, canonical self, EN + AR (21 Nov) |
| 9 | Brand | Hugo Boss | Watchizer | watchizereg.com/brand/hugo-boss | watchizereg.com/ar/brand/hugo-boss | 200, index, canonical self, EN + AR (21 Nov) |
| 10 | Brand | Emporio Armani | Watchizer | watchizereg.com/brand/emporio-armani | watchizereg.com/ar/brand/emporio-armani | 200, index, canonical self, EN + AR (21 Nov) |
| 11 | Brand | Armani Exchange | Watchizer | watchizereg.com/brand/armani-exchange | watchizereg.com/ar/brand/armani-exchange | 200, index, canonical self, EN + AR (21 Nov) |
| 12 | Brand | Fossil | Watchizer | watchizereg.com/brand/fossil | watchizereg.com/ar/brand/fossil | 200, index, canonical self, EN + AR (21 Nov) |
| 13 | Brand | Lacoste | Watchizer | watchizereg.com/brand/lacoste | watchizereg.com/ar/brand/lacoste | 200, index, canonical self, EN + AR (21 Nov) |
| 14 | Brand | Diesel | Watchizer | watchizereg.com/brand/diesel | watchizereg.com/ar/brand/diesel | 200, index, canonical self, EN + AR (21 Nov) |
| 15 | Brand | Tory Burch | Watchizer | watchizereg.com/brand/tory-burch | watchizereg.com/ar/brand/tory-burch | 200, index, canonical self, EN + AR (21 Nov) |
| 16 | Brand | Just Cavalli | Watchizer | watchizereg.com/brand/just-cavalli | watchizereg.com/ar/brand/just-cavalli | 200, index, canonical self, EN + AR (21 Nov) |
| 17 | Brand | Pinko | Watchizer | watchizereg.com/brand/pinko | watchizereg.com/ar/brand/pinko | 200, index, canonical self, EN + AR (21 Nov) |
| 18 | Brand | Maserati | Watchizer | watchizereg.com/brand/maserati | watchizereg.com/ar/brand/maserati | 200, index, canonical self, EN + AR (21 Nov) |
| 19 | Brand | G-Shock | Watchizer | watchizereg.com/brand/g-shock | watchizereg.com/ar/brand/g-shock | 200, index, canonical self, EN + AR (21 Nov) |
| 20 | Brand | Seiko | Brand Fashion | www.brandfashionegy.com/brand/seiko | www.brandfashionegy.com/ar/brand/seiko | 200, index, canonical self, EN + AR (21 Nov); FALLBACK - the rule's shop: wz: noindex (noindex, follow) |
| 21 | Brand | Casio | - | - | - | NOT LINKED: noindex on both shops (21 Nov) |
| 22 | Category | Watches | Watchizer | watchizereg.com/category/watches | watchizereg.com/ar/category/watches | 200, index, canonical self, EN + AR (21 Nov) |
| 23 | Category | Chronograph | Watchizer | watchizereg.com/subtypes/chronograph | watchizereg.com/ar/subtypes/chronograph | 200, index, canonical self, EN + AR (21 Nov) |
| 24 | Category | Automatic | Watchizer | watchizereg.com/subtypes/automatic | watchizereg.com/ar/subtypes/automatic | 200, index, canonical self, EN + AR (21 Nov) |
| 25 | Category | Fashion | Brand Fashion | www.brandfashionegy.com/category/fashion | www.brandfashionegy.com/ar/category/fashion | 200, index, canonical self, EN + AR (21 Nov) |
| 26 | Category | Bags | Brand Fashion | www.brandfashionegy.com/subtypes/bags | www.brandfashionegy.com/ar/subtypes/bags | 200, index, canonical self, EN + AR (21 Nov) |
| 27 | Category | Wallets | Brand Fashion | www.brandfashionegy.com/subtypes/wallets | www.brandfashionegy.com/ar/subtypes/wallets | 200, index, canonical self, EN + AR (21 Nov) |
| 28 | Category | Belts | Brand Fashion | www.brandfashionegy.com/subtypes/belts | www.brandfashionegy.com/ar/subtypes/belts | 200, index, canonical self, EN + AR (21 Nov) |
| 29 | Category | Bracelets | Brand Fashion | www.brandfashionegy.com/subtypes/bracelets | www.brandfashionegy.com/ar/subtypes/bracelets | 200, index, canonical self, EN + AR (21 Nov) |
| 30 | Category | Perfumes | Brand Fashion | www.brandfashionegy.com/subtypes/perfumes | www.brandfashionegy.com/ar/subtypes/perfumes | 200, index, canonical self, EN + AR (21 Nov) |
| 31 | Category | Electronics | Brand Fashion | www.brandfashionegy.com/category/electronics | www.brandfashionegy.com/ar/category/electronics | 200, index, canonical self, EN + AR (21 Nov) |
| 32 | Brand + category | Hugo Boss men's watches | Watchizer | watchizereg.com/brand/hugo-boss/watches/men | watchizereg.com/ar/brand/hugo-boss/watches/men | 200, index, canonical self, EN + AR (21 Nov) |
| 33 | Brand + category | Michael Kors women's watches | Watchizer | watchizereg.com/brand/michael-kors/watches/women | watchizereg.com/ar/brand/michael-kors/watches/women | 200, index, canonical self, EN + AR (21 Nov) |
| 34 | Brand + category | Guess women's watches | Watchizer | watchizereg.com/brand/guess/watches/women | watchizereg.com/ar/brand/guess/watches/women | 200, index, canonical self, EN + AR (21 Nov) |
| 35 | Brand + category | Tommy Hilfiger men's watches | Watchizer | watchizereg.com/brand/tommy-hilfiger/watches/men | watchizereg.com/ar/brand/tommy-hilfiger/watches/men | 200, index, canonical self, EN + AR (21 Nov) |
| 36 | Brand + category | Coach bags | Brand Fashion | www.brandfashionegy.com/brand/coach/bags | www.brandfashionegy.com/ar/brand/coach/bags | 200, index, canonical self, EN + AR (21 Nov) |
| 37 | Brand + category | Guess bags | Brand Fashion | www.brandfashionegy.com/brand/guess/bags | www.brandfashionegy.com/ar/brand/guess/bags | 200, index, canonical self, EN + AR (21 Nov) |
| 38 | Brand + category | Tommy Hilfiger bags | Brand Fashion | www.brandfashionegy.com/brand/tommy-hilfiger/bags | www.brandfashionegy.com/ar/brand/tommy-hilfiger/bags | 200, index, canonical self, EN + AR (21 Nov) |
| 39 | Brand + category | Coach wallets | Brand Fashion | www.brandfashionegy.com/brand/coach/wallets | www.brandfashionegy.com/ar/brand/coach/wallets | 200, index, canonical self, EN + AR (21 Nov) |
| 40 | Brand + category | Calvin Klein wallets | Brand Fashion | www.brandfashionegy.com/brand/calvin-klein/wallets | www.brandfashionegy.com/ar/brand/calvin-klein/wallets | 200, index, canonical self, EN + AR (21 Nov) |
| 41 | Brand + category | Tommy Hilfiger wallets | Watchizer | watchizereg.com/brand/tommy-hilfiger/wallets | watchizereg.com/ar/brand/tommy-hilfiger/wallets | 200, index, canonical self, EN + AR (21 Nov); FALLBACK - the rule's shop: bf: noindex (noindex, follow) |
| 42 | Product | BOSS Legacy | Watchizer | watchizereg.com/product/boss-legacy-mens-watch-with-black-dial-and-silver-bracelet-hb1513671 | watchizereg.com/ar/product/boss-legacy-mens-watch-with-black-dial-and-silver-bracelet-hb1513671 | 200, index, canonical self, our photo on the page, EN + AR (21 Nov) |
| 43 | Product | BOSS Navigator | Watchizer | watchizereg.com/product/boss-navigator-mens-watch-with-charcoal-dial-and-black-mesh-bracelet-hb1513674 | watchizereg.com/ar/product/boss-navigator-mens-watch-with-charcoal-dial-and-black-mesh-bracelet-hb1513674 | 200, index, canonical self, our photo on the page, EN + AR (21 Nov) |
| 44 | Product | BOSS Intensity | Watchizer | watchizereg.com/product/boss-intensity-mens-watch-with-charcoal-dial-and-silver-bracelet-hb1513680 | watchizereg.com/ar/product/boss-intensity-mens-watch-with-charcoal-dial-and-silver-bracelet-hb1513680 | 200, index, canonical self, our photo on the page, EN + AR (21 Nov) |
| 45 | Product | BOSS Automatic | Watchizer | watchizereg.com/product/boss-mens-automatic-watch-black-dial-black-strap-hb1514120 | watchizereg.com/ar/product/boss-mens-automatic-watch-black-dial-black-strap-hb1514120 | 200, index, canonical self, our photo on the page, EN + AR (21 Nov) |
| 46 | Product | Emporio Armani Renato | Watchizer | watchizereg.com/product/emporio-armani-mens-renato-three-hand-date-black-tone-stainless-steel-watch-ar11276 | watchizereg.com/ar/product/emporio-armani-mens-renato-three-hand-date-black-tone-stainless-steel-watch-ar11276 | 200, index, canonical self, our photo on the page, EN + AR (21 Nov) |
| 47 | Product | Emporio Armani AR11015 | Watchizer | watchizereg.com/product/emporio-armani-watch-for-men-ar11015 | watchizereg.com/ar/product/emporio-armani-watch-for-men-ar11015 | 200, index, canonical self, our photo on the page, EN + AR (21 Nov) |
| 48 | Product | Michael Kors MK5566 | Watchizer | watchizereg.com/product/michael-kors-mk5566-pearl-light-blue-dial-silver-stainless-steel-bracelet-watch | watchizereg.com/ar/product/michael-kors-mk5566-pearl-light-blue-dial-silver-stainless-steel-bracelet-watch | 200, index, canonical self, our photo on the page, EN + AR (re-checked 21 Nov: the old mk00006 slug answers 308 to it) |
| 49 | Product | Tommy Hilfiger Kane | Watchizer | watchizereg.com/product/tommy-hilfiger-kane-mens-watch-1710401-blue-dial-silver-stainless-steel-bracelet | watchizereg.com/ar/product/tommy-hilfiger-kane-mens-watch-1710401-blue-dial-silver-stainless-steel-bracelet | 200, index, canonical self, our photo on the page, EN + AR (21 Nov) |
| 50 | Product | Tory Burch shoulder bag | Brand Fashion | www.brandfashionegy.com/product/tory-burch-light-cream-women-shoulder-bag-159379-100 | www.brandfashionegy.com/ar/product/tory-burch-light-cream-women-shoulder-bag-159379-100 | 200, index, canonical self, our photo on the page, EN + AR (21 Nov) |
| 51 | Product | Pinko crossbody bag | Brand Fashion | www.brandfashionegy.com/product/pinko-white-women-crossbody-bag-100941a0gk-z14q | www.brandfashionegy.com/ar/product/pinko-white-women-crossbody-bag-100941a0gk-z14q | 200, index, canonical self, our photo on the page, EN + AR (21 Nov) |
| 52 | Product | Coach shoulder bag | Brand Fashion | www.brandfashionegy.com/product/coach-dark-brown-women-shoulder-bag-cad75-b4mpl | www.brandfashionegy.com/ar/product/coach-dark-brown-women-shoulder-bag-cad75-b4mpl | 200, index, canonical self, our photo on the page, EN + AR (21 Nov) |
| 53 | Product | Coach bifold wallet | Brand Fashion | www.brandfashionegy.com/product/coach-womens-signature-canvas-floral-heart-bifold-wallet-c7653-burgundy | www.brandfashionegy.com/ar/product/coach-womens-signature-canvas-floral-heart-bifold-wallet-c7653-burgundy | 200, index, canonical self, our photo on the page, EN + AR (21 Nov) |
| 54 | Product | Tommy Hilfiger backpack | Brand Fashion | www.brandfashionegy.com/product/tommy-hilfiger-mens-black-front-pocket-backpack | www.brandfashionegy.com/ar/product/tommy-hilfiger-mens-black-front-pocket-backpack | 200, index, canonical self, our photo on the page, EN + AR (21 Nov) |
| 55 | Product | Guess handbag | Brand Fashion | www.brandfashionegy.com/product/guess-womens-handbag-large-size-pink-bag0061 | www.brandfashionegy.com/ar/product/guess-womens-handbag-large-size-pink-bag0061 | 200, index, canonical self, our photo on the page, EN + AR (21 Nov) |
| 56 | Product | Tommy Hilfiger laptop briefcase | Brand Fashion | www.brandfashionegy.com/product/tommy-hilfiger-dark-grey-and-black-laptop-briefcase-with-shoulder-strap | www.brandfashionegy.com/ar/product/tommy-hilfiger-dark-grey-and-black-laptop-briefcase-with-shoulder-strap | 200, index, canonical self, our photo on the page, EN + AR (21 Nov) |
| 57 | Product | Stronger With You Intensely | Brand Fashion | www.brandfashionegy.com/product/stronger-with-you-intensely-eau-de-parfum-men-perfume-100ml | www.brandfashionegy.com/ar/product/stronger-with-you-intensely-eau-de-parfum-men-perfume-100ml | 200, index, canonical self, our photo on the page, EN + AR (21 Nov) |
| 58 | Map | Heliopolis (the office) | Google Maps | maps.app.goo.gl/c5wtDgsmLEP398ht7 | same | outbound Google Maps link (the shops' own) |
| 59 | Map | Arkadia Mall | Google Maps | maps.app.goo.gl/jA9owX6N3L6bCpXP8 | same | outbound Google Maps link (the shops' own) |

The exceptions: FALLBACK 2 - Seiko's brand page (Watchizer's is noindex: Brand Fashion's), Tommy Hilfiger wallets
(Brand Fashion's is noindex: Watchizer's). NOT LINKED 1 - Casio (noindex on both shops; shown, not linked). Changed
after the check: the Michael Kors watch was first a fallback to Brand Fashion (Watchizer's mk00006 slug answers 308);
the 308 leads to Watchizer's live MK5566 page (200, index, canonical itself, our photo on it, EN and AR), so by the
rule it links there.

### 8.4 Security as built (§4, unchanged and all tested)

What the page CAN do: answer GET and HEAD on / and /ar with one static HTML document, its stylesheet, fonts and images
from public/elegance, and plain links out. What it CANNOT do: run a script (CSP default-src 'none'; the only script
element is the JSON-LD, which is data), be framed, send a form, read any input (the language is the route's default;
a query string changes nothing, byte for byte), touch the database (zero queries), start a session or set a cookie
(the session group is dropped on both routes, as on the sitemaps), or point at the dashboard (no word of it in the
page, the JSON-LD, robots.txt or the sitemap). Headers on the two routes only: the CSP above, nosniff, X-Frame-Options
DENY, Referrer-Policy strict-origin-when-cross-origin, Permissions-Policy camera, microphone and geolocation denied,
Cache-Control public 3600, X-Powered-By removed; no HSTS (the host does not send it). Everything else keeps its own
headers - proved on Apache by the before / after records (§8.5). No package was added. The 404 is the existing page.
Two notes: the page preloads its own fonts (self, inside the CSP); and Lighthouse's SEO score is 92 because its
robots.txt audit fetches the file from inside the page, which the CSP refuses - crawlers read robots.txt directly,
and the deploy checks the served file byte for byte. The CSP is not loosened for a lab score.

### 8.5 Proof

- Tests: tests/Feature/Elegance/ElegancePageTest.php (10 tests) and ShellTest ("an admin at /
  sees the company page"), all passing; phpstan level 10: no errors on the whole project.
- The whole suite on scratch_relpest_20261117 (two halves, D5): 159 failures, every one also failing on a844190 (the
  rehearsal bed's tree, same database) except one, ServerTranslationCoverageTest (the content file's Arabic was not
  marked; it now carries the file-level i18n-exempt with its consumer), then fixed and passing. 11 tests fail on the
  control only.
- Routes (before/route-list-before.json, after/route-list-after.json, after/route-list-diff.txt): 261 before, 262
  after; the only differences are GET / (the redirect named dashboard becomes the page, named elegance) and GET /ar.
- Apache rehearsal: XAMPP's httpd 2.4.58 with its own config on 127.0.0.1:8098 (rehearsal/httpd-bed.conf), PHP 8.3
  through php-cgi FastCGI, a production-shaped tree (core at a844190 by git archive, a vendor copy, its own .env with
  APP_DEBUG=false) and a production-shaped .htaccess (the repo's, "AddType image/avif .avif" first, §4.1.1's block).
  Every host by its Host header: eleganceeg.com, www, api.watchizereg.com, api.brandfashionegy.com and an unknown
  host, 17 paths each (rehearsal/out/apache-before.txt, apache-after.txt). Before: / 302 to /manage with the session
  and XSRF cookies, exactly as live. After: / and /ar the page with its headers and no cookie, robots.txt the page's
  own on eleganceeg.com and www, the API hosts' 34 lines identical (status, Location, cookies, headers, body), /manage
  and /manage/login with the same cookies and attributes, /api 404, the 404 page unchanged. An unknown host shows the
  page where it showed the redirect (the default arm reaches the app, as before).
- Lighthouse 12.8.2 on the bed (after/lighthouse.json): see the table below; CLS 0 everywhere. On the way, a probe
  that holds the web fonts back 1.5 s found three layout shifts the first build had (the hero centred as a whole, its
  engraved layer sized as a share of the hero, the wider Georgia fallback): fixed in fc073ff and 8388164.
- Screenshots: before/shots (live eleganceeg.com / and /ar - the sign-in and a 404 - and the four live About pages)
  and after/shots (desktop 1440 and phone 390, EN and AR, from the bed through Apache with the CSP in force).

| Lighthouse (the bed) | Performance | Accessibility | Best practices | SEO | FCP | LCP | CLS |
|---|---|---|---|---|---|---|---|
| Phone, English | 99 | 100 | 100 | 92 | 1.1 s | 2.0 s | 0 |
| Phone, Arabic | 99 | 100 | 100 | 92 | 1.2 s | 2.0 s | 0 |
| Desktop, English | 100 | 100 | 100 | 92 | 0.3 s | 0.4 s | 0 |
| Desktop, Arabic | 99 | 100 | 100 | 92 | 0.3 s | 0.4 s | 0 |

### 8.6 The deploy (code only), rehearsed

RUN IT AFTER the 19 Nov core deploy reached DONE (it replaces routes/web.php as that deploy leaves it, a844190, and
STOPS before any change otherwise). Files (deploy-scripts/ and C:\temp, not in the repo):

    elegance-20261121.tar.gz        a0541e9d614f3cc790039191c681de032ab0a8717959e8273ef78eb6fd4c4386  (1,833,512 bytes; 140 files: 1 replaced, 139 new; manifests)
    deploy-elegance-20261121.sh     759a12a51a40dd2295048fa599969c67e502d976947b19f32b22a3a6a04580cc
    undo-elegance-20261121.sh       9be3b15e0cf4d018d3bc6f95e362e0a467be841564ed188ae9652dd21718c0c8
    build-elegance-20261121.sh      142cafba12f287cfa4937abadf2363955c063ab1d5cacb15461a1c65bf98e276  (local: builds the archive)

What it does, each step a gate: the archive's sha256 and every file's; php -l; the live routes/web.php proved to be
a844190's; none of the new files there; APP_DEBUG false; the hosts' before-record (/ must be the redirect, /manage the
302, /api 404, the API hosts 401 / 404); a full before-snapshot and a backup of routes/web.php; the .htaccess backed
up, its first line and its three R=404 rules checked, the anchor (the dashboard host's condition and /api rule) found
exactly once, the new file proved to be the old one plus the five lines; the one copy; caches; the cached routes
(elegance and elegance.ar in, dashboard out, one route more); the hosts after (the page with its exact headers and no
cookie, the right canonical in both languages, robots.txt byte for byte, www, a missing page 404 with no trace) and
every other host and path identical to the before-record. The checks add a unique query string so they read the
origin, not the CDN. The undo restores the .htaccess (only if it is exactly the run's file), routes/web.php (proved
to be a844190's), removes the 139 files and their folders, re-caches, and proves every host answers as before.

Rehearsed on the bed with the final archive: run a stopped on purpose after the copy (step 6) and its undo restored it
(every host and path as before, 52 lines); run b went to DONE and its undo restored it; run c went to DONE and stayed
for Lighthouse and the screenshots. Logs: rehearsal/logs (run-a, undo-a, run-b, undo-b, run-c; the two earlier
archives' rehearsals as archive1-* and archive2-*).

TO RUN (Michael; the SSH details are in the hand-over, never here):

    scp -P <port> C:\temp\elegance-20261121.tar.gz deploy-elegance-20261121.sh undo-elegance-20261121.sh <user>@<host>:~/
    ssh -p <port> <user>@<host>
    sha256sum ~/elegance-20261121.tar.gz ~/deploy-elegance-20261121.sh ~/undo-elegance-20261121.sh   (compare above)
    bash ~/deploy-elegance-20261121.sh 2>&1 | tee ~/deploy-elegance-20261121.log

then purge the CDN cache for eleganceeg.com in hPanel once. UNDO: TAG=<the run's tag> bash ~/undo-elegance-20261121.sh

### 8.7 Found on the way

- The runbook's copy of the .htaccess (§4.1.1) names api.(watchizereg|michaelkhalaf).com, but production answers
  api.brandfashionegy.com (401 on /api/catalog/meta): the live file has moved on from the runbook. The deploy anchors
  only on the dashboard-host lines, so it does not depend on it; the runbook's copy is worth bringing up to date from
  the live file.
- The CDN answers a headless browser on eleganceeg.com with "Checking your browser" (403); normal browsers and curl
  get the page. After the deploy, Search Console's URL inspection of https://eleganceeg.com/ shows whether Google gets
  the page.
- Calvin Klein's catalogue logo file is damaged; its tile sets the name. Worth re-uploading (Catalogue > Brands).
