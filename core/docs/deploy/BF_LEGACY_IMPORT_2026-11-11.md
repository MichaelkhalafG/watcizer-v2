# Brand Fashion — the legacy catalogue import, SEO, offers, and the old site (record of 2026-11-11)

Four steps asked for by Michael on 2026-11-11, in this order. **Everything below ran on scratch copies on
the workstation only** (MariaDB 11.8.9 @ 127.0.0.1:3307): no production write, no SSH, no git state change,
no `media:prune`. The production procedure is §6; nothing in it has been run.

Inputs: the production dump of 2026-11-11 09:30 (restored into `scratch_bf_import_20261111`, a second copy
`scratch_bf_verify_20261111` for the tests, and `scratch_bf_final_20261111` for the final rehearsal); the old
WordPress database of 2026-11-09 (`bf-legacy.sql`, restored into `bf_legacy`); the old site's source (read,
never run). The old-site material stays outside the repository (`D:\BF-handover`).

## 0. What was built (core, uncommitted)

| File | What |
|---|---|
| `app/Domain/Import/BrandFashion/LegacyText.php` | the cleaning rules (entities, copy markers, HTML → paragraphs, short text from long, identity keys) — pure, unit-tested |
| `app/Domain/Import/BrandFashion/LegacySpecs.php` | the old free-text spec labels → core's fields; only labels whose meaning is not in doubt |
| `app/Domain/Import/BrandFashion/LegacyExtractor.php` + `bf:legacy-extract` | reads the WordPress DB **read-only** and writes `products.jsonl`, `site.json`, `images-needed.txt`, `extract-report.json` |
| `app/Domain/Import/BrandFashion/CatalogueCleanup.php` + `bf:catalogue-clean` | step 1A: classify, back up (streamed, read back), hard-delete / archive, restore |
| `app/Domain/Import/BrandFashion/LegacyImporter.php` + `bf:legacy-import` | step 1B: match, skip or create through `ProductWriter` → `PlacementWriter` → `InventoryService` → `VariantWriter`; images through `MediaStore` |
| `app/Domain/Import/BrandFashion/LegacyRedirects.php` + `bf:legacy-redirects` | step 2: the 301 map → `storefront_redirects` (source `bf_legacy`) + CSV |
| `app/Domain/Promotions/OfferCopy.php` + `offers:copy` | step 3: live rules and campaigns → the target storefront's own |
| `tests/Unit/BrandFashionLegacyTextTest.php`, `tests/Feature/Import/BrandFashionLegacyTest.php` | 14 tests (round 1) |
| round 2 (§8): `CataloguePrepare` + `bf:catalogue-prepare`, `LegacyMarkdown`, `LegacyBlogs` + `bf:legacy-blogs`, `GoogleFeed` + `feeds:google` + `GoogleFeedController`, `RedirectController` (v2), Brand Fashion's `middleware.js` | the decisions of 2026-11-11 |
| round 2 tests: `BrandFashionLegacyMarkdownTest`, `BrandFashionDecisionsTest`, `GoogleFeedTest`, `RedirectReaderTest`, Brand Fashion `tests/legacyRedirect.test.mjs`; `ApiAllowListTest`, `StockWriteGuardTest` extended | 25 more |

Every command that writes is a **dry run by default**, asks `WriteTarget` before writing (`--allow-remote`
on production), and says in its docblock what it writes.

## 1. Step 1A — removing the September sheet import

### How "came from the sheet import" and "created from the dashboard" are detected

| Bucket | Test | Count (production, 11 Nov) | Cross-check |
|---|---|---|---|
| transform | id is in legacy `products` (ids preserved, D1) | **626** | none has a "created" log row |
| sheet import | `catalog_products.import_ref LIKE 'woo:%'` (only `import:catalogue` writes the column) | **7,087** | all 7,087 resolve to a real WordPress product id; all have `created_by` NULL; created 16 Sep 23:35 → 17 Sep 06:29 |
| dashboard | neither | **1,459** | all 1,459 have `created_by` and a "created" activity row by a named user |

### Buckets of the 7,087 sheet products

| Bucket | Strict rule (any ledger movement = history) | With `--opening-balance-is-not-history` |
|---|---|---|
| active on Watchizer — never touched | 72 | 72 |
| duplicate — listed, never removed (`duplicate.csv`) | 457 | 457 |
| history — never hard-deleted | 4,189 | 32 |
| hard-delete | 2,369 | 6,526 |

- **Duplicates (457):** 342 share a SKU with a product the team re-created in the dashboard (e.g. sheet
  `Tommy HIlfiger Watch For Men 1791674` ↔ dashboard `Tommy Hilfiger limited edition Men's Watch 1791674`,
  visible on both shops); 113 are SKU-less sheet products sharing a generic name with other sheet products;
  2 share a SKU with another sheet product.
- **History:** none of the 7,015 candidates has an order line, cart line, wishlist line, rating, banner,
  campaign, promotion, stock alert or price batch. What makes 4,189 "history" is the ledger: 4,157 hold
  ONLY the sheet import's own opening balance (`reason = import`); **32** carry a team stock correction
  (`adjustment` / `manual`).
- `core_price_watch` is a derived row per live product (rebuilt daily) and goes with the product; the first
  dry run listed it as history and called all 6,558 "history" — fixed and recorded here.
- **Units:** the products removed under the narrowing held their September opening balance; the import
  re-states the stock from the old site (§2).

### Rehearsed (on `scratch_bf_import_20261111`, then again on `scratch_bf_final_20261111`)

`--opening-balance-is-not-history --history=archive --apply`: **6,526 hard-deleted, 32 archived**, backup
written and read back first (13 tables, 6,558 product rows, 4,157 ledger rows), 40–86 s, peak 52 MB.
**Undo proven:** `--restore` put back every row — a row-level digest over the stored columns of all 13
tables equalled the untouched restore exactly (72 s). The first restore attempt was refused by
`StockWriteGuard` (re-inserting stock columns outside `InventoryService`) and rolled back whole; the restore
now runs inside `StockWriteGuard::allow()`, scoped like the transform's run.

`CHECKSUM TABLE` was NOT usable as evidence: it gave three different values for identical data in
`storefront_category_product` (a table with a virtual column) across restores of one dump. The row-level
digest (`count`, `SUM(CRC32(CONCAT_WS(...)))` over stored columns) was used instead.

## 2. Step 1B — the import from the old site

### The old site (WordPress 6.9 / WooCommerce 10.9.4, dump of 9 Nov)

7,782 published products (7,758 simple, 24 variable with 110 published variations); 747 without a SKU; 3 SKUs
used twice; 34 titles with HTML entities; 9 WordPress "copy" duplicates; 15 without a regular price (13 of
them variable — priced from their variations; 2 truly priceless); 39 without an Arabic title; 115 without
any description; 416 hidden from the catalogue on the old site; 4,332 with a stock count (21,461 units),
2,703 counted out of stock, **682 "in stock" with no count** (stock not managed).

### Cleaning (all counted in `extract-report.json`; samples in §2.3)

