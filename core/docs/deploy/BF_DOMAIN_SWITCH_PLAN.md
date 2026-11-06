# Brand Fashion — moving to the real domain (PLAN ONLY, nothing built)

Written 2026-11-05. From `michaelkhalaf.com` / `api.michaelkhalaf.com` (the temporary domain) to
`brandfashionegy.com` / `api.brandfashionegy.com`.

**Nothing in this file has been done, and no code was changed for it.** Every "today" below was read from
the repository on 2026-11-05; every server-side fact (DNS, hPanel, the live `.htaccess`, the live `.env`)
is marked **CHECK** because it was not read — production was not touched.

---

## 0. What the switch is, in one paragraph

Core knows a storefront's hosts from ONE column, `storefronts.domain` (`StorefrontUrls`, `StorefrontHost`).
From it core derives the shop's site (`https://{domain}`), its API and image host (`https://api.{domain}`),
the payment return, the Paymob callback, the Google sign-in callback and the links in every e-mail — and
it decides which storefront a request belongs to by the request's host. So for core the switch is **one
row and one cache flush**. Everything else is around it: DNS and SSL so the new hosts exist, the
`.htaccess` allow-list and CORS so they are let in, a new storefront build so the site calls the new API
host, the third parties that hold the old address (Google OAuth, Paymob, Search Console, the pixels), and
redirects so nothing that pointed at the old address — or at the old WordPress shop — is lost.

**One host per storefront, so the switch is a moment, not a period.** `StorefrontHost` maps a host to a
storefront by `storefronts.domain` only. The instant the row changes, `api.michaelkhalaf.com` belongs to
no storefront and answers 404 to `/api/v2/brandfashion/…`. A browser tab opened before the switch keeps
calling the old API host until it reloads. On a shop that is still on its temporary domain that is a few
minutes of a few tabs; if it must be seamless, the one code change that buys it is listed in §9 (D-1).

## 1. Where the domain is written today (read from the repo)

| Where | What | Changes by |
|---|---|---|
| `storefronts.domain` (row `brandfashion`) | the source of every derived host in core | **SQL**, §5 |
| `storefronts.settings.urls.*` (row `brandfashion`) | explicit overrides, if any were set by SQL | **CHECK** with §5's SELECT; clear or rewrite |
| core `.env` → `CORS_EXTRA_ORIGINS` | the origins the API lets a browser call from | **server `.env`** + `config:cache`, §4 |
| live `public/.htaccess`, API-host condition `^api\.(watchizereg\|michaelkhalaf)\.com$` | which hosts may reach `/api` — 1 line on the live file (the runbook's §4.1.1 block shows it twice: the rule and the default arm's negation — **CHECK** the live file) | **server file** + runbook §4.1.1, §4 |
| `Frontend-brandfashion/src/lib/requestLang.js` `SITE` | canonical, hreflang, sitemap base | **code**, §3 |
| `Frontend-brandfashion/src/lib/listingSeo.js`, `detailSeo.js` `SEO_DOMAIN` | canonicals + JSON-LD on listing and product pages | **code**, §3 |
| `Frontend-brandfashion/app/layout.jsx` (5 places) | `metadataBase`, canonical, Open Graph URLs | **code**, §3 |
| `Frontend-brandfashion/app/(main)/page.jsx` (5 places) | home Open Graph + Store JSON-LD `url`, `logo`, `image` | **code**, §3 |
| `Frontend-brandfashion/public/robots.txt` | `Sitemap:` line | **code**, §3 |
| `Frontend-brandfashion/next.config.js` `images.remotePatterns` | `next/image` refuses any host not listed: `api.michaelkhalaf.com` | **code**, §3 (add the new host, keep the old one for the transition) |
| Hostinger build env of the BF app: `NEXT_PUBLIC_API_BASE`, `NEXT_PUBLIC_ASSET_BASE`, `LARAVEL_ORIGIN` | inlined at build | **hPanel env**, §6 |
| `core/database/seeders/content/blog/brandfashion/*.md` (12 files) | links inside the seeded articles | **content**: re-seed or edit in the dashboard, §8 |
| core tests (`StorefrontUrlsTest`, `SocialPerStorefrontTest`, `StorefrontSettingsScreenTest`, …) | the temporary domain as a fixture value | leave — they test the derivation, not the name |
| Google Cloud OAuth client | authorised redirect URI `https://api.michaelkhalaf.com/api/auth/google/callback` | **Google console**, §7 |
| Paymob (if Brand Fashion ever gets online payment) | the callback is sent per request by core from `api.{domain}` — nothing stored at Paymob; today Brand Fashion is cash on delivery only | nothing now; §7 |
| Mail | links in e-mails derive from `domain`; the sender address and DKIM/SPF are **CHECK** | §7 |

