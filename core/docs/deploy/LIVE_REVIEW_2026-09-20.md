# Live review — https://eleganceeg.com/manage, 2026-09-20

Driven in a browser as `admin@watchizer.com` and `data.entry@watchizer.com`, in Arabic and in
English, against the live server. Every number below was read off that server, not from the
workstation, except where a line says it was checked against the code or the local dump.

Media was still uploading during the review, so blank thumbnails are not reported as findings.
**No order status was changed and no order was cancelled** — see the mail note under §0.3.

---

## 0. RESTORATION PROOF

### 0.1 Everything touched, and its state now

| # | What I touched | Change made | State now | Verified by |
|---|---|---|---|---|
| 1 | Product **25324** (`111111111`, your deployment smoke-test product) | Arabic short description overwritten ×2, then restored; 3 saves total | **Restored.** All 15 snapshotted fields + all 4 translations byte-identical to the pre-review values | field-by-field diff against the snapshot taken before the first edit |
| 2 | Product **25326** (`REVIEW-PROBE-001`) | **Created** by me, then stock +5, category 14 added then removed, stock set to 0, then **archived** | Soft-deleted. Absent from every list and every count | `filters[flag]=archived` returns exactly this one row |
| 3 | Shipping — **Giza** | 100.00 → 115.00 → 100.00 | **Restored** to `100.00` | shipping screen re-read |
| 4 | Article **452** (`REVIEW PROBE ARTICLE`) | Created, published, **deleted** | Gone. Articles list back to **0 rows** | blogs screen re-read |
| 5 | Admin account dashboard language | ar → en → **ar** | **Restored** to `ar` | profile re-read |
| 6 | Your own browser session (`Maikel Khalaf`) | Signed out so I could use the two review accounts | You will need to sign in again | — |

### 0.2 Headline counts — before and after

| | At start | Now |
|---|---:|---:|
| Products in catalogue | 7,714 | **7,714** |
| Active products | 7,713 | **7,713** |
| Orders | 9 | **9** (all 9 statuses unchanged: 5 pending, 2 processing, 2 cancelled) |
| Articles | 0 | **0** |
| Banners (both shops) | 0 / 0 | **0 / 0** |
| Out of stock | 2,577 | **2,577** |
| Low stock | 4,946 | **4,946** |

### 0.3 What cannot be put back, and why

Three kinds of row are append-only by design. All of them are mine, all are attributable, and
none of them touch your data:

1. **2 `inventory_movements` rows**, both on product 25326 — my own product, now archived:
   `express +5 → 5` at 01:46:01, and `express −5 → 0` at 02:16:44. A ledger movement is history;
   `inventory:verify` still reports **all 15,674 checks agree**.
2. **19 `core_activity_log` rows** under `Admin Watchizer` (user 5). Breakdown:
   `catalog_products` updated 6 / created 1 / deleted 1 / adjusted 2, `storefront_product` updated 2,
   `core_blogs` created 1 / updated 1 / deleted 1, `shipping_cities` updated 2,
   `core_user_preferences` updated 2.
3. **One soft-deleted `catalog_products` row, id 25326.** That is exactly what the dashboard's own
   delete does. To remove it completely:
   `DELETE FROM catalog_products WHERE id = 25326;` — first clear its 2 ledger rows and its
   `storefront_product` rows, or leave it; it costs nothing where it is.

`updated_at` / `updated_by` also moved on product 25324 and on shipping city Giza. Their **content**
is identical; only the stamp differs.

### 0.4 The mail check you asked for — and why no order was touched

`OrderFulfilment::advance()` calls `OrderMailer::statusChangedNow()`, which resolves the recipient
from `orders.guest_email` / `users.email` and sends **inline, in the request** (`QUEUE_CONNECTION=sync`,
by design, per the runbook). Order 8's customer is a real person with a real Gmail address.
`cancel()` does the same and additionally returns stock to the ledger.

I could find no way to read the server's `MAIL_*` configuration from the browser, and nothing in
the dashboard surfaces it. So I did not advance or cancel anything.

**What I would have done instead**, and what I recommend you do: create one order against an address
you own, then walk `pending → processing → shipped → delivered → completed` on it. Alternatively add
a one-line read-only diagnostic (`php artisan tinker --execute="echo config('mail.default');"`) to
the runbook's smoke test, so step 9 stops being "send a real e-mail and hope".

