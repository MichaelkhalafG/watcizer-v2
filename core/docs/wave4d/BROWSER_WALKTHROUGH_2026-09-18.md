# The browser walkthrough — 2026-09-18

The dashboard driven as an operator drives it: in a real browser, logged in, as **administrator**
and as **data-entry**, in **Arabic** and in **English**, in **light** and **dark**, against the real
catalogue — 7,713 products, 8,339 placements, two storefronts, 9 orders, 5,795 stock movements.

This is the pass `DASHBOARD_REVIEW_2026-09-18.md` could not do. That review says so in its own
words: *"Visual layout is unverified on 31 of the 32 screens… Driving a browser needs a password
typed into a login form, which is a line I hold to."* Everything there was driven through the test
HTTP layer, which authenticates without a password and therefore proves behaviour, props, roles and
wording — but cannot see a column rendered off-screen, a badge with no contrast, or a confirmation
nobody ever scrolls to. This document is what the eyes found.

**Every number here was measured on this machine on 2026-09-18** — from the live DOM, from the
database, or from a file on disk. Where a finding names a file and a line, that line was read. Where
it quotes a screen, that screen was open. Nothing is inferred from a plan.

**Scope note.** Two accounts were created for this pass (one administrator, one data-entry), both
named after the developer, with passwords chosen for the session, on the developer's explicit
instruction and on a local rehearsal copy. Both accounts, and every other change, are gone — §0
proves it.

---

## 0. Restoration proof

### 0.1 The catalogue is byte-identical to how the walkthrough found it

| Check | Before | After | Verdict |
|---|---|---|---|
| All-table content digest (`core:checksum --set=all`, 123 tables) | `84cb4861882e32ac9254971f935cd2f448bc9b88` | `84cb4861882e32ac9254971f935cd2f448bc9b88` | **IDENTICAL** |
| Frozen set (`--set=frozen`, 56 tables) | `0ec951ee75e52c5b1aaa87ea57ebf43639b353d1` | `0ec951ee75e52c5b1aaa87ea57ebf43639b353d1` | **IDENTICAL** |
| Clean set (`--set=clean`, 58 tables) | `4f754a6f1c5f1b1a8777219b0bc6773e5edcaf74` | `4f754a6f1c5f1b1a8777219b0bc6773e5edcaf74` | **IDENTICAL** |
| Row counts, every table in the schema (124 tables) | snapshot | `diff` | **0 differences** |
| Media tree `public/Uploads_Images` | 116,022 files | 116,022 files | **`diff` empty** |
| `inventory:verify` | — | 15,670 checks, all agree | ✓ |
| `mysqld` | PID 17260, up 04:50:28 | PID 17260, up 04:50:28 | never crashed |

The comparison was made **table by table on rows and checksum**, not only on the combined sha1 — a
single combined digest can hide two compensating differences. 123 tables compared, 0 differing.

Final spot-counts after restoration:

```
users 4 (max id 4, AUTO_INCREMENT 5) | core_user_roles 1 | catalog_products 7713
orders 9 (order 9 = pending) | inventory_movements 5795 | core_activity_log 7634
integration_outbox pending mail rows: 0
```

### 0.2 Everything that was touched, and how it went back

| # | Action | Rows / files created or changed | How it was restored |
|---|---|---|---|
| 1 | Created two accounts — `michael.review.admin@watchizer.local`, `michael.review.entry@watchizer.local` (bcrypt cost 10, per AGENTS §3(a)) | `users` ids 5, 6 | deleted; `AUTO_INCREMENT` reset to 5; `users` back to 4 rows |
| 2 | `php artisan manage:role grant` ×2 | `core_user_roles` 99756 (admin→5), 99757 (data_entry→6) | revoked via `manage:role revoke … --all-scopes`; table back to its single row (id 46207, user 1) |
| 3 | Created one product through the form — every field, both languages, categories, SEO, image | `catalog_products` 16531; `catalog_product_translations` 31051–31052; `catalog_product_images` 1014404; `storefront_product` 14472–14473; `storefront_category_product` 19638; `catalog_product_gender` 1 row; `catalog_product_search` 2 rows | all deleted in one transaction, children first |
| 4 | Uploaded an 800×800 PNG | 11 files `Product_image/1789738835_2026-09-18_6aad3f5344547*.{webp,avif}` | deleted; media-tree `diff` empty |
| 5 | Stock adjustment +3 express on the test product | `inventory_movements` 16036; `integration_outbox` 21888 (`morabaa` / `stock.changed`) | deleted |
| 6 | Hid, then un-hid, product 4 on Watchizer (to exercise the confirmation) | `storefront_product` id 1 | `is_visible`→1, `updated_at`→`2026-08-11 20:07:24`, **`published_at`→NULL** |
| 7 | Advanced order 000009 `pending` → `processing` → `shipped` | `orders` id 9 | `status`→`pending`, `updated_at`→`2026-09-01 19:41:36` |
| 8 | Two customer e-mail obligations written by step 7 | `integration_outbox` 21889, 21890 (`mail`, `pending`) | **deleted within minutes** |
| 9 | Set the dashboard language to English to inspect the English shell | `core_user_preferences` user 5 | deleted (the pre-existing row for user 1 was untouched) |
| 10 | Audit rows for all of the above | `core_activity_log` 121253–121263 (11 rows) | deleted; count back to 7,634 |
| 11 | Temporary upload source inside the tree | `core/storage/app/review-test-image.png` | deleted (gitignored while it existed) |
| 12 | `npm run build` (see §7) | `core/public/build/` | gitignored build output; `core/public/hot` removed when Vite was stopped |

**The one that nearly got away.** After steps 6 and 7 the all-table digest still differed on exactly
one table: `storefront_product` — same row count, different checksum. Un-hiding a product stamps
`published_at` when it was previously NULL, and only `is_visible` and `updated_at` had been put
back. It was found because the comparison ran per table rather than on the combined digest alone.
Restoring `published_at` to NULL made all 123 tables match.

**The rule worth keeping: a per-table comparison is what makes a restoration claim provable. A
single combined sha1 tells you something moved, not what.**

### 0.3 No customer was e-mailed

Advancing an order status mails the customer from core (`App\Domain\Orders\OrderFulfilment`, around
line 157: `$this->mailer->statusChangedNow(...)`). Four independent things made that safe, and all
four were verified **before** the order was touched, not after:

1. `core/.env` carries `ORDER_MAIL_INLINE=false`, and the resolved value was read back out of the
   running application: `notifications.send.inline = false`. `OrderMailer::flush()` (line 193)
   returns immediately when that is false — the obligation row is written, nothing is sent.
2. The application was served with `MAIL_MAILER=log` exported into the server process.
3. `mail:drain` was never run, and no scheduler was running on the machine.
4. Both pending obligations were **deleted** minutes after they were written, so nothing could ever
   drain them. `integration_outbox` now holds **0 pending `mail` rows**.

The two obligations named a real customer address in the legacy data. They were removed rather than
left for a future `mail:drain`, because a row that is only safe while nobody runs a command is not
safe — the same lesson as the 2026-09-13 incident in which a harness run mailed four real
administrators about a test order.

### 0.4 Git and the servers

`HEAD` is unchanged at `b572305`. No file was created in the repository by this pass; the 122 dirty
paths are the ones the working tree already carried. `core/public/build` and `core/public/hot` are
both gitignored (`core/.gitignore:17` and `:19`).

Servers were stopped and **proved** stopped, per the AGENTS rule that a POSIX kill command which
does not exist on this workstation reports success while doing nothing:

```powershell
Get-Process php -ErrorAction SilentlyContinue                      # -> none
Get-NetTCPConnection -State Listen | ? LocalPort -in 8000,5173     # -> both free
Get-Process mysqld                                                 # -> PID 17260, StartTime 04:50:28 (unchanged)
```

### 0.5 The one thing deliberately not done