Not affected: Watchizer (its values come from `.env`, not from `storefronts.domain`), the dashboard host,
the shared catalogue, the images on disk (one `Uploads_Images` folder serves every API host).

## 2. Before anything moves — inventory (no change, all reversible)

1. **Namecheap**: confirm who is the registrar of `brandfashionegy.com` and where its DNS is hosted today
   (Namecheap BasicDNS, or nameservers already pointed at Hostinger). **CHECK**. Lower the TTL of the
   records that will change (`@`, `www`, `api`) to 300 s at least a day before.
2. **The old WordPress shop** on `brandfashionegy.com`: before DNS moves, export what it has —
   `/sitemap.xml` (or `/wp-sitemap.xml`), the Search Console "Pages" and "Links" reports for the property,
   and the WooCommerce product list (ID, slug, SKU, title). §8 turns these into the redirect map. Once
   DNS moves the old site is unreachable by name; take the export first, and keep a full backup of the
   WordPress files and database.
3. **Search Console**: verify `brandfashionegy.com` as a DOMAIN property (DNS TXT at Namecheap) now, while
   the old site is still up — the history stays with the property.
4. **Mail on the domain**: list the MX, SPF, DKIM and DMARC records `brandfashionegy.com` has today.
   Moving DNS or nameservers must carry them over unchanged, or the domain's mail stops. **CHECK**.