**A related gap worth naming:** the order screen has no way to move an order forward *without*
notifying the customer. Every correction of a mis-clicked status sends another e-mail.

---

## 1. WHAT IS BROKEN

### B1 🔴 The login screen — the first thing the whole team sees — is in English, left-to-right

`APP_LOCALE` on this server is **`en`**, not `ar` as the runbook §2.4 specifies.

- **Repro:** open `https://eleganceeg.com/manage/login` signed out. Title "Sign in", fields "Email
  address" / "Password", sidebar-less LTR layout.
- **Why:** `SetDashboardLocale` only applies a locale for an *authenticated* user
  (`bootstrap/app.php:32`); a guest falls through to `config('app.locale')`.
  `HandleInertiaRequests::share()` reads `app()->getLocale()`, which returned `en` on the live host.
- **Fix:** set `APP_LOCALE=ar` in `.env`, then `php artisan config:clear && php artisan config:cache`.
- The runbook's own smoke test step 1 says "the login screen renders, **in Arabic**". It does not.

### B2 🔴 Signing out shows an Arabic message on an English page

Same screen. Sign out → the page is English, the confirmation reads «تم تسجيل الخروج.». The flash
string is built while the *old* locale is still active, then rendered under the new one.

Same bug on the language switch: switching to English lands you on an English page whose
confirmation says «تم حفظ تفضيلاتك.». Reproduced both ways.

### B3 🔴 The orders storefront filter returns nothing, on either shop

- **Repro:** `/manage/orders` → filter by shop → Watchizer → **0 of 9 orders**. Brand Fashion → **0**.
  Both options are offered in the dropdown.
- **Why it is worse than an empty filter:** the order detail screen states the opposite rule in
  plain Arabic — «عمود المتجر أُضيف في هذه الموجة، فالطلبات الأقدم منه لا تحمله، **تُعامل هذه الطلبات
  على أنها تابعة للمتجر الأساسي (Watchizer)**». The screen says these orders belong to Watchizer; the
  filter says none of them do. `orders.storefront_id` is NULL on all 9, and the filter does not
  apply the NULL-is-primary rule the detail screen describes.
- An operator filtering "Watchizer orders" concludes there are none.

### B4 🔴 The activity log records the wrong thing about a product edit, twice, and never records the edit

This is the screen that answers "who changed this?", so it matters more than its size suggests.

- **Repro:** edit any product's Arabic description → Save. Two rows appear in `/manage/activity`,
  one second apart, both saying `is_active: 0 ← ` (empty right-hand side). Neither mentions the
  description. Reproduced three times; the third save produced exactly **one** `PUT` on the wire, so
  the duplication is server-side.
- **Cause 1 — the phantom change.** `ActivityLog::same()` compares the DB value `0` (int) with the
  payload value `false` (bool): `is_numeric(false)` is `false`, so it falls through to
  `(string) 0 === (string) false` → `"0" === ""` → not equal. Every boolean column sitting at `0`
  logs a change on every save, and renders with a blank "to" value.
- **Cause 2 — the duplicate.** `ProductController::saveStorefrontSide()` calls
  `ProductWriter::update()` a **second** time (line 1606) to re-derive the family after placement.
  That call writes its own audit row.
- **Cause 3 — the silence.** `ProductWriter::update()` audits only `catalog_products` scalar
  columns. `writeTranslations()`, `writeSpecs()`, `writePivots()` and `writeImages()` all run
  *after* the `ActivityLog::record()` call and log nothing. Because `record()` skips an UPDATE with
  no diff, a translation-only edit would log **nothing at all** if the phantom were fixed alone.
  **Fix both together**, or fixing one makes the other worse.
- Placement edits *are* logged correctly (`storefront_product/updated`), and the shipping log is
  exemplary: `shipping_cost: 100.00 ← 115.00`.

### B5 🟠 "Low stock" means three different things and gives two different numbers

| Where | Rule | Answer |
|---|---|---|
| Products list, quick filter «مخزون منخفض» | `(express + market) <= threshold` (`ProductController.php:886`, inline SQL) | **7,524** |
| Inventory screen, view «مخزون منخفض» | `is_active = 1 AND in_stock = 1 AND (express+market) <= threshold` (`Sql::belowLowStockThreshold`) | **4,946** |
| Inventory row badge «منخفض» | `(express + market) <= threshold` (`InventoryController.php:207`) | fires on all **2,578** out-of-stock rows |