The brief included *"correct a wrong category on twenty products"*. **There is no bulk category
action anywhere in the dashboard** — see W-1 — so the only way to do it is twenty complete form
edits. One was done, on the throwaway product, and the cost measured. Twenty real rows were not
edited and then reconstructed by hand, because a restoration that depends on retyping twenty
category sets correctly is not a restoration.

---

## 1. Defects

Ordered by how much they hurt. Every entry gives what it is, where it is, the measured evidence, and
how to reproduce it. Line numbers are as read on 2026-09-18; if code has moved since, search for the
quoted expression rather than trusting the number.

---

### D-1 · The activity log's "ما تغيّر" column renders off-screen

**What.** The audit trail's whole purpose — what changed, from what, to what — is not visible. The
screen shows four columns and a wide empty area where the fifth should be.

**Where.** `resources/js/pages/Manage/Activity/Index.tsx`, the `subject_label` column (`key:
'subject_label'`, header `common.record`) takes all the horizontal slack; the `changes` column
(header `activity.changed`, "ما تغيّر") is pushed past the edge. The wrapper is the shared
`DataTable`'s `div.w-full.overflow-x-auto.scrollbar-thin`.

**Measured**, in the live page at an inner width of 1,740 px:

```
table.scrollWidth        = 1742      container.clientWidth = 1278      scrollLeft = 0
cell "السجل"    left = -262   width = 1257
cell "ما تغيّر"  left = -376   width =  114      <- entirely outside the viewport
headers present in the DOM: ["التاريخ","المستخدم","الإجراء","السجل","ما تغيّر"]
```

The `السجل` cell is 1,257 px wide to hold a value like `000009`. There *is* an `overflow-x-auto`
wrapper, but in RTL `scrollLeft: 0` is the **right** edge, so the page opens on the columns nobody
needs and the hidden 464 px sits where the eye stops reading. The thin scrollbar did not appear in
any screenshot. What the operator sees is an empty column and concludes nothing was recorded.

**Reproduce.** Open `/manage/activity` in Arabic at any window narrower than about 2,000 px. Read
the header row: `التاريخ · المستخدم · الإجراء · السجل`, then white space. The content is in the DOM
— `get_page_text` returns `الحالة processing ← shipped` — it is simply not on screen.

**Note for the fix session.** The same `DataTable` on `/manage/storefronts/1/products` and
`/manage/inventory/ledger` measured `scrollWidth == clientWidth` (1278/1278) with no hidden headers,
so this is the activity log's column widths, not the shared component.

---

### D-2 · Two screens give two different numbers for "low stock", and the larger one is wrong

**What.** `/manage` reports **4,946 وصلت إلى حد التنبيه**. `/manage/inventory` banners **7,524
منتجًا تحت حد التنبيه**. Same concept, same words, two numbers, on two screens an operator uses in
the same minute.

**Where.**
* `app/Http/Controllers/Manage/HomeController.php:189` — `->where('in_stock', 1)->whereRaw(Sql::belowLowStockThreshold())`
* `app/Http/Controllers/Manage/InventoryController.php:578` — `->whereRaw('(stock_express + stock_market) <= low_stock_threshold')` with no `in_stock` filter
* `app/Http/Controllers/Manage/InventoryController.php:129` — the list filter writes the same predicate as a raw string a third time

**Measured.**

```
home     (deleted_at IS NULL, is_active=1, in_stock=1, sum <= threshold)  = 4946
banner   (deleted_at IS NULL,               sum <= threshold)             = 7524
out of stock (is_active=1, in_stock=0)                                    = 2578
                                                             4946 + 2578  = 7524
```

The banner counts 2,578 products that are not low — they are **gone** — and announces 7,524 as an
alert on a catalogue of 7,713. **That is 97.5 % of the shop.** One rule, three copies of the
predicate, already disagreeing.

**Reproduce.** Read the KPI card on `/manage`, then the amber banner at the top of
`/manage/inventory`.

**Related, and the reason the alert is structurally meaningless.** `low_stock_threshold` is
defaulted to 5 and almost nothing has ever changed it:

```
threshold 5: 7578   threshold 1: 106   threshold 2: 27   threshold 3: 3
total stock 2: 2667   0: 2578   1: 1558   3: 491   5: 160   4: 88   10: 45   8: 24
```

Fixing the query narrows 7,524 to 4,946. Only W-2 (a bulk way to set thresholds) makes the number
mean anything.

---

### D-3 · The products list flags 7,087 products "بلا تصنيف" and tells the operator to fix them

**What.** On `/manage/storefronts/1/products`, 7,087 of 7,713 rows carry a red badge reading **بلا
تصنيف**, whose tooltip reads: *"هذا المنتج غير موضوع في أي تصنيف على هذا المتجر، فلا يظهر في أي
قائمة. اختر له تصنيفًا من شاشة المنتج أو من شاشة التوزيع."*

Those 7,087 are the Brand Fashion catalogue. They are not *uncategorised on Watchizer* — they are
**not sold on Watchizer at all**, which the same row states correctly two columns away (`—
Watchizer`). The Home screen calls the same set `غير مضاف` and reports Watchizer's `بلا تصنيف` as
**0**.

**Where.** Two different predicates behind one name and one Arabic word:

* `app/Http/Controllers/Manage/HomeController.php:87` — `unplaced` counts products that **have** a
  `storefront_product` row for this storefront and **no** `storefront_category_product` row → **0**
  for Watchizer.
* `app/Http/Controllers/Manage/ProductController.php:634` — the `unplaced` flag counts products with
  no `storefront_category_product` row **regardless of placement** → **7,087** for Watchizer.
* The badge itself: `resources/js/pages/Manage/Products/Index.tsx:253–262`, rendered when
  `row.placement === "none"`, text at line 261 (`products.unplaced`).

