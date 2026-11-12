# The fourth deploy — record (2026-11-12): everything of 2026-11-11 and the night to 12 Nov, core first

**State (2026-11-12, 03:00): built, gated (§3), not committed, not deployed.** Nothing on production was written. One
read-only GET reached a live host during the night (`api.michaelkhalaf.com/api/v2/brandfashion/meta`, the last check of
a rollback rehearsed on scratch; 200). It is the first step of the Brand Fashion switch day
(`BF_SWITCH_DAY_20261112.md`, step 1).

## 0. In one screen

| | What | Where it is recorded |
|---|---|---|
| core | the Brand Fashion legacy import (8 commands, run on switch day, not by the deploy); the redirect reader; the Google feed (off until a token); MyFatoorah (off until switch day); one content id per product; each shop's own Conversions API pair (D-25 b); a coupon EXCLUSION scope (WR10 / BR10, §8 — no migration); banners one device each with the crop shape; "See more" on a hand-picked slider; MediaStore's atomic name claim; Brand Fashion's Meta feed entry (off until a token) | `BF_LEGACY_IMPORT_2026-11-11.md`, `OVERNIGHT_20261112.md` Blocks A–D |
| dashboard bundle | Banners (device, size and shape), Home sliders (See more link), Payments (MyFatoorah fields, Meeza) | Block A, B |
| storefronts | Watchizer: See more, the banner box shapes, content ids (rebuilt by the push). Brand Fashion: the logo lockup, the shapes, content ids, the redirect middleware, ONE site-address constant — built on switch day, step 8 | Blocks A, C; this file §2 |
| **migrations — ONE** | `2026_11_12_000000_payment_transaction_id_text` (`payment_statuses.pay_transaction_id` BIGINT → VARCHAR(64), its unique index kept) | §6 |
| **`.htaccess` — ONE line** | the Google feed's exception after the Meta feed's (runbook §4.1.1); the script writes it, with a backup | §4 |

## 1. Commits

```powershell
& 'D:\coding\watchizer website\checkpoints\2026-11-11_bf-legacy\COMMITS-20261112.ps1'
```

The ONE commits script for everything uncommitted (generated at the end of the night; it stops at the first refusal;
the hook runs normally). Left out on purpose: `core/docs/gsc/`, `core/docs/seo/`, `screenshots/`.

## 2. What ships (beyond the import record)

- **Two checks of Part 3 that stopped falsely, corrected:** the live `.htaccess` has **2** API-host lines (the rule
  and the default arm's negation, as the runbook block shows), and the campaigns answer's `wheel` object is
  `{"enabled": …, "campaign": "round-1"}`, so the script now checks the KEY `data.wheel.enabled` (a boolean) and prints
  its value. It does not demand OFF, since Michael may have switched the wheel on.
- **Brand Fashion's Meta feed** (`config/feeds.php` `meta.brandfashion`, found missing tonight): links on
  `https://www.brandfashionegy.com`, images on `api.brandfashionegy.com`, ids the old site's (Block C).
  `FEED_META_BRANDFASHION_TOKEN` stays empty until switch day (feed off, URL 404). Test `BrandFashionMetaFeedTest` (2).
- **The Conversions API: one token and one pixel PER SHOP** (D-25 b, Michael 2026-11-12).
  - Watchizer keeps `META_CAPI_TOKEN` / `META_CAPI_PIXEL_ID`, unchanged: production's `.env` needs nothing for
    Watchizer.
  - Brand Fashion has `META_CAPI_BRANDFASHION_TOKEN` / `META_CAPI_BRANDFASHION_PIXEL_ID`
    (`services.meta_capi.storefronts.brandfashion`), on the core server only.
  - `MetaConversions::pair()` / `pairForOrder()`: an order is queued only when ITS shop has a complete pair, and is
    sent with that pair. The pair is read again at send time, so a pair removed after queueing FAILS the row; it is
    never sent with another shop's.
  - `meta:capi-check --storefront=brandfashion` checks Brand Fashion's pair (the token's length and fingerprint only,
    never the token).
  - Test (`MetaConversionsTest`):
    - Brand Fashion without a pair: nothing queued, nothing to Watchizer's pixel;
    - with its pair: sent to pixel `1594687941022756` with its token, nothing to Watchizer's;
    - Watchizer's order: Watchizer's pair only;
    - a pair removed after queueing: the row fails.
    10 pass; the 11th is the control's `DecryptException`.
  - The dashboard field (option a) is in `POST_CUTOVER_BACKLOG.md` (2026-11-12).