Two screens use the same Arabic words and disagree by 2,578. And the badge contradicts the filter on
the *same screen*: every out-of-stock row is badged «منخفض», and clicking «مخزون منخفض» does not
return any of them. A product with threshold 0 — which the product form explicitly describes as
"leave it zero if you don't want an alert for this product" — is still badged low.
`Sql::belowLowStockThreshold()` exists as the shared helper; two of the three call sites do not use it.

### B6 🟠 The activity log cannot find a record by the name it is showing you

- **Repro:** in English, `/manage/activity` shows rows labelled `REVIEW PROBE PRODUCT - DELETE ME`.
  Type `REVIEW` in the search box → **0 results**. Type the Arabic title → found.
- The displayed label follows the current locale, but the search matches only the stored Arabic
  label (`ProductWriter::labelFor()` picks `FIELD(locale,'ar','en')`). Searching for what is on the
  screen returns nothing.

### B7 🟠 Validation errors show raw field keys to the operator

On the product form: «حقل **title.ar** مطلوب.», «حقل **title.en** مطلوب.», and
«**storefronts.1.category_ids** — اختر تصنيفًا واحدًا على الأقل…». The price error on the same screen
is correct («سعر البيع — حقل سعر البيع مطلوب»), so the mechanism works; these three just have no
label mapped.

### B8 🟡 Error counts do not clear as you fix them

Submit the create form incomplete → tab badges show «الاسم والوصف ②» «السعر ①» and a red summary.
Fill the fields and the badges, the summary and the red pill all stay until the next submit.

---

## 2. STILL REACHING FOR THE OLD SYSTEM

### 2.1 The good news first — verified, not assumed

- **No URL, asset or database reference to `dash.watchizereg.com` anywhere.** I pulled the Inertia
  props of **24 screens** and grepped every one. Two hits for `watchizereg.com` exist and are both
  correct: the `domain` column of storefront 1 on the Storefronts list and its edit screen — that is
  the public shop's own domain, not the legacy dashboard.
- **Product images resolve to `https://eleganceeg.com/Uploads_Images/…`** — all 25 covers on a
  sampled page. This is the failure the runbook §2.4 warns hides for months. It is not present.
- **Nothing was lost by not migrating the legacy content modules.** In the database that was
  deployed, `blogs` = 0, `blog_translations` = 0, `banner_homes`/`banner_sides`/`banner_bottoms` = 0,
  `offers` = 0. The empty Articles and Banners screens are honest, not broken.

### 2.2 The bad news — eight shipped strings send the operator back to the old dashboard

Every one of these is live text on a screen, in both languages:

| # | Screen | String | Severity |
|---|---|---|---|
| 1 | **Users & roles** — granting a role to an unknown e-mail (`users.account_not_found_hint`) | "There is no account with that email. Accounts are created on the storefront **or in the old dashboard**." | 🔴 blocks onboarding |
| 2 | **Users & roles** — page notice (`Users/Index.tsx:153`) | "…the accounts table is shared with the storefront and **the old dashboard**… The account is created on the storefront **or in the old dashboard**, then its permissions are granted here." | 🔴 |
| 3 | **Users & roles** — empty search result (`Users/Index.tsx:282`) | "No results. The account is created on the storefront **or in the old dashboard**." | 🟠 |
| 4 | **Users & roles** — the `legacy_type` column header (`Users/Index.tsx:406`) | «علم **الداشبورد القديم** — لا تقرأه اللوحة الجديدة» | 🟡 |
| 5 | **Profile** — identity notice (`profile.identity_notice`) | "…they are changed on the storefront **or in the old dashboard**." | 🔴 |
| 6 | **Profile** — password note (`profile.password_note`) | "The password is changed on the storefront **or in the old dashboard**. This dashboard never writes to the accounts table." | 🔴 |
| 7 | **Home** — the "Orders today" tile hint (`home.stat_orders_today_hint`) | "From the shared table (includes orders placed **in the old dashboard**)" | 🟠 |
| 8 | **Login** — the footnote (`auth.staff_only`) | "The same accounts used in **the current dashboard**." | 🟠 |

Numbers 1, 2, 5 and 6 are not wording problems. They describe a **workflow that no longer exists on
this server**, and they are the reason §3 below opens with two jobs the team genuinely cannot do.

### 2.3 One more, in a different category

**Home, "Orders today" tile** — the hint promises the count "includes orders placed in the old
dashboard". On a standalone database it cannot and must not. The sentence is now false as well as
stale.

