# DEPLOY — Part 1 of the twelve updates (2026-11-04): variants

**Nothing here is committed or deployed. Every step is Michael's.** Expected values were read from the tree or
measured on the dev database (`u591083448_watchizer` on MariaDB 11.8.9 @ 127.0.0.1:3307, which carries the DEMO
variants: 205 `DEMO-` rows on 33 products, 1,014 visible products per storefront). The standing procedure is
`RUNBOOK_PHASE2_STOREFRONT.md` §2 (build, archive, staging, before-archive, permissions, caches); this document says
what is different this time. The second deploy (parts 2–7) is a separate record.

## 0. What you are deploying

**Three commits, ONE migration, both storefronts, the dashboard bundle.**

| # | commit | lane | what the client sees |
|---|---|---|---|
| 1 | variants: colour chosen by image, price in full, the own colour as a row, band colour hidden | core + both storefronts | the product page: a photo per colour swatch, the gallery follows the colour, the picker opens on the product's own colour; the dashboard: a price box per row that reads the real price, a photo picker per row, a one-click row for the product's own colour |
| 2 | dashboard fixes: Promotions in the sidebar, the 404 page on a wrong URL | core (dashboard) | the Promotions entry opens; `eleganceeg.com/manage/storefronts/1/promotions` is a 404 page, not a white screen |
| 3 | records | — | this file |

The one migration:

```
2026_11_04_000000_variant_images
```

Run up on the dev database (315 ms); its `down` is guarded and was NOT exercised through `migrate:rollback` (refused by
this environment's guard, as every core migration's is). Hand-written rollback SQL in §5.

**No new storefront API route.** Everything rides on `/api/v2/{storefront}/products/{slug}` (new keys inside the
answer) and the dashboard's own `/manage/products/{product}/variants` routes. `.htaccess` and runbook §4.1.1 are
unchanged; `ApiAllowListTest` passed on the tree (see §3). The core tar still overwrites `.htaccess` — restore it in
the same breath as always.

## 1. Commit

From the repository root, PowerShell, branch `wave-5`:

```powershell
& 'D:\coding\watchizer website\deploy-scripts\COMMITS-PART1-20261104.ps1'
```

One explicit `git add` per commit, the records last, the hook runs normally, the script stops at the first refusal.
Written from `git status` at 15:30; if the tree changed after that, compare with `git status` before running it.
Left out on purpose: `core/docs/gsc/`, `core/docs/seo/` (your decision of 2026-10-30), `screenshots/` (this
brief's reference images). Then merge `wave-5` → `main` and push as usual.

## 2. What changed, and why it is safe in either order

### 2.1 Core

- **`catalog_product_images.variant_id`** (nullable, indexed, FK → `catalog_product_variants` ON DELETE SET NULL,
  guarded migration). An image belongs to ONE row or to none; a deleted row hands its pictures back.
- **`VariantWriter`**: `price` (full) → stored as `price − selling_price` in `price_delta` (no schema change;
  `price_delta` still accepted, the importer uses it); `image_ids` per row (assigning takes an image from whichever
  row held it); **the conversion now moves the product's units down** — product level set to 0 through
  `InventoryService` with the original count in the note, the units landing on the base-colour row or on the first
  row when it IS the base colour and its bucket was left blank. Before this, the first row left the product's units
  in place and the aggregate drifted by exactly that amount (`inventory:verify` invariant 3). **At conversion, when
  the first row is a different colour from the product's own colour, the own colour becomes row 0** (its name, the
  product's price, the product's units). `summary()` tells the panel the base price and whether the own colour has a
  row; the panel offers the one-click row when it does not. Every variant edit now flushes the product's cached
  detail (it did not: a label or price edit went stale until the TTL).
- **`ProductDetail` (v2)**: `images[].variant_id`, `variants[].image_ids`, `variants[].is_base`,
  `product.base_variant_id`. The cards (`ProductCards::images()`), the listing index and the header menu select
  none of it — the memory constraint of 1.1.
- **`Navigation`**: Promotions carries its route again (`ShellTest` moved it to the built list).
- **`ManageError`**: shares the shell's props when no route middleware ran; the page reads `auth` defensively.
- **`VariantFixture::order()`**: `storefront_id` (the column has been NOT NULL since 2026-10-23; five tests failed
  on it before this change, see §3).

### 2.2 Storefronts

- **`src/lib/variantGallery.js`** (new twin, 9 unit tests): the gallery per colour, the swatch photo, the colour the
  picker opens on, the colour count.
- **Brand Fashion `BfProductPage`**: photo swatches (`.bf-opt__swatch--photo`, 56 px), the gallery follows the colour
  and goes back to slide 1, the picker opens on `base_variant_id` (else the first colour in stock; `?color=` wins),
  the band/main colour line hidden when the rows carry more than one colour.
- **Watchizer `ProductDetailClient`** (twin, both sides identical): the same four behaviours on the chip picker
  (`.wz-pd-chip--photo`, 40 px photo); `v2Product.js` (twin) hides the Band Colour / Colour rows of the spec table
  on multi-colour products. Dial colour stays.