Entities decoded (twice where double-encoded); the WordPress copy suffix removed from 9 titles/slugs;
wrapping quotes removed; descriptions → plain text paragraphs (690 had HTML or shortcodes; core stores plain
text — 0 of 9,172 descriptions hold a tag); **short descriptions derived** from each product's own long text
(only 9 products had one): first sentence, lines joined as sentences, a lone heading line ("Highlights" 641,
"Overview" 303 and their Arabic forms) dropped — never a word added; prices: sale kept only when
`0 < sale < regular` and its schedule is running, variable products priced at their lowest variation with
per-variant differences; Arabic title and description from the ACF fields (`ar_title`, `ar_description`);
specs mapped label by label (`LegacySpecs`), lookups matched by exact name only; brands matched by name to
core's brands; categories through the existing `CategoryMap` onto Brand Fashion's tree (no node is created);
WooCommerce variations → variants (size/colour by attribute term name); featured image first, then gallery.

### Samples — raw → clean

| Old site | Imported |
|---|---|
| `Dolce &amp; Gabbana K EDP For Men 100ml`; content `Highlights / Launched in 2019 / A woody aromatic fragrance for men / …` | `Dolce & Gabbana K EDP For Men 100ml`; short `Launched in 2019. A woody aromatic fragrance for men.`; `fashion/perfumes`, Men; sale 1,450 of 2,000 |
| `EMPORIO ARMANI Men's Water Resistant Chronograph Watch AR5988 Copy`, slug `…-ar5988-2-copy` | title without ` Copy`, slug `…-ar5988-2`; the old `-copy` URL is redirected |
| `GUESS GW0203G2 … Skeleton Dial &amp; White Silicone Strap …`, 14 spec rows (`Band Material: Silicon`, `Case Size/Diameter: 43 Mm`, …) | entity decoded; case size 43 mm, movement Quartz, closure Buckle, display Analog, colours dial/band White, Men; `Silicon` NOT matched to `Silicone` (listed) |

### What is listed, not guessed

- **Spec values with no exact lookup** (746 distinct, 9,444 occurrences in the final run; `report.json` → `spec_value_unmatched`):
  the big ones are `mineral` / `Mineral` as a glass (2,357 — core has no "Mineral" glass), `Clasp` (2,080 —
  no plain "Clasp" closure), `Chronograph` as a display type (1,055 — it is not one), water resistance in
  **metres** (~550 — core has only ATM and Bar), `Silicon`.
- **Spec labels with no home** (282 distinct): sunglasses' `lens size`, `frame shape`, `bridge size`,
  `lens colour` (~1,600 each), `product weight`, `feature 1/2`, `warranty`, `model`, perfume notes.
- **Brands not in core** (20 names, 166 products → `Generic`): Chanel 27, SKMEI 26, Louis Vuitton 19, AP 18,
  Carti 17, Karl 8, invicta 7, Timberland 6, Dolce & Gabbana 5, Porsche 5, H 4, X 4, RayBan 4, Ibso 3, …;
  1,517 products have no brand term at all on the old site → `Generic`.
- **Categories:** every old category maps; **3,593 placements land in 16 nodes that are switched off on
  Brand Fashion** (the 13 Oct cleanup, D27): Quartz 2,511, Accessories 215, the bag shapes, Leather 152,
  Satin 119, Luxury 48, Bundles 46, Shoes 27, Slippers 9 … (D-12).

### Identity and matches

`import_ref = bfwp:<legacy id>`, `wa_code = BFL-<legacy id>`; the BF slug is the legacy slug where it is
free (58 had to change — taken by a kept product — and are redirected). Matching order: same WordPress id
as a kept sheet product → same SKU (product or variant) → same name when neither has a SKU. A match is not
created and not changed; `matches.csv` lists each with the existing product, its origin, its placements and
a proposal.

### Final rehearsal — fresh copy, final code (`scratch_bf_final_20261111`, 13:11–13:22)