**Measured.** `?filters[flag]=unplaced` on storefront 1 returns **7,087 سجل** and every returned row
carries the badge. Home's own table prints, for Watchizer: `ظاهر 626 · مخفي 0 · غير مضاف 7,087 · بلا
تصنيف 0`.

**Reproduce.** Open `/manage` and note Watchizer's `بلا تصنيف = 0` and `غير مضاف = 7,087`. Open
`/manage/storefronts/1/products`, set the quick filter to `بلا تصنيف`, and read `7087 سجل`.

---

### D-4 · A category's branch count double-counts, and exceeds the whole catalogue

**What.** `/manage/storefronts/2/categories`, node **ساعات**, badge: **`7823 في الفرع`**. The
catalogue holds 7,713 products. A branch cannot contain more products than exist.

**Where.** `app/Http/Controllers/Manage/CategoryController.php:246`, `subtreeTotals()` — it **sums**
each node's own count up the tree, so a product filed under both `ساعات غوص` and `كرونوغراف` is
counted twice in `ساعات`. Consumed at line 529 (`$subtreeLive`) and rendered as the blue badge.

**Measured**, over the 18 nodes of that branch on storefront 2:

```
placement rows in the branch   = 7829
DISTINCT products in the branch = 4692
```

The label says products; the number is placement rows.

**Reproduce.** `/manage/storefronts/2/categories`, read the badges on the first row.

---

### D-5 · The category header states the tree is 10 levels deep. It is 3.

**What.** The tree card's subtitle reads `61 تصنيفًا · أقصى عمق 10`. An operator reads that as a
fact about this tree. It is the system's maximum.

**Where.** `app/Http/Controllers/Manage/CategoryController.php:93` sends
`'max_depth' => CategoryTreeWriter::MAX_DEPTH`; `app/Domain/Catalog/CategoryTreeWriter.php:51` is
`public const MAX_DEPTH = 10`. The string is
`resources/js/pages/Manage/Categories/Index.tsx:429` (`":count تصنيفًا · أقصى عمق :depth"`).

**Measured** on storefront 2: `MAX(depth) = 3` — depth 1: 4 nodes, depth 2: 47, depth 3: 10, total
61.

The controller's own comment at line 310 says *"`max_depth` is 3 today"* — the same conflation, in
the same file.

**Reproduce.** `/manage/storefronts/2/categories`, read the line above the first node.

---

### D-6 · Every red badge is invisible in dark mode

**What.** The `destructive` badge variant is dark red text on a near-black card. It is the alarm
colour, and it is the one variant with no dark-mode treatment.

**Where.** `resources/js/components/ui/badge.tsx:15`:

```
destructive: 'border-transparent bg-destructive/10 text-destructive',
```

Compare the two lines above it, which both carry an override:

```
success: '... bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
warning: '... bg-amber-100  text-amber-900  dark:bg-amber-950  dark:text-amber-300',
```

`resources/css/app.css:24` sets `--destructive: 0 84.2% 60.2%` for light and line 60 sets
`0 62.8% 30.6%` for dark — a background-grade dark red, used here as text.

**Measured** in the live dark-mode page:

```
"بلا تصنيف"  color rgb(127,29,29)  on card rgb(9,9,11)   ->  contrast  ~2.0 : 1
"أزياء"      (neutral)                                    ->  5.81 : 1
"نعم"        (success)                                    ->  9.94 : 1
```

WCAG minimum for body text is 4.5 : 1. The destructive badge is at roughly 2.

**Affected call sites** (7): `Products/Index.tsx:225` (`عربي ناقص`), `:230` (`بلا صورة`), `:255`
(`بلا تصنيف`, 7,087 rows), `Placement/Index.tsx:140` (`عربي ناقص`), `Inventory/Index.tsx:210`
(`نفد`), `Home.tsx:137` (the Arabic-missing count), `Promotions/Form.tsx:282`.

**Reproduce.** Any list screen, click the moon icon in the top bar, look at the red badges.

---

### D-7 · Customer name and phone collide in every RTL order row

**What.** `/manage/orders` in Arabic renders **`· 01022315422Adam Ayoub`**. It should read
`Adam Ayoub · 01022315422`. The separator is orphaned at the far left and the phone is glued to the
name with no visual break.

**Where.** `resources/js/pages/Manage/Orders/Index.tsx:102–103`:

```
{row.customer}
{row.phone ? (<span dir="ltr"> · {row.phone}</span>) : null}
```

`{row.customer}` is emitted bare into an RTL paragraph; the `dir="ltr"` span is an LTR island that
floats to the left of it and takes the separator with it.

**Measured.** Zoomed capture of the cell at 1568 px: `· 01022315422Adam Ayoub`. The same rows in the
English (LTR) shell render correctly — so the bug only exists in the language the team actually
uses.

**Reproduce.** `/manage/orders` in Arabic; read the second line of the first column.

**Note.** The project already ships the helper this needs: `resources/js/components/ui/bidi.tsx`
exports `Name`, a `<bdi dir="auto">` wrapper. It is used on the products list and not here.

---

### D-8 · The stock screen cannot be searched by product name

**What.** `/manage/inventory` lists rows headed *ساعة هوغو بوس للرجال 1513581*. Typing **هوغو** into
the search box returns **"لا توجد منتجات"** — 0 records.

**Where.** The search placeholder is `كود المنتج أو SKU…` and that is exactly what the query covers.
The empty state (`لا توجد منتجات` / `جرّب تعديل البحث أو التصفية`) never says the search cannot see
names.

**Measured.** `/manage/inventory` → `7714 سجل`. `?q=هوغو` → `0 سجل`, empty state, while the first
seven unfiltered rows all contain the word. `?q=ZZ-REVIEW-001` → `1 سجل`. The products list finds
Arabic name fragments correctly — `?q=كرافتة` → 66 of 7,713 — and even tolerates the `ة`/`ه`
normalisation (`كرافته` → the same 66).

**Reproduce.** `/manage/inventory`, read a product name off the screen, type part of it into the
search box.

---

### D-9 · Two ledger filters render as blank boxes, and one option is untranslated

**What.** On `/manage/inventory/ledger` the `المخزن` and `المصدر` dropdowns show an empty selected
option — two blank boxes in a filter row.

**Where.** `resources/js/pages/Manage/Inventory/Ledger.tsx:252–254` and `:264–266`:

```
{filters.buckets.map((option) => (<option key={option.value} value={option.value}>
    {bucketLabel(t, option.value)}
</option>))}
```

The server *does* send a label — `InventoryController.php:310` sends
`['value' => '', 'label' => 'كل المخازن']` and line 588 sends `'كل المصادر'` — but the client
discards `option.label` and translates `option.value`. `bucketLabel` ends at
`resources/js/lib/labels.ts:50` with `return bucket ?? '—'`, and `referenceLabel` at `:137` with
`return reference ?? '—'`. **`''` is not `null`**, so `??` does not fire and the option renders as
an empty string.

**Measured**, read out of the live DOM:

```
select "المخزن"  options:  ""=""  |  "إكسبريس"="express"  |  "ماركت"="market"
select "المصدر"  options:  ""=""  |  "legacy:products"="legacy:products"
select "السبب"   options:  "كل الأسباب"=""  |  "حجز لطلب"="order"  |  "promotion_reward"="promotion_reward"  |  …
```

Two further things in the same row: **`promotion_reward`** is untranslated among ten translated
reasons, and **`legacy:products`** is the only option of the source filter — a raw source key.

**Reproduce.** `/manage/inventory/ledger`; look at the two unlabelled boxes between `رقم المنتج` and
`كل الأسباب`; open `كل الأسباب` and read the third entry.

**Note.** This is the item-10 vocabulary fix creating a new defect: translating the option *values*
broke the option that has no value.

---

### D-10 · One payment value, three words, on three screens

**What.** `orders.payment_method` holds `paymob` on **7 of 9 orders** and `cash` on 2. It is
rendered three different ways.

| Screen | Renders | Where |
|---|---|---|
| Orders queue | `paymob` / `نقدًا` | `Orders/Index.tsx:155` calls `methodLabel(t, row.payment_method)`; `labels.ts` has no `paymob` case (Paymob lives in `providerLabel`, `labels.ts:82`), so it falls through `default: return method ?? '—'` at `:121` |
| Order detail | `paymob` / **`cash`** | `Orders/Show.tsx:813` renders `{order.payment_method ?? …}` **without calling `methodLabel` at all** |
| Order line | `Market` | `order_items.type_stock` printed verbatim; the same bucket is `ماركت` on `/manage/inventory` and `express / market` on `/manage` |

**Measured.**

```
SELECT payment_method, paid_via_provider, COUNT(*) FROM orders GROUP BY 1,2
  cash   NULL  2
  paymob NULL  7
```

`paid_via_provider` is NULL on every order, so the raw fallback branch is the one every order takes.

**Where the assumption failed.** The comment at `Orders/Index.tsx:151` says the legacy column
*"holds its own vocabulary (`cod`, `cash`, `online`) — the same map covers it."* It does not: 78 %
of the rows are `paymob`.

**Reproduce.** `/manage/orders` (queue) then `/manage/orders/8` (detail) — the same order's payment
reads `نقدًا` on one and `cash` on the other.

---

### D-11 · `user #5` in the stock ledger

**What.** `/manage/inventory/ledger`, column **من**: a dashboard adjustment shows **`user #5`**.
Every other screen resolves that id to the operator's name. On the one screen whose product is
accountability, the actor is a raw foreign key. Import rows show `system`; the `المصدر` cell shows
`woo:58398`.

**Reproduce.** `/manage/inventory`, adjust any product, then open `/manage/inventory/ledger` and read
the last column of the top row.

---

### D-12 · The sign-out message comes back in the wrong language

**What.** Sign out with the dashboard set to English. The login page renders in Arabic RTL — correct,
the session preference is gone — and the flash reads **`.You have been signed out`**, English text
in an RTL paragraph with the full stop pushed to the left.

