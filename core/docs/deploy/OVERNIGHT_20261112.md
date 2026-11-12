# The overnight of 2026-11-11 → 12 — record

Michael's overnight queue (sent 2026-11-11, ~23:55): finish round 3 of the Brand Fashion import (its record is
`BF_LEGACY_IMPORT_2026-11-11.md` §9), then blocks A–F in order, without waiting. Rules: scratch only — no production
writes, no SSH, no git state changes, no `media:prune`, never the dev database; a decision that is Michael's is taken
the safest REVERSIBLE way and marked **decided overnight — Michael to confirm**; the uncommitted work saved outside the
repo after each block (`D:\coding\watchizer website\checkpoints\2026-11-11_bf-legacy\save-*`).

Scratch databases used: `scratch_bf_final5_20261111` (the round-3 rehearsal copy of the production dump; the shops'
checks), `scratch_bf_verify_20261111` (the test suite). Servers: core `php -S` on :8031 behind the Brand Fashion host
router (`bf-host-router.php` — compat picks the storefront by host) and on :8032 plain (the local Watchizer pin); the
two shops' production builds from copies of the working tree on :3011 (Brand Fashion) and :3012 (Watchizer).

---

## Block A — three design fixes (ship in tomorrow's deploy)

### A1. Brand Fashion's header shows the FULL logo

| | Before | After |
|---|---|---|
| header (ink bar), phone | `wordmark-white.svg`, 28 px high | **`logo-lockup-white.svg`** — the monogram with the name — **44 px** high (60.6 × 44), filling the 44 px tap box of the 56 px row |
| header, computer | wordmark, 36 px | **the lockup, 56 px** (77.2 × 56) inside the 64 px row |
| the phone drawer | `wordmark.svg` 28 px | **`logo.svg`** (the ink lockup) 40 px |
| footer | already the full white lockup (96 × 70) | unchanged |