- **The old compat callback (`/api/callback_payment`) compares the payment id as TEXT.** It cast it to an integer, and
  after the migration that would compare numerically: no index, and inexact past 2^53. It keeps the integer
  normalisation and binds the result as a string (`(string) (int)`): the same ids, the same answers, the unique index
  used.
- **MyFatoorah's webhook check is a pure verifier now:** it decodes the received signature and compares the raw
  HMAC. The suite's `TokenIssuanceTest` flags any file that both HMACs and base64-encodes as a second token minter;
  the earlier form tripped it. Same accept/refuse behaviour (`MyFatoorahTest` 7/7).
- **`.env.example`** names `MYFATOORAH_BASE_URL` (the host only; keys are per storefront on the dashboard) and
  `FEED_META_BRANDFASHION_TOKEN`.
- **Brand Fashion's site address is ONE literal** (`Frontend-brandfashion/src/lib/siteAddress.js`), read by
  `requestLang` `SITE`, both `SEO_DOMAIN`s, `app/layout.jsx` and `app/(main)/page.jsx` (switch plan §3 item 1; it was
  typed 13 times). The value is unchanged tonight (`https://michaelkhalaf.com`). Switch day changes it and
  `robots.txt`'s Sitemap line with `bf-switch-code.py` (2 lines, refuses unless both are as expected, `--undo`).
  `next.config.js` also lists `api.brandfashionegy.com` for images (§3 item 3, harmless before the switch).
  **Proved unchanged:** every site URL on 7 pages (home EN/AR, a product EN/AR, the listing, blogs, offers),
  counted, is identical between the build before and the build after; hydration / console 0 / 0 on 10 loads; ESLint
  `no-undef` clean; vitest 178 / 178.

## 3. Gates (2026-11-12, night)

| | |
|---|---|
| `composer phpstan` (level 10) | `[OK] No errors` |
| Pint | passed (every touched file) |
| core: the full suite on `scratch_bf_verify_20261111` | **299 failed, 39 skipped, 2,104 passed — the control's 299, name for name, but two:** `TokenIssuanceTest > exactly one construction site` (NEW, real, MINE: MyFatoorah's verifier, fixed above, 16/16 alone) and `DashboardHomeTest > the same weekday` (GONE: date-dependent). The 299 are the environment's (the production copy's encrypted credentials, this machine's key: `DecryptException`) |
| core after the suite (the files changed during it) | feeds 27/27; MyFatoorah + TokenIssuance 23/23; EnvParity, ApiAllowList, StorefrontTwins, translation coverage 28/28; media/import/shard tests 73/73 |
| Brand Fashion build | exit 0 (93 s); vitest 178 / 178; hydration / console 0 / 0 (10 loads) |
| dashboard typecheck + bundle | ✓ (Block A/B gates, unchanged since) |
| `deploy-part4-20261112.sh` | `bash -n` ✓; its `.htaccess` steps (4, 5, 5b) RUN against a stand-in built from runbook §4.1.1: the result IS the runbook block (indentation and backslashes kept), the backup is the old file; every expected answer measured on the scratch core (the feed's own 404 body `Not found`, the reader's `{"data":null}` 404, `data.wheel.enabled`, `data.0.content_id`, the 10 commands, the schedule, the column type and the two-column index) |