**Where.** `app/Http/Controllers/Manage/Auth/LoginController.php:118`:

```
return redirect()->route('manage.login')->with('status', ManageText::t('auth.signed_out', 'تم تسجيل الخروج.'));
```

`app/Http/Middleware/SetDashboardLocale.php:42` has already set the app locale from the user's
preference, and `Auth::logout()` on line 114 does not undo it, so the string is resolved in the
departing user's locale and then rendered on a guest page that is always Arabic.
`lang/en/manage.php:322` is the English string.

**Reproduce.** Profile → `لغة اللوحة` → English → Save → sign out.

---

### D-13 · The English shell opens with an Arabic product name

**What.** In the English interface the sidebar header reads **لوحة التحكم**.

**Where.** `config/branding.php:26`:

```
'suffix' => env('BRANDING_SUFFIX', 'لوحة التحكم'),  // i18n-exempt: an ENV DEFAULT for the browser-title suffix, set per deployment
```

The comment is true of the browser title and not of the sidebar:
`resources/js/components/manage/Sidebar.tsx:204–205` renders `{branding.suffix}` as the visible
product name beside the logo.

**Reproduce.** Switch the dashboard to English and look at the top-left of the sidebar.

---

### D-14 · `الفئة (رجالي/حرمي…)` — misspelled

**Where.** `resources/js/pages/Manage/Products/Form.tsx:876` — `t("products.genders", "الفئة
(رجالي/حرمي…)")`. The same concept is spelled correctly at `Form.tsx:527` — `t("products.field_gender",
"الفئة (رجالي/حريمي)")` — and that string is the one used in the visibility-gate sentence, so **one
product form shows both spellings**, a few hundred pixels apart.

**Reproduce.** Open any product form; compare the heading of the gender checkbox group with the
"لن يظهر المنتج…" sentence above it.

---

### D-15 · Every saved lookup logo reports `0×0 · 0 KB`

**What.** `/manage/lookups/brands` prints, under each of the 78 brand logos, a line like
`Brand/1784479110_2026-07-19_6a5cfd8652bf6.webp · 0×0 · 0 KB`. The image renders fine; the
measurements are zeros.

**Where.** `resources/js/pages/Manage/Lookups/Index.tsx:373` builds the value object from the stored
path alone:

```
{ file: value, folder: '', url: urlHint ?? '', width: 0, height: 0, bytes: 0, renditions: {}, skipped: [] }
```

and `resources/js/components/form/ImageField.tsx:160` prints the line unconditionally:

```
{value.file} · {value.width}×{value.height} · {Math.round(value.bytes / 1024)} KB
```

A freshly uploaded image has real numbers; a stored one never does.

**Reproduce.** `/manage/lookups/brands`, read the grey line under any logo.

---

### D-16 · The image pipeline reports a resolution the photo does not have

**What.** An 800 × 800 upload is reported on screen as **`1200×1200`** with renditions
`960 · 640 · 480 · 320 · 160`. The 960 and the 1200 are padding, not detail.

**Where.** `config/media.php:58–63` — the `product` preset is
`'master' => ['width' => 1200, 'height' => 1200, 'quality' => 85, 'pad_square' => true]`.
`app/Domain/Media/ImagePipeline.php`, `fit()` (around line 192) caps the scale at `1.0` so it never
upscales *pixels* — correct — but with `pad_square` the canvas is created at exactly `$maxWidth ×
$maxHeight` and the source is centred on white. Line 109 then skips a rendition only when
`$target >= $width`, where `$width = imagesx($master)` — the **padded** width.

**Measured.** After uploading an 800 × 800 PNG, on disk:

```
…6aad3f5344547.webp        1200x1200   4374 bytes
…-960.webp 960x960   -640.webp 640x640   -480.webp 480x480   -320.webp 320x320   -160.webp 160x160
pixel sample of the master: (5,600) #FFFFFF   (150,600) #FFFFFF   (210,600) #EBDFC7   (600,600) #785032
```

White from x = 0 to about 199; the photograph starts at x = 200. 44 % of the master is padding, and
because the guard measures the padded master, the *"source is only Npx wide"* message can never fire
for `product` or `product_gallery`.

The class docblock's own example — *"an 800 px source asked for a 1200 px master stays 800"* — is
not what happens when padding is on.

**Reproduce.** Upload any image smaller than 1200 px into a product and read the line beside it.

---

### D-17 · 403 and 404 are bare English pages with no way back

**What.** Two of the three ways an operator leaves the happy path end on a white page in a language
most of the team does not read, with no dashboard chrome and no link home.

**Measured.**

* Data-entry opening `/manage/users` → **`403 | This action is unauthorized.`**
* Anyone opening `/manage/products` (the un-scoped path, a natural guess, and what a stale bookmark
  would hold) → **`404 | Not Found`**

**Reproduce.** Sign in as data-entry, put `/manage/users` in the address bar.

**Note.** The generic-404 posture is deliberate for the public API (wave-2 review 🟡-11). It is not
the right answer inside `/manage`.

---

### D-18 · Raw identifiers in operator copy — the item-10 sweep missed the hot ones

**What.** Machine vocabulary on screens the shop floor reads daily.

| Where (file:line) | Text on screen |
|---|---|
| `HomeController.php:139` | **`is_active = 1`** as a KPI tile's hint, marked `// i18n-exempt: a column name and its value, not a sentence` |
| `Home.tsx:175` | *"محسوبة من عمود **`low_stock_threshold`** لكل منتج"* |
| `Home.tsx:192` | *"إجمالي الوحدات: **`express / market`**"* — translated as إكسبريس/ماركت on two other screens |
| `VariantsPanel.tsx:203` | **`<code dir="ltr">InventoryService</code>`** — a PHP class name, split across three translation keys (`variants.ledger_note_before_service` / `…_before_active` / `…_after_active`) so it survives into English too |
| product image row | the storage path, plus *"ويُنظَّف بأمر **`media:prune`** بعد مراجعة تقريره"* — a command that answers 403 to every role, including administrators (`Role::RESTRICTED`) |
| order mail panel | *"ويعيد **`mail:drain`** المحاولة كل دقيقة"* |
| users banner | *"الأمر **`php artisan manage:role`** هو الطريق الوحيد"* |
| profile | *"يُكتب في جدول واحد يملكه النظام الجديد (**`core_user_preferences`**)"* |
| category nodes | **`category_type#1`**, **`sub_type#1…18`** as a badge on every node |
| `ActivityController.php:342` | subject type **`orders`** — `typeLabel()` maps 9 types and not the one the shop floor generates |
| `labels.ts:222` | changed-field names **`express`**, **`model_number`**, **`role`**, **`user_id`** — `changedFieldLabel()` covers 14 columns; **29 of 36** change entries in the whole log show a raw name |
| `CategoryController.php:397` | an English sentence as an audit value: **`previous order ← 15 node(s) reordered`** |
| every list URL | `sort=p.id`, `sort=p.wa_code` — SQL aliases in a link people bookmark and paste |

**Measured**, over the whole `core_activity_log`:

```
RAW role 12 | RAW user_id 12 | RAW express 4 | sort_order 3 | is_visible 2 | status 2 | RAW model_number 1
distinct keys 7, entries 36, raw 29
```

**Reproduce.** `/manage`, first two KPI tiles and the stock card; any product form's variants panel;
`/manage/activity`.

---

### D-19 · `required` never reaches the input

**What.** The red asterisk beside a required label is decoration. There is no HTML5 `required`, no
`aria-required`, and therefore no client-side guard at all.

**Where.** `resources/js/components/form/Field.tsx:40–45` hands the control only:

```
render({ id, 'aria-describedby': describedBy, 'aria-invalid': …, 'aria-errormessage': … })
```