The files are Brand Fashion's own (`new branding/design/brand-fashion-logo/`, already in `public/`): nothing drawn, and
the lockup is **scaled whole**, as its README requires ("do not re-stretch, re-space or recolour"). Measured on the
build, both languages: the header stays **56 px (phone) / 104 px (computer: 64 + the 40 px nav row)**, the box is fixed
by `width`/`height` + `aspect-ratio`, so nothing shifts while it loads. Files: `Frontend-brandfashion/src/Components/
Header/Header.jsx`, `Header.css` (Brand Fashion's own, not twins).

- **Decided overnight — Michael to confirm: the e-mails keep the WORDMARK.** Both shops share ONE mail layout with a
  fixed 200 × 61 bar (`emails/partials/header.blade.php`, `StorefrontIdentity`'s `mail_wordmark`), and Brand Fashion's
  logo README says the full lockup is "too tall for a mail header; use the wordmark". The full logo in the mail would
  mean a taller bar for both shops and a new PNG + an identity setting on switch day. *Alternative:* the lockup in the
  mail, ~96 × 70 on the dark bar. *Cost of the choice:* the mail shows the name without the monogram.
- **Decided overnight — Michael to confirm: the lockup is the STACKED one** (monogram above the words) — the client's
  own arrangement. A side-by-side arrangement (monogram left of the wordmark) would read larger in a 56 px row, but it
  is a new arrangement of the mark, which the README forbids without the client.

### A2. Banners: the box IS the crop's shape — measured on both shops

**Measured** (`banner-boxes.mjs`, the production builds, the home page, 11 widths from 360 to 1920 px; `work\final5\
banners-bf*.json`, `banners-wz.json`):

| Placement | Box, before tonight | Dashboard crop | After tonight |
|---|---|---|---|
| hero, ≥ 1024 px | 1377 : 480 (2.8688) | computer 1377 : 480 | **2.8688 at every width** — unchanged, exact |
| hero, < 1024 px | 358 : 160 (2.2375) | phone 358 : 160 | 2.2375 — exact for a PHONE banner; a "both" banner (cropped 1377 : 480) was **cut 22 %** by `object-fit: cover` |
| side slots, ≥ 1024 px | the grid's 2.15fr / 1fr: the slots' shape drifted with the width (≈ 1.91–1.93) | 960 : 500 (1.92) | **1.9200 – 1.9202 at every width** — the columns are now computed (below) |
| side slots, 600 – 1023 px | 960 : 500 | 960 : 500 | exact |
| side slots, < 600 px | 4 : 3 | phone 520 : 390 (4 : 3) | exact for a PHONE banner; a "both" banner (1.92) was **cut 31 %** |

The same in Watchizer (the components are twins): its hero is narrower (1048 × 366 at 1920 in the section), the
shapes identical. The brand banner has no uploaded picture (the brand's logo, the team's colours, three product photos)
— nothing to crop. Those are all the placements core has (`BannerWriter::PLACEMENTS`: `home`, `home_side_1`,
`home_side_2`).

**What changed:**
- **Storefront (twins `homeZone.css`, `BannerHero.css`, `HeroZone.jsx`, both apps):** the zone's columns are
  `calc((100% - 0.56px) / 1.33464)` for two side slots and `calc((100% - 14px) / 1.66928)` for one — the solution of
  "hero 1377 : 480, each slot 960 : 500, 14 px gaps, same height" — so every box is exactly its crop's shape; every
  banner picture is drawn `object-fit: contain`: a picture of the right shape fills its box exactly, one of another
  shape (an old upload) is shown WHOLE, never cut a second time.
- **A bug the measurement found, fixed:** a side slot with a computer banner AND a phone picture was hidden on a phone
  as "computer only" — its phone picture was never seen (Brand Fashion's side-bottom slot: one slot on phones instead
  of two). Now both slots show, the phone picture in its 4 : 3 tile (measured: 0.2 % off).
- **Dashboard (`Banners/Index.tsx`, `BannerController`):** a NEW banner is for the computer OR the phone — "Computer
  and phone" is no longer offered, and core refuses it (one crop cannot be both shapes). A banner already saved as
  "both" keeps working and says, under its device, to re-upload it as two. Beside the upload field: the recommended
  size AND the shape ("2800 × 976 px — shape 1377 : 480, exactly the shape of its box on the site").
  **Decided overnight — Michael to confirm** (the alternative: keep "both" and crop the same original twice — two
  files per banner, which needs a second picture column, i.e. a migration).

**Live banners now the wrong shape — re-upload (production copy of 2026-11-11; nothing re-cropped automatically):**

| Banner | Shop · placement · device | Crop | Wrong where | Re-upload as |
|---|---|---|---|---|
| 1364 | Brand Fashion · hero · both | 1600 × 558 (1377 : 480) | phones (< 1024 px): shown with bars, 22 % of the box | a "Phone only" hero banner, 716 × 320 (keep 1364, switched to "Computer only") |
| 1365 | Brand Fashion · hero · both | 1920 × 669 | phones: bars, 22 % | the same |
| 1366 | Brand Fashion · side top · both | 800 × 417 (1.92) | phones (< 600 px): bars, 31 % | a "Phone only" banner in the side-top slot, 520 × 390 |
| 1367, 1368 | Brand Fashion · side bottom · computer / phone | 1532 × 798 / 1067 × 800 | — exact | — |

Watchizer had no banner on the production copy.

### A3. "See more" on a hand-picked slider (both shops)

Watchizer's hand-picked sliders had no button (`viewAll={rail.kind !== 'custom'}`); Brand Fashion's DID — "See all" to
`/listing`, the whole shop. A hand-picked slider has no listing of its own, so: an optional **"See more" link** in the
slider's settings on the dashboard (Home sliders → a hand-picked slider → "رابط «عرض المزيد» (اختياري)"), kept in the
rail's existing `settings` JSON (**no migration**), an internal path only (`/…`, refused: `https://…`, `//host`,
`javascript:`, spaces), sent by both home answers as `more_href`. The button shows **only when it is set**, on both
shops. **Decided overnight — Michael to confirm** (Brand Fashion's hand-picked sliders lose their "See all → whole
shop" until a link is set).

Proved on the builds (scratch rows 38 / 49 given a link): Brand Fashion's "Men's watches" → `/brand/rolex`, its two
other hand-picked sliders without a button; Watchizer's "Bags & wallets" → `/brand/rolex` (`/ar/brand/rolex` in
Arabic), its four other hand-picked sliders without a button. **Note for the team:** a link is a path of THAT shop —
Watchizer's listing does not read Brand Fashion's `sale=1`, so copy the address from the shop's own address bar.

### The gate (Block A)

| Check | Result |
|---|---|
| both shops' production builds (copies of the working tree, every `NEXT_PUBLIC_*` in the process) | Brand Fashion exit 0 (246 s, rebuilt after the slot fix), Watchizer exit 0 (94 s, rebuilt) |
| twins | `homeZone.css`, `BannerHero.css`, `HeroZone.jsx` identical in both apps; `StorefrontTwinsTest` 4 passed; nothing new diverged |
| hydration / console | **0 / 0 on 16 loads**: both shops × `/`, `/ar`, `/listing`, `/ar/listing` × phone and computer |
| storefront unit tests | Brand Fashion 178 / 178, Watchizer 228 / 228 |
| ESLint (with `no-undef`) on the touched files | 0 errors (2 old warnings: `<img>` for the SVG logo, deliberate) |
| dashboard | `npm run typecheck` clean; `npm run build` ✓ (41.8 s) |
| core | PHPStan **No errors**; Pint clean; `HomeRailMoreLinkTest` (new, 2), the banner tests updated to the one-device rule (`BannerDeviceAndCropTest` — "refuses a NEW banner for both devices or none, lets a banner saved as both keep it, never gain it" — `BannerScreenTest`, `BannerImageUrlTest`) and the rail/home tests: all green except failures that the CONTROL run has too (`CatalogHomeTest` ×3, `HomeRailScreenTest` reorder, `HomeRoundTwoTest` rebuild); `TranslationCoverageTest` 4 / 4 (the new dashboard words in `lang/en/manage.php`) |

Scratch fixtures (on `scratch_bf_final5_20261111` only): Brand Fashion's five banners copied to Watchizer to measure its
boxes; rails 38 and 49 given a "See more" link; the two Hot Deals campaigns extended by a day (round 3).

---

## Block B — MyFatoorah for Brand Fashion (built, tested end to end on MyFatoorah's TEST environment)

The brief's shape (handover §7.2 A4): a second implementation of `PaymentProvider`, MyFatoorah's **hosted payment
page** (a redirect, like the old site and like the Paymob flow), the dashboard's credential fields from the interface,
tests in the style of Paymob's. (The original brief message was not found in the session transcripts; the handover's
A4 and tonight's message are what this follows.)

### What was built

| Piece | What |
|---|---|
| `app/Domain/Payment/MyFatoorahProvider.php` | **initiate:** `InitiatePayment` (the account's ENABLED methods, read at that moment — nothing hard-coded) → the method whose `PaymentMethodCode` is the dashboard row's integration id (`vm` cards, `md` Meeza, … as the account lists them) → `ExecutePayment` → its `PaymentURL`; `CustomerReference` = the order number, `UserDefinedField` = `{order_id, storefront_id, method}`, the return and error URL = core's callback route. **verify:** from the shopper's return (`?paymentId=`) or the webhook (`Data.PaymentId` / `Data.InvoiceId`) only the payment's id is taken; `GetPaymentStatus` with THIS contract's key is the authority — status, value, our order number, our method code. A webhook is refused when the contract's webhook key is set and `MyFatoorah-Signature` does not match (MyFatoorah's rule: the event's fields sorted case-insensitively, `key=value` joined by commas, HMAC-SHA256 with the key, base64). `resolveStatus` for a reconciler |
| `ProviderRegistry` | `myfatoorah` registered |
| `CallbackVerdict` + the callback controller | a provider may state its own OUTCOME (MyFatoorah: Paid → success, a Failed/Canceled transaction → failed, else pending); Paymob's is read as before. The signature header is handed to MyFatoorah only |
| `config/services.php` `myfatoorah.base_url` (`MYFATOORAH_BASE_URL`) | the API host, **no default** on purpose (a live key on the test host fails like an outage); test `https://apitest.myfatoorah.com` |
| dashboard (Payments) | credential fields **API key** and **Webhook signing key** (both write-only, both required for the contract to count as live); MyFatoorah and **Meeza** (a new method key) labelled; the integration-id hint and the "malformed" refusal say what each provider wants (Paymob: digits; MyFatoorah: the method code) |
| `php artisan payments:myfatoorah-methods brandfashion` | READ ONLY: the account's methods now, with their codes, and each dashboard row checked against them; `--payment=<id>` shows what MyFatoorah says about one payment (the switch-day currency check) |
| **migration `2026_11_12_000000_payment_transaction_id_text`** | `payment_statuses.pay_transaction_id` BIGINT → VARCHAR(64), its unique index kept. MyFatoorah's payment id is a 20-digit string (measured: `07077367088394980975`) — wider than BIGINT — and it is the idempotency key of the callback route. **Decided overnight — Michael to confirm** (it is tomorrow's ONE pending migration). `down()` proven on scratch: back to BIGINT while every id is a number; refuses with a MyFatoorah-length id stored |

### Proved

- `MyFatoorahTest` (new): **7 passed** — registration and fields; initiation on the method the account offers (and the
  exact `ExecutePayment` body: method id, value, order number, return URL, mobile, method code, no display currency);
  refusal for a method the account lacks, an unset host, no key; a forged return's amount/reference ignored — only
  `GetPaymentStatus` counts; the webhook signature accepted/refused; through the route: paid once, a webhook replay
  recorded once, a smaller amount or an unpaid invoice never pays.
- The Payment / Compat payment+checkout / Manage payment tests: 121 passed, 85 failed, 1 skipped — the 85 are, name for
  name, the control run's (`DecryptException`: the production copy's encrypted credentials cannot be read with this
  machine's key); none new.
- **End to end on MyFatoorah's TEST environment** (`apitest.myfatoorah.com`, MyFatoorah's published test key, scratch copy
  `scratch_bf_final5_20261111`, the contract's `callback_base` set to `https://mf-e2e.example.org` — a reserved domain, so
  the redirect reached nobody): a Brand Fashion guest order by the v2 API (order **000164**, EGP 2,745) → core started the
  payment → MyFatoorah's hosted page (headless Chrome, test card 4508 7500 1574 1019, its 3-D Secure test page passed) →
  MyFatoorah redirected to the return with `paymentId=07077367088394980975` → that return delivered to the scratch core's
  callback route → **order 000164 `processing`, paid via MyFatoorah, the attempt 274,500 piastres = the order**, the
  shopper sent to `order-confirmation?order=000164&status=paid`; a replay: still one row. Again with the method code on
  the invoice (order **000165**): method **card** (row 5848) recorded.