Order: restore → 1A dry runs → 1A apply (`--opening-balance-is-not-history --history=archive`) → 1B dry run →
1B apply (`--stock=ledger`, a 31-file sample of real originals fetched read-only from the live old site, since
the archive is on the server) → `inventory:verify` → 1B re-run → step 2 → step 3. Log and every report:
`D:\BF-handover\work\final\`.

| | Count |
|---|---|
| removed (hard-deleted sheet products) | **6,526** |
| hidden (archived — history) | **32** |
| legacy records | 7,782 |
| **imported** | **7,053** (0 failed) — 110 variants, 37,380 spec fields written, no size lookup created (Medium/Large read as the existing M/L) |
| matched, not created (`matches.csv`) | **703**: 457 = the 457 kept sheet "duplicates" (same WordPress product); 152 = Watchizer's own products, visible on both shops; 72 = sheet products active on Watchizer, visible on both; 19 dashboard products visible on both; 2 dashboard products hidden on both; 1 sheet product |
| skipped (`skipped.csv`) | **26**: 24 duplicates inside the old site (same SKU or name — the first is imported, its twin's URL redirects to it), 2 without any price |
| stock opening balance (`--stock=ledger`) | **20,539 units** as `import` movements, storefront 2; `inventory:verify`: 19,847 checks, all agree |
| visible on Brand Fashion now | 8 (only the sample had images) |
| **projected visible once the originals are on disk** | **~6,412** — 6,404 are hidden ONLY for the missing image; ~641 stay hidden for real reasons: 414 hidden on the old site, ~95 missing a description in a language, 83 no category, 44 no gender |
| slug kept = legacy slug | 6,995; 58 changed (taken) → redirected |
| re-run | 7,053 "already", 0 created, the 24 twins still skipped, digest unchanged |
| time / memory | **446 s, peak 74 MB** for 7,782 records **without** image processing (26 images processed) |

Found and fixed during the rehearsals, each proven by a run or a test: the backup ran out of memory
(128 MB) before writing anything — now streamed; `core_price_watch` wrongly counted as history; the restore
refused by `StockWriteGuard` — now scoped; **a second run would have CREATED the old site's in-source
duplicate of a product the first run imported** — the earlier run's identity keys are now claimed up front
(the test goes red without the fix, green with it); a gallery's shared box photo became the cover when a
product's own photo was missing — now the featured image is the cover or there is none; short descriptions
that were only "Highlights".

### Images

25,278 originals (`images-needed.txt` — the list to extract; 0 size variants; 1,176 png, 413 gif, 45 webp,
the rest jpg). 30,364 product→image references collapse to 25,278 files because shared photos (a brand's
box shot sits in up to 684 galleries) are stored ONCE and reused — safe because the dashboard never deletes
a product image's file. Each original goes through `MediaStore::storePath()` (master + AVIF/WebP renditions)
exactly like an upload. Time per image, measured on the 31 real originals (workstation, nothing else running): **mean 0.91 s,
median 0.50 s, max 2.83 s, peak 78 MB** — so the 25,278 originals take **about 6½ hours here**; the
server's speed was not measured. The import is re-runnable and reuses what it already processed
(`image-map.jsonl`), so a run stopped by a time limit is simply started again with the same `--out`.

### The gate

- **PHPStan** (level 10, whole project): no errors. **Pint**: clean on every touched file.
- **New tests**: 14 (8 unit, 6 feature), all pass; the re-run test proven sensitive (red without the fix).
- **Full suite** on the fresh production copy (`scratch_bf_verify_20261111`, via `suite-baseline.sh`): 300
  failed / 2,067 passed / 39 skipped; 0 tables changed outside the transactions; `mysql.db` OK. The baseline
  file does not fit this raw copy (it was measured on an adjusted one — production's encrypted rows re-keyed,
  D36/D38: 222 of the failure messages are `MAC is invalid`). So the change was tested by a **control run**:
  the same suite on the same database with every new file moved aside — **299 failed, and the two failure
  lists differ by exactly one test**, `StockWriteGuardTest › names every file allowed to open the guard
  window`: the census of files that may open the stock-guard window, which the restore now does. It is
  declared there with its reason (the only tracked code file this work modifies; the other tracked change is the domain plan), and passes.
- **Builds**: not run — neither storefront app nor core's dashboard bundle was touched.

## 3. Step 2 — SEO

### 3.1 The old site's public URLs (read from its code and options, not guessed)

Permalinks `/%postname%/`; WooCommerce bases `/product/`, `/product-category/` (nested paths); the theme
registers `brand` at `/brand-category/`, `gender` at `/gender-category/`, `collection` at `/collection/`;
`/product-tag/`; post categories at `/category/`; pages and posts at `/<slug>/`. **Arabic was `?lang=ar` on
the same URL** (83 links in the theme), not a path. Attachment pages: Rank Math already 301s them to their
parent (`attachment_redirect_urls = on`), so they were never indexed pages (32,821 attachments; 12,365 with a
parent) — no rows. The archived sitemaps (2 Sep) listed products, 18 pages, 11 posts and 2 post categories.

### 3.2 The map — `bf:legacy-redirects` (rehearsed: 7,968 old URLs, 7,879 rows stored, 89 need none)

| Kind | Rule | Rehearsal (images missing) | Production projection (images present) |
|---|---|---|---|
| product (7,782) | imported → `/product/<slug>`; matched → the existing product's BF slug; old-site twin → the kept twin; hidden or switched off on BF → its primary category's page (the nearest parent with one), else `/listing` (round 2: a SKU match to a product switched off on the dashboard is a fallback too — product 71 answered 404 in the hop test) | 89 same, 162 matched, 7,529 fallback, 2 unmatched | ~6,350 **same URL** (slug kept, no row — only the trailing-slash/host rules), ~300 matched (58 changed slugs + 243 existing products visible on BF), 24 ambiguous → the twin, ~1,100 fallback to a category (≈640 hidden imports + 460 matches hidden on BF — D-11), 2 unmatched (no price → `/listing`) |
| `/product-category/<path>` (66) | old leaf → `CategoryMap` → BF node → the URL the APP serves it at (round 2, after the hop test: top level `/category/<English name slugged>`, second level `/subtypes/<…>`, a node without a page — deeper than two, out of the menu, or without a visible product — its nearest parent with one, flagged `fallback`); gender leaves → `/listing?gender=` | 37 matched, 22 fallback (node switched off on BF), 7 unmatched (empty categories: Outlet, Women THBAGS, USB, Organiser, Women Socks, Women Shoes, Toys) | same; the 22 become matched if D-12 reactivates the nodes |
| `/brand-category/<slug>` (47) | brand name → core brand → `/brand/<slug>` | 25 matched, 22 unmatched (no such brand on BF, incl. Chanel, SKMEI, Louis Vuitton…) → `/listing` | same, better after D-10 |
| `/gender-category/` (3) | → `/listing?gender=Men/Women/Unisex` | 3 matched | same |
| `/collection/` (7), `/product-tag/` (6) | no collections or tags on the new site → `/listing` | 13 unmatched | same |
| pages (18) | a hand table (`LegacyRedirects::PAGES`): home → `/`, about-us, contact-us, privacy-policy, terms-and-condition → `/terms-and-conditions`, blog → `/blogs`, shop/brands/search/best-sellers → `/listing`, new-arrivals → `/listing?sort=newest`, sale → `/listing?sale=1`, cart, checkout, my-account and wishlist → `/account` | 16 matched, 2 unmatched (sample-page, accept-payments → `/`) | same |
| posts (11) | → `/blog/<slug>` if migrated, else `/blogs` | 11 fallback — **none of the 11 is on Brand Fashion** (it has 6 new articles) | same unless D-13 |
| `/category/blog`, `/category/uncategorized` | → `/blogs` — these paths COLLIDE with the new `/category/[category]` route and must be answered before it | 2 fallback | same |
| `/wp-content/uploads/<original>` | → the master core made from it, `https://api.brandfashionegy.com/Uploads_Images/Product/<file>.webp` (from `image-map.jsonl`) | 26 (the sample) | **25,278** rows; WordPress size variants (`-300x300`) are one rule: strip `-WxH` and look up the original |

Request-level rules, never rows: trailing slash (old URLs end in `/`, rows are stored without it — the lookup
strips it); host (`http`/bare → `https://www.`, §8.2 of the domain plan); `?lang=ar` → the `/ar` twin of the
target. Matching is case-insensitive (`StorefrontRedirect::hashPath` lowercases).

**Nothing serves `storefront_redirects`.** It has been written since wave 1 (slug changes, transform twins)
and is read by no route (grep of `app/Http`, `app/Storefront`, `app/Compat`, and the BF app). Without a
reader the map does nothing — D-17.

### 3.3 Rank Math → our fields

| Rank Math data | Count on the old site | Home in core | Carried |
|---|---|---|---|
| product SEO title | 50 | `catalog_product_translations.meta_title` (EN) | **34** written (16 were the default `%title% - Brand Fashion`; the storefront appends "\| Brand Fashion" itself, so the tail is removed) |
| product SEO description | 24 | `meta_description` (EN) | **10** written (10 used `%variables%`; the rest belong to matched products, which are not changed) |
| product focus keyword | 508 | **none** — `search_keywords` exists but is the site's own search, not SEO | not carried (D-14) |
| category title/description/keyword | 0 | `storefront_category_translations.meta_*`, `core_seo_texts` | nothing to carry |
| page title/description/keyword | 9 pages | **none in core** — page metadata is written in the storefront app's code | listed in `site.json`; home, about-us, contact-us, privacy-policy carry real text worth copying into the app (D-14) |
| post title/description/keyword | 9 posts | `core_blogs` — but the posts are not migrated | with D-13 |
| Arabic SEO | none on the old site (Arabic was a `?lang=ar` switch, Rank Math stored English) | — | — |

## 4. Step 3 — offers

Watchizer's offers on the production copy (11 Nov 09:30):

| Offer | State | On Brand Fashion after `offers:copy` (rehearsed) |
|---|---|---|
| Promotion `Black1000` — EGP 1,000 off each watch of Rolex, Patek Philippe, Audemars Piguet, Omega, Tissot, Hublot; not stackable; 30 Oct → 31 Dec; used 8× | live | a NEW rule, storefront 2 only, same scopes/reward/window/labels, its own counters — **under a different code** (`promotion_rules.code` is unique across the whole table: the same code cannot exist twice). Decided (D-7): **`FRIDAY1000`** (rehearsed; first rehearsed under a placeholder — D-7) |
| 3 wheel rules | live | not copied (Watchizer only, as asked) |
| Hot Deals "Black Friday", 134 products, 67 with a campaign price, until **12 Nov 00:00** | live | copied: 133 products (1 is not visible on BF), 66 campaign prices |
| Flash Sale "Black Friday", 10 products | ended 10 Nov | not copied (not live) |
| Free shipping over 10,000 | setting | **already identical** on both shops — nothing to copy |
| Money rewards | setting | **already on** for both shops — nothing to copy |