and passes `required` **only** to `<Label htmlFor={id} required={required}>` (lines 52 and 59).
`resources/js/components/ui/label.tsx:17–21` turns that into a red `*` and nothing else. A grep for
`required` across `resources/js/components/form/` returns hits in `Field.tsx` and
`TranslatedField.tsx` only — never on an input element.

**Consequence.** An incomplete save posts, the server answers 422, and the errors render inline
beside their fields — **six screens above the button that was pressed** — with no error summary at
the top and no scroll to the first offender. Combined with D-20 the operator sees nothing change at
all.

**Reproduce.** Open a product form, press `إنشاء المنتج` without filling anything, and watch the
viewport.

---

### D-20 · The save confirmation is never seen

**What.** The product form's Save button is at the bottom; the success flash *"تم حفظ المنتج."*
renders at the top; the page does not move.

**Where.** `resources/js/pages/Manage/Products/Form.tsx:455` and `:462` — both `form.post` and
`form.put` pass `{ preserveScroll: true }`.

**Measured** on the edit form of a real product:

```
document.body.scrollHeight = 4868      window.innerHeight = 662      -> 7.4 screens
```

Clicking Save left the viewport at the bottom with no visible change; the flash was only found by
pressing Ctrl+Home.

**Reproduce.** Edit any product, change one field, press `حفظ التغييرات`, and look for confirmation
without scrolling.

---

### D-21 · The login form's email field has no associated label

**Where.** `/manage/login`. Read from the accessibility tree:

```
textbox "name@example.com"  type="email"  placeholder="name@example.com"     <- accessible name is the placeholder
textbox "كلمة المرور"        type="password"                                  <- correct
```

The visible label `البريد الإلكتروني` is drawn but not associated, so the accessible name falls back
to the placeholder. The password field on the same form is wired correctly, which is what makes it a
defect rather than a house style.

---

### D-22 · The order total does not equal its lines, and nothing says why

**What.** Order 000009 shows **الإجمالي 4300.00** and a single line of **4200.00 × 1**. Order 000008
shows **1099.00** against **999.00**.

**Measured.** The difference is the Cairo delivery price, confirmed on `/manage/shipping`
(`القاهرة / Cairo / 100.00`). But the `orders` table carries only `total_price_for_order` — no
subtotal, no shipping, no discount column — and the detail screen renders no totals block at all.

**Consequence.** Someone checking an invoice sees a total that contradicts the only line on the
page, with nothing on screen to account for the gap.

**Reproduce.** `/manage/orders/9`; compare the الإجمالي in the الطلب card with the الإجمالي of the
one row in الأصناف.

---

### D-23 · "إجمالي المشتريات 0.00" for every customer, including one with five orders

**What.** `/manage/customers/g:01067418030` shows **عدد الطلبات 5** and **إجمالي المشتريات 0.00**
side by side on the same card, with five orders of 3,190 listed underneath.

**Where.** `app/Domain/Customers/Customers.php:192–198`, `spent()` — `SUM(...)` filtered to
`status IN ('delivered','completed')`. The docblock explains why, and the reasoning is sound: *"a
pending order is not a purchase and a cancelled one is not either."*

**Measured.** No order in this database has ever reached `delivered` or `completed`, so the column
is 0.00 for every row of `/manage/customers`.

**The defect is the label, not the query.** `إجمالي المشتريات` with no qualification, printed beside
an order count, reads as broken data.

---

## 2. Design — screen by screen

Blunt, as asked. Each entry says what is wrong, why it is wrong for this team, and what to change.

---

### 2.1 The product form — the worst screen in the dashboard

**Measured: 4,868 px tall against a 662 px viewport. 7.4 screens. One column. No section
navigation, no tabs, no sticky save bar.**

In order, top to bottom: `التعريف` (4 fields) · `الاسم والوصف` (six bilingual pairs = 12 inputs) ·
`السعر` (6 fields) · the **whole Watchizer placement block** · the **whole Brand Fashion placement
block** · `مواصفات المنتج` · `الخصائص والفئات والألوان` · `الصور` · `بيانات SEO` · **the Save
button** · and then the variants panel, *after* the primary action.

**Both storefronts' complete category trees live on this one page** — about 37 nodes for Watchizer
and 61 for Brand Fashion, roughly 98 checkboxes. Each tree sits in a scroll pane of about 200 px,
showing 8 of 61 names at a time, and each is rendered as a **two-column grid with `—` and `— —` as
the only depth indicator**. Two columns destroy the indentation: `أزياء وإكسسوارات` lands in the
right column with an unrelated child beside it on the left, and a reader cannot tell a parent from
a sibling. There is no search inside the picker. Filing one product means scrolling a 200 px window
through 61 names inside a page that is already seven screens long — and the mouse wheel inside the
pane hijacks the page scroll.

`شجرة التصنيفات القديمة` — the legacy tree — is offered as a selectable root in **both** pickers.

Three colour selects render for every product regardless of family: `اللون الأساسي`, **`لون
القرص`** and **`لون السوار`**. `resources/js/pages/Manage/Products/Form.tsx:1476–1478` defines
`ROLES` as a fixed array, while the rest of the specification block *is* family-gated through
`config/catalog.php`. A hat and a handbag get "dial colour" and "strap colour".

**What to change.**
* Tabs: `التعريف · الاسم والوصف · السعر · الظهور · المواصفات · الصور · SEO`.
* A sticky save bar carrying the error count and the save state.
* The category picker becomes a **searchable single-column tree with real indentation**, showing
  only the storefront being edited; the other storefront collapses to a one-line summary with a link.
* Colour roles come from the family config, like every other spec field.
* The legacy tree is not offered for new placements.

---

### 2.2 The products list — unreadable at 7,713 rows

**Measured: rows are 118–190 px tall. Five to six fit one screen. 7,713 products at 25 per page is
309 pages.**

The title is printed **twice** — Arabic, then English underneath in grey. When the Arabic field
holds the English string, which is common in the imported Brand Fashion catalogue, **the same
200-character sentence prints twice**, ten lines, in one cell.

Then the badges. Almost every row carries the same three: `بيانات ناقصة: تصنيف، الماركة` ·
`ترجمة آلية — تحتاج مراجعة` · `بلا تصنيف`. **7,087 of 7,713 rows carry that set.** A warning that
fires on 92 % of the catalogue is wallpaper, and it costs a third of each row's height. `تصنيف` is
named twice on the same row, once inside `بيانات ناقصة` and once as its own badge.

The thumbnail is roughly 24 px inside a 40 px frame, so images sit small and off-centre; a product
with no image shows an empty bordered box with no word explaining whether it is missing or failed.

**What to change.** One line per row — thumbnail, code, title **in the reader's language only**,
brand, price, stock, visibility. The second language on hover or in a detail drawer. Collapse the
three common badges into a single "needs work" dot with a count and a tooltip listing what is
missing; keep an explicit badge only where it is rare and actionable. Remove `بلا تصنيف` from
products that are not on this storefront at all (D-3).

---

### 2.3 Pagination — 309 pages, previous and next

Every table in the dashboard offers `السابق` / `التالي`, a `1 / 309` indicator, and nothing else. No
page jump, no first/last, no rows-per-page control. Page 200 is 199 clicks.

**What to change.** A page-number input and first/last in the shared pagination component. Every
table inherits it at once.

---

### 2.4 Colour means four different things

Red marks: a destructive action (`حذف`); a value that is merely zero (`ماركت 0` on the stock list,
where an empty bucket is normal); a badge true of 92 % of rows (`بلا تصنيف`); and, on the units
screen, a usage count of **244** — a *healthy* number rendered in the alarm colour.

Amber marks both "7,087 products are not on this site" (a fact, nothing to do) and "this product
reached its reorder point" (an action). Green is used consistently. In dark mode the alarm colour
is invisible (D-6).

**What to change.** Fix the meaning before the palette: red = you must act or something is broken;
amber = worth knowing; neutral grey = a fact. A zero in a stock bucket is a fact.

