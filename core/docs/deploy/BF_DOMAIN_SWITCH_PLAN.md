# Brand Fashion — moving to the real domain (PLAN ONLY, nothing built)

> **2026-11-12: switch day runs from `BF_SWITCH_DAY_20261112.md`** (one sitting, scripts with gates). This plan stays
> the reference for the reasons; that runbook's §11 lists where it corrects this file (§5's SQL, the cache flush,
> the two API-host lines, the Conversions API).

Written 2026-11-05. From `michaelkhalaf.com` / `api.michaelkhalaf.com` (the temporary domain) to
`brandfashionegy.com` / `api.brandfashionegy.com`.

**Nothing in this file has been done on production.** Every "today" below was read from the repository on
2026-11-05; every server-side fact (DNS, hPanel, the live `.htaccess`, the live `.env`) is marked **CHECK**
because it was not read — production was not touched.

**Updated 2026-11-11** (the legacy import, `docs/deploy/BF_LEGACY_IMPORT_2026-11-11.md`): the canonical host
is now **`www.brandfashionegy.com`** — Michael's decision, "as the old site was" (D-3 below is closed); §8.1
is no longer a plan but a built, rehearsed map (`bf:legacy-redirects`) with its numbers; the old site's
Rank Math data, its feeds and its third parties are inventoried from the WordPress dump of 9 November; §9
re-states every decision with a recommendation.

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
| `storefronts.settings.urls.frontend` (row `brandfashion`) | **the `www` canonical (2026-11-11):** `domain` stays the BARE `brandfashionegy.com` — core derives `api.{domain}` from it, so `domain = www.…` would make the API host `api.www.brandfashionegy.com` — and the site address is the `frontend` override `https://www.brandfashionegy.com`. `StorefrontHost` matches a sub-domain of `domain`, so a request on `www.` resolves to Brand Fashion (`str_ends_with($host, '.'.$domain)`) | **SQL** or the settings screen (D25), §5 |
| `storefront_redirects` (storefront 2, source `bf_legacy`) | the old WordPress URLs → the new ones, 301 (§8.1) — written by `bf:legacy-redirects --apply`; **nothing serves this table yet** (D-17) | the command, §8.1 |
| core `.env` → `CORS_EXTRA_ORIGINS` | the origins the API lets a browser call from | **server `.env`** + `config:cache`, §4 |
| live `public/.htaccess`, API-host condition `^api\.(watchizereg\|michaelkhalaf)\.com$` | which hosts may reach `/api` — **2 lines on the live file** (the rule and the default arm's negation; Part 3 stopped on expecting 1, 2026-11-12) | **server file** + runbook §4.1.1, §4 |
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
4. **Redirects from the old WordPress URLs** — §8.1: the map is built in core (`bf:legacy-redirects`);
   the app needs the READER — one `middleware.js` branch for legacy-shaped paths (D-17 of the import record).
   The site constant of item 1 is `https://www.brandfashionegy.com` (D-3).
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
| 4.6 | **server** core `.env` | `CORS_EXTRA_ORIGINS` gains `https://www.brandfashionegy.com,https://brandfashionegy.com` — `www` is the one the site runs on; the bare one only ever redirects, it is listed so a browser caught mid-redirect is not refused (keep the `michaelkhalaf.com` origins until §8.4). Then `php artisan config:cache`. | `php artisan tinker --execute 'print_r(config("cors.allowed_origins"));'` |

After §4 the new hosts exist, have certificates and are let through Apache — and still serve nothing of
Brand Fashion, because core does not know them. The live shop has not moved.

## 5. The switch (core) — one statement, one flush

Read first, then write. `core_migrations` is not involved; this is data.

```sql
-- READ: what is there now
SELECT id, code, domain, JSON_EXTRACT(settings, '$.urls') AS urls FROM storefronts WHERE code = 'brandfashion';

-- WRITE: the switch (bare domain = the API host's parent; www = the site, as the old shop)
UPDATE storefronts
   SET domain = 'brandfashionegy.com',
       settings = JSON_SET(settings, '$.urls.frontend', 'https://www.brandfashionegy.com'),
       updated_at = NOW()
 WHERE code = 'brandfashion' AND domain = 'michaelkhalaf.com';
-- expect: 1 row. 0 rows = the domain was not what this plan assumed: STOP.

-- ROLLBACK: domain back to 'michaelkhalaf.com' and JSON_REMOVE(settings, '$.urls.frontend')
-- (or JSON_SET back to whatever the READ showed, if an override existed before).
```

The same two values can be typed on the storefront's settings screen (D25, 2026-10-13) instead of SQL;
the screen validates them with the readers' own refusals.

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
   `NEXT_PUBLIC_ASSET_BASE=https://api.brandfashionegy.com`, `LARAVEL_ORIGIN=https://api.brandfashionegy.com`,
   and **`SITE_CANONICAL_HOST=www.brandfashionegy.com`** (2026-11-11: the middleware then sends the bare domain to
   `www`; unset, it keeps the temporary domain's www → apex rule). **CHECK that `STOREFRONT_SERVER_KEY` is set on
   the app** (D35 put the same value as core's into its panel): the old-URL redirects are looked up by the
   storefront server, all from ONE address, and without the key core's per-address limit (60 a minute) answers 429
   and the old URLs fall back to "served as they are" — measured in the rehearsal of 2026-11-11, where it failed
   most rows until the key was set. With the key the server has its own 1,200 a minute (`STOREFRONT_SERVER_RATE`),
   shared with its ordinary page renders.
   Every `NEXT_PUBLIC_*` is inlined at build time, so the values must be in place BEFORE the build — and treat
   `SITE_CANONICAL_HOST` and `LARAVEL_ORIGIN` the same way (the middleware may be built with them inlined).
   On core, the same sitting: `FEED_GOOGLE_BRANDFASHION_TOKEN` (40 letters/digits) in `.env`, `config:cache`,
   `php artisan feeds:google --storefront=brandfashion`, then the feed URL
   `https://api.brandfashionegy.com/feeds/google/brandfashion/<token>.tsv` in Merchant Center (D-9).
2. Deploy the commit of §3 and build. Bind `brandfashionegy.com` (and `www.`) to the app (§4.1).
3. Verify on the new domain: home, a category, a product, the cart, sign-in; view-source of a product page
   shows `<link rel="canonical" href="https://www.brandfashionegy.com/…">`, `hreflang` pairs on the new
   domain (`/…` and `/ar/…`), JSON-LD URLs on the new domain; `/sitemap.xml` and `/robots.txt` name it.
   The §3 constant is `https://www.brandfashionegy.com`; the temporary domain's `noindex` (BF-NOINDEX) comes
   off in the same build — the real domain must be indexable.
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
| **Google Merchant Center** (measured 2026-11-11: the old site's *Google Listings & Ads* plugin had **4,926 products synced**, 170 open issues) | The product feed stops with WordPress. Core has a Meta feed (`feeds:meta`) and **no Google feed**: Google Shopping listings lapse until one exists (D-9). In Merchant Center, the website URL and the feed source move to `www.brandfashionegy.com`. | before §5 if a feed exists; else accepted |
| **Meta / Google catalogues** | The old site also served hand-written feeds (`/catalog-feeds/{facebook,google}-catalog{,-ar}.php`, `/feeds/fb/`). Any catalogue in Meta Commerce Manager pulling from those URLs breaks at the switch: point it at core's Meta feed for storefront 2 first. **CHECK** which catalogues read which URL. | before §5 |
| **MyFatoorah / Tamara** | The old site took online payments through MyFatoorah — **1,191 of 5,873 orders** used a MyFatoorah method (the rest cash on delivery); the Tamara plugin was active. The new shop launches cash on delivery only (C-1+); the MyFatoorah provider is not built (A4). Any webhook URL registered at MyFatoorah for the old site stops answering at the switch — **CHECK** the MyFatoorah portal and remove it. | before §5 |
| **PixelYourSite / TikTok** | The old site's pixels ran through PixelYourSite (5 option rows) and the TikTok plugin. Brand Fashion's own pixel ids go in the app's build env (D11/D34); which ids the old site used is **CHECK** in the WordPress admin — reuse them only if the campaign history should continue. | with §6 |
| **Mail** | Links follow `domain` automatically. If the sender address moves to `@brandfashionegy.com`: SPF include and DKIM record for the sending service on the new domain (Namecheap DNS), then the address in the storefront's mail settings. DMARC stays as decided (`p=none`, memory 2026-09-26). | with §5 or later; independent |

### 7.1 Brand Fashion's own sending address on `brandfashionegy.com` — DECIDED 2026-11-11 night (O-5, O-6); plan only, nothing done

**Decided (Michael):** a NEW Hostinger mailbox `no-reply@brandfashionegy.com`, wired to core's storefront mail
settings exactly like Watchizer's; **no forwarder**; the old Namecheap Private Email box is **not wanted** — nothing
kept or migrated from it; the MX records simply move to Hostinger.

**How Watchizer's is set up (read 2026-11-11: the `storefront_mailboxes` row on the production copy, and public DNS):**

| | Watchizer (`watchizereg.com`) |
|---|---|
| Mailbox | **Hostinger Email**, `no-reply@watchizereg.com` (hPanel → Emails) |
| Core's mailbox row (`storefront_mailboxes`, storefront 1) | host `smtp.hostinger.com`, port `465`, encryption `ssl`, username = from address = `no-reply@watchizereg.com`, from name `Watchizer`, daily cap 800 with 200 kept for order and account mail, the team recipients; the password encrypted in the row |
| Nameservers | Hostinger (`ns1/ns2.dns-parking.com`), so hPanel wrote the mail records itself |
| MX | `mx1.hostinger.com` priority 5, `mx2.hostinger.com` priority 10 |
| SPF (TXT `@`) | `v=spf1 include:_spf.mail.hostinger.com ~all` |
| DKIM | three CNAMEs: `hostingermail-a._domainkey` → `hostingermail-a.dkim.mail.hostinger.com`, the same for `-b` and `-c` |
| DMARC (TXT `_dmarc`) | `v=DMARC1; p=none` (as decided, 2026-09-26) |

Brand Fashion today sends from `no-reply@bf.michaelkhalaf.com` with the same settings (storefront 2's row: the same
host, port, encryption, cap 800 / reserve 200, from name `Brand Fashion`).

**`brandfashionegy.com` today (public DNS, 2026-11-11):** nameservers **Namecheap** (`dns1/dns2.registrar-servers.com`);
MX `mx1.privateemail.com` and `mx2.privateemail.com` (priority 10 each) — Namecheap Private Email, no subscription in
Michael's account and not wanted (O-6); SPF `v=spf1 include:spf.privateemail.com ~all`; a DKIM key at
`default._domainkey` (Private Email's); **no DMARC record**.

**What moving MX does to `Info@brandfashionegy.com` (a consequence of O-5 + O-6, written down, not reopened):** MX
decides where mail TO every address of the domain goes. `Info@brandfashionegy.com` is published on Brand Fashion's
contact page (`Frontend-brandfashion/src/content/trustPages.js`, `EMAIL`). From the minute MX points at Hostinger, a
message to Info@ is answered by Hostinger, which has no such address (no mailbox, no forwarder) — **it bounces**.
See O-7 (§9) for the choice; nothing in the steps below depends on it.

**The steps (all before §5, independent of the switch, none done):**

1. **hPanel — create the mailbox.** hPanel → **Emails** → the email service that holds `no-reply@watchizereg.com` →
   add the domain **`brandfashionegy.com`** to it (**CHECK** first that the plan has a free domain/mailbox slot; a
   domain whose DNS stays at Namecheap is verified by a TXT record hPanel shows — add it in step 2) → **Email
   accounts → Create email account** → `no-reply@brandfashionegy.com`, a long random password (it is typed once, in
   step 4, and kept in the password manager — never in a ticket or a chat). The menu wording is from the hPanel of
   2026; where hPanel's own DNS list for the domain differs from step 2, **hPanel's values win**.
2. **DNS at Namecheap** — Domain List → `brandfashionegy.com` → **Advanced DNS**. The nameservers stay at Namecheap
   (moving them would move every record of the live WordPress site too). TTL **Automatic** everywhere.
   - **Mail Settings** (the box above the records): **Custom MX** — otherwise Namecheap keeps re-adding Private
     Email's MX.
   - The records, exactly:

     | Action | Type | Host | Value | Priority |
     |---|---|---|---|---|
     | delete | MX | `@` | `mx1.privateemail.com` | 10 |
     | delete | MX | `@` | `mx2.privateemail.com` | 10 |
     | add | MX | `@` | `mx1.hostinger.com` | **5** |
     | add | MX | `@` | `mx2.hostinger.com` | **10** |
     | replace | TXT | `@` | `v=spf1 include:_spf.mail.hostinger.com ~all` (instead of `v=spf1 include:spf.privateemail.com ~all` — ONE SPF record, never two) | — |
     | add | CNAME | `hostingermail-a._domainkey` | `hostingermail-a.dkim.mail.hostinger.com` | — |
     | add | CNAME | `hostingermail-b._domainkey` | `hostingermail-b.dkim.mail.hostinger.com` | — |
     | add | CNAME | `hostingermail-c._domainkey` | `hostingermail-c.dkim.mail.hostinger.com` | — |
     | delete | TXT | `default._domainkey` | Private Email's DKIM key | — |
     | add | TXT | `_dmarc` | `v=DMARC1; p=none` | — |
     | add (if hPanel asks) | TXT | as hPanel shows | hPanel's domain-verification value | — |

     Nothing else changes: the `@` / `www` A and CNAME records of the old shop stay as they are until §4.3 / §5.
   - **CHECK — the old WordPress shop's own mail:** if it sends its order mails through Private Email's SMTP, they
     stop authenticating when SPF changes. With no Private Email subscription that path is presumably already dead;
     a WooCommerce test order before the change settles it. If it is alive, do step 2 in the switch sitting.
3. **Verify from outside** (any computer): `nslookup -type=mx brandfashionegy.com` (the two Hostinger names),
   `nslookup -type=txt brandfashionegy.com` (ONE `v=spf1`), `nslookup -type=txt _dmarc.brandfashionegy.com`,
   `nslookup -type=cname hostingermail-a._domainkey.brandfashionegy.com` (and `-b`, `-c`); hPanel → Emails → the
   domain's DNS status green. Then send one mail TO `no-reply@brandfashionegy.com` from any address and see it arrive
   in hPanel's webmail.
4. **The dashboard — Brand Fashion's mail settings** (`/manage/storefronts/2/edit`, section **صندوق البريد (الرسائل
   الصادرة)**). Only after step 3 is green — a sender address whose DNS is not ready fails SPF/DKIM. The values,
   Watchizer's row with Brand Fashion's address:

   | Field on the screen | Value |
   |---|---|
   | صندوق البريد مفعّل (mailbox on) | on — as it is |
   | مضيف SMTP (host) | `smtp.hostinger.com` — as it is |
   | المنفذ (port) | `465` — as it is |
   | التشفير (encryption) | `SSL (منفذ 465)` — as it is |
   | اسم دخول SMTP (username) | **`no-reply@brandfashionegy.com`** |
   | كلمة مرور SMTP (password) | **the new mailbox's password** (write-only: the screen then says a password is stored) |
   | عنوان المرسِل (from address) | **`no-reply@brandfashionegy.com`** |
   | اسم المرسِل (from name) | `Brand Fashion` — as it is |
   | الحد اليومي (daily cap) | `800` — as it is |
   | المحجوز لرسائل الطلبات والحسابات (reserve) | `200` — as it is |
   | عناوين الفريق لإشعارات الطلبات (team recipients) | as they are |

   Save. The banner must read **جاهز: كل ما يحتاجه الإرسال الفعلي مضبوط.** The same from the server instead, the password
   from the environment (never an argument):

   ```
   BF_MAIL_PW='…' php artisan mail:mailbox brandfashion --username=no-reply@brandfashionegy.com \
       --from=no-reply@brandfashionegy.com --password-env=BF_MAIL_PW
   php artisan mail:mailbox brandfashion --show      # the row; the password shown only as "set"
   ```

   Watchizer's row is not touched. The old `no-reply@bf.michaelkhalaf.com` mailbox can be deleted in hPanel once a
   week of Brand Fashion mail has gone out from the new one.
5. **Prove it:** one cash-on-delivery test order on Brand Fashion; in the received mail's headers `SPF: pass` and
   `DKIM: pass` with `d=brandfashionegy.com`; the links follow `storefronts.domain`, so before §5 they still name the
   temporary domain — expected.

**Undo:** the dashboard fields back to `no-reply@bf.michaelkhalaf.com` (its mailbox still exists until deleted); the
DNS rows back as listed in "today" above.

## 8. Redirects

### 8.1 The old WordPress URLs on `brandfashionegy.com` → the new shop — BUILT and rehearsed 2026-11-11

The new site answers on the same host the WordPress shop used, so every old URL arrives at the Next app.
Unmatched, each is a 404 and the links and rankings the old shop earned are lost.

**Built:** `php artisan bf:legacy-redirects <extract> [--image-map=… --asset-base=…] [--apply]` (core). It
reads the old site's URLs from the extract (`products.jsonl`, `site.json` — taken from the WordPress
database, not from a crawl) and resolves each against the database AS IT IS when it runs, so it is run
again on switch day. Rows go to `storefront_redirects` (storefront 2, source `bf_legacy`, 301) and to
`redirect-map.csv` with a status per URL (matched / same / fallback / ambiguous / unmatched). Rules, counts
and the projection with images present: `docs/deploy/BF_LEGACY_IMPORT_2026-11-11.md` §3.

In short (rehearsal on the production copy, 7,968 old URLs): products keep their old slug where it is free
(6,995 of 7,053 imported), so most product URLs need no row at all — only the trailing-slash and host
rules; categories, brands, genders, pages and the image originals each get one row; collections, tags and
the two empty pages go to the listing and are listed; the 11 blog posts go to `/blogs` until they are
migrated; `/category/blog` and `/category/uncategorized` collide with the new `/category/[category]` route
and must be answered before it.

Never redirect everything to the home page: Google treats a mass redirect to `/` as a soft 404 — the map
never does; its fallbacks are the nearest category or the listing.

**Not built — the reader.** Nothing serves `storefront_redirects` today (D-17 in the import record):
recommended, a core read endpoint plus one branch in the BF app's `middleware.js` that asks it only for
legacy-shaped paths. WordPress internals (`/wp-admin`, `/wp-login.php`, `/wp-json/…`, `/feed/`, `/?p=…`)
answer **410 Gone** in the same branch — they are not pages.

Test before the switch: every old URL in `redirect-map.csv` through the reader on the staging build,
expecting one hop to a 200.

### 8.2 `www` and `http`

**Decided 2026-11-11: `www` is canonical.** `http://` → `https://` and `brandfashionegy.com` →
`https://www.brandfashionegy.com`, path and query kept, one hop: at Hostinger (**hPanel** → force HTTPS) and
in the app's `middleware.js`, which already canonicalises the host for Watchizer (**CHECK** it reads the
`www` direction for Brand Fashion — Watchizer's is bare). The redirect rows of §8.1 are path-only, so the
four host forms (`http`/`https` × bare/`www`) reach the same rows after this rule, never four rows per URL.

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

Re-stated 2026-11-11. Three are open, one is decided. Each is laid out the same way — the question, the
options with what each costs, my recommendation (marked; it is a recommendation, not a decision), and a line
for your answer.

| | Question | Recommendation (2026-11-11) |
|---|---|---|
| D-1 | seamless or a moment | **DECIDED: A — a moment** (Michael, 2026-11-11) |
| D-2 | domain from the dashboard or SQL | **A — the statement once** (decided overnight 2026-11-12, Michael to confirm; `bf-switch-core.sh row-write`, with a byte-exact rollback) |
| D-3 | bare or `www` | **DECIDED: `www`** (Michael) |
| D-4 | when, how many sittings | **DECIDED (Michael, 2026-11-11 night): ONE sitting, 2026-11-12** — the client's season is urgent |
| M-1 | `info@brandfashionegy.com` on Hostinger: mailbox or forwarder (§7.1) | **DECIDED 2026-11-11 night: no forwarder** (O-5); the Private Email box is not wanted (O-6) |
| O-7 | `Info@brandfashionegy.com` once MX moves (published on the contact page) | **DECIDED (Michael, 2026-11-12): (a) — `info@brandfashionegy.com` is a real Hostinger mailbox**, created beside `no-reply@` before the MX change (the same records cover both); the contact page already shows it — `BF_SWITCH_DAY_20261112.md` 6.1.3 |

The import's own decisions (D-5 … D-19: the cleanup rule, the codes, the Google feed, the redirect reader,
brands, categories, blog posts, stock) are in `docs/deploy/BF_LEGACY_IMPORT_2026-11-11.md` §7; D-17 (who
serves the redirect map) and D-9 (a Google Shopping feed) gate the switch.

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

**DECIDED 2026-11-11 (Michael): A — a moment.** "Nobody uses michaelkhalaf.com; it was never announced."

### D-2 — Should the domain be changeable from the dashboard, or stay a one-time SQL statement?

The domain is a column that only SQL can change today (backlog item SF-URL-SETTINGS).

| | Option | What it costs | What you get |
|---|---|---|---|
| A | **SQL, once** — run §5's statement by hand (the plan carries it and its rollback) | Nothing to build. You run one statement and one cache flush on the server | The domain cannot be changed by a click, by anyone |
| B | **A dashboard field** — a "Domain" field on the storefront's settings screen, for the owner role only, with the cache flush behind it | A screen change, a permission, validation (a wrong value takes the shop's API offline), tests, a deploy | Changing the domain later needs no server access |

**RECOMMENDATION: A.** It changes once; a field that can take the shop offline with one typing mistake is
a risk kept forever for a convenience used one time.

**Your answer (A / B):** ______

### D-3 — Which address is the canonical one — DECIDED 2026-11-11: `www.brandfashionegy.com`

Michael: "Keep www.brandfashionegy.com as the canonical host, as the old site was." The old site's indexed
URLs are `https://www.brandfashionegy.com/…`, so the product URLs that keep their slug stay byte-identical.
Consequences, written into §1, §4.6, §5, §6 and §8.2: `storefronts.domain` stays the bare
`brandfashionegy.com` (the API host is derived from it), the site address is the `settings.urls.frontend`
override `https://www.brandfashionegy.com`, the app's §3 constant is the `www` address, and the bare domain
and `http` redirect to it. Unlike Watchizer (bare) — the one place the two setups now differ.

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
production; the sender address and DNS mail records; which pixels Brand Fashion has. None of them was
read: production was not touched for this plan. (What URLs the old WordPress shop has is no longer unknown:
read from its database and code on 2026-11-11, §8.1.)