### 2.4 Verdict on "can the client be told the old dashboard is finished?"

**Not yet — but the gap is small and it is about accounts, not catalogue.**

The catalogue, orders, stock, customers, shipping, categories and placement are genuinely complete
here. What still needs the old system is exactly one thing: **anything that writes the `users`
table** — creating a team account and changing a password. Close that (§5, item 1) and the answer
becomes yes.

---

## 3. WHAT THE TEAM CANNOT DO HERE THAT THEY COULD DO THERE

Walked as the jobs, in the order the team does them.

### 3.1 🔴 Nobody can change their own password, and a forgotten password is a lockout

There is no password field anywhere: the Profile screen shows name, e-mail and phone **read-only**
with a lock icon, and the Users screen is grants-only by design (`AGENTS §2.18` — `users` is a legacy
table core may not write). The runbook says it plainly: *"There is no password-reset screen on the
dashboard."* Both screens tell the operator to go to the old dashboard, which is unreachable.

Today the only route back for a locked-out operator is you, editing a row by hand.

### 3.2 🔴 An admin cannot onboard a new team member

`manage:role grant` requires the account to already exist in `users`. The screen's own error says to
create it in the storefront or the old dashboard. On this host the storefront is not present either.
So adding a person is a shell job for you, every time.

### 3.3 🟠 An article can be written but never reaches a customer

The Articles screen says so itself: «المقالات لا تظهر على الموقع بعد… صفحة المقالات على المتجر لم
تُبنَ». That is half true and worth correcting: the live storefront **does** have `/blogs` and
`/blog/[name]` pages (`Frontend-next/app/(main)/blogs`, `…/blog/[name]`), and they read `/all_blog`
from the legacy API. The new dashboard writes `core_blogs`, which nothing reads. Nothing was lost
(legacy `blogs` is empty), but the capability is not there.

### 3.4 🟠 Same for banners

`storefront_banners` is empty on both shops and no consumer reads it. The legacy banner tables are
empty too, so again nothing was lost — but "put a banner on the home page" is not a job that can be
completed end-to-end here.

### 3.5 🟠 Advancing an order always e-mails the customer

No "update without notifying" option. See §0.3.

### 3.6 🟡 Bulk archive exists on the server but not in the UI

`products.bulk` validates `activate, deactivate, archive, restore, set_threshold`. The selection bar
renders only **activate / deactivate / low-stock threshold**. Archiving a batch — the obvious cleanup
after a bad import — has to be done one product at a time from each form.

### 3.7 🟡 The bulk category tools are real, but filed where nobody will look

`set_category` / `clear_category` work correctly (I added and removed a category on a selection and
both verbs behaved, leaving `primary_category_id` untouched as intended). They live on
**«العرض والترتيب» / "Display & order"**. An operator told "fix the categories on these twenty
products" will look under Categories or Products. Neither has them.

### 3.8 🟡 A governorate cannot be switched off

The shipping screen admits it: «لا يوجد تفعيل/تعطيل، لا ترتيب ولا مناطق». Stopping delivery somewhere
means deleting the row, which is refused once any customer address points at it (Cairo, 9 addresses).

### 3.9 Not a gap, but say it to the client: payments

No provider is configured and the runbook deliberately leaves the Paymob keys unset, because the
client's Paymob still calls back to the legacy host. Consequence on screen: the orders queue's
provider and method filters have empty option lists and the settlement CSV has nothing to export.
That is correct for this deployment — it is not correct to call payments "migrated".

---

## 4. WHAT IS WORSE THAN IT SHOULD BE

Bluntly, and worst first.

### W1 🔴 The Products screen shows the wrong shop's catalogue

With **Watchizer** selected, the list reads **7,714 products**. Of the 25 rows on page 1, **24 are
not on Watchizer at all** and the 25th is hidden. **Zero** of the first 25 rows are live on the shop
whose name is in the selector. 7,015 of the 7,714 are absent from Watchizer entirely.

The shared catalogue is a correct design decision (D3). Presenting it as "Products — Watchizer" is
not. Switching the selector to Brand Fashion changes two small badges per row and nothing else, which
makes the selector look broken even though it is working.

**What I would change:** default the list to products placed on the selected shop, with an explicit
"show the whole shared catalogue (7,714)" escape. The `absent` quick filter already exists and
already works — it is the default that is wrong.