---

### 2.5 Symbols nobody explains

The `الظهور على المتاجر` column uses **✓** (visible), **✕** (placed but hidden) and **—** (not
placed on that storefront). ✕ and — appear in adjacent rows in the same column, look alike at a
glance, and mean very different things: *"somebody decided against this"* versus *"this is not on
that site at all"*. There is no legend and no tooltip. The icon also moves — before the name on one
row, wrapped below it on the next — because the cell is too narrow.

**What to change.** Three words instead of three glyphs (`ظاهر` / `مخفي` / `غير مضاف`) in three
tones, or a legend row above the table.

---

### 2.6 Headings duplicated, panels ragged

`أسعار الشحن` appears as the page `h1` and again as the card title immediately beneath it. Same on
`سجل النشاط`. On the shipping screen a full-width bordered box holds nothing but a grey sentence.

The Home KPI row is `sm:grid-cols-2 xl:grid-cols-4` with **five** tiles, so `إجمالي الطلبات` sits
alone on a second row. Beside it, `صلاحياتك` is one badge in about 250 px of white, stretched to
match the height of the stock card next to it.

`المستخدمون والصلاحيات` puts a single text input and one sentence in a half-empty panel next to the
grant form.

Profile and Storefront settings use a narrow container inside the wide page container without
centring it, so the whole screen hugs one side — visible in Arabic, glaring in English, where the
content sits left with about 550 px of empty space on the right.

**What to change.** Drop the duplicated card titles where the page already has the heading; make the
KPI grid fit its contents (4 or 6, not 5); centre the narrow forms or widen them.

---

### 2.7 The storefront switcher moves between screens

Top-left beside `منتج جديد` on Products. Top-right beside the description on Banners. Left, under
the title, on Payments — where it **collides with the `أضف عقد مزوّد` button**, the two controls
stacked with their edges nearly touching.

**What to change.** One position, in the page header, on every storefront-scoped screen.

---

### 2.8 Date filters are unlabelled US-format boxes

Orders, Activity and the Ledger each render two bare `mm/dd/yyyy` inputs. On Orders and Activity
they are split across two rows, so one sits at the end of the first filter row and the other begins
the second. They carry `aria-label`s (`من تاريخ` / `إلى تاريخ`); nothing is painted, so a sighted
operator cannot tell which is which. On an Egyptian dashboard `mm/dd` is the one format nobody
writes.

**What to change.** Visible `من` / `إلى` labels, the two inputs adjacent in one group, and a few
presets (اليوم · آخر 7 أيام · هذا الشهر) which is what an order queue is actually filtered by.

---

### 2.9 The category tree reads as a list, not a tree

This is the screen the previous review specifically asked to be looked at with eyes. The verdict:
**the structure is correct and it does not read as a tree.**

Each node is a **73 px full-width bordered card**, indented about **23 px** per level, with a faint
vertical rail. Depth is real but imperceptible against the card height and its full border — the eye
reads a stack of rows with slightly ragged edges.

Each row carries eight controls: delete (red trash), edit, add-child, move down, move up, `في
القائمة` toggle, `مفعّل` toggle, and a collapse chevron. At 61 nodes that is roughly **490 controls
on one screen**. The **red trash is the leftmost and most prominent control on every row**. The two
toggles are adjacent, identically styled and identically coloured, and nothing on the row says that
one controls the menu and the other deactivates the category.

Each node also carries up to five badges, including `category_type#1` / `sub_type#N` (D-18) and
three unexplained numbers — `4683 منتجًا ظاهرًا`, `7823 في الفرع` (D-4), `4688 مرتبطًا`.

**What to change.** Single-line rows. Indent 28 px with a visible rail. Delete/edit/add behind one
overflow menu, so the destructive action is not the first thing under the cursor. Drop the
`sub_type#N` badges. Keep one number per node, and make it count products, not rows.

---

### 2.10 The lookups screen's grid is broken by its own image editor

On `/manage/lookups/brands` the logo renders **twice** per row — a 24 px thumbnail under the
`الشعار` header, then a ~190 px editor box containing the logo again at 60 px plus `استبدال`,
`إزالة` and the file path — and that box extends left underneath the `الرابط` and `الاسم` columns.
The result is that the column headers no longer align with anything below them, and each row is
about 190 px tall for four short text fields.

`إزالة` is a red destructive control with no confirmation.

**What to change.** The logo cell is a thumbnail and a small "change" affordance; the editor opens in
a popover or a drawer, not inline in the row.

---

### 2.11 Payments, storefront settings, units — dense copy

The payments screen carries a genuine vocabulary collision: the banner says *"المفاتيح تُكتب ولا
تُقرأ"* (credentials) and the card immediately below says *"عند تكرار نفس **المفتاح** تحت عقدين"*
(a payment-method key). The same word, two meanings, two blocks apart.

The storefront settings screen ends with a five-line amber paragraph about promotions that mentions
*"انتقال واجهة المتجر إلى الإصدار الثاني"* — roadmap language on a settings screen. The `الرمز`
field renders its fixed value as a grey **placeholder**, which reads as empty; a junior will try to
type in it.

The units screen is otherwise good, but prints its usage count (`244 مواصفة`) in red, and shows each
unit code twice — lowercase above, uppercase below.

---

## 3. Workflow gaps — work the interface makes the operator do

**W-1 · There is no bulk category action.** `ProductController::bulk` validates
`action in [activate, deactivate, archive, restore]`; `PlacementController::bulk` validates
`action in [show, hide, feature, unfeature]`. Neither touches categories. Correcting twenty products
means, twenty times: open the form (7.4 screens) → scroll to the picker → scroll inside the 200 px
pane → untick → tick → set the primary → scroll five screens down → Save → scroll back up to see
whether it saved (D-20). **This is the single most expensive gap in the product.**

**W-2 · No bulk field edit of any kind.** Brand, grade, price, sale price, currency, warranty and the
reorder threshold are per-product only. **7,578 of 7,713 products carry `low_stock_threshold = 5`**,
the default, while almost all stock sits between 0 and 3 — which is why the low-stock alert covers
97.5 % of the shop (D-2). There is no way to change that except 7,578 individual form saves.

**W-3 · Select-all selects the page, not the result.** The header checkbox selects the 25 rows on
screen. Nothing offers "select all 627 matching". Any bulk action is therefore capped at 25 per
round trip.

**W-4 · No page jump.** See §2.3.

**W-5 · The language switch is four steps deep** — avatar → `الملف الشخصي` → scroll → select →
Save — and is not in the header, where a bilingual team will look for it.

**W-6 · A product cannot be removed from the form that created it.** There is no `DELETE` route for
products in the route table; archiving exists only as a bulk action on the list. A junior who
creates a duplicate has to leave the form, find the row, tick it and use the bulk bar.

**W-7 · The stock list cannot be filtered by brand or category** — the only filters are
`كل المنتجات`, `كل المخازن` and a code-or-SKU search (which cannot see names, D-8). "Restock all the
Casios" means listing them on Products and then looking each code up on Inventory.

**W-8 · The screen that flags a problem is not the screen that fixes it.** Every quality badge lives
on the list; every fix lives inside the seven-screen form. There is no inline edit and no "fix next"
flow, so working through a flagged list is: open, scroll, fix, save, go back, find your place again.

---

## 4. What a junior will get wrong

The team is not expert and some of it is careless. These are the places where the wrong thing looks
like the right thing.

