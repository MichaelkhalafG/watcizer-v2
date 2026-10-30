# Activity log, made useful — runbook (2026-10-30)

Michael's item 3: answer "who created this product, who changed its price, who changed its stock, and when".

## 1. What existed (measured first)

- `core_activity_log` (who, what, which record, when, `changes` as `{field: {from, to}}`) and the screen
  `/manage/activity` with filters for user, action, record type, storefront and a date range, a CSV export, field
  names already shown as words (`changedFieldLabel`), and a PREFIX search on the record name and the user (a decision
  of 2026-10-13, so the search uses indexes) plus the record number.
- Writers (`git grep ActivityLog::record`): 29 files — the product writer, variants, categories, placement, home
  rails, blogs, banners, units, shipping, re-engagement, promotions, profile, lookups, order fulfilment, guest order
  links, roles, accounts, storefront and payment settings, inventory, media prune, three console commands.
- Gaps found:
  - no search by SKU or by any part of a product's name;
  - no way to ask for "price changes", "stock changes" or "bulk changes", and no sign-in rows at all;
  - in English the arrow read backwards (`old ← new`);
  - no history on the product itself;
  - **stock moved by an order, a cancellation, an import or the system is not in the log** — by design since wave 4D
    (it is in the stock ledger `inventory_movements`, the authority, with its actor), so the log screen alone could
    not answer "who changed its stock" for those;
  - the bulk price change (item 2) wrote per-product rows; the other bulk actions wrote rows that could not be told
    apart from single edits.
- Price paths checked: product form (`ProductWriter::update`, logs), bulk price (`BulkPrice`, logs one row per
  product), importer (creates through `ProductWriter::create`, logs `created`), placement overrides
  (`PlacementController`, logs). No price path writes without a row. Stock paths: a person's adjustment
  (`InventoryService`, logs `adjusted`), orders/cancellations/imports/system (ledger only — now shown on the product's
  History tab, below).

## 2. What was added

- **Search**: the record name and the user stay PREFIX (indexed, the 2026-10-13 decision); a term is ALSO matched on
  the catalogue — the product's SKU (exact or any part), its internal code, its title in either language — resolved to
  product and variant ids, and the log is probed by its indexed subject.
- **Kind filter**: price (selling/sale price or a storefront override changed), stock (a person's adjustment, or a
  stock field), bulk (written inside a bulk action, or a batch row), sign-in.
- **Sign-ins** are recorded (`action = login`, subject = the user) on every successful dashboard sign-in.
- **`context`** column (migration `2026_10_30_000002_activity_context`, indexed with `created_at`): set by
  `ActivityLog::within('bulk' | 'bulk_price', …)`; the product bulk actions, the placement bulk actions, the stock bulk
  actions and the bulk price change are wrapped in it; such rows wear a "bulk change" badge.
- **Each row** of a product (or variant) carries its SKU and links to the product; the arrow follows the language
  (← in Arabic, → in English); "Times are Cairo time" on the screen (the stored times are Cairo wall-clock times,
  APP_TIMEZONE=Africa/Cairo).
- **The product form's History tab** (`ProductHistory`): the product's and its variants' log rows (created, edits
  with old → new, price changes single or bulk, archive) AND every stock movement from the ledger — by a person or by
  the system, with the order number when an order moved it — newest first, 80 entries.

## 3. Measurements (scratch copy, the log grown to 290,240 rows, local single-threaded server, full page render)

| view | rows matching | time (3 runs, ms) |
|---|---|---|
| newest page, no filter | 290,240 | 577, 467, 454 |
| search "ROL07" (SKU part) | 224 | 452, 426, 408 |
| search «دايتونا» (product name) | 224 | 418, 418, 428 |
| kind = price | 288 | 691, 641, 638 |
| kind = stock | 13,152 | 563, 545, 530 |
| kind = bulk | 384 | 405, 369, 755 |
| one user + a month | 3 | 386, 399, 378 |
| a product's edit page with its History | — | 391, 387, 372 |

Indexes: the existing ones (created_at, user+created_at, subject_type+subject_id, action+created_at,
storefront+created_at, subject_label, user_name, subject_id) plus `activity_context_idx (context, created_at)`.
"kind = price" scans `changes` with `LIKE`, the slowest view at ~0.65 s with 290k rows; acceptable now, and the place
to add a stored "kind" column if the log grows by another order of magnitude (recommendation, not done).

## 4. Tests

`tests/Feature/Manage/ActivityFindTest.php` (5): SKU part and title word find the product's rows, with SKU and link;
the four kinds each select what they must and not what they must not; a sign-in is recorded with the person as actor;
a bulk action tags every row it writes; the History tab holds the price change and a SYSTEM stock movement.
`BulkPriceTest` (7) and the translation, seam and route tests stay green; `ActivityLogTest`'s two failures and
`WalkthroughFixesTest`'s one are baselined (the scratch copy's first account reads the dashboard in English).

## 5. Deploy and rollback

With core (`php artisan migrate --force`). Rollback: `ALTER TABLE core_activity_log DROP INDEX activity_context_idx,
DROP COLUMN context; DELETE FROM migrations WHERE migration = '2026_10_30_000002_activity_context';` (after the code;
the old code never reads the column).