### W2 🟠 The category row menu is a 1,135-pixel wall of text

Open the «…» menu on any category as a data-entry operator. Two of the three items are disabled, and
each one repeats **the same 40-word explanation** — a paragraph already displayed in the banner at the
top of the page. Measured: the menu is **1,135 px wide** in a 1,740 px viewport and holds 521
characters. For a three-item context menu.

The gating itself is right and the banner wording is good. Put «للمدير فقط» on the item and let the
banner do the explaining.

### W3 🟠 Imported titles destroy the row rhythm

Brand Fashion titles run to 200+ characters ("Jacop&Philipp Elegant Silk Sleep Bonnet for Women, 1
Piece Satin Sleep Fabric, High-Tech Care, Can Be Worn While Sleeping, Plus Sponge Headband for
Heatless Curling…"). They wrap to six lines, so a page of 25 scrolls for ages and rows of wildly
different heights are hard to scan. Clamp to two lines with the full title on hover.

### W4 🟠 The flash banner pushes the page down and makes you misclick

Every successful action inserts a banner above the heading, shifting everything ~70 px. I aimed at a
row checkbox three times during this review and hit empty space or the wrong row each time. On the
placement screen the bulk bar sits directly under that banner, and on the articles list the delete ×
is in the shifted row. Overlay the flash, or reserve its height.

### W5 🟡 Filter buttons clip their own labels

"Active and inacti", "Search by name, internal code or" — fixed-width controls truncating mid-word,
in both languages.

### W6 🟡 Lookup dropdowns are Arabic-first even in English

In the English UI the brand field reads «رولكس — Rolex» and the grade list «فاخرة — Luxury». The
category tree localises correctly (Watches / GMT / Field), so the pattern is inconsistent as well as
wrong. Separately: 80 brands in a plain `<select>` with no search, in a catalogue that is about to
grow.

### W7 🟡 Two brands have no Arabic name

"Tory Burch" (76) and "Pinko" (77) appear untranslated in the Arabic brand picker.

### W8 🟡 The Articles form is the odd one out

No sticky action bar — the Save button is at the bottom of a two-and-a-half-screen form, while the
product form has a proper sticky bar. And the body is a bare `<textarea>`: no headings, no bold, no
links, no inline images. That is thinner than the legacy blog editor.

### W9 🟡 It still calls itself Watchizer

`APP_NAME` is `Watchizer`, the browser tab says "… - Watchizer Core", the sidebar carries the
Watchizer logo, and the footer reads "Watchizer · لوحة التحكم" — on `eleganceeg.com`, for a dashboard
that runs two shops. The runbook §2.4 specifies `APP_NAME=Elegance`.

### W10 🟡 The English UI renders in an Arabic font

`body { font-family: "Noto Sans Arabic", "IBM Plex Sans Arabic", Tahoma, … }` — unchanged in English
mode, so Latin text renders from an Arabic face's Latin subset.

### W11 🟡 Shipping goes read-only for data-entry with no explanation

As data-entry the Add button, the Actions column and every edit control simply vanish. No message.
The Categories screen handles the identical situation beautifully («تعديل شكل الشجرة للمدير فقط…
لو الشجرة تحتاج تعديلًا اطلب ذلك من المدير») — apply the same treatment here.

### W12 🟡 "Needs review" is on almost everything, so it means nothing

`machine_ar` = **7,087 of 7,714 (92%)**, `missing_data` = 7,192. Nearly every row carries an amber
«يحتاج مراجعة (N)» badge, which trains the team to ignore it. A worklist that includes everything is
not a worklist.

---

## 5. WHAT WOULD MAKE THE BIGGEST DIFFERENCE

Ordered by how much it improves their day. Costs are rough developer-hours.

| # | Change | Why it is first | Cost |
|---|---|---|---|
| **1** | **A password screen and an "invite a team member" path** (§3.1, §3.2, §2.2). One screen, two verbs, writing `users.password` and inserting a `users` row — the only two writes to that table this application needs. Retire the eight "old dashboard" strings with it. | It is the single thing standing between you and telling the client the old dashboard is finished, and the only failure here is a person locked out of their job. `AGENTS §2.18/§3` forbids core writing `users` — that rule exists because legacy was still live. On this standalone host it no longer is, so this is a decision to take, not a rule to break quietly. | **6–10 h** + your call on the rule |
| **2** | **Default the Products list to the selected shop** (W1) | Every catalogue task starts on this screen, and today it opens on 24 rows out of 25 that do not belong to the shop in the selector. Everything else on the list works. | **2–3 h** |
| **3** | **Fix the activity log's three product defects together** (B4) | It is the answer to "who broke this?", and right now it answers with a phantom, twice, and stays silent about the real edit. All three causes are one afternoon: a `same()` bool/int arm, suppress the second writer's audit, and audit translations + specs. | **4–6 h** |
| **4** | **One definition of "low stock"** (B5) | 7,524 vs 4,946 for the same words on two screens, and a badge that contradicts the filter beside it. Route all three through `Sql::belowLowStockThreshold()` and add `is_active`/`in_stock` to the badge. | **2 h** |
| **5** | **Set `APP_LOCALE=ar` and `APP_NAME=Elegance`; fix the locale-lagging flash** (B1, B2, W9) | Two `.env` values and a `config:cache` buy the Arabic front door the runbook already specified. The flash fix is choosing the new locale before building the message. | **1 h** + **2 h** |
| **6** | **Make the orders storefront filter apply the NULL-is-primary rule** (B3) | It is the only filter in the product that lies, and the order detail screen already states the rule it should be using. | **1–2 h** |
| **7** | **Move bulk category onto the Products list, and add bulk archive** (§3.6, §3.7) | The bulk category work is described in your own code as *"the single most expensive gap in the product"* — it was built, and then filed on a screen nobody doing that job will open. | **3–4 h** |
| **8** | **A "do not notify the customer" checkbox on the order status control** (§0.3, §3.5) | Every mis-clicked status currently costs a real e-mail to a real customer, and there is no way to correct one quietly. | **2–3 h** |
| **9** | **Trim the disabled-item explanations; clamp long titles; overlay the flash** (W2, W3, W4) | Three small changes that between them fix the three things that made the dashboard feel unfinished while I was using it. | **3–4 h** |
| **10** | **Decide what Articles and Banners are for** (§3.3, §3.4) | Either wire `core_blogs` / `storefront_banners` into the storefront, or mark both screens "not in service" instead of letting someone write an article that goes nowhere. The honest notice is already there; the decision is not. | **decision first**, then 8–16 h |

**Below the line, worth doing when convenient:** the raw field keys in validation errors (B7), the
activity search missing the English label (B6), error badges that do not clear (B8), Arabic-first
lookup labels in English (W6), the two brands with no Arabic name (W7), the Articles form's sticky
bar and editor (W8), the Latin font stack (W10), the shipping permission notice (W11), and a
narrower definition of "needs review" (W12).

---

## Appendix — what was exercised, and what held up

Worth recording, because most of it is good and the list above is by definition the parts that are not.

- **Every list filter bites.** Products (family, active, in stock, visible, featured, category
  branch, and all 10 quick filters), orders (status, date range, search by number and by phone),
  inventory (view, bucket), activity (user, action, subject type, date), customers (phone full,
  partial and without the leading zero, name, e-mail). Sorts reverse the first row. Unrecognised
  filter values are dropped rather than accepted. The single exception is B3.
- **Arabic search normalisation works.** `ساعه` and `ساعة` both return 4,675. `رولكس`, `Rolex` and
  `rolex` all return the same 125. `zzzznomatch` returns 0.
- **Authorisation is enforced on the server, not by the menu.** As data-entry, 9 admin URLs answered
  **403** (users, activity, storefronts, storefront edit, payments, promotions ×2, settlement export,
  media prune) and 13 permitted ones answered 200. The order screen showed "تم الشحن" and no cancel
  control, exactly as `AGENTS §2.7` specifies.
- **The prevention-over-warning work is real and visible.** Root-only placement warns live as you
  tick a top-level category; visibility is gated on an image and a category; the archive dialog names
  the consequence and what survives; the article delete dialog names what is destroyed; the save
  button disables itself when nothing is dirty; the product form tells you it saves every section at
  once.
- **The inventory reconciliation panel is the best screen in the product.** It runs `inventory:verify`
  live (**15,674 checks over 7,715 products and 110 variants**, all agree), prints the raw output, and
  explains in a box why there is deliberately no "Fix" button.
- **CSV exports work:** products 7,715 rows × 22 columns (1.7 MB), orders 10 × 12, headers following
  the UI language.
- **404s are handled properly** — in-shell, in Arabic, with an explanation and a way back.
- **No console errors** were captured on any screen visited.