| # | The trap | What it costs |
|---|---|---|
| **J-1** | **The brand select has no empty option and defaults to `رولكس — Rolex`** (`value="1"`, the first row of `catalog_brands`). `الماركة` is marked required, so there is no "choose one" state — a form left untouched *is* Rolex. `غير محدد — Generic` is the 78th and last option. | A Coach handbag is published as a Rolex: wrong brand page, wrong sitemap entry, wrong filter facet, on a luxury storefront. Silent, because the field is filled. |
| **J-2** | **The role select on `/manage/users` defaults to `مدير (كل الصلاحيات)`.** Granting a new data-entry hire in a hurry grants admin. | Full access — payments, storefront settings, users, order cancellation — handed out by omission. |
| **J-3** | **7,087 products are flagged `بلا تصنيف` with a tooltip telling the operator to pick a Watchizer category** (D-3). A junior working that list as a to-do would file the Brand Fashion catalogue into the Watchizer tree. | The Watchizer taxonomy fills with bonnets and phone chargers; and `غير مضاف` — the number that says what is *not* on this site — collapses to zero and stops being a signal. |
| **J-4** | **A sale price higher than the selling price is accepted silently.** Entering selling 1500 / sale 2000 produced no warning, no refusal, no highlight. The rule is narrated in prose above the fields (*"يُحتسب فقط إذا كان… أقل من سعر البيع"*) and on save the sale is stored empty. | Somebody transposes the two fields, sees the form save cleanly, and the discount they promised a customer simply is not there. AGENTS §2.27 says a rule in the domain is enforced *in the form*; this one is explained next to it. |
| **J-5** | **The stock dialog offers `استيراد` and `مزامنة ERP` as reasons for a hand adjustment**, while the hint directly above says the machine reasons *"تكتبها المنظومة وحدها"*. `تسوية جرد` versus `تعديل يدوي` is never explained either. | A hand-typed count is labelled "ERP sync" in the permanent ledger and in whatever reconciliation the Morabaa connector runs later. The ledger is forward-only; a wrong reason cannot be edited out. |
| **J-6** | **`لون القرص` and `لون السوار` render on every non-watch product** (§2.1). Somebody filling a handbag's strap colour writes into the column the storefront renders as a watch band. | Wrong attribute on the public product page, and the form looked entirely correct while it was filled. |
| **J-7** | **Advancing an order e-mails the customer and nothing on the button says so.** Hiding a product — reversible, silent — gets a full confirmation dialog naming the open carts. Marking an order shipped — irreversible through the UI, sends mail — gets no dialog at all. | A mis-click tells a customer their order shipped. There is no way back through the interface, and the e-mail has gone. |
| **J-8** | **Disabled controls hide their reason in a native `title`.** Cairo's `حذف` on the shipping screen (9 linked addresses) and your own `اسحب` on the users screen both look close enough to enabled and do nothing when clicked; the explanation appears only after about a second of hover, and never on touch. | Reported as "the dashboard is broken" rather than understood as a rule. AGENTS §2.27 asks for the reason **on** the control. |
| **J-9** | **`شجرة التصنيفات القديمة` is selectable in both category pickers.** | New products filed into a dead taxonomy, where nothing on the storefront will ever list them. |
| **J-10** | **The placement screen renders 25 live slug inputs per page**, each about 90 px wide and showing a truncated value, above a banner warning that changing a slug issues a 301. | A stray keystroke silently rewrites a live URL. The banner is a warning, not a guard, and the operator cannot even read the value they are editing. |

---

## 5. Proposals

Ordered by how much each improves the team's day, not by how hard it is. Sizes are estimates for
somebody who knows this codebase.

### Tier 1 — do these first

| # | Fix | Addresses | Size |
|---|---|---|---|
| **1** | **Bulk category assign / remove**, on the products list and the placement list, reusing the existing bulk bar. Add `set_category` / `clear_category` (and a primary flag) to `PlacementController::bulk`, which is already transactional and already reports skipped ids, and put a category picker in the bulk bar. | W-1, W-3 | ~1 day. The largest single return in this list. |
| **2** | **Stop calling not-placed products `بلا تصنيف`.** Give the list a third placement state — `absent` — badged `غير مضاف` in a neutral tone, matching the word Home already uses; keep the red `بلا تصنيف` only for products that *are* on this storefront with no category. Split the quick filter into two entries. | D-3, J-3 | Half a day. Removes a red badge from 7,087 rows and closes the worst junior trap. |
| **3** | **One low-stock rule, one number.** Delete the raw predicate in `InventoryController::lowStockCount()` and the one in the list filter; call `Sql::belowLowStockThreshold()` with the same `in_stock` filter Home uses; report out-of-stock separately in the banner. | D-2 | 1 hour. |
| **4** | **Bulk-set `low_stock_threshold`**, plus a default of `0` (no alert) for new products instead of 5. With 7,578 rows sitting on the default, the alert is noise whatever the query does. | W-2, D-2 | 2 hours for the bulk action. |
| **5** | **Fix the activity log's column widths.** Cap `السجل` (`max-w-[28rem] truncate`, full text in `title`) and move `ما تغيّر` next to `الإجراء` so the two columns an auditor reads are adjacent. | D-1 | 30 minutes. Makes the audit trail exist. |
| **6** | **Row density on the products list.** One line per row; the second-language title on hover or in a drawer; collapse the three common badges into one dot with a count. | §2.2 | Half a day. Turns 5 rows per screen into about 20. |
| **7** | **Wrap the order customer cell in `bdi`** — `<bdi>{customer}</bdi>{phone && <> · <bdi dir="ltr">{phone}</bdi></>}`, using the existing `bidi.tsx` helper. | D-7 | 10 minutes. |
| **8** | **Sticky save bar, scroll-to-first-error, and real `required`.** Drop `preserveScroll` on the product form's create/update calls (or scroll to the flash); pass `required` through `Field`'s `render()` onto the input; add an error summary at the top of the form. | D-19, D-20 | 2 hours. |

### Tier 2

| # | Fix | Addresses | Size |
|---|---|---|---|
| **9** | Brand select gains a `— اختر ماركة —` empty first option; the role select defaults to `إدخال بيانات`. | J-1, J-2 | 15 minutes. Two of the three worst traps in the document. |
| **10** | Add a `dark:` variant to the `destructive` badge — `dark:bg-red-950 dark:text-red-300` — matching what `success` and `warning` already have. | D-6 | 5 minutes. |
| **11** | Live refusal on `sale_price >= selling_price` at the field, with the reason on the control. | J-4 | 1 hour. |
| **12** | Page-jump and first/last in the shared pagination component; every table inherits it. | W-4, §2.3 | 2 hours. |
| **13** | Name search on the stock list — reuse `ProductController::applySearch` — and update the placeholder and the empty state to say what the search covers. | D-8, W-7 | 1 hour. |
| **14** | **The vocabulary sweep, second pass.** `option.value === '' ? option.label : bucketLabel(...)` in both ledger filters; `paymob` case in `methodLabel`; call `methodLabel` at `Orders/Show.tsx:813`; `orders` in `ActivityController::typeLabel`; `express`, `market`, `model_number`, `role`, `user_id` in `changedFieldLabel`; resolve the actor's name in the ledger; translate `promotion_reward`; translate the two English audit values in `CategoryController.php:397`. | D-9, D-10, D-11, D-18 | Half a day for the lot. |
| **15** | Distinct-count the category branch badge; change the header to `أقصى عمق مسموح: 10` or drop it. | D-4, D-5 | 1 hour. |
| **16** | An error page inside `/manage` for 403 and 404 — dashboard chrome, the reader's language, a link home and a line saying who to ask. | D-17 | 2 hours. |
| **17** | Resolve the sign-out flash in the guest locale; route `BRANDING_SUFFIX` through the translation seam (or give the sidebar its own translated string); fix `حرمي` → `حريمي`. | D-12, D-13, D-14 | 30 minutes total. |
| **18** | Hide the dimensions line in `ImageField` when width and bytes are zero, or send the real metadata for stored images. | D-15 | 10 minutes. |
| **19** | A totals block on the order detail — `الأصناف`, `الشحن (محسوب)`, `الإجمالي` — with the difference labelled rather than left to be noticed; qualify `إجمالي المشتريات` (or show ordered *and* delivered). | D-22, D-23 | 2 hours. |
| **20** | Visible `من` / `إلى` labels on all three date-filter pairs, adjacent in one group, plus presets. | §2.8 | 2 hours. |
| **21** | Move the reasons of disabled controls out of `title` into visible inline text next to the control. | J-8 | Half a day across the screens that have them. |