**Re-tested on a PRODUCTION-LIKE bed (Michael's review, 2026-11-12, `D:\BF-handover\work\p4test\`):**
- **The bed:** git HEAD's core (Part 3) as the live tree, with `route:cache` + `config:cache` built from the OLD code
  in place (production's state); production's `.htaccess` (runbook §4.1.1, 2 API-host lines); a fresh copy of the
  production dump (Part 3 migrated, the column BIGINT). The script's own curls go to a local core, whose router
  applies the LIVE `.htaccess`'s host rules, read on every request. Only the script's two size bounds were widened
  (the bed's archive carries dev vendor).
- **The PRE-fix script** STOPPED at 5b right after the `.htaccess` edit ("the Google feed path does not reach core"):
  the old cached routes have no `/feeds/google`. Michael's finding, reproduced.
- **The fixed script:**
  - 5b: one line added; both hosts 401 / 404.
  - 7: ONE pending migration, `["bigint",0]`.
  - 8: migrated in 30 ms, `["varchar(64)",1,2]`; the caches rebuilt; the 10 commands.
  - **8b:** `/feeds/google` in the cached routes; both hosts reach core; a longer path refused.
  - 9: `inventory:verify` agrees. Its first run stopped at Watchizer's `payment-methods` → 500: `DecryptException`.
    The production copy's Paymob credentials are encrypted with production's key, which the bed does not have (the
    control list's failure; on production the key is there). With that one encrypted field emptied ON THE BED'S
    database, the script's own step-9 text ran to `DONE`: both shops' v2 answers 200, `data.wheel.enabled` read
    (`true` on this copy), a card's `content_id`, the reader's `{"data":null}` 404, Brand Fashion `cod` only,
    `core:checksum`.

**Found while testing the script** (each would have stopped the real run, safely; fixed before morning):
`awk -v` turned the Meta line's `\.` into `.`, so the Google line would never have been added (the strings now go
through `ENVIRON`); and the "no MyFatoorah at checkout" check grepped the methods answer, which names a MyFatoorah
method only as `card` (it now counts the contracts).

## 4. Deploy

On the workstation (PowerShell):

```powershell
Set-Location 'D:\coding\watchizer website\new watchizer\core'
npm run typecheck
npm run build                                                          # the dashboard bundle (Banners, Home sliders, Payments)
composer install --no-dev --optimize-autoloader
tar -czf C:\temp\core-20261112.tar.gz -X C:\temp\exclude.txt -C 'D:\coding\watchizer website\new watchizer' core
Get-Item C:\temp\core-20261112.tar.gz | Select-Object Length          # 7 to 9 MB; over 15 MB = dev tooling in vendor, STOP
(Get-FileHash 'D:\coding\watchizer website\new watchizer\core\public\build\manifest.json' -Algorithm SHA256).Hash.ToLower()
composer install                                                       # the dev tools back
```

`C:\temp\exclude.txt` is the list of Part 2 (it keeps out `.env`, `node_modules`, `tests`, `docs`, `storage`,
`Uploads_Images`, `dumps`, `hot`). Upload `C:\temp\core-20261112.tar.gz` → `~/core-20261112.tar.gz` and
`D:\coding\watchizer website\deploy-scripts\deploy-part4-20261112.sh` → `~/`, then:

```bash
EXPECTED_MANIFEST_SHA=<the hash printed above> bash ~/deploy-part4-20261112.sh
```

What it does, each a STOP gate:
1. the archive (size, readable, no backslash names, today's files and markers, no `.env` / `storage` / uploads);
2. the before-snapshot (> 50 MB);
3. the live `.htaccess`: first line, **2** API-host lines, 3 `R=404,L`, the `/api/v2/` and `/api/pay/` exceptions,
   ONE Meta feed line and NO Google feed line; the backup, proved equal;
4. the copy over, and the `.htaccess` restored at once; both API hosts 401 / 404, `eleganceeg.com/api` 404;
5. **5b**, the one line added after the Meta feed's (`diff`: exactly one line added, none removed); then both
   hosts' 401 / 404 again. **Not** the Google feed path here (Michael's review, 2026-11-12): production runs on
   CACHED routes and config (Part 3), so until step 8 rebuilds them the new route does not exist yet;
6. the manifest on disk and as served;
7. exactly ONE pending migration, by name, with the column still `bigint` and no MyFatoorah contract;
8. migrate; `["varchar(64)",1,2]` (the text column, one ledger row, the two-column unique index); caches; the 10
   commands; `feeds:google` on the schedule; MyFatoorah's host printed (empty);
   **8b** (after the caches): `/feeds/google` is in the cached routes; on both API hosts the feed path reaches core
   (its own 9-byte 404 for a wrong token); a longer path does not;
9. `inventory:verify`; v2 on both hosts; the host binding; `data.wheel.enabled` present; a card's `content_id`; the
   redirect reader's `{"data":null}` 404; no MyFatoorah contract; the dashboard 302; `core:checksum --set=frozen`.

Then **merge `wave-5` into `main` and push** (Michael). Hostinger rebuilds Watchizer. Brand Fashion's app is built
on switch day (step 8) after `bf-switch-code.py`, never before: its canonicals must not name the new domain before
core serves it. **CHECK** whether the Brand Fashion Node app is set to rebuild on a push to `main`. If it is, the
push of step 1 rebuilds it with the temporary domain still in `siteAddress.js` (harmless: identical to today, proved
above). The switch-day commit of step 8 then rebuilds it with the real one.

**Why core first:** an old storefront ignores `content_id`, `more_href` and the reader; a new storefront on the old
core sends the product id and shows "See all" as before. Either order is harmless; core first keeps one direction.

## 5. Post-deploy (yours)

1. Watchizer after the push: the home's hand-picked sliders show "See more" only where a link is set (Home sliders
   screen, the new field); banners keep their look (the boxes are the crop's shape now).
2. **Re-upload the three Brand Fashion banners** saved as "both" (Block A table): 1364 and 1365 (hero) and 1366
   (side top). Each becomes a "Computer only" banner plus a new "Phone only" one at the size the upload field shows.
3. Nothing else until the switch-day runbook.

## 5b. The one migration — what it touches (measured 2026-11-12 on the production copy taken 2026-11-10)

- **Table and column:** `payment_statuses.pay_transaction_id`, `bigint(20) NULL` → `VARCHAR(64) NULL`. The unique
  index `ps_provider_transaction_unique (provider, pay_transaction_id)` is kept (rebuilt with the table, still unique).
- **Rows:** 46 on the copy, all `provider = paymob`, every id 9 digits (552486495 … 571479199), the newest
  2026-11-10 18:47; the table is 96 KB (InnoDB). Production today has those plus any card payment since.
- **The lock:** MariaDB refuses INSTANT and INPLACE for a column type change ("Cannot change column type. Try
  ALGORITHM=COPY"), so the table is rebuilt by copy. MariaDB 11.2+ (production: 11.8.8) runs a COPY with
  `LOCK=NONE`; accepted on the copy. Reads AND writes continue during the copy; a brief metadata lock is taken at
  the start and at the swap, and it waits for any open transaction on the table. **Measured: 151 ms** for the
  migration on the 46 rows.
- **Every Paymob id survives unchanged:** the 46 `id:provider:pay_transaction_id` triples, as text, are
  byte-identical before and after (md5 `97e1a081…`), and again after the rollback below.
- **Every reader still works** (each one read in the code):
  - the scoped callback (`PaymentCallbackController`: idempotency, the twin delivery) binds a STRING id — before,
    string vs BIGINT; now string vs VARCHAR, exact and indexed;
  - the old compat callback compared an INTEGER: changed tonight to bind the same normalised value as a string
    (§2);
  - `orders:reinstate-paid --transaction=` binds the option string;
  - the Orders screen and the findings / reconciliation (`CallbackPolicy`) READ the value as a string (`Row::nstr`)
    — a BIGINT already arrived as a string through PDO, so nothing changes for them.
  - The 23 test files that touch the column, the callbacks, reinstate-paid, the findings and the Conversions API: 78
    failed, **every one in the control list** (`DecryptException`), 0 new, 153 passed.

## 6. Rollback

- **Files:** the before-snapshot `~/core-before-20261112-a.tar.gz` (extract to a staging folder, `cp -a` over;
  never a delete), and `cp ~/htaccess.before-20261112-a.bak <core>/public/.htaccess`.
- **The migration** needs no rollback when the code goes back (the old code reads the column as a string either
  way). Only to undo it fully, after the code is gone, and only while no MyFatoorah payment is stored:

```sql
-- must be 0: an id that does not fit a BIGINT (a MyFatoorah payment) cannot go back
SELECT COUNT(*) FROM payment_statuses WHERE pay_transaction_id IS NOT NULL AND pay_transaction_id NOT REGEXP '^-?[0-9]{1,18}$';
ALTER TABLE payment_statuses MODIFY pay_transaction_id BIGINT NULL;
DELETE FROM core_migrations WHERE migration = '2026_11_12_000000_payment_transaction_id_text';
```

Rehearsed on the production copy (2026-11-12): the check gave 0, the column was `bigint(20)` again, 1 ledger row was
deleted, and all 46 ids were identical to before the migration.

## 7. Decided overnight — Michael to confirm (also in the morning report)

- The ONE migration (MyFatoorah's 20-digit payment id does not fit BIGINT); the webhook key required for a live
  contract.
- The Brand Fashion Meta feed entry added now, off until its token (the alternative: add it on switch day, a code
  change in the switch sitting).
- The site address as ONE literal now, value unchanged (the alternative: 13 edits on switch day).

## 8. The 10 % coupons WR10 (Watchizer) and BR10 (Brand Fashion) — Michael, 2026-11-12

**Could the engine already say "every product except these brands' watches"? NO.** Scopes only INCLUDED: `target` (what
the discount comes off) and `qualify` (what must be in the cart), AND across dimensions, OR within one. "Everything
except brand ∈ {6} AND watch" is a negation they cannot express: listing every other brand would wrongly drop
unbranded products and every brand added later.

**Built (the smallest change, in today's Part 4, NO migration):**
- a third scope role, **`exclude`**: the discount never comes off a line it matches, even inside the target. Same
  reading as the others (AND across dimensions, OR within one). `promotion_rule_scopes.role` is `varchar(8)`, so
  `exclude` fits and nothing in the schema changes.
- the engine (`PromotionEngine::moneyOf`) skips those lines;
- the dashboard's coupon form has a third picker, **«ما عدا (لا يُخصم منها أبدًا)»** (English: "Except (the discount
  never comes off these)"); the writer and `offers:copy` take the role like the others.
- Tests in `CouponTest`:
  - WR10's shape: a mixed cart (an excluded watch 5,000, another brand's watch 3,000, a bag 2,000) → 500 off, only
    the two others;
  - only the excluded watch → 0, with "no eligible items";
  - the bag alone → 200;
  - an excluded brand's NON-watch keeps the discount (brand AND type, together);
  - the screen saves exclusion rows.
  The first fails on the old engine. Promotions + campaigns + translation tests: no failure outside the control
  list. PHPStan, Pint, typecheck, the bundle build: clean.

**Already true, confirmed:**
- **One offer per order** applies to these codes. A cart holding ANY item at a live Hot Deals / Flash Sale price
  refuses EVERY code for the whole order (`on_offer`: "Discount codes can't be combined with items already on offer"). It is checked before any
  code is evaluated (`PromotionEngine`, the ONE OFFER PER ORDER block), at the cart, the checkout and the order.
- **Codes are unique across both shops** (the database's `promo_code_unique`, case-insensitive). On the production
  copy of 10 Nov neither WR10 nor BR10 exists in any letter case; the screen refuses a duplicate anyway.
- "Include sale items" ON = a product's own strikethrough price is not an offer (Black1000 has it ON too).

### The dashboard settings — field by field (after today's deploy; the form of «العروض الترويجية» → «عرض ترويجي جديد»)

| Field (screen label) | **WR10** — Watchizer | **BR10** — Brand Fashion |
|---|---|---|
| Name | `WR10 — 10% off (except the Black1000 watches)` | `BR10 — 10% off (except the FRIDAY1000 watches)` |
| Storefronts | **Watchizer only** | **Brand Fashion only** |
| مفعّلة (active) | ON | ON (see "when" below) |
| Starts / Ends | `2026-11-12 15:00` → `2026-12-31 23:59:59` (the dashboard's time is Cairo, `APP_TIMEZONE=Africa/Cairo`) | the same |
| Conditions | none | none |
| المكافآت (reward) | percent discount, **10** | the same |
| كود الخصم (code) | `WR10` | `BR10` |
| يُطبَّق الخصم (apply per) | «مرة واحدة على الطلب» (once on the order: 10 % of the eligible goods) | the same |
| ما يراه العميل (عربي / إنجليزي) | `خصم ١٠٪` / `10% off` | the same |
| أقصى خصم (max discount) | empty (no cap) | empty |
| أقل قيمة للطلب (minimum order) | empty (no minimum) | empty |
| عدد مرات الاستخدام الكلي / لكل عميل (usage limits) | empty / empty (no limit) | empty / empty |
| يشمل المنتجات المخفّضة بالفعل (include sale items) | **ON** | **ON** |
| يتجمّع مع العروض الأخرى (stackable) | OFF (as Black1000) | OFF |
| يُخصم من (target) | nothing (= every product) | nothing |
| **ما عدا (except)** | الماركة: **رولكس، باتيك فيليب، أوديمار بيغيه، أوميغا، تيسو، هوبلو** (Rolex, Patek Philippe, Audemars Piguet, Omega, Tissot, Hublot — brand ids 1, 2, 3, 5, 64, 9, exactly Black1000's) **and** نوع المنتج: **ساعة** (watch) | the same six brands and **ساعة** (FRIDAY1000's scope is Black1000's, copied) |
| بشرط وجود (qualify) | nothing | nothing |

**BR10 — recommended: let switch-day step 5 create it.** Once WR10 exists on Watchizer, step 5's `offers:copy`
(`--code=WR10=BR10`, now in the script) copies it to Brand Fashion as its OWN rule BR10: the same 10 %, dates and
exclusion. Proved on the production copy: WR10 created through the dashboard's writer, then `offers:copy` → BR10 on
storefront 2 with the 7 exclusion rows and `percent_discount 10.00`. Without the pair, a live WR10 makes the whole
copy refuse, so the script always passes it (ignored while WR10 does not exist).
- So the team creates only WR10, by hand, after today's deploy (before step 5).
- If step 5 runs first, create BR10 by hand with the table above.
- Before the switch BR10 is harmless either way: only the temporary domain could use it, and nobody does.

### The quick check (each in the form's «جرّب» panel — a sample cart against the saved rule, nothing is written — or in a real cart on the shop)

| Cart (on Watchizer, code WR10; the same on Brand Fashion with BR10) | Expected |
|---|---|
| one Rolex (or Omega, Tissot …) WATCH only | no discount — "None of the items in your cart are included in this offer." |
| any other product (a watch of another brand, a bag) | 10 % of it |
| the excluded watch + another product | 10 % of the other product only |
| any item of a live Hot Deals / Flash Sale + another product | the code is refused for the whole order ("Discount codes can't be combined with items already on offer (Hot Deals / Flash Sale).") |