### 2.3 In either order

| | NEW storefront, OLD core | OLD storefront, NEW core |
|---|---|---|
| gallery | no `variant_id` on any image → every image is "general" → the whole gallery, as today | ignores the new keys |
| swatch | no `image_ids` → hex circle, as today | hex circle |
| picker | no `base_variant_id` → opens on the first colour in stock (a mild new behaviour, harmless) | "Not chosen", as today |
| band colour | hidden only when the rows carry >1 colour — true of the data, not of the core version | shown, as today |

Recommended: **core first**, then the storefronts, so the first shoppers on the new pages get the photos.

## 3. The gate on the tree (2026-11-04)

| check | result |
|---|---|
| Pint (`--dirty`) | passed |
| PHPStan level 10 (`composer phpstan`, 1G) | no errors |
| `tsc --noEmit` (dashboard) | clean |
| `vite build` (dashboard) | built, 35.6 s |
| vitest Watchizer | 14 files, 112 tests passed (9 new) |
| vitest Brand Fashion | 11 files, 74 tests passed (9 new) |
| eslint on the changed storefront files | 0 errors (warnings only: `<img>` as the galleries already use, and pre-existing unused-disable directives) |
| `StorefrontTwinsTest`, `ApiAllowListTest` | passed |
| new tests: `VariantImagesPriceTest` (6), `ErrorPageUnmatchedUrlTest` (2), the v2 detail case | passed |
| full `php artisan test` | see the addendum at the end of this file — written when the run finished |

**Pre-existing failures on THIS machine, not from this change, and left alone:** `ShellTest › renders the dashboard
inside an Arabic RTL shell`, `VariantPanelTest › states the refusal ON the product form`, `VariantPanelTest › REFUSES
to delete …` (three), `StockAdjustVariantTest › tells the operator what to DO` assert Arabic sentences, and the test
admin (user 1) has `core_user_preferences.locale = en` on the dev database since 2026-10-13, so the dashboard answers
in English. They pass on a copy whose admin prefers Arabic (the scratch suites). A test that inherits the laptop's
preference is the shape AGENTS §4 forbids; fixing them is a separate, small change (set the preference in the test)
that I did not make. `VariantStockTest` (two) and `VariantPanelTest` (two) failed on `orders.storefront_id` before
this change and pass now (the fixture fix above).

## 4. Memory at 128 MB — Brand Fashion, before and after (1.1)

Measured on the dev copy (1,014 visible products on storefront 2 — NOT production's catalogue; production's
`memory_limit` is 3072M, measured 2026-10-07, so 128M is the brief's bar, not the host's). Each line is a fresh
`php -d memory_limit=128M artisan tinker` process; the tinker baseline (≈40–42 MB) is INCLUDED in every peak, and a
real request does not carry it. Scripts: `new branding/scripts/l8/l8_prove.php` (cold; `L8_SF=2`) and
`scratchpad/measure_part1.php` (warm through `Artisan::call('catalog:warm')` with `COMPAT_WARM_STOREFRONTS=1,2` in the
process env; the product page through the HTTP kernel after `StorefrontCache::flush(2)`). Product page: a DEMO
variant product, 9 rows, 5 images.

| measurement | before | after | note |
|---|---|---|---|
| cold shopper request (index built + nav + a page of cards + one product), peak | **55.8 MB** | **55.8 MB** | identical; 2,086 ms → 547 ms is the disk cache warming between runs, not the code |
| `catalog:warm` storefront 2, peak | 65.8 MB | 65.0 MB | within noise; 11.1 s → 9.5 s likewise |
| product page, cold (cache flushed), peak | 45.0 MB | 46.3 MB | +1.3 MB; body 71,474 → 71,863 bytes (+389 B: `variant_id`, `image_ids`, `is_base`, `base_variant_id`) |
| product page, warm, peak | 45.3 MB | 46.6 MB | same |

Reading: the per-variant image data lives on the product page only. The listing index and the nav are built from the
cards' queries, which select none of the new column, and the numbers say so. Nothing approaches 128 MB on this copy;
the slope recorded for L8 (≈11 MB per 1,000 visible products, cold) is unchanged by this work because the index build
is untouched. **To be reviewed together before the deploy, as the brief says.**

## 5. Rollback

- **Storefronts:** revert the storefront commit and push; Hostinger rebuilds Watchizer, Brand Fashion per its runbook.
  An old storefront against the new core is safe (§2.3).
- **Core:** restore the before-archive, `config:cache` + `route:cache`. The migration is forward-only in production; if
  the release is withdrawn, the hand-written SQL (the ledger is `core_migrations`, never `migrations`):

