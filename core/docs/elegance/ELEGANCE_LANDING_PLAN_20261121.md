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

## 2. Design (preview)

Concept: the hallmark - the stamp that certifies a watch case or a gold bar as genuine - for a group whose business
is original goods. One bold element, the wordmark in a clipped double frame like an assay punch; the rest quiet.
Palette: onyx #12100D (page), raised #1A1712 (plates), the family gold #C8A45C (Watchizer's own), champagne #E9DCC0,
ivory #F2ECE1, taupe #A69D8E (7:1 on onyx). Type: Cormorant Garamond (Latin) and El Messiri (Arabic), the faces the
shops already self-host under the SIL OFL, copied with their licences; no Google request. Layout: the hallmark
centred, everything under it start-aligned in one column so Arabic mirrors; two brand plates cut with the
hallmark's corners (stacked on a phone); contact as the address plus phone, e-mail and a map link; a plain footer.
One load moment (the hallmark settles in, off with reduced motion). No all-caps labels, numbered markers or arrows.

Wordmark: three options in direction.html - A Hallmark (recommended), B Signature, C Monogram - each in English and
Arabic «إليجانس» with its favicon. The chosen one is drawn as OUTLINED PATHS (no font needed) for the header mark,
`favicon.svg` (+ 32 and 180 px PNGs) and the 1200 x 630 share image.

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

## 7. Questions for Michael

1. The wordmark: A, B or C?
2. The about line and the two brand lines in §3: as written, or your words? Anything to add (I added nothing you did
   not give, except "Egypt" after Cairo)?
3. The .htaccess robots rewrite (§4): approve the two lines?
4. Outlining the wordmark needs a font tool on this machine: may I install fonttools into a throwaway Python
   environment in the scratchpad (nothing in core, deleted after)?
5. www.eleganceeg.com: same page with the canonical on eleganceeg.com, no redirect - fine?