- PHPStan **No errors**; Pint; dashboard typecheck + bundle ✓.

### What switch day needs (in the runbook, Block F)

1. `MYFATOORAH_BASE_URL` in core's `.env` = the live host MyFatoorah's portal gives the Egyptian account; `config:cache`.
2. Dashboard → Brand Fashion → Payments → add **MyFatoorah**: the NEW live API key, the webhook signing key (portal →
   webhook page → "secure key" ON → its value); the webhook URL in the portal:
   `https://api.brandfashionegy.com/api/pay/brandfashion/myfatoorah/callback`.
3. `php artisan payments:myfatoorah-methods brandfashion` → the account's codes; one method row per method wanted
   (card `vm`, Meeza, wallets, Fawry — **the codes as the live account lists them**), each switched on.
4. One real small payment; `payments:myfatoorah-methods brandfashion --payment=<its id>`: `InvoiceDisplayValue` must
   name **EGP** (MyFatoorah reports values in the account's base currency — measured on the Kuwaiti test account: an EGP
   2,745 order became a 2,745 KD invoice; an Egyptian account invoices in EGP).

### Found, not changed

- The order's legacy `payment_method` column reads `paymob` for ANY online method (the v2 order maps `card` there);
  `paid_via_provider` says `myfatoorah`. Cosmetic, pre-existing.
