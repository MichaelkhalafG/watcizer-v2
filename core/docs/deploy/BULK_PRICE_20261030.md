# Bulk price edit on the products list — runbook (2026-10-30)

Michael's item 2: filter the products list, select some or all, change prices with a preview, a confirm, a cap, a
floor at zero, rounding, an activity-log row per product, and an undo of the last batch.

## 1. What existed (measured first)

- `ProductController::bulk()`: activate / deactivate / archive / restore / set_threshold, by the ids on screen or
  "all matching" (the screen's query string re-resolved server-side through `listTable()` — nothing outside the
  screen's filters can be reached); 500 ids per request.
- The list's filters: family, brand, active, in stock, visible, featured, storefront scope, category branch, flags.
  No grade, no price range.
- `ProductPatcher`: the partial-update path (only named fields; the sale-price contract `0 < sale < selling` applied
  to the resulting row; refreshes each storefront's `effective_price`, the search row and the caches). It writes no
  activity-log row itself.
- No bulk price change and no record from which a price change could be undone.

## 2. What was added

- **Filters** on the list (and so on "all matching"): `p.grade_id`, and `price_min` / `price_max` on the CHARGED
  price (a valid sale, else the price). The price bounds commit on Enter or blur, not per keystroke.
- **`App\Domain\Catalog\BulkPrice`** — plan / apply / undo:
  - modes: raise or lower by EGP or %, or set a fixed price; target: regular price, sale price (starts from the price
    when there is no sale), or clear the sale; rounding to 5 / 10 / 50 / 100 EGP.
  - a row that would reach 0 or less is REFUSED and left as it is; a sale target that would reach the regular price is
    refused; a regular price that falls to or under the stored sale clears that sale (and the preview says so).
  - **cap: 300 products per action** (D-B1) — above it, the preview returns only the count and the sentence and apply
    is refused before anything is written.
  - every product goes through `ProductPatcher` and gets its own activity-log row (user, product name, batch number,
    `selling_price`/`sale_price` from → to); the batch and each product's before/after are recorded.
  - undo restores a product only where its prices are still exactly the batch's; a product changed since is skipped
    and counted; a batch is undone once.
- **Endpoints** (inside the catalogue group, so `can:manage-catalog` + the storefront scope apply):
  `POST /manage/storefronts/{s}/products/bulk-price/preview` (JSON, writes nothing),
  `POST …/bulk-price` (needs `confirm`), `POST …/bulk-price/{batch}/undo`.
- **Screen**: "Change prices…" in the selection bar opens a dialog (change, value, rounding, which price → Preview →
  table of name / SKU / current / new with the reason on any row that will not change → tick "I have checked the
  table" → Apply N). "Undo last price change" in the header while a batch is not undone.
- **Migration `2026_10_30_000001_price_batches`**: `core_price_batches`, `core_price_batch_items` (both in
  `CoreChecksumCommand::DASHBOARD_TABLES`).

## 3. Decisions — RECOMMENDATIONS

| # | Decision | Why | Alternative |
|---|---|---|---|
| D-B1 | Cap 300 products per action | a price change is the riskiest bulk write; 300 still covers one brand at a time (Rolex: 124 watches) | 500, the ids cap the other bulk actions use |
| D-B2 | Prices stay catalogue-wide (both storefronts) | prices live on `catalog_products` and the per-storefront override gate is off (D3); the dialog says so | a per-storefront price — a bigger change to the price model |
| D-B3 | Undo from the header offers the LAST batch not undone | the brief's "undo last bulk change"; older batches stay recorded and can be undone by id (`…/bulk-price/{batch}/undo`) | a batch history screen |
| D-B4 | The preview lists every row (≤ 300) in the dialog | the operator sees each price before it changes | a sample of rows |

## 4. Tests and measurements

- `tests/Feature/Manage/BulkPriceTest.php` (7): every mode/target/rounding; zero, sale-at-price and sale-cleared;
  preview writes nothing; over the cap refused with the count and nothing written; apply needs a confirm, writes each
  product, the storefront's `effective_price` follows, one log row per product with user and batch, the batch and
  items recorded; undo restores and skips a product changed since, and refuses a second undo; the price-range and
  grade filters select exactly the rows the SQL predicate selects.
- Browser, on `scratch_promo_20261030`: Rolex + 4,000–8,000 showed 86 products; one selected; +5%, nearest 50 →
  preview 7,440 → 7,800 (sale 6,200 kept); applied (flash "batch #1"); the row read 7,800; undo restored 7,440;
  `core_activity_log` holds both rows, «… — تغيير أسعار جماعي #1» 7,440 → 7,800 and «… — تراجع عن تغيير الأسعار #1»
  7,800 → 7,440, user "Night Check".

## 5. Deploy and rollback

Deploy with core (`php artisan migrate --force`); the dashboard assets are part of core's build. Rollback (after the
code): `DROP TABLE core_price_batch_items; DROP TABLE core_price_batches; DELETE FROM migrations WHERE migration =
'2026_10_30_000001_price_batches';` — the prices themselves are untouched by a rollback (undo any batch first if it
must be reversed).