### Tier 3 — the bigger rebuilds

| # | Fix | Addresses | Size |
|---|---|---|---|
| **22** | **Tab the product form**, and make the category picker a searchable single-column tree scoped to the storefront being edited. | §2.1, W-1 | 2–3 days. The difference between a form people dread and one they do not. |
| **23** | Family-scoped colour roles, read from `config/catalog.php` like every other spec field. | J-6, §2.1 | Half a day. |
| **24** | Rebuild the category rows: single line, 28 px indent with a rail, destructive action behind an overflow menu, `sub_type#N` badges removed, one number per node. | §2.9, D-4, D-18 | 1 day. |
| **25** | A confirmation on order advance that names the e-mail it will send and to whom. | J-7 | Half a day. |
| **26** | Fix the lookups row grid — thumbnail in the cell, editor in a popover. | §2.10 | Half a day. |
| **27** | Decide whether `pad_square` should keep reporting the padded canvas as the image's resolution, and whether the rendition skip guard should measure the **source** rather than the padded master. | D-16 | A design decision plus about 2 hours. |
| **28** | An inline-edit or "fix next" flow so a flagged list can be worked through without leaving it. | W-8 | 2 days. |

---

## 6. What is genuinely good

So the list above reads in proportion. These were driven, not read.

**The bulk-hide confirmation is the best thing on the dashboard.** Selecting 25 products and pressing
`إخفاء` produces:

> إخفاء 25 منتجًا عن Watchizer — سيختفي 25 منتجًا من القوائم ومن البحث على هذا المتجر. الإخفاء لا
> يحذف شيئًا ولا يمس المخزون أو الطلبات، ويمكن إرجاعه، لكنه يطبّق على المحدد كله دفعة واحدة.
> **من بينها 7 منتجات موجودة الآن في 10 سلة مفتوحة، ومن يفتح سلته لن يتمكن من إتمام شرائه.**

Consequence first, with real numbers, before the fact. The single-product version goes further and
talks the operator out of it when the reason is stock: *"لو كان السبب نفاد الكمية، الأفضل تركه
ظاهرًا — المتجر يكتب «نفدَ» وحده."* This is exactly what AGENTS §2.27 asks for, and nothing else in
the dashboard is at this standard yet.

**The stock adjustment dialog and the reconciliation screen.** The dialog explains relative versus
absolute with an example (*"مثال: 3 لوصول ثلاث قطع، أو -2 لخصم قطعتين"*), asks for a note framed for
the reader six months later (*"اكتب ما يفسّر الرقم لمن يقرأه بعد شهر"*), and closes with *"لا توجد
طريقة لتغيير رقم بدون سطر في السجل."* The reconciliation screen answers the obvious question before
it is asked, under the heading *"لماذا لا يوجد زر «أصلح»"*. Both treat the operator as somebody who
deserves to understand the machine.

**The role split holds at the screen level.** Driven as data-entry: the settings section simply is
not in the sidebar — no dead links; the order detail offers `تم الشحن` and no `إلغاء الطلب`; the
slug field is disabled with the reason written out in full (*"تغيير رابط المنتج متاح للمدير فقط…
لو الرابط يحتاج تعديلًا اطلب ذلك من المدير"*); the shipping screen is readable but has no `تعديل`,
`حذف` or `إضافة محافظة`; the CSV export button is absent where `export-data` is not held; and a
direct hit on `/manage/users` is refused server-side. The self-revoke guard is real — the `اسحب`
button on your own admin grant is genuinely disabled and carries the reason as its accessible name.

**The read-only banners say plainly what the dashboard will never do.** Customers: *"بيانات العملاء
يملكها المتجر، ولا تُعدّل من اللوحة… لا يوجد جدول عملاء في قاعدة البيانات، فهذا تجميع مبني على
بيانات الطلبات نفسها."* Profile: *"لوحة التحكم الجديدة تقرأ هذا الجدول ولا تكتب فيه إطلاقًا."* The
articles screen admits it is not served yet. An operator is never left guessing whether a thing is
broken or simply not theirs.

**Arabic search works properly.** `كرافتة` returns 66 of 7,713 with every row matching, and the
common `ة`/`ه` misspelling `كرافته` returns the same 66. Brand names match in Arabic — `كوتش`
returns 104 Coach rows. Code search is exact. On the products list, at least.

---

## 7. What could not be measured, and why

**Response times.** Every route under `php artisan serve` returned a near-uniform **~500 ms**, from
`/manage/shipping` (27 rows) to `/manage/storefronts/1/products` (7,713 products with filters and a
cover-image prepare step). That uniformity is the dev server's floor, not query cost — a 27-row page
and a 7,713-row page cannot legitimately cost the same. **No performance claim in either direction
should be made from this pass.** Measuring it needs a real web server (or `EXPLAIN` plus
`CatalogExplainListCommand`, which is the tool this project already has for it).

**The Vite dev server would not serve modules on this machine.** Started twice — once on the default
host, once with `--host 127.0.0.1` — it reported `ready in 471 ms` and then:

```
GET /@vite/client                      200   182631 bytes   16.5 s
GET /resources/js/app.tsx              timed out at 25 s, then at 180 s
GET /resources/js/pages/Auth/Login.tsx timed out at 25 s
```

Nothing appeared in the Vite log — no error, no stack, just no response. The consequence was a blank
white `/manage/login`. **The walkthrough was therefore driven against production assets**
(`npm run build`, clean, 9.72 s) with `public/hot` removed — which is the bundle the team will
actually run, so it is arguably the better target; but hot-reload behaviour and any dev-only
overlay were not exercised.

**Two screens were not driven.** `/manage/media/prune` answers 403 to every role by design
(`MANAGE_MEDIA_PRUNE` sits in `Role::RESTRICTED`, an ability no role holds implicitly), and
`/manage/promotions` is parked in the navigation with `promotion_rules` holding 0 rows. Both were
covered by the previous review through the test HTTP layer.

**Renderer instability on the product form.** The browser extension repeatedly timed out capturing
screenshots of the 4,868 px product form (`Page.captureScreenshot` at 30 s), and twice returned a
blank or tiled image after a scroll while `window.scrollY` reported 0. Reloading always fixed it.
This is reported as a tooling artefact and **not** as a product defect, because it could not be
separated from the capture layer — but it is worth knowing that the heaviest screen in the
dashboard is heavy enough to make a remote-debugging capture time out.

---

## 8. How to repeat this

For whoever runs the next browser pass.

```
# database must be up first — a count that failed because mysqld died is not a measurement
Get-Process mysqld ; Get-NetTCPConnection -LocalPort 3306 -State Listen

# baseline BEFORE anything: per-table digest, per-table row counts, media file list
php artisan core:checksum --set=all --json  > baseline/checksum_all_before.json
#  + a row count for every table in information_schema
#  + find public/Uploads_Images -type f | sort > baseline/media_files_before.txt
#  + the exact rows you are about to touch, printed in full

# serve on 8000 — STOREFRONT_ASSET_BASE points there, anything else means no images
$env:MAIL_MAILER='log'; php artisan serve --host=127.0.0.1 --port=8000
npm run build          # see §7: the Vite dev server does not work on this machine

# accounts: nothing in this application creates a user, so insert directly (bcrypt cost 10)
# then: php artisan manage:role grant <email> admin | data_entry

# afterwards, restore, then prove it PER TABLE — not on the combined sha1 alone
```

The `published_at` miss in §0.2 is the reason for that last line. The combined digest said
"something moved"; only the per-table comparison said *which column of which row*.