- Every order on the scratch core logged "maximum execution time of 30 s" AFTER its response: the listing-index rebuild
  (`CatalogWarmer`, run once the response is sent) of Watchizer's 2,157 products takes 11 s from the command line and
  more under the single-threaded `php -S`. Pre-existing (Watchizer's own orders trigger it today); check production's
  PHP error log after switch day's first test order.
- The workstation's PHP has no CA bundle for its HTTP client (`cURL error 60`): the scratch core was started with
  `-d curl.cainfo=<Git's ca-bundle>` — production is unaffected.

---

## Block C — Meta pixel continuity (and Google's item ids)

### What the old site did (read from `bf_legacy`, read only)

- **Pixel:** PixelYourSite, `pys_facebook.pixel_id = 1594687941022756`, enabled — **confirmed: the old site used the
  client's pixel "Brand Fashion Egypt's pixel".**
- **Content ids:** `woo_content_id = product_id`, no prefix, no suffix (`woo_content_id_prefix/suffix` empty), variable
  products not sent as simple — the pixel sent the **WooCommerce product id**.
- **Google Merchant Center:** Google Listings & Ads (`gla_merchant_id` set); its items are `online:en:EG:gla_<WooCommerce
  id>` (`_wc_gla_google_ids`, 5,243 products) — the item id is **`gla_<WooCommerce id>`**.

### What changed — ONE rule, used by every channel (`app/Storefront/ContentIds.php`)

| Channel | Where | Before | Now |
|---|---|---|---|
| pixel ViewContent | `ProductDetailClient.jsx` (twin) | product id | `content_id` (from the v2 product) |
| pixel AddToCart / RemoveFromCart | Brand Fashion `cartStore.js` | product id | the confirmed cart line's `product.content_id` |
| pixel InitiateCheckout / Purchase | Brand Fashion `Checkout.jsx`, `OrderConfirmation.jsx` (twin) | product id | `product.content_id` (handed over as `cid`) |
| Conversions API Purchase | `MetaConversions::purchaseEvent` | product id | `ContentIds` for the order's storefront |
| Meta feed `id` | `MetaFeed::lines` | product id | `ContentIds` |
| Google feed `id` | `GoogleFeed` | the Meta id | `ContentIds::google` |
| v2 cards (listing, home, campaigns, cart items) and product page | `ProductCards::build`, `ProductDetail` | — | new field `content_id` |

- **Watchizer:** `content_id` = the product id — nothing it sends changes (its Meta feed byte-identical; its tests green).
- **Brand Fashion:** an old product keeps the old site's id — `bfwp:<id>` (imported) → `<id>`; `woo:<id>` (the September
  sheet copy) → `<id>` unless an imported product holds the same old id (the 32 archived copies have imported twins:
  the import wins). Google: `gla_<id>`. **Every other Brand Fashion product: `p<id>`** — **decided overnight — Michael
  to confirm:** the bare product id cannot be used: measured on the rehearsal copy, **288** products' own ids equal some
  old WooCommerce id (old ids run 875 – 66,800; core's up to 33,944 and growing) — two products would share one content
  id in the catalogue. A letter never equals an old numeric id. (Brand Fashion's pixel has run only on the temporary
  domain, which nobody used, so changing its new products' ids costs nothing.)

### Proved

- `ContentIdsTest` (new, 2): the rules above for both shops, both channels; the v2 product page answers `content_id`
  (Brand Fashion `990011` for `bfwp:990011`; Watchizer its product id).
- On the rehearsal copy: the imported product `bfwp:875` → `content_id "875"` on its page; its Google id `gla_875` —
  **exactly the old Merchant Center's item (`online:en:EG:gla_875`)**; Brand Fashion's Google feed 2,135 rows, all ids
  unique (80 `gla_…` now — most imported products are hidden until their photos are processed; ~6,479 once they are);
  the Conversions API Purchase of order 000165 → `["p25407"]`, the same id the pixel and the feeds give that product.
- Feeds, campaign prices, v2 and Conversions API tests: no failure that the control run does not have (the `DecryptException`
  ones); PHPStan No errors; Pint; ESLint 0 errors (warnings as at HEAD); vitest Brand Fashion 178 / 178, Watchizer
  228 / 228; both builds exit 0 (93 s / 66 s); the twin files identical; hydration / console **0 / 0 on 18 loads**
  (product EN/AR, checkout EN/AR, confirmation; both shops, phone and computer).

### Switch day (runbook, Block F)

**Corrected at 03:10 (Block F found it, see there):** the line written here earlier said the pixel id and the
Conversions API token go "on the dashboard". **There is no such field.**
- The **browser pixel** id `1594687941022756` is the Brand Fashion app's build value `NEXT_PUBLIC_META_PIXEL_ID`
  (hPanel → the app → environment, before the build).
- The **Conversions API** is ONE token and ONE pixel for the whole core (`META_CAPI_TOKEN` / `META_CAPI_PIXEL_ID`,
  Watchizer's). Until tonight, a Brand Fashion card order (MyFatoorah, Block B) would have been sent to WATCHIZER's
  pixel. Now only the configured shop's orders are sent (`META_CAPI_STOREFRONT=watchizer`). Brand Fashion's card
  orders send no server-side Purchase until it has its own token: **D-25**, a decision for Michael (the morning
  report). Its browser pixel still fires on the confirmation page with the old site's content ids.

Meta Commerce Manager's catalogue is fed from Brand Fashion's Meta feed link (`config/feeds.php` `meta.brandfashion`,
added tonight; off until `FEED_META_BRANDFASHION_TOKEN`). Google Merchant Center is fed from the Google feed link:
the old items (`gla_…`) are matched by id, and new products arrive as `p…`.

---

## Block D — the image pass, in parallel and apart from the import

Processing the originals is the long part of switch day (one process, 0.67–0.91 s an image on the workstation; about
24,000 images). It now runs **before** the import, in as many processes as the server can carry, while the old site
still serves. The import then only reuses what was made.

### The images the zip does not have (`D:\BF-handover\work\final5\images-not-in-zip.csv`)

The handover zip covers 2023–2026 (30,912 files, 2.84 GB). The import needs 25,278 originals: 2,245 from 2023,
12,200 from 2024, 7,185 from 2025, 2,697 from 2026, and **951 from 2022/, which the zip does not have**. No other
path is missing.

- **299 old products** use a 2022 photo (981 photo uses, because some photos are shared).
- **259 of them lose their FEATURED photo.** The import gives a product without a featured photo no images, and the
  shop shows no product without a picture, so these 259 are imported **hidden**. Their text, price, sizes, SEO fields
  and redirect are all in place. All 259 were visible on the old site.
- **40** keep their featured photo and only miss 1–3 gallery photos. They are visible, with fewer pictures.
- **Proposal:** ask the agency for `wp-content/uploads/2022/` (the 951 files are listed in the CSV). When it comes,
  extract it into the same originals folder and run `bf-images.sh start` again (it processes only the new files).
  Then attach the photos to the 259 with a small step written for it (not written tonight: it waits for the folder).
  Until then the 259 stay hidden, and their old URLs redirect to their category (the redirect map's existing rule
  for a hidden product, else `/listing`). After the photo step, re-run `bf:legacy-redirects` so their old URLs go to
  their product pages again.

### What was built

- **`php artisan bf:legacy-images <extract> --images=<originals> --out=<the import's --out> --shard=K/N [--apply]`**
  (`LegacyImagePass`).
  - It processes exactly the files the import will store: the images of the products it will CREATE, from the same
    plan on the same database, skipping a product whose featured photo is missing.
  - A file goes to shard `crc32(path) % N`, so shards 1/N … N/N are disjoint and together are every file. Over the
    25,278 needed images, 4 shards get 6,584 / 6,268 / 6,148 / 6,278.
  - Each shard appends to `image-map.shard-K-of-N.jsonl` as it goes, and skips a file that ANY map in the folder
    already holds (with its master still on disk). A killed shard is restarted with the same line.
  - It writes media files only, never the database. Dry run by default; `--bench=50` times 50 originals into a
    throwaway folder and deletes it.
- **`MediaStore::storePath`** now CLAIMS each new file name with an exclusive create (`fopen 'x'`) before writing.
  The legacy name scheme is the clock to the microsecond, so two parallel processes could have drawn the same name.
  Now exactly one gets it; the other draws again. A failed write removes its claim.
- **The import** (`LegacyImporter::prepare`) reads the shard maps beside its own `image-map.jsonl`.
- **The redirects** (`LegacyRedirects::build`) read them too, one row per original. **Found and fixed tonight:** an
  image the shards made is reused by the import and never written to the import's own map. Without this fix, no
  old image URL (`/wp-content/uploads/…`) would have been redirected. `BrandFashionImageMapsTest` fails on the old
  code and passes on the new.
- **Server scripts** (in `checkpoints\2026-11-11_bf-legacy\switch-day\`):
  - **`bf-measure.sh`** (about 2 minutes, read-only for the shop):
    - CPU count, memory, disk, the CloudLinux limits where readable;
    - php `memory_limit` and `max_execution_time`, the image extensions;
    - the originals (files and size, and whether `IMAGES` points at the folder holding `2024/`);
    - the time per image on 50 originals in one process;
    - **what the account really GETS in parallel**: four benches at once, measured against one process's pace.
      CloudLinux can show 32 CPUs and give the account 2.
    - The recommended shard count. Watchizer's live API runs in the same hosting account, so the script leaves it
      one process: **shards = what the account gets − 1**, minimum 1.
    - It stops if the import would store 0 originals (the import already ran, or clean/prepare were skipped).
  - **`bf-images.sh start | status`**:
    - starts shards 1/N … N/N under **`nohup … < /dev/null &`**, so they survive an SSH disconnect;
    - leaves a running shard alone, and refuses to start beside a running `bf:legacy-import`;
    - logs to `~/bf-handover/image-logs/`;
    - `status` shows each shard's last progress line and says when ALL have finished — only then does the import
      start;
    - after a disconnect, a kill or a reboot: the same `start` line again.

### Proved (on `scratch_bf_d_20261112`, a fresh copy of the production dump; `D:\BF-handover\work\rehearsalD.sh`)

- §6's order: cleanup 6,526 removed and 32 archived; prepare (1 renamed, Clasp = مشبك); the reviewed removal (112
  removed). The same numbers as round 3.
- **A = one process** and **B = four processes in parallel** on the same originals: the same 26 sources, none twice,
  the same sizes and renditions, every master on disk, no file name drawn twice, **26 / 26 masters byte-identical**,
  and 150 files on each side. (The workstation holds a 26-file sample of the originals. The split over all 25,278 is
  above.)
- **Re-run** of shard 2/4: 0 processed, 12 already done.
- **The import on B's maps:** 7,161 created, `images_reused` 31 (26 files, some shared by several products),
  **nothing processed inline**, 31 product image rows.
- **The redirects on B's maps:** `image:matched` 26 (the old code gives 0).
- `bf-measure.sh` on the workstation: one process 674 ms an image; four at once 10.3 s for 60 images = **3.93
  effective processes**, so it recommends 3; no bench folder left behind. `bf-images.sh` start and status
  exercised; the logs and map files are where the import looks.
- Tests: the media, upload, import and shard tests **73 / 73**; the new redirects test 1 / 1; PHPStan No errors;
  Pint passed.

### How long — the table (24,000 originals; the measured time per image decides the row)

| shards (processes) | at 0.7 s / image | at 0.9 s / image (workstation) | at 1.5 s | at 2.0 s |
|---|---|---|---|---|
| 1 | 4.7 h | 6.0 h | 10.0 h | 13.3 h |
| 2 | 2.3 h | 3.0 h | 5.0 h | 6.7 h |
| 3 | 1.6 h | 2.0 h | 3.3 h | 4.4 h |
| 4 | 1.2 h | 1.5 h | 2.5 h | 3.3 h |
| 6 | 0.8 h | 1.0 h | 1.7 h | 2.2 h |

These hours hold only while the account gives the shards a full core each. `bf-measure.sh` prints the same table
from the server's own numbers, capped at what it measured. The memory per process is ~50–66 MB: keep shards ×
that under half the account's limit. The import itself, with every image reused, is the database work alone
(minutes, not hours).

### Decided overnight — Michael to confirm

- **D-21:** the 259 products whose featured photo is in the missing 2022 folder are imported **hidden**, not
  without a picture and not left out. Undo: none needed; the photo step shows them when the folder comes.
- **D-22:** the shard count leaves one process for Watchizer's live API (shards = measured − 1). Alternative: all
  of them, faster but Watchizer's API slows during the pass.

---

## Block E — deploy-part4 (`DEPLOY_PART4_20261112.md`, `deploy-scripts\deploy-part4-20261112.sh`)

The shape of Part 3, with every gate. It carries everything uncommitted (2026-11-11 and tonight).
- **ONE pending migration** (expected 0 in the brief; Block B added it): `2026_11_12_000000_payment_transaction_id_text`.
  The production dump has Part 3's two (batch 18), so exactly this one is pending. The script stops on any other
  count.
- **ONE `.htaccess` line:** the Google feed's exception (round 1's D-9, already in runbook §4.1.1 and
  `ApiAllowListTest`, but never on the live file). The script adds it right after the Meta feed's line, with a
  backup, a one-line diff and a check that the path reaches core.
- **Part 3's two false stops, corrected:** API-host lines **2** (not 1); the wheel checked by its KEY
  `data.wheel.enabled` (the object is `{"enabled":…,"campaign":"round-1"}`), its value printed, not demanded.
- **Tested** against a stand-in built from runbook §4.1.1 (the script's own text, the network stubbed) and against
  every answer on the scratch core. Two faults found and fixed this way: `awk -v` eats the backslashes (the Google
  line would never have been added), and the "no MyFatoorah at checkout" check grepped for a word the answer never
  contains.
- The archive block builds the dashboard bundle (`npm run build`), as Part 2's.

### Found while writing it, and fixed (code)

- **Brand Fashion had no Meta feed**: `config/feeds.php` said "deliberately absent until its own domain is live".
  Block C's pixel continuity needs the old catalogue fed by ids it knows. Added, off until
  `FEED_META_BRANDFASHION_TOKEN` (**D-23**); `BrandFashionMetaFeedTest` 2/2 (off without a token; with one, an old
  product's row has the old id and a www link).
- **Brand Fashion's site address was typed 13 times** (switch plan §3 item 1, "not written yet"): now ONE literal in
  `src/lib/siteAddress.js`, value unchanged (**D-24**). Proved by every site URL on 7 pages, counted, before and after
  the build: identical. Hydration / console 0 / 0 on 10 loads; vitest 178/178; lint `no-undef` clean.
- **The full suite's one new failure was mine**: `TokenIssuanceTest` treats a file that HMACs and base64-ENCODES as
  a second token minter, and MyFatoorah's webhook check did both. It is a pure verifier now (decode the received
  signature, compare the raw HMAC), with the same behaviour (`MyFatoorahTest` 7/7, `TokenIssuanceTest` 16/16).

## Block F — the switch-day runbook and scripts (`BF_SWITCH_DAY_20261112.md`)

ONE runbook, 10 steps with times and the critical path:
deploy 45 min → measure 5 → clean/prepare/reviewed 30 → the image pass (1–6 h) → import 20–40 → redirects/articles/offers
15 → the core switch 15 → app, DNS, SSL, verify 60–90. About **5½–6 h** with 3 shards. hPanel / DNS for the API host
and the mail, Google OAuth, Search Console and the MyFatoorah portal go into the image pass's wait. The old WordPress
shop keeps selling until DNS `@` / `www` move (step 8.5).

Scripts (`checkpoints\2026-11-11_bf-legacy\switch-day\`): `bf-switch-import.sh` (each apply refuses until its dry run
was read and refuses an existing output folder), `bf-switch-core.sh` (`.htaccess`, CORS, the row read / write /
rollback, env, feeds, MyFatoorah methods), `bf-switch-verify.sh` (read-only PASS / FAIL, 100 random old URLs
included), `bf-switch-code.py` (the 2-line code switch, `--undo`), and Block D's `bf-measure.sh` and `bf-images.sh`.

### Found while writing it — each would have gone wrong on the day

1. **The plan's §5 SQL would not have set the `www` address.** `JSON_SET(settings, '$.urls.frontend', …)` does not
   create a missing parent, and `settings` has no `urls` on production (measured on the production copy). The
   statement would report 1 row and change only the domain: every e-mail link and the payment return would name the
   bare domain. `row-write` merges `urls` as an object. Rehearsed on scratch: write, then `row-rollback`; the row's
   md5 identical to before.
2. **The Conversions API is ONE token and ONE pixel for all of core** (Watchizer's), not per storefront. My Block C
   note said "on the dashboard": there is no such field (corrected in Block C). With MyFatoorah live, Brand Fashion's
   card orders would have been reported to WATCHIZER's pixel. Guarded tonight (`META_CAPI_STOREFRONT=watchizer`;
   another shop's orders send nothing; test in `MetaConversionsTest`). **D-25** for Michael.
3. The cache flush is `StorefrontCache::forgetStorefront` (the narrow one the plan's CHECK asked for), not
   `cache:clear`.
4. `offers:copy` copies only what is live, and **Hot Deals ended 12 Nov 00:00**: on switch day it will be absent.
   Expected, said in step 5.

### Proved

- `bf-switch-import.sh` + `bf-images.sh` run in their order on a fresh copy of the production dump
  (`D:\BF-handover\work\rehearsalF.sh`, `scratch_bf_f_20261112`, media into a throwaway folder; `backup` skipped).
  The second run (03:11–03:23):
  - cleanup 6,526 removed and 32 archived; prepare (1 renamed, 3 lookups, 14 brands, 17 categories, 5 twins);
    reviewed 112 removed;
  - 2 shards (26 originals), ALL SHARDS FINISHED;
  - import **7,161 created**, `images_reused` 31, nothing processed inline; `inventory:verify` agrees;
  - redirects **7,880 rows** (image:matched 26); the 11 articles published, then `post:matched 11`;
  - offers: Black1000 → FRIDAY1000, no campaign (Hot Deals ended).

  Refusals proved: an apply before its dry run; a FAILED step (its log without its marker) does not unlock the
  next; a dry run into an existing folder.

  **The first run found two faults in the script, both fixed before the second:**
  - `bf:legacy-redirects` does not create its `--out` folder, so the step failed (the script now makes it);
  - **a failed step still unlocked the next one**, because the gate only looked for its log. Each step now writes
    `<step>.ok` only after exit 0, the gate requires that marker, and `verify` also requires the nohup import's
    closing summary in its log. On the day, this would have let the articles and the second redirect run go ahead
    after a failed redirect run, with an empty redirect table: every old URL "served as is".
- `bf-switch-core.sh`: `row-read` / `row-write` / `row-rollback` on scratch (the rollback byte-exact; a rollback
  from an unreadable file refuses with `UNREADABLE`). The `.htaccess` and CORS edits run on stand-ins (2 lines
  changed; CORS keeps what is there and adds only what is missing). The steps whose checks call live hosts were NOT
  run. One read-only GET reached `api.michaelkhalaf.com` (the rollback's last check, 200).
- `bf-switch-code.py`: apply then `--undo` → both files byte-identical. A first version refused its own undo (a
  comment quoted the new line); fixed and re-proved.
- `bf-switch-verify.sh`: its HTML patterns (canonical, `hrefLang`, the sitemap) checked against the real build.

### Decided overnight — Michael to confirm

- **D-23:** Brand Fashion's Meta feed entry added now, off until its token. Alternative: a code change in the switch
  sitting.
- **D-24:** the site address as one literal now, value unchanged. Alternative: 13 edits on switch day.
- **D-25:** the Conversions API reports Watchizer only. Brand Fashion's card orders send no server-side Purchase
  until it has its own token. Alternatives:
  - (a) a per-storefront token and pixel on the dashboard: encrypted like the payment keys, about 2 h with tests;
    the right end state;
  - (b) a second env pair `META_CAPI_BRANDFASHION_*`: 30 min, but the token in a file, which the brief excludes.
- **D-26:** the commits as ONE script of 8 commits by area (`COMMITS-20261112.ps1`, generated from `git status` by
  `make_commits_20261112.py`). `COMMITS-bf-legacy.ps1` now refuses to run.