One offer per order: carried by each rule's `stackable = 0`, which the engine reads per storefront; the copy
keeps it. A rule naming a category or sub-type of the source tree is REFUSED by the command (each shop has
its own tree) — none does today. Both writers' own validation ran (the copy goes through
`PromotionWriter::save()` and `CampaignWriter::create()`), and each creation is activity-logged with the id it
was copied from.

**Do the two brands look connected?** The customer-facing parts of the copied rule are its LABEL ("Black
Friday: EGP 1,000 off each watch") and, for a coupon, its CODE. A shared code name (`Black1000` on both) is
not possible technically, and a near-identical one (`Black1000` / `BLACK1000BF`) on two shops with the same
discount, window and brands is exactly the kind of overlap coupon-aggregator sites list side by side. The
cure is cheap: a code of Brand Fashion's own that does not echo Watchizer's (D-7). The rule's internal NAME
(`Black Friday — Black1000`) is dashboard-only. **Answered (D-8, Michael 2026-11-11): the two brands do NOT need
to look unconnected — the 2026-09-26 decision holds.** The code is Brand Fashion's own (`FRIDAY1000`) because a
second `Black1000` cannot exist, not to hide anything.

## 5. Step 4 — what the old site had

From the old source (read only) and the 9 Nov database: 40 active plugins. Measured use, what it did, whether
core has it, and whether it is worth building. **Nothing was built.**

| Feature (plugin) | Evidence of use | Core today | Worth building? |
|---|---|---|---|
| **Google Shopping feed** (Google Listings & Ads) | **4,926 products synced** to Merchant Center, 170 open issues | no Google feed (a Meta feed exists, `feeds:meta`) | **Yes — first.** Free Shopping listings lapse the day WordPress goes; a Google feed beside the Meta one, per storefront |
| **Hand-written catalogue feeds** (`/catalog-feeds/{facebook,google}-catalog{,-ar}.php`, `/feeds/fb/`) | in the site root, EN and AR | Meta feed per storefront | Point the Meta catalogue at core's feed before the switch; nothing to build for Meta |
| **SEO** (Rank Math) | titles/descriptions on 50/24 products, focus keywords on 508; 18 pages/posts; 0 categories | product and category meta fields exist; the import carries products' | Done for products; pages/posts → §3 |
| **Contact forms** (Fluent Forms, WPForms) | "Contact Form" 2,692 submissions, "Contact Form AR" 5; WPForms 27 log rows. **These hold customer data** (names, phones, messages) — counted, not read | contact page with WhatsApp/phone; no form | Worth a small form → team inbox only if the team answers forms; 2,692 says people used it |
| **Abandoned-cart capture** (CartFlows) | 1,083 carts captured, **0 recovery e-mails sent** | recovery e-mail for declined/expired card orders (D18); no abandoned-cart capture | Not now: the old site captured but never mailed |
| **Wishlist** (WPC Smart Wishlist) | 265 user-meta rows | dropped by decision (D5) | No (D5) |
| **Discount rules** (Woo Discount Rules Pro) | 29 rules, **0 enabled**, 0 order discounts | the promotions engine | No — not in use |
| **Coupons** (WooCommerce) | 10 published; used: `SATIN10` 44×, `SN30` 4×, `ORDERAGAIN5` 4×, `MF100` 3×; the rest 0 | coupon codes on promotion rules | Recreate `SATIN10` if it is still meant to work (D-15); the others are expired or unused |
| **Online payment** (MyFatoorah; Tamara active) | **1,191 of 5,873 orders** paid through MyFatoorah methods; Tamara: no orders found under it | Paymob only; MyFatoorah provider not built (A4) | Yes, already planned (A4) — 20 % of the old site's orders were online |
| **PDF invoices** | 11 invoice numbers | order e-mails | No |
| **Checkout field editor** | active | core's checkout | **CHECK** in WP admin which fields were added before deciding |
| **Variation swatches, quantity buttons** | active | size picker, cart quantity | No — the new design covers them |
| **Pixels** (PixelYourSite, TikTok for Business) | configured | Meta pixel + CAPI per storefront, TikTok by env (D34) | Reuse the old pixel ids only if the campaign history should continue (domain plan §7) |
| **Jetpack** (stats, WooCommerce analytics, …) | 11 modules active | none | No — GA/Meta cover it |
| **Product import tools** (WP All Import, Product Import Export, WooCommerce XML/CSV import, Post Duplicator) | 80 CSV + 3 XLSX uploads are data-entry sheets; Post Duplicator is where the "-copy" products came from | dashboard + `import:catalogue` | The team's bulk-entry habit is real; a dashboard CSV import is worth considering later |
| **Order export** (Woo Order Export Lite), **activity logs**, security (Wordfence, MalCare, CleanTalk, hide-login), cache (LiteSpeed, Redis), backups (UpdraftPlus), SMTP | active | core has its own equivalents | No |
| **Shopify migration plugin** (w2s-migrate-woo-to-shopify) | installed | — | Note only: someone considered Shopify |

**The spreadsheets in `uploads.zip`** (identified from WordPress's own attachment records, names only; none
opened): ~70 product data-entry sheets (`Brand-Fashion-_-Data-Entry-Test-Training-…`, `jojo-girl`, `koko-N`,
`KOKO-BAG`, `jojo-sunglasses`, `shoes`, `caps`, `rimo-watch`), 2 WooCommerce **product** exports
(`wc-product-export-10-11-2023…`, `wc-product-export-12-11-2023…`), brand price/stock sheets (`Armani.csv`,
`boss-4.csv`, `tommy-web-2-2024*.csv`, `Maserati-6-mm-1.csv`, `mmn-mk.csv`, `wc-imports/product-tommy-web-price-…`),
`BUNDEL-BIG-SALE-2-Sheet1.csv`, `BAGS-WOMEN-Sheet1.csv`, `brand-fashio-batch-16-3-Noon.csv` (a Noon batch),
`ar.csv` (likely Arabic text), `stock...xlsx`, and **`SANDRA.xlsx` (2025-10-04) — the one name that says
nothing; treat it as possibly holding personal data and do not extract it.** By name, none is an order or
customer export (those would come from Woo Order Export and are not among the attachments). The import
needs none of them: it reads the database. The zip holds 75 CSV + 1 XLSX against 80 + 3 recorded — some
were deleted from disk since.

**Not part of this job, stated so it is not assumed:** the old site's **orders (5,873) and customer
accounts (5,024 in its customer lookup)** stay in WordPress — nothing here imports them. It took ~160–200
orders a month through 2026 and 44 between 1 and 9 November, so every order placed there until the switch
stays there.

## 6. Production procedure — the decided options, in order (for Michael; nothing here has been run)

Rewritten 2026-11-11 (evening) with the decisions of §7, and again that night with O-1 … O-6 (§9). Every step
names its **backup** and its **undo**. All commands run in core's folder on the server unless marked; each WRITES
only with `--apply`, and refuses a non-local database without `--allow-remote` (WriteTarget). Rehearsed end to end on
a fresh copy of the production dump (`scratch_bf_final4_20261111`, §9) in exactly this order.

**0. Deploy first** (a normal core deploy; no migration — nothing here adds a table or a column): the commits of
`COMMITS-bf-legacy.ps1`, then `config:cache`. Brand Fashion's app with the new middleware is built and deployed
**at the switch** (domain plan §6), not before — its middleware needs core's reader, which this deploy brings.
The API host's `.htaccess`: add the Google feed line of runbook §4.1.1 (`!^/feeds/google/[a-z0-9-]+/[A-Za-z0-9]{40}\.tsv$`)
in the same sitting, backup first, as the runbook says.

**A. On the workstation (switch week — a FRESH WordPress dump; the old site is still selling):**

1. Restore the dump into a local database `bf_legacy`, then
   `BF_LEGACY_DB_PASSWORD=… php artisan bf:legacy-extract --wp-port=3307 --wp-database=bf_legacy --out=<dir>`.
   Upload `<dir>` to the server as `~/bf-handover/extract/` (products, site structure, the articles — no order,
   customer or user row), and the articles' Arabic (O-1) beside it: `D:\BF-handover\work\articles-ar\articles-ar.json`
   → `~/bf-handover/articles-ar.json` (made by `articles_ar.py` in the same folder; a copy is kept in the
   checkpoint folder). *Backup/undo: none needed — reads only.*

**B. On the server — the image originals only:**

2. `cd ~/bf-handover && unzip -l uploads.zip | head -8` — the path prefix in front of `2024/…`.
3. `PREFIX='<from step 2>'; sed "s#^#${PREFIX}#" extract/images-needed.txt > want.txt; mkdir -p originals`
   then `xargs -a want.txt -d '\n' unzip -o -q uploads.zip -d originals`. 25,278 originals (the articles' images
   are among them). The images root below is `~/bf-handover/originals/<PREFIX>` — the folder holding
   `2022 … 2026`. Never extract the `.csv`/`.xlsx` (§5). *Undo: delete the folder.*

**C. Backup — the undo for everything below:**

4. `php artisan core:backup` (or the runbook's database backup), kept outside the web root; write down its name.
   Steps 5–6 also keep their own undo files; steps 7–12 are undone by this backup (an opening balance is not
   written any more — stock is zero — so nothing below writes the ledger).

**D. Step 1A — the cleanup (D-5, D-6):**

5. Dry run, read it: `php artisan bf:catalogue-clean --opening-balance-is-not-history --history=archive --out=~/bf-handover/clean-dry`
   — then the same with `--apply --allow-remote --out=~/bf-handover/clean-apply`.
   It writes `backup.json` and reads it back BEFORE deleting. Rehearsed: **6,526 removed, 32 archived**.
   **Undo:** `php artisan bf:catalogue-clean --restore=~/bf-handover/clean-apply/backup.json --allow-remote` (exact, proven).

**D2. The data decisions (D-19, D-10, D-12, D-11, D-15):**

6. `php artisan bf:catalogue-prepare ~/bf-handover/extract --out=~/bf-handover/prepare-dry` (read
   `prepare-plan.json` and `twins-review.csv`), then with `--apply --allow-remote --out=~/bf-handover/prepare`.
   Creates the 3 lookups, the 14 brands, switches on the 17 categories (active AND in the menu — D-20), makes the 5 hidden dashboard twins visible
   where complete, adds SATIN10. **Undo:** `php artisan bf:catalogue-prepare --restore=~/bf-handover/prepare/prepare-undo.json --allow-remote`
   (categories back to both flags as they were, twins back, SATIN10 switched off, unused brands/lookups deleted — a brand an imported
   product uses stays until the import is undone). It also renames Buckle's Arabic to إبزيم BEFORE creating Clasp as مشبك (O-3 —
   Buckle shows on Watchizer's product pages too); the undo puts مشبك back on Buckle once Clasp is gone. SATIN10 ends
   31 Dec 2026 23:59:59 (O-2).
6b. **O-4 — the reviewed duplicates:** `php artisan bf:catalogue-clean --reviewed=~/bf-handover/prepare-dry/twins-review.csv --opening-balance-is-not-history --out=~/bf-handover/reviewed-dry`
   (read `reviewed.csv`: every id with its decision and why), then the same with `--apply --allow-remote --out=~/bf-handover/reviewed`.
   The flag is D-5, exactly as in step 5: without it the sheet's own opening balance counts as history and 25 of the 112
   are refused (found in the rehearsal of 23:30; a product with history is now refused BEFORE the backup is written).
   Removes the "not a duplicate" sheet copies and all but one of each real duplicate (rehearsed: **112 removed, 3 kept**: 6980,
   7049, 7058 — nobody had edited any, so the oldest of each); refuses any id that is no longer a live sheet copy in the
   duplicate bucket. Backup written and read back first, as in step 5. **Undo:**
   `php artisan bf:catalogue-clean --restore=~/bf-handover/reviewed/backup.json --allow-remote`.

**E. Step 1B — the import (D-16: stock at zero):**

7. Dry run: `php artisan bf:legacy-import ~/bf-handover/extract/products.jsonl --images=<images root> --out=~/bf-handover/import-dry`
   — `image_not_on_disk` must be ~0 now.
8. `nohup php artisan bf:legacy-import ~/bf-handover/extract/products.jsonl --apply --stock=zero --images=<images root> --allow-remote --out=~/bf-handover/import-apply > ~/bf-handover/import.log 2>&1 &`
   — re-runnable with the same `--out` (created products are skipped, processed images reused). Products that
   already existed are matched, never changed — their stock untouched. Rehearsed: **7,053 created**.
9. `php artisan inventory:verify` — must say all agree. **Undo:** the backup of step 4 (imported rows are
   `import_ref LIKE 'bfwp:%'`, `wa_code LIKE 'BFL-%'` for a targeted repair).

**F. Step 2 — redirects and articles (D-17, D-13):**

10. `php artisan bf:legacy-redirects ~/bf-handover/extract --image-map=~/bf-handover/import-apply/image-map.jsonl --asset-base=https://api.brandfashionegy.com --apply --allow-remote`
11. `php artisan bf:legacy-blogs ~/bf-handover/extract --images=<images root> --arabic=~/bf-handover/articles-ar.json --apply --allow-remote`
    — the 11 articles **published** in both languages, each on its original date (O-1); the command refuses the file if any
    article lacks a title, SEO title, SEO description or body.
12. Step 10 once more — now each old article URL leads to its article (it is safe to repeat; on switch day it
    runs again after the last visibility decisions). The map points each category at the page the app
    serves, and a category has a page only while it holds a visible product — so the map is only as good as the
    visibility at the moment it runs: always AFTER the images are in (step 8), never before.
    **Undo:** `DELETE FROM storefront_redirects WHERE storefront_id = 2 AND source = 'bf_legacy';` and the articles
    from the Blogs screen.

**G. Step 3 — the offers (D-7):**

13. `php artisan offers:copy --code=Black1000=FRIDAY1000` (dry run), then with `--apply --allow-remote`. Copies
    only what is live at that moment (Hot Deals ends 12 Nov 00:00). **Undo:** delete the printed rule/campaign ids
    on the Promotions and Campaigns screens.

**H. D-9 — the Google feed, at the switch** (domain plan §6): `FEED_GOOGLE_BRANDFASHION_TOKEN` in core's `.env`,
`config:cache`, `php artisan feeds:google --storefront=brandfashion`, the URL into Merchant Center.

Order matters: D before E (otherwise the legacy products match their own sheet copies), D2 and 6b before E (the
import reads the new lookups, brands and categories, and 6b's removals are what let the clean old products in), E before F and G (the map and the campaign copy read the imported
placements), F10 before F11 (the articles' links are rewritten through the map).

## 7. Decisions — answered by Michael on 2026-11-11, and what is still open

D-1 … D-4 and M-1 are the domain plan's (`BF_DOMAIN_SWITCH_PLAN.md` §9).

| # | Decision | Answer (Michael, 2026-11-11) | Built / applied as |
|---|---|---|---|
| D-5 | the sheet import's own opening balance | **not history** | `--opening-balance-is-not-history` (§6 step 5) |
| D-6 | the 32 with team stock corrections | **archive** | `--history=archive` |
| D-7 | Brand Fashion's Black1000 | code **FRIDAY1000** | `offers:copy --code=Black1000=FRIDAY1000` (checked: the code exists nowhere) |
| D-8 | must the brands look unconnected? | **no — the 2026-09-26 decision holds** | the near-copy-code concern is withdrawn |
| D-9 | Google Merchant feed | **build it before the switch** | `feeds:google`, `/feeds/google/{storefront}/{token}.tsv` (§8) |
| D-10 | missing brands | **create the real names; AP → Audemars Piguet, Carti → Cartier, Karl → Karl Lagerfeld, patek philip → Patek Philippe; H and X stay Generic** | `bf:catalogue-prepare` (14 brands) + `LegacyImporter::BRAND_ALIASES` |
| D-11 | the dashboard twins | **make the 342 visible on Brand Fashion; the other 115 in a sheet** | 337 already were; 5 made visible where complete; `twins-review.csv` (§8) |
| D-12 | switched-off categories | **reactivate all 16, Quartz included** | 17 switched on — the 16 plus their switched-off parent "Shop by material", without which Leather and Satin stay unreachable — **active and in the menu** (D-20 below) |
| D-13 | the 11 blog posts | **migrate, cleaned, Rank Math kept, old URLs redirected** | `bf:legacy-blogs` — as DRAFTS (the old site has no Arabic for them; see open item O-1) |
| D-14 | focus keywords / page texts | **drop the keywords; copy home / about / contact / privacy** | the four EN titles/descriptions in the app's own text files |
| D-15 | SATIN10 | **recreate exactly** | Brand Fashion's own rule: 10 % on the Satin category, sale items included, no limits — with an END DATE (open item O-2) |
| D-16 | stock | **all at ZERO** | `--stock=zero`; matched products keep their stock untouched |
| D-17 | serving the redirects | **build it: core reader + one middleware branch, WordPress internals 410** | `GET /api/v2/{storefront}/redirect`, Brand Fashion's `middleware.js` (§8) |
| D-18 | derived short descriptions | **accept** | as built |
| D-19 | three lookup values | **add them and re-run the dry run** | metres (code `metre`), Mineral, Clasp — rehearsed (§8) |
| D-20 *(mine, 2026-11-11 evening — decided, not asked)* | what "reactivate" switches on | **both flags: active AND in the menu**, as the 17 were switched off (both off on the production dump) | The hop test found `/category/quartz` and 30 more answering 404: the app lists, filters and serves only nodes that are active, in the menu and hold a visible product (`CompatCategories`), so "active" alone sells the products with no category page, menu entry or filter. *Alternative:* active only — the products sell through search and the listing, their old category URLs fall back to the parent. *Cost:* up to 17 more category entries on Brand Fashion (each appears only once it holds a visible product); 10 of the 17 are third-level, for which the app has no page — their old URLs go to their parent. Undo restores both flags |
| — | old orders and customers | **not migrated**; they stay in the WordPress backup | nothing imports them |
| D-1 | the switch: seamless or a moment | **A — a moment** | domain plan §9 |
| D-2, D-4 | domain from the dashboard; how many sittings | **later, before switch day** | recommendations unchanged in the domain plan |

### The open items — answered by Michael on 2026-11-11 (night)

| # | Question | Decision | Where |
|---|---|---|---|
| O-1 | the 11 articles have no Arabic | **I translate them** (Modern Standard Arabic for the reader, the same facts, nothing added): title, SEO title, SEO description and body each; **published at launch, not drafts**; the redirect map re-run so each old URL lands on its article | `articles_ar.py` → `articles-ar.json` (outside the repo), `bf:legacy-blogs --arabic` (§6 step 11, §9) |
| O-2 | SATIN10's end date | **31 Dec 2026** | as built (`CataloguePrepare::SATIN10`) |
| O-3 | "Clasp" in Arabic | **Buckle becomes إبزيم, Clasp gets مشبك** — knowing it changes Watchizer's pages too | `CataloguePrepare::RENAMES`, applied before the lookups (§6 step 6) |
| O-4 | the 115 duplicates | **remove the 107 that are not duplicates, keep one of each real one**; confirm the clean old versions then ARE imported | `bf:catalogue-clean --reviewed` (§6 step 6b) + the importer's name identity now carries the photo (§9) |
| O-5 | `info@` | **no forwarder**; Brand Fashion's sender is a NEW Hostinger mailbox `no-reply@brandfashionegy.com`, wired like Watchizer's | domain plan §7.1 (hPanel, the Namecheap records, the dashboard values) |
| O-6 | the Private Email box | **not wanted**: nothing kept or migrated; MX simply moves to Hostinger | domain plan §7.1 |

## 8. Round 2 — the decisions applied, built and rehearsed (2026-11-11, evening)

### What was built

| Piece | Where | What |
|---|---|---|
| D-9 Google Merchant feed | `app/Feeds/GoogleFeed.php`, `feeds:google` (hourly :45), `GoogleFeedController`, `/feeds/google/{storefront}/{token}.tsv`, `config/feeds.php` section `google` | the Meta feed's lines (`MetaFeed::lines()`, now public and shared — the Meta file byte-identical, its 19 tests green) renamed to Google's columns, SKU as `mpn`, `identifier_exists=no` without one; Brand Fashion's entry on `www.brandfashionegy.com`, off until its token is set. Allow-list line in runbook §4.1.1, `ApiAllowListTest` |
| D-17 redirect reader | `GET /api/v2/{storefront}/redirect?path=` (`RedirectController`) | the first reader of `storefront_redirects`: normalised path, hits counted, 404 without a row, uncached |
| D-17 middleware | `Frontend-brandfashion/middleware.js` (now DIVERGED from Watchizer's), `next.config.js` `skipTrailingSlashRedirect` | old-looking paths only → core → ONE 301 (its `/ar` twin for `?lang=ar`); no row + trailing slash → one 301 without it; WordPress internals → 410; the canonical host from `SITE_CANONICAL_HOST` (www, D-3), unset = today's www → apex |
| D-13 articles | `bf:legacy-blogs`, `LegacyMarkdown` | the 11 posts → Markdown (h1 → h2, only what the shop renders), links to the old shop re-pointed through the map, images re-processed from their originals (Banner_home), Rank Math title/description kept; DRAFTS (O-1) |
| D-14 page texts | `src/content/trustPages.js`, `app/(main)/page.jsx` (Brand Fashion's own, not twins) | the old home/about/contact/privacy English titles and descriptions |
| D-19, D-10, D-12, D-11, D-15 | `bf:catalogue-prepare` (`CataloguePrepare`) with `--restore` | the three lookups, the 14 brands, 17 categories on (active AND in the menu — D-20), the 5 hidden twins visible, SATIN10 |
| D-10 aliases | `LegacyImporter::BRAND_ALIASES` + an accent-blind brand key | AP, Carti, Karl, patek philip read as their brands; "HERMES" = "Hermès" (a bug the second rehearsal found: 3 products had gone to Generic — fixed, unit-tested; the third rehearsal matched the brand page too) |
| D-11 match order | `LegacyImporter::plan()` | the team's own products are matched BEFORE the sheet copies, so an old URL leads to the visible dashboard twin: matches to dashboard products 21 → 364 |
| D-16 | `--stock=zero` | nothing written to the ledger by the import |
| the map's targets (found by the hop test) | `LegacyRedirects::nodeHref()`, `appSlug()` | a category goes to the URL the APP serves: top level `/category/<English name slugged>`, second level `/subtypes/<…>` (the app's `toSlug`, ported and unit-tested against it), a node without a page (deeper than two, out of the menu, no visible product) → its nearest parent with one, flagged `fallback`; product fallbacks follow the same rule; a matched product switched off on the dashboard (product 71) is a fallback, not a dead link |

### The rehearsal — `scratch_bf_final3_20261111`, a fresh copy of the production dump, §6's order exactly (`D:\BF-handover\work\rehearsal3.sh`, outputs in `work\final3\`)

The second rehearsal (`scratch_bf_final2_20261111`) ran the same steps; its hop test found the category targets above, so the third ran everything again on a fresh copy with the fixes. Import, cleanup and feed numbers are identical between the two.

| Step | Result |
|---|---|
| 1A cleanup (D-5, D-6) | **6,526 removed, 32 archived** (457 duplicates listed, 72 Watchizer-active untouched); backup written and read back first |
| prepare | 3 lookups, **14 brands**, **17 categories** switched on (active and in the menu), **5** dashboard twins made visible (337 already were), SATIN10 created; nothing refused |
| import (stock zero) | **7,053 imported**, 0 failed, 110 variants, **0 units** written; **703 matched** (364 dashboard products, 152 Watchizer's own, 187 sheet copies), **26 skipped** (24 old-site duplicates, 2 without a price); 58 slugs changed; spec fields written 43,843; `inventory:verify` all agree; a re-run created nothing (digest unchanged); 596 s, 72 MB |
| visible on Brand Fashion | 8 now (sample images only) → **~6,412 once the originals are on disk** (6,404 are hidden for the photo alone); ~641 stay hidden for real reasons (413 hidden on the old site, ~100 a missing description, 82 no category, 41 no gender) |
| redirect map (final run) | 7,968 old URLs, **7,880 rows**, 88 need none. By status: product same 88 / matched 505 / fallback 7,187 / unmatched 2; category matched 34 / fallback 25 / unmatched 7; brand matched 43 / unmatched 4; gender 3; page matched 16 / unmatched 2; post fallback 11 (drafts); post category fallback 2; collection 7 and tag 6 unmatched; image 26 (the sample). The category and product fallbacks are the rehearsal's missing photos: a category shows only with a visible product, so with the originals present most fallbacks become same-URL / matched — which is why the map is built again on switch day |
| articles | **11 drafts**, 33 internal links re-pointed to the new URLs, their 8 images listed (not in the local sample) |
| offers | rule **FRIDAY1000** on Brand Fashion only; Hot Deals copied with 133 products / 66 campaign prices (1 left out: not visible on Brand Fashion) |
| Google feed (rehearsal token) | **2,135** Brand Fashion products, 1,983 in stock / 152 out, 177 without an MPN, every link `https://www.brandfashionegy.com/product/…` |

### The hop test — every row of the map through the real app

`next start` of the Brand Fashion build (:3011) → its middleware → the scratch core (:8031) on the rehearsal copy, both with a shared `STOREFRONT_SERVER_KEY` (the per-IP limit of 60/min otherwise answers 429 — the switch-day requirement is in the domain plan §6). Each old URL as WordPress served it (trailing slash; images as they were) must answer ONE permanent redirect to its row's target, and every distinct target must answer 200 (`work\sample-tools\hops.mjs`, results `work\final3\hops.json`).

- **7,968 / 7,968 old URLs: one hop to the right URL**, 0 failures. One of them, `/search/`, answers **308** (Next's own `/Search → /listing` rule runs before the middleware) — permanent, one hop, right target; counted apart.
- **696 / 696 distinct targets answer 200** (image targets checked as the files the import wrote).
- **How it got there** (so the numbers can be trusted): the round-2 run passed every hop but 58 targets answered 404 — 26 images (a test artefact: the scratch server serves `public/` only; now checked on disk), 31 category pages and 1 product (the two real bugs above, fixed). The first run on the third copy then still showed 5 category pages 404: the scratch core was answering the shop's catalogue reads **as Watchizer** — compat picks the storefront by the request HOST and `127.0.0.1` falls back to the Watchizer pin in local mode — so the category pages that had "passed" did so on Watchizer's tree. The core was restarted behind a scratch router that presents Brand Fashion's API host (what production's proxy does); its `catalog/meta` then read Brand Fashion's tree (Watches / Fashion / Electronics), and the whole test was run again: the result above. No repo or data change for this.

### The gate

| Check | Result |
|---|---|
| PHPStan (`composer phpstan`, level 10, whole project) | **No errors** — 7 found and fixed in this round (2 from the map fix, 5 from late round-2 edits not re-checked until then) |
| Pint | clean on every touched PHP file |
| core tests, the touched ones | **33 passed** — import (7, incl. the new category-URL test), decisions (3), text (10, incl. the app slug), markdown (4), Google feed (6, new), redirect reader (3, new) |
| core suite, full | 299 failed, 39 skipped, **2,087 passed** on a fresh copy of the production dump (`scratch_bf_verify_20261111`, 1,118 s) — the 299 failures are **line for line the control run's** (HEAD without this work, same copy): none new, none gone. — they fail the same way without this work |
| Brand Fashion app | lint incl. `no-undef` clean; vitest 23 files / 178 tests; production build OK (from a copy, every `NEXT_PUBLIC_*` set in the process) |
| Watchizer app | production build OK (from a `git archive` copy) — its code is unchanged; built because the twins manifest changed |
| twins | `StorefrontTwinsTest` 4 passed; `middleware.js` declared DIVERGED in `TWINS.txt` |
| hydration / console | **0 / 0** on phone and desktop, as Brand Fashion: `/`, `/about-us`, `/contact-us`, `/privacy-policy`, `/listing?sale=1`, `/blogs`, a product page, `/subtypes/quartz`, `/category/fashion`; the four carried-over titles render |
| after the builds | comment-only edits in `page.jsx` / `trustPages.js` (the "BrandFashion" note made unambiguous) — the built output is the same |

## 9. Round 3 — O-1 … O-6 applied and rehearsed (2026-11-11 night → 12 Nov, 00:20)

### What changed — data AND four pieces of core logic

| Decision | What was built | Why it needed code |
|---|---|---|
| O-1 the articles in Arabic, published | `articles_ar.py` → `articles-ar.json` (outside the repo: `D:\BF-handover\work\articles-ar\`, copied to the checkpoint): the 11 articles' Arabic title, SEO title, SEO description and body; `bf:legacy-blogs --arabic=<file>` | the Arabic body is HTML with the OLD links and image addresses, so it goes through the same cleaning, link re-pointing and image processing as the English; the command refuses a file missing any of the four fields; an article with Arabic is published on its original date |
| O-2 SATIN10 until 31 Dec 2026 | nothing — already so | — |
| O-3 Buckle → إبزيم, Clasp → مشبك | `CataloguePrepare::RENAMES`, applied BEFORE the lookups are created; undo puts مشبك back on Buckle once Clasp is gone | the dashboard refuses two rows of one list with the same Arabic name, so the order matters |
| O-4 the 115 reviewed duplicates | `bf:catalogue-clean --reviewed=<twins-review.csv>` (§6 step 6b): the "not a duplicate" rows removed, one of each real duplicate kept (the one the team edited, else the oldest), anything else refused; backup + restore as step 5 | **and the importer's identity:** two old products with the same name and no SKU were ONE product (the second skipped as an old-site duplicate), so removing the 107 sheet copies alone would NOT have imported their clean old versions. Rule 3 now needs the same name AND the same first photo (the review's own evidence) — `LegacyImporter::identityKey()`, the sheet copies keyed by their old product's photo too |
| O-5, O-6 mail | domain plan §7.1 rewritten (hPanel, the exact Namecheap records, the dashboard values) | — |

Found by the rehearsal and fixed before the final run: the reviewed removal, run without `--opening-balance-is-not-history`,
counted the sheet's own opening balance as history — the delete step's re-check skipped 25 of the 112 silently, while the
backup already held all 112 (a restore would then refuse). A product with history is now refused BEFORE the backup is
written (the backup holds exactly what is deleted), a short delete is reported loudly, and §6 step 6b carries the D-5 flag.

### The rehearsal — `scratch_bf_final5_20261111`, a fresh copy of the production dump, §6's order (`rehearsal5.sh`, outputs `work\final5\`)

| Step | Result |
|---|---|
| 1A cleanup | 6,526 removed, 32 archived (unchanged) |
| prepare | **1 renamed** (Buckle → إبزيم), 3 lookups (Clasp = مشبك), 14 brands, 17 categories, 5 twins, SATIN10 |
| import dry run BEFORE O-4 (the new identity alone) | 7,055 to create, 703 matched, 24 skipped (22 old-site duplicates, 2 without a price) — the photo rule alone frees 2 products |
| **O-4** | **112 removed, 3 kept** (6980, 7049, 7058 — nobody had edited any: the oldest of each), 0 refused; the backup read back first (112 products and their 25 opening-balance movements) |
| **import (stock zero)** | **7,161 created** (round 2: 7,053), 0 failed, 110 variants, 0 units; **596 matched** (364 dashboard, 152 Watchizer's own, 75 sheet copies by their WordPress id, 5 by name and photo — the old site's own duplicates of the 3 kept), 25 skipped (23 old-site duplicates, 2 without a price); `inventory:verify` agree; a re-run created nothing |
| **O-4 confirmed** | of the 107 "not a duplicate" old products, **106 are now imported**; the 107th (`BUNDLE BIG SALE MK3215-CKW2`) carries the SKU `BUNDLE ES4284-mkw1` of another old bundle and is skipped as the old site's duplicate — its old URL leads to that twin. The 5 old duplicates of the 3 kept copies match them (no duplicate created) |
| visible once the originals are on disk | 8 now → **~6,479** (6,471 hidden only for the photo) |
| articles | **11 published** in both languages, on their original dates; their links re-pointed through the map |
| redirect map (final) | 7,968 old URLs, 7,880 rows, 88 same; **post matched 11** (was fallback); the rest as round 2 (product same 88 / matched 505 / fallback 7,187 / unmatched 2; category 34 / 25 / 7; brand 43 / 4; gender 3; page 16 / 2; post category 2; collection 7, tag 6; image 26) |
| O-3 | closure type 1: en Buckle / ar **إبزيم**; closure type 11: en Clasp / ar **مشبك** |
| offers, feed | FRIDAY1000 (rule 9139), Hot Deals 133 products / 66 prices; Google feed 2,135 products, 177 without an MPN |

### The hop test — the changed rows and a sample (Michael's instruction, round 3)

455 rows through the real app with the Brand Fashion host router: the 41 rows whose target or status changed since the
last full test (the 11 articles among them), the 2 old post-category rows, the old URLs of the 112 O-4 products, and
300 random others (`hop_subset.py`, seed 1111). **455 / 455 one permanent hop to the right URL; 85 / 85 distinct
targets 200** (`work\final5\hops-subset.json`).

### The Brand Fashion re-check — what the earlier rounds measured against Watchizer's data (Michael's question)

The earlier rounds' Brand Fashion build read core at `127.0.0.1:8020` (server) and from the browser — no storefront
host — so every **compat** answer it got was Watchizer's; **v2** answers name the shop in the path and were always
Brand Fashion's. What Brand Fashion reads from where:

| Feature (Part 3) | Brand Fashion's source | Measured against Watchizer's data? |
|---|---|---|
| the wheel | the page is Watchizer's only; the account tab's visibility comes from v2 campaigns | no |
| campaign prices | product page, listing, Hot Deals / Flash Sale, cart page, checkout: **v2**. The cart drawer prints the cart line's own price (v2); its compat card gives only the picture and title | the prices: no |
| payment badges | v2 `payment-badges` | no |
| the deal line | v2 home rails and campaigns | no |
| one offer per order | v2 cart and orders; shipping cities are ONE shared table | no |
| **the page around them** | the header menu (`catalog/nav`), the footer's categories, the listing filters' names and the category/brand page resolution (`catalog/meta`), the cart drawer's card pictures, the offer pages' rails (`catalog/related`) — **compat** | **yes** — the floating-element positions, stability and hydration measurements of 7–10 Nov ran with Watchizer's menu and tables on Brand Fashion |

Re-checked tonight through the router on the rehearsal copy (`work\final5\part3-recheck.json`; the copy's two Hot Deals
campaigns extended by one day — scratch only — because they end at 00:00 on 12 Nov):
- product 25405 on Brand Fashion's Hot Deals: v2 card 2,650 (sale 3,450, price 4,500); the guest cart line **2,650**;
- the drawer's compat card for 25405, as Brand Fashion and as Watchizer: identical (4,500 / 3,450, no campaign
  fields) — the drawer's price comes from the cart line, not from the card;
- **FRIDAY1000 with that item in the cart: refused** — `on_offer`, "Discount codes can't be combined with items already
  on offer (Hot Deals / Flash Sale)" / "لا يمكن استخدام كود الخصم مع منتجات عليها عرض";
- hydration / console **0 / 0** on 18 loads (phone and computer): `/`, `/hot-deals`, the product, `/cart`, `/checkout`,
  `/subtypes/quartz`, `/blogs`, an article in English and in Arabic; the Arabic article's HTML carries its Arabic
  SEO title, headings and description and no link to the old site.

### The gate (round 3)

| Check | Result |
|---|---|
| PHPStan (`composer phpstan`, whole project) | **No errors** |
| Pint | clean on every touched file |
| import / feed / redirect tests | **36 passed** — 3 new (the photo identity, the review sheet, the Arabic article) and the O-3 checks added to the prepare test |
| core suite, full (PHP logic changed) | 299 failed, 39 skipped, **2,090 passed** (`scratch_bf_verify_20261111`, 1,068 s); against the control run (HEAD without this work): one line NEW — `TranslationCoverageTest`, the dashboard keys of Block A (2026-11-12) added while the suite ran, without their English entries; fixed (`lang/en/manage.php`), the test 4 / 4 — and one line GONE: `DashboardHomeTest` "the SAME WEEKDAY", which depends on the date (the clock passed midnight between the runs). Every other failure is the control run's, line for line |
| storefront apps | **no app code changed** this round — no rebuild (the round-2 builds stand) |