```sql
ALTER TABLE catalog_product_images DROP FOREIGN KEY cpi_variant_fk;
ALTER TABLE catalog_product_images DROP INDEX cpi_variant_idx;
ALTER TABLE catalog_product_images DROP COLUMN variant_id;
DELETE FROM core_migrations WHERE migration = '2026_11_04_000000_variant_images';
```

  Run AFTER the old code is back (the new code selects the column). Leaving the column in place with the old code is
  harmless too: nothing old reads it.
- **Never** undo a conversion by hand: the units that moved down are ledger movements with their note; a row created
  at conversion is a row like any other (deactivate, never delete, once it has history — AGENTS §2.22).

## 6. After the deploy — checks (hard reload first)

| # | where | expect |
|---|---|---|
| 1 | dashboard → any product with rows → المقاسات والألوان | the price column shows the FULL price (1,290, not 90); a photo strip per row; the hint names the product's price |
| 2 | dashboard → the brown wallet (the product from the brief) | the amber notice "لون المنتج الأصلي ليس صفًا" with the one-click button; after the click a brown row at the product's price with 0 units — **the team sets its quantity and ticks its photos** |
| 3 | dashboard → sidebar | Promotions opens the screen |
| 4 | `https://eleganceeg.com/manage/storefronts/1/promotions` | the dashboard's 404 page (icon, sentence, a way back), not a white page |
| 5 | `api.<host>/api/v2/<code>/products/<slug>` of a variant product | `images[].variant_id`, `variants[].image_ids`, `variants[].is_base`, `product.base_variant_id` |
| 6 | the product page of that product, both shops | the picker opens on the product's own colour; a swatch is a photo where one was ticked; choosing a colour swaps the gallery; no "Band colour: Brown" line above a picker offering red |
| 7 | `php artisan inventory:verify` on the server | green — the conversion rule changed, and this is the net that would say so |

## 7. What is NOT in this deploy

The brief's parts 2–7 (campaigns, the home zone, the mega menu, the category image fallback, the page texts, the
payment badges). The three open questions of the brief are answered in the session report, not here.

## Addendum — the full suite (2026-11-04, after the build)

Two runs, read whole after they finished, compared with `tests/baseline/suite-baseline.sh --log` against the
61-entry baseline (demo variants ON on both copies, so the two `[demo-on]` entries count):

| copy | `Tests:` line | in the baseline | NOT in the baseline | baseline entries now passing |
|---|---|---|---|---|
| dev `u591083448_watchizer` | `97 failed, 39 skipped, 2158 passed (320990 assertions)`, 684 s | 61 | **36** | 0 |
| `scratch_suite_20261029` (migrated to `variant_images` first; it lacked the three 2026-10-30 migrations too, which ran with it) | `97 failed, 39 skipped, 2158 passed (320990 assertions)` | 61 | **36** | 0 |

**The 36 are one cause, and it is not Part 1's.** Every one inserts an `orders` row without `storefront_id`
(`PaymobCallbackTest` ×8, `GuestOrderLinkTest` ×9, `CancellationRestoresStockTest` ×6, `ExactlyOnceTest` ×5,
`InventoryServiceTest`, `AccountCompatTest`, `CallbackChecksTest`, `AccountOrdersTest`) or asserts the column is
still nullable (`OrderStorefrontTest` ×4). `orders.storefront_id` is NOT NULL on both copies at the end of a run —
and on the scratch copy it was NULLABLE before the run: `OrderStorefrontTest` runs `orders:backfill-storefront
--force`, whose hardening of the column is DDL and survives the test's transaction, so every later test that
builds an order the old way fails. Production has carried the NOT NULL column since the 2026-10-23 deploy. The
fixtures need `storefront_id` (the one in `VariantFixture::order()` got it in this change, which is why
`VariantStockTest` and `VariantPanelTest`'s order cases pass again); the rest are a separate small change I did
not make, and the baseline file should then shrink rather than grow. Nothing in the 36 touches variants, images,
the product detail, the navigation or the error page.

**The 61 in the baseline** are the known environmental set (the test admin's English preference on this machine
accounts for 20 of them — `VariantPanelTest` ×4, `ShellTest`, `StockAdjustVariantTest`, `PreSwitchTest` ×2,
`PreventionTest` ×3 and the rest assert Arabic sentences; `core_user_preferences.locale = en` for user 1 on both
copies). The new tests of this change — `VariantImagesPriceTest` (6), `ErrorPageUnmatchedUrlTest` (2), the v2
detail case, `ShellTest`'s rewritten parked-list assertion — pass on both copies.

**Browser check (dev servers, Brand Fashion on the dev copy, demo product 25649 with two photos marked as the
silver rows' and one as the green rows' through `VariantWriter::assignImages`, reverted afterwards — 0 marked
rows left):** the picker opened on Silver (the product's own band colour), the green and silver swatches were the
rows' own photos and black stayed a hex square, choosing green swapped the gallery to its one picture, the 40 mm
size greyed where green has no stock, and the details table listed the dial colour only. The dashboard panel was
not opened in a browser (no dashboard login in this session); its endpoints and props are covered by the tests
and the bundle built. Both dev servers were stopped and the ports proved free.