5. **E-mail sender for Brand Fashion's order mail**: which address core sends from for storefront 2 and
   whether SPF/DKIM for it are on `brandfashionegy.com` already. **CHECK** (`config/watchizer.php`,
   the storefront's mail settings in the dashboard).

## 3. Code changes (one commit, deployable BEFORE the switch only where marked)

All in `Frontend-brandfashion` unless said. None of this is written yet.

1. **One constant for the site address.** `SITE` (`requestLang.js`), both `SEO_DOMAIN`s, the five literals
   in `app/layout.jsx` and the five in `app/(main)/page.jsx` are the same value typed 13 times. Make them
   read one export (keep it a literal, not an env var: a canonical that depends on a build variable is a
   canonical that can be wrong on one deploy). Then the switch is a one-line diff.
2. `public/robots.txt` → `Sitemap: https://brandfashionegy.com/sitemap.xml`.
3. `next.config.js` `images.remotePatterns` → ADD `api.brandfashionegy.com`; keep `api.michaelkhalaf.com`
   until the old domain is retired (a cached page that names an old image URL must still optimise).
   **This one line can ship before the switch.**
4. **Redirects from the old WordPress URLs** — §8. In `next.config.js` `redirects()` for the patterns, a
   generated map for the products.
5. **Core**: nothing to change for the switch itself. Optional, decided in §9: D-1 (a second host per
   storefront for a soft transition), D-2 (a dashboard field for `storefronts.domain`, today SQL only —
   backlog SF-URL-SETTINGS).
6. **Runbook** `RUNBOOK_PHASE2_STOREFRONT.md` §4.1.1: the API-host block names the new host (§4 below);
   update the block and the two host tables that quote it in the same commit, and run `ApiAllowListTest`.
7. `Frontend-brandfashion/TWINS.txt`: none of the files in 1–4 is a twin (each already differs by the
   domain), so no entry changes.

## 4. Server, in order — everything here is ADDITIVE and safe before the switch

| # | Where | Step | Verify |
|---|---|---|---|
| 4.1 | **hPanel** → Domains | Add `brandfashionegy.com` to the Hostinger account that hosts the BF Node app and core. Whether it is added as the app's primary domain or as an additional one depends on how the Node app is bound — **CHECK** in hPanel before choosing. | the domain appears in hPanel |
| 4.2 | **hPanel** → Subdomains | Create `api.brandfashionegy.com` with the SAME document root as `api.michaelkhalaf.com` (core's `public/`). | hPanel shows both sub-domains on one folder |
| 4.3 | **Namecheap** DNS (or hPanel DNS if the nameservers are Hostinger's) | `A @` and `A www` → the BF app's server IP; `A api` → core's server IP (the IPs hPanel shows for 4.1 and 4.2). Keep MX/SPF/DKIM/DMARC as inventoried in §2.4. | `nslookup brandfashionegy.com`, `nslookup api.brandfashionegy.com` |
| 4.4 | **hPanel** → SSL | Issue certificates for `brandfashionegy.com`, `www.` and `api.` once DNS resolves. | `curl -I https://api.brandfashionegy.com/` answers over TLS |
| 4.5 | **server** `core/public/.htaccess` | Widen the API-host condition to `^api\.(watchizereg\|michaelkhalaf\|brandfashionegy)\.com$` — in EVERY line that names the API hosts (the rule, and the default arm's negation if the live file has it). Back the file up first under a new name; never overwrite a backup. The archive's stock file must not replace it (the deploy script's backup/restore rule). | `curl -I https://api.brandfashionegy.com/api/catalog/meta` → **404 before** this step, and after it **404 still** (the host belongs to no storefront yet — that is §5) but `/manage` on it → 404 from the catch-all |
| 4.6 | **server** core `.env` | `CORS_EXTRA_ORIGINS` gains `https://brandfashionegy.com,https://www.brandfashionegy.com` (keep the `michaelkhalaf.com` origins until §8.4). Then `php artisan config:cache`. | `php artisan tinker --execute 'print_r(config("cors.allowed_origins"));'` |

After §4 the new hosts exist, have certificates and are let through Apache — and still serve nothing of
Brand Fashion, because core does not know them. The live shop has not moved.

## 5. The switch (core) — one statement, one flush

Read first, then write. `core_migrations` is not involved; this is data.

```sql
-- READ: what is there now
SELECT id, code, domain, JSON_EXTRACT(settings, '$.urls') AS urls FROM storefronts WHERE code = 'brandfashion';

-- WRITE: the switch
UPDATE storefronts SET domain = 'brandfashionegy.com', updated_at = NOW() WHERE code = 'brandfashion' AND domain = 'michaelkhalaf.com';
-- expect: 1 row. 0 rows = the domain was not what this plan assumed: STOP.

-- ROLLBACK: the same statement with the two values swapped.
```

If the READ shows `urls` overrides naming the old domain, rewrite or remove them in the same sitting — an
override wins over `domain`.

Then flush what remembers the old value (three caches: the resolved storefront row, the host map
`StorefrontCache::HOSTS_KEY`, the URL map `sf:urls`, plus the storefront's payload cache, which holds
image URLs on the old host): `php artisan cache:clear` on the core server. **CHECK** whether production has
a narrower command for the storefront caches before using the blunt one; it is safe either way, it only
costs cold reads.

Verify, in this order:
1. `curl -s -o /dev/null -w '%{http_code}\n' https://api.brandfashionegy.com/api/v2/brandfashion/meta` → **200**
2. the same on `https://api.michaelkhalaf.com/…` → **404** (it now belongs to no storefront — expected)
3. `https://api.watchizereg.com/api/v2/watchizer/meta` → **200** (Watchizer untouched)
4. `https://api.watchizereg.com/api/v2/brandfashion/meta` → **404** (the host binding still holds)
5. an image URL inside the first answer starts with `https://api.brandfashionegy.com/Uploads_Images/` and
   loads.

## 6. The storefront — immediately after §5, in the same sitting

1. **hPanel** → the BF Node app → environment: `NEXT_PUBLIC_API_BASE=https://api.brandfashionegy.com/api`,
   `NEXT_PUBLIC_ASSET_BASE=https://api.brandfashionegy.com`, `LARAVEL_ORIGIN=https://api.brandfashionegy.com`.
   Every `NEXT_PUBLIC_*` is inlined at build time, so the values must be in place BEFORE the build.
2. Deploy the commit of §3 and build. Bind `brandfashionegy.com` (and `www.`) to the app (§4.1).
3. Verify on the new domain: home, a category, a product, the cart, sign-in; view-source of a product page
   shows `<link rel="canonical" href="https://brandfashionegy.com/…">`, `hreflang` pairs on the new
   domain (`/…` and `/ar/…`), JSON-LD URLs on the new domain; `/sitemap.xml` and `/robots.txt` name it.
4. Place one cash-on-delivery test order end to end and check the two e-mails: every link in them is on
   `brandfashionegy.com`.

**Order core → storefront, and why:** the storefront build of §6 calls `api.brandfashionegy.com` from its
first page render (ISR renders at build and on first hit). If the build goes out before §5, every one of
those calls is a 404 and the pages are built empty — and cached that way. Core first costs the reverse:
the OLD site's API calls 404 for the minutes between §5 and §6. An empty cached page is worse than a few
minutes of a failing temporary domain, so core goes first, and §5 and §6 are one sitting.

## 7. Third parties

| Who | What | When |
|---|---|---|
| **Google Cloud** → OAuth client used by Brand Fashion | Add the authorised redirect URI `https://api.brandfashionegy.com/api/auth/google/callback` and the JavaScript origin `https://brandfashionegy.com`. Keep the old ones until §8.4. The URI must match what core sends character for character (`CustomerSocial`): scheme, host, no trailing slash. | **before §5** — adding is harmless, and without it Google sign-in fails the minute the domain flips |
| **Google OAuth consent screen** | Add `brandfashionegy.com` to the authorised domains; the app's home page and privacy-policy links move to the new domain. | before §5 |
| **Search Console** | Property verified in §2.3. After §6: submit `https://brandfashionegy.com/sitemap.xml`. For the old temporary property use **Change of address** (`michaelkhalaf.com` → `brandfashionegy.com`) once the 301s of §8.3 are live — the tool checks them. | after §6 / after §8.3 |
| **Analytics / pixels** (GA4, Meta, TikTok — the ids are build env values) | GA4: the data stream's URL and, if cross-domain is not wanted, nothing else; Meta: add and verify the new domain in Business settings (Domain verification) or the Conversions API events are flagged; TikTok: same idea. **CHECK** which of the three Brand Fashion actually has ids for. | after §6 |
| **Paymob** | Brand Fashion has no online payment today (cash on delivery only). Core sends `notification_url` / `redirection_url` with every payment from `api.{domain}`, so when a contract is added nothing is stored at Paymob that names the old host. If a contract exists by the time of the switch: the callback then arrives on the new host — covered by §4.5 (the callback path is in the allow-list) and §5. | nothing now |
| **Mail** | Links follow `domain` automatically. If the sender address moves to `@brandfashionegy.com`: SPF include and DKIM record for the sending service on the new domain (Namecheap DNS), then the address in the storefront's mail settings. DMARC stays as decided (`p=none`, memory 2026-09-26). | with §5 or later; independent |

## 8. Redirects

### 8.1 The old WordPress URLs on `brandfashionegy.com` → the new shop

The new site answers on the same host the WordPress shop used, so every old URL arrives at the Next app.
Unmatched, each is a 404 and the links and rankings the old shop earned are lost.

Build the map from §2.2's export, in three layers, most specific first:

1. **Products** — `/product/{wp-slug}/` → `/product/{new-slug}`. Match the WooCommerce export to
   `catalog_products` by **SKU** first, by exact title second; what matches neither goes to its category,
   and what has no category goes to `/listing`. Produce a file (`old slug → new path`), commit it, and have
   the report of unmatched products read by a person — do not guess a product.
2. **Categories, tags, brands** — `/product-category/{slug}/` (and nested), `/product-tag/{slug}/`,
   `/brand/{slug}/` → the matching `/category/…`, `/subtypes/…` or `/brand/…` page; a hand-written table,
   it is a few dozen rows.
3. **Fixed pages** — `/shop/` → `/listing`, `/cart/` → `/cart`, `/checkout/` → `/checkout`,
   `/my-account/` (and its sub-paths) → `/account`, `/contact/`, `/about/`, `/privacy-policy/`,
   `/refund_returns/` → the trust pages, the blog's posts → `/blog/…` where an article exists, else `/blogs`.
   WordPress internals (`/wp-admin`, `/wp-login.php`, `/wp-json/…`, `/feed/`, `/?p=…`, `/wp-content/uploads/…`)
   answer **410 Gone** — they are not pages and must not redirect to the home page.

Where it lives: `Frontend-brandfashion/next.config.js` `redirects()` with `permanent: true` (308 — Google
treats it as a 301) for layers 2 and 3; layer 1 as a generated lookup read by the app's `middleware.js`
(a few hundred rows is too many for the config and must be exact-match). Trailing slashes: WordPress URLs
end in `/`, the new ones do not — match both. Arabic: if the old shop had `/ar/…` (WPML/Polylang) map
those to the new `/ar/…` pages, not to English.

Never redirect everything to the home page: Google treats a mass redirect to `/` as a soft 404.

Test before the switch: the map is data, so run it offline — every old URL from the sitemap through the
redirect function, expecting one hop to a path that returns 200 on the staging build.

### 8.2 `www` and `http`

`http://` → `https://` and `www.` → the bare domain (or the reverse — pick one, the canonical must agree):
at Hostinger (**hPanel** → force HTTPS) and in the app's `middleware.js`, which already canonicalises the
host for Watchizer (**CHECK** it reads the same for Brand Fashion).

### 8.3 `michaelkhalaf.com` → `brandfashionegy.com`

After §6 verifies. `michaelkhalaf.com/{path}` → `https://brandfashionegy.com/{path}`, path and query kept,
permanent; `api.michaelkhalaf.com` gets NO redirect (an API answering 301 to a browser's `fetch` only hides
the failure — it stays 404). Simplest place: an `.htaccess` (or hPanel redirect) on the old domain's own
document root, so it does not depend on the Node app. If `michaelkhalaf.com` is to remain Michael's own
domain for other things, redirect only what the shop used and say so here.

### 8.4 Retiring the temporary domain

When Search Console's change of address has been accepted and the old property's clicks are near zero:
remove `michaelkhalaf.com` origins from `CORS_EXTRA_ORIGINS`, the old host from the `.htaccess` condition
and from `next.config.js` `remotePatterns`, the old redirect URI from the Google OAuth client. Each is a
removal and none is urgent.

## 9. Decisions for Michael

Four decisions are open. Each is laid out the same way — the question, the options with what each costs,
my recommendation (marked; it is a recommendation, not a decision), and a line for your answer. Nothing in
§3 to §8 is built or run until they are answered; D-1 and D-2 decide whether there is code to write at all.

### D-1 — Must the switch be seamless, or may the shop be unreachable for a few minutes?

Today a storefront has ONE domain (`storefronts.domain`), so the statement of §5 cuts the old API host
(`api.michaelkhalaf.com`) off at once: a tab that is open on the temporary domain at that moment gets
errors on its next request until it is reloaded on the new one, and the storefront of §6 is built minutes
later.

| | Option | What it costs | What you get |
|---|---|---|---|
| A | **A moment** — switch as the plan is written: §5, then §6, in one sitting at a quiet hour | No code. A few minutes in which the temporary domain's open tabs fail and the new domain is not built yet | The host binding stays exactly as it is: one domain per shop |
| B | **Seamless** — teach core that a storefront may answer on alias hosts (`storefronts.settings.hosts.aliases`), keep `api.michaelkhalaf.com` serving Brand Fashion until the old site redirects | A code change in `StorefrontHost` and `StorefrontUrls` (the lock that keeps the two shops apart), its tests, one more deploy before the switch, and a later change to remove the alias | No failed request for anyone on the temporary domain |

**RECOMMENDATION: A.** The temporary domain has no audience worth a new path through the host binding;
a mistake there mixes the two shops, which is the one thing that binding exists to prevent.

**Your answer (A / B):** ______

### D-2 — Should the domain be changeable from the dashboard, or stay a one-time SQL statement?

The domain is a column that only SQL can change today (backlog item SF-URL-SETTINGS).

| | Option | What it costs | What you get |
|---|---|---|---|
| A | **SQL, once** — run §5's statement by hand (the plan carries it and its rollback) | Nothing to build. You run one statement and one cache flush on the server | The domain cannot be changed by a click, by anyone |
| B | **A dashboard field** — a "Domain" field on the storefront's settings screen, for the owner role only, with the cache flush behind it | A screen change, a permission, validation (a wrong value takes the shop's API offline), tests, a deploy | Changing the domain later needs no server access |

**RECOMMENDATION: A.** It changes once; a field that can take the shop offline with one typing mistake is
a risk kept forever for a convenience used one time.

**Your answer (A / B):** ______

### D-3 — Which address is the canonical one: `brandfashionegy.com` or `www.brandfashionegy.com`?

One of the two is the address every page, the sitemap and Search Console name; the other redirects to it
permanently (§8.2).

| | Option | What it costs | What you get |
|---|---|---|---|
| A | **Bare** — `https://brandfashionegy.com`, `www` redirects to it | Nothing extra: it is how Watchizer is set up, so the same rules and checks apply | Both shops configured the same way; the shorter address in results and in print |
| B | **`www`** — `https://www.brandfashionegy.com`, the bare domain redirects to it | The Brand Fashion setup differs from Watchizer's in every place the host is written (env values, the `.htaccess` condition, OAuth URI, Search Console property) | Only worth it if the old WordPress shop's indexed addresses are `www` and you want them unchanged — the redirects of §8.1 cover that case either way |

**RECOMMENDATION: A (bare), as Watchizer.**

**Your answer (A / B):** ______

### D-4 — When, and in how many sittings?

The steps are not equally risky: §4 (server, additive) and the Google OAuth step of §7 change nothing a
shopper sees; §5 + §6 are the switch; §8.3 points the temporary domain at the new one; §8.4 is cleanup.

| | Option | What it costs | What you get |
|---|---|---|---|
| A | **Two sittings** — the additive steps (§4, §7 Google) on any day before; then §5 + §6 + §8.3 together in one quiet hour; §8.4 weeks later, when Search Console has accepted the change of address | Two visits to the server instead of one | The switch itself is short, and everything it depends on was verified days earlier |
| B | **One sitting** — everything from §4 to §8.3 in one evening | One long session; a DNS or certificate surprise in §4 is met with the shop already half-moved | It is over in one go |
| C | **With the next deploy** — fold §5 + §6 into the deploy of Parts 2 to 7 | Two unrelated changes land together: a fault after the deploy could be either one's | One maintenance window |

**RECOMMENDATION: A, and not on the day of another deploy.** Tell me the day and I will turn §4 to §8
into a script in the style of the deploy scripts, with its stop gates.

**Your answer (A / B / C, and the day):** ______

## 10. Rollback

Until §8.3, the switch is undone by: §5's ROLLBACK statement + `cache:clear`, the BF app's previous build
(the env values of §6.1 back to the old host, rebuild) and nothing else — §4 and §7 are additive and can
stay. After Search Console's change of address is submitted, rolling back also means withdrawing it.

## 11. What this plan does not know (each is a CHECK above)

DNS host and current records of `brandfashionegy.com`; how the BF Node app is bound to a domain in hPanel;
the live `.htaccess` line count for the API hosts; whether `storefronts.settings.urls` holds overrides on
production; the sender address and DNS mail records; which pixels Brand Fashion has; what URLs the old
WordPress shop has. None of them was read: production was not touched for this plan.
