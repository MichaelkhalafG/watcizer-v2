# Eight changes for Watchizer and Brand Fashion - plan for approval (2026-11-24)

Status: PLAN ONLY. Nothing is built. Michael approves, then the build starts in the order of section 10.

## 0. Preconditions, checked 24 Nov

| Check | Result |
|---|---|
| Branch | updates, working tree clean |
| In sync with origin/updates | yes: HEAD = origin/updates = f82073c, 0 ahead, 0 behind |
| Production | f82073c's core code plus the Elegance page (live: / and /ar 200 with their headers, read-only checks 22 Nov) |
| Day record | read (RUN_2026-11-17.md, D1-D48, RESUME 1-8) |
| Offer rule, wheel, promotions, e-mail sending, SEO gate | read (sections below name the files) |
| Weekly SEO reports | NOT on this machine: they are written on the server (storage/app/seo-reports). facts-20261124.sh copies the newest four |
| Search Console | on this machine: Watchizer coverage of 22 Oct and 14 Nov and performance of 29 Oct only; nothing for Brand Fashion |

Three things to know before reading on:

1. **The Elegance deploy is not recorded as finished.** Run a stopped at step 8; the read-only finish script went to Michael on 22 Nov. The day record does not say whether it ran. Nothing here depends on it.
2. **Item 7 reopens the hide-from-search topic** that was closed on 14 Nov. This plan touches it only as item 7 asks: what customers see on the site. The rule that decides what search engines see is unchanged.
3. **Item 8's "every product's markup carries its brand" conflicts with the 14 Nov rule for restricted Rolex products**: their markup names no brand on purpose. Decision D11.

No item needs an .env value, a new paid service, a DNS change or a new package, with two conditions:
- The .xlsx template and upload need PHP's zip and XML extensions on production. Line 0 of the facts script shows whether they are there; locally they are.
- The sort data step cannot be keyed safely until Michael picks the signal that marks a 0 as set on purpose (D6). Until then, that step is a STOP.

## 1. Abandoned-cart e-mail with a discount code

**What exists today**
- **Carts are on the server:** `carts` / `cart_items`, one per shop, owned by an account (`user_id`) or by a guest token (App\Compat\CompatCart).
- **A guest cart carries no e-mail.** A guest's address reaches core only with the order (`orders.guest_email`).
- **Bulk sending exists:** App\Domain\Notifications\BulkMailer, MailBudget, `bulk-mail:drain` every 5 minutes.
- **Unsubscribe exists:**
  - `core_marketing_optouts`, per shop, for good;
  - the HMAC link `/unsubscribe/{send}/{signature}`: GET shows a button, POST unsubscribes, and mail apps send that POST as their one-click unsubscribe;
  - the List-Unsubscribe header.
- **Personal codes exist only on the wheel:** a spin row holds the customer's own code and points at one shared rule per set of terms (Wheel::ruleFor). The cart and the order check it and mark it used.

**What I will build**
- **A personal-code store for both items 1 and 5:** `core_personal_codes`.
  - Columns: code (unique), shop, promotion rule, bound e-mail (lower case), purpose (cart_reminder / campaign) with its source id, expires_at, used_order_id, used_at.
  - One shared rule per set of terms, made the way the wheel does it.
  - **The code is checked by the same coupon path as a wheel code:** bound e-mail = the order's e-mail, not expired, not used. It is marked used inside the order's transaction.
  - **The one-offer rule is unchanged, because the engine is unchanged:** a cart with a campaign item rejects the code with the existing status and message.
- **Guest e-mails at checkout:** a new public route, `POST /api/v2/{shop}/checkout/contact`.
  - It takes the e-mail and the consent tick, ties them to the guest cart, and is throttled like the other storefront POST routes.
  - Turnstile is not changed.
  - Both checkouts call it when the e-mail field is valid and the box is ticked, debounced. It is stored in `core_checkout_contacts`.
- **Settings per shop:** `core_cart_reminder_settings`, a card on the dashboard.
  - On/off; delay in hours (default 48).
  - The discount: percent, cap in EGP, days valid.
  - The audience: team or customers, team by default, as the weekly e-mail is today.
  - The team addresses.
- **The run:** `cart-reminders run`, hourly. It picks carts that:
  - hold items, idle for at least the delay and no longer than 7 days;
  - have a known e-mail: the account's, or the checkout contact's;
  - have no order from that e-mail or account on that shop since the cart's last activity;
  - belong to an address that is not unsubscribed, has had no reminder for this cart, and none at all in 30 days;
  - meet the consent rule (D1).

  Each pick writes a `core_cart_reminders` row (cart_id unique), queued as bulk mail.
- **At send time everything is checked again.** The cart must be non-empty, with no order since and no unsubscribe; if any check fails, the e-mail is not sent and the reason is recorded. The code is issued at send time, not at planning time.
- **The e-mail** is Arabic with English beneath, like the other e-mails:
  - "You left something in your cart";
  - the items with image and today's price;
  - a button back to the cart (D4);
  - the code, its percent, cap and expiry date;
  - "not valid on items already on offer";
  - a one-click unsubscribe link and the List-Unsubscribe header.
- **The dashboard** gets a "Cart reminder" card per shop: the settings, a test send to the signed-in staff address, and counts of planned, sent, codes used, skipped (with reasons) and unsubscribed.

**Files**
- New: app/Domain/Notifications/CartReminders.php, app/Domain/Promotions/PersonalCodes.php, app/Mail/CartReminderMail.php, resources/views/emails/cart-reminder.blade.php, the command, a migration with the four tables, the checkout-contact controller and route, the dashboard card.
- Changed:
  - the coupon lookup that knows wheel codes (CouponStatus / PromotionEngine call sites), and the order's use of a code;
  - BulkMailer (a new kind);
  - routes/console.php;
  - both storefronts' checkout: the field, the tick, the call.

**Data steps:** none. These are new tables with nothing to change.

**Risks**
- **Someone types a stranger's e-mail at checkout.** That stranger gets at most one e-mail in 30 days, with an unsubscribe link. The consent tick, the throttle and the 30-day limit keep this small.
- **Carts abandoned on purpose to win a code.** Mitigated by one per address per 30 days, the code bound to the address, and the cap.

## 2. The wheel: dashboard options the shops do not support

**What exists**
- **Seven prize types** (WheelPrizes::TYPES): percent_discount (with cap, category, minimum and the order/cheapest mode), fixed_discount, free_shipping, none, referral, gift, and offer (a builder offer, since 19 Nov).
- **Both shops' wheels** (src/lib/wheel.js, identical in both) draw a segment's text from its type:
  - `segmentLabel` and `headline` have branches for free_shipping, none, gift, fixed_discount, referral and percent;
  - **an `offer` segment falls into the percent branch**, and its value is null, so the disc shows an empty big line. This is the first candidate for "some prizes do not appear".

**What I will do**
- **First, the audit.** On a scratch copy loaded with production's prizes (the rehearsal export), I put every type and every option against six checks:
  - the dashboard accepts it;
  - the disc shows it;
  - the reveal says it;
  - a code is issued;
  - the cart applies it;
  - the order checks it.

  This runs on both shops, with a browser pass for the disc and reveal and a test for each code path.
- **STOP point:** the findings table goes to Michael before any change, as the brief asks.
- **Then, per finding,** either the shops are made to support it (both wheels, the code path, the conditions lines) or the dashboard stops offering it (the writer refuses it, and the choice disappears from the screen).

**Files:** core app/Domain/Wheel/*, app/Http/Controllers/Manage/WheelController.php, resources/js/pages/Manage/Wheel/Index.tsx; both shops' src/lib/wheel.js, wheelCodes.js, Components/Wheel/*.

**Data steps:** only if a prize on production uses an option that is removed. Michael decides each one: re-author it on the dashboard (recommended) or run a four-pass step keyed by prize id plus its type and value.

**Risk:** a live prize changes what it gives. The audit names every live prize affected before anything changes.

## 3. Product sort order: 1 by default, 0 for featured

**What exists**
- **The column:** `storefront_product.sort_order`, default 0. Shops list by `sort_order` ascending, then id.
- **Writers that put 0 when nothing is given:**
  - PlacementWriter (line 243);
  - the product form (ProductController lines 2110 and 2196);
  - the importers (CataloguePrepare and the BF legacy importer).
- **The placement screen's reorder** numbers products 0, 1, 2 and so on (PlacementWriter line 376), so the first product becomes 0.
- **On the 17 Nov test copy:** 0 is the value of nearly every row.
  - Watchizer: 1,007 visible, non-featured rows at 0, and 4 featured.
  - Brand Fashion: 1,011 visible non-featured rows at 0.
  - Production's figures come from facts-20261124.sh, section 1.

**What I will build**
- **A migration:** the column default becomes 1.
- **Every writer above** writes 1 when no value is given. The product form shows 1 for a new product.
- **The reorder** starts at 1, so it never makes a product "featured" by position (D7).
- **The compat feed's sort mapping** (CompatCatalog line 488) is checked against the old shape so the legacy JSON does not move.

**Data step:** `catalog:sort-default`, four passes: dry run, apply, a second run that changes nothing, undo.
- **Keyed by** (shop, product id).
- **Guard:** a row changes only when its sort is 0 now, it is not featured, and it does not carry the signal Michael picks (D6).
- **Never touched:** a row with any other value.
- **The undo** puts back exactly the rows it changed, from its own record. The counts are reported before anything is applied.

**Risk:** the order of every listing changes for products that are 0 today. That is the point of the change. The dry run shows the counts per shop first.

## 4. The discount code test ("Try it")

**What exists:** the offer form's test cart (Promotions/Form.tsx, the preview at `POST /manage/promotions/preview`) evaluates the draft offer inside a rolled-back transaction.
- **Why it looks broken:** any refused request (a 422) is read as if it were a preview, and every error is swallowed. The panel then shows nothing, or breaks.
- **Why it is hard to use:** products can only be picked from the search, and the result is a dense table.
- I reproduce the failure with a test before changing anything.

**What I will build:** a short "Test this code" panel.
- **Inputs:** products (search, or paste one or more SKUs), quantity, payment method, and optionally a phone or an account e-mail (for the first-order rule and personal codes).
- **The answer, in plain words in both dashboard languages:**
  - what the customer pays: lines, discount, shipping, total;
  - why the code applies or not, in the same sentences the shopper reads (CouponStatus messages and PromotionSkips);
  - any server refusal, shown as words instead of nothing.
- **What it tests:** the form as typed. On a saved code, it can also test the code as saved.

**Files:** Promotions/Form.tsx (the panel moves into its own component), PromotionController::preview (SKU lookup, account e-mail, typed errors), lang files.

**Data steps:** none.

## 5. E-mail campaigns: steps, audiences, lists, consent

**What exists**
- **Today's screen** (ReEngagement) runs one automatic weekly e-mail per shop:
  - customers absent 30+ days;
  - products picked by the system;
  - a preview to the team, then sent 24 h later unless paused;
  - the audience is team or customers.
- **How mail is sent today:**
  - **Each shop has its own Hostinger mailbox over SMTP** (StorefrontMailer). Hostinger allows 1,000 messages per mailbox per rolling 24 hours.
  - **Our daily cap per mailbox is 800,** of which 200 are reserved for order e-mails (MailBudget, on the mailbox row). Bulk mail therefore gets at most 600 a day per shop.
  - **Bulk mail goes through its own outbox channel,** sent by `bulk-mail:drain` every 5 minutes, 20 per pass (MAIL_BULK_PER_RUN, default 20). That is at most 240 an hour and 600 a day per shop.
  - **Order e-mails never queue behind bulk mail:** they use their own channel, are sent at once in the request, and are not counted against the bulk allowance.
- **Consent:** neither shop asks for marketing consent at sign-up or checkout (searched both storefronts: no consent, newsletter or marketing field). **So the number of addresses with consent today is 0.**

**What I will build**
- **The page** "E-mail campaigns" (the weekly automatic e-mail stays as a card on it). Each campaign is a step-by-step flow:
  1. **Audience:** ready filters that can be combined, over every address we hold: accounts, guest order e-mails and checkout contacts.
     - No order in the last 30 / 60 / 90 days.
     - N orders or more.
     - Spent EGP X or more.
     - Bought a brand or a category.
     - Signed up but never ordered.
     - By shop.

     The live count is shown, minus unsubscribed addresses and minus addresses without consent (D2).
  2. **Message:** subject and text in Arabic and English, and optional product picks (the existing picker). No free HTML (D15).
  3. **Optional code:** a unique single-use code per recipient (percent, cap, expiry), through the personal-code store of item 1. The one-offer rule applies.
  4. **Preview:** both languages, with a sample recipient's code.
  5. **Test send** to the signed-in staff address.
  6. **Send now or schedule.** The confirm step shows the count and how many days the throttle needs (count ÷ 600 per shop per day).
- **Upload a list**
  - A downloadable .xlsx template (e-mail, name, optional phone), written by our own code with PHP's zip and XML extensions. No package (D14).
  - Upload of .xlsx or .csv: up to 5,000 rows, 2 MB, first sheet only.
  - Each row is validated (e-mail format, Egyptian phone when given). Duplicates in the file are dropped, as are already unsubscribed addresses.
  - The errors table is shown before anything is saved.
  - **Saving needs the tick** "these people agreed to receive our e-mails", stored with the staff member and the time.
- **Consent**
  - An unticked opt-in box on sign-up and on checkout, on both shops. Stored in `core_marketing_consents`: shop, e-mail, source, text version, time.
  - Withdrawal is the existing unsubscribe, honoured by every campaign and reminder on that shop.
- **Sending** uses the same mailboxes and drain, no new provider. Campaign mail is bulk mail, so it never takes an order e-mail's place. A campaign larger than a day's allowance continues the next day; the page shows sent, waiting and failed.
- **During development and testing,** every send goes to the log mailer or a test mailbox. On production the campaign feature ships with the team audience only, until Michael switches it.

**Files**
- New: app/Domain/Campaigns/EmailCampaigns.php, Audience.php, ListUpload.php, Xlsx.php (reader and writer); the mails; a migration (campaigns, recipients, lists, list rows, consents); the controller; the pages under resources/js/pages/Manage/EmailCampaigns.
- Changed: BulkMailer (kind campaign); the unsubscribe, which now also covers campaign and reminder sends; both storefronts' sign-up and checkout (the tick).

**Data steps:** none. All tables are new.

**Risks**
- **Mail sent to people who did not agree.** Consent filtering is in the audience query, and a test proves it.
- **The mailbox limit.** The throttle stays under our cap, which already sits under Hostinger's limit.

## 6. Missing page texts

**What exists:** held pages (landing, brand, brand + category) are listed by the gate (`seo:gate`, config/content_gate.php). Texts go in through `seo:load-copy`; Sources sections are required where the gate requires them.

**What I will do:**
- **Rank the held pages** by Search Console impressions (the newest weekly reports, plus a 28-day performance export per shop from Michael: Search results > Pages) and by live in-stock products.
- **Write in rank order,** EN and AR. Every claim comes from our own pages or an approved source, with no invented facts.
- **Load each text** through `seo:load-copy` on a scratch copy and the gate. The batch ships as a load step in the deploy.

**This round:** 12 pages per shop, 24 pages in all (48 texts), in rank order (D17). The exact list comes after the held list is read on the production copy.

## 7. Hidden brands: hidden from search engines only

**Today there are three ways a brand is hidden** (17 Nov copy; facts section 2 gives production's):
1. **The brand is switched off** (catalog_brands.is_active = 0): Le Pliage on both shops. There is no brand page; its products stay in their categories.
2. **Products the team hid** (`storefront_product.is_visible` = 0): for example 4 Rolex on Watchizer, and 119 Generic on Brand Fashion. These are never unhidden, by the brief.
3. **Kept from search engines** by the 14 Nov rule (SearchExposure):
   - "hidden": noindex, out of sitemaps, feeds and markup. Examples: Hublot, Patek Philippe and Audemars Piguet on both shops; Cartier, Chanel, Louis Vuitton, Hermes and others on Brand Fashion.
   - "restricted": Rolex. It stays indexable, but its markup names no brand.

**Measured live on 24 Nov**
- Site search answers **404** for:
  - "hublot", "patek", "cartier" and "omega" on Watchizer;
  - "chanel" on Brand Fashion.
- The brand pages show those products: Hublot's page lists 23, Chanel's lists 47.
- Searching "rolex" works today (24 results), because Rolex is restricted, not hidden.
- So site search drops the products that are kept from search engines. The exact line is found on the copy before the fix; the candidates are the search index rows and the listing query.

**What I will build**
- **Site search finds every visible product,** whatever its search-engine class. The results page stays noindex, as today.
- **A search with no results** shows a "no results" page with suggestions: status 200, noindex, never a 404 (D8).
- **Brand pages, product pages and cards** stay as they are: they already work for customers and are noindex where the rule says so.
- **A test per class** proves the product is found by site search, absent from the sitemaps, feeds and markup, and the page answers 200.
- **Le Pliage (switched off) and team-hidden products stay as they are** (D9).

**Files:** core app/Storefront/ProductListing.php and/or app/Domain/Catalog/ProductIndexer.php (wherever the drop is); both shops' app/(main)/listing/page.jsx (no 404 on an empty search).

**Data steps:** a search reindex, only if the cause is in the index. It is a rebuild command, not a data edit.

## 8. SEO fixes

**8a. 404s with demand → 301**
- **Watchizer:** /subtypes/watches, /subtypes/audio, /subtypes/dress, /subtypes/electronics, /subtypes/fashion and their /ar versions; /ar/category/chronograph; and the removed /ar/product/michael-kors-mk4508-...
  - Each goes to its closest live page, checked 200, index and canonical-self before it is written. Dress has no products on production (D23 of 18 Nov is still open), so its target is part of that decision.
  - MK4508 goes to its replacement if the same model is live, otherwise to /ar/brand/michael-kors (D19).
- **Brand Fashion:** the /wp-content/uploads image URLs with the most asks (facts section 6).
  - Where the product is clear, they go to its current image.
  - The rest stay 404. Both groups are listed in the day record.
- **Ignored:** /.well-known/traffic-advice and apple-app-site-association.
- **Mechanism:**
  - Watchizer: static rules in next.config.js. That is code, reviewed, with no data step (D10).
  - Brand Fashion: rows in core's `storefront_redirects`, which its middleware already reads. Written by a four-pass step (`seo:redirects-load`), keyed by (shop, from_hash), with guards so it never replaces a row it did not write. Source 'manual'.

**8b. The BF link with stray quotes**
- **The link:** /product/tommy-hilfiger-mens-dark-green-dial-rubber-belt-1792121/"" (33 asks).
- **Find the source** on the copy: blog bodies, page texts, product descriptions, and the storefront code that builds product links.
- **Fix it there,** and add one general rule: a product path ending in /"" or %22%22 is 301'd to the clean path.

**8c. Merchant listings: brand and GTIN**
- **Every product has a brand id:** the column is NOT NULL.
- **Product markup names no brand for two groups:**
  - restricted Rolex (by the 14 Nov rule);
  - Generic products.
- **Products kept from search engines** carry no Product markup at all, so they are not in Merchant listings.
- **On the 17 Nov copy (visible products):**

  | Group | Watchizer | Brand Fashion |
  |---|---|---|
  | named brand | 1,723 | 6,978 |
  | restricted | 215 | 248 |
  | Generic | 46 | 1,020 |
  | kept from search | 139 | 1,283 |

  Production's figures come from facts section 7.
- **GTIN:** the catalogue has no GTIN or barcode column, so there is none to add (no invented numbers).
- **The fix:** prove with a test that every named-brand product carries `brand` in its Product markup on both shops' detail pages, and fix any page that does not. Restricted and Generic follow D11 and D12.

**8d. Brand Fashion indexing**
- **Needs Search Console exports** for Brand Fashion: Pages > "Duplicate, Google chose different canonical than user" and "Excluded by noindex tag" (Export > Excel).
- **I classify each URL:**
  - a Brand Fashion address under the old domain or with a query;
  - a page that should be noindex by rule (thin, or kept from search);
  - a page that should be indexed and is not.

  Only the last group is fixed.

**8e. The blog publishing guard**
- **Today** (BlogSafety::check): publishing pauses when the blog's impressions OR its pages with impressions fall by more than 40 % week on week, once the earlier week had 100 impressions. "12 to 7 pages" passed that test.
- **Proposed rule:** pause only when the earlier week had at least 1,000 blog impressions and 20 pages with impressions, and either:
  - **both measures** fall by more than 40 % this week; or
  - **impressions fall** by more than 25 % for two weeks in a row.
- **Below the volume floor,** only the alert e-mail goes out (a "watch" note), and publishing continues.
- The thresholds live in config/seo.php with code defaults, so no .env value is needed. If the existing SEO_MAX_WEEKLY_DROP is set on production, it keeps its meaning (the single-week fall).

## 9. Validation (after approval and build)

**Tests**
- **Abandoned cart:**
  - timing: before the delay, at the delay, after 7 days;
  - exclusions: ordered since, empty cart, second reminder for the same cart, a second e-mail inside 30 days, unsubscribed, no consent.
- **Codes:** unique codes; a code bound to its e-mail; single use; expiry.
- **The one-offer rule:** a campaign item rejects the code with the honest message.
- **Unsubscribe:** one-click POST and the List-Unsubscribe header.
- **Audiences:** consent filtering, and every audience count against a hand-built set.
- **Upload validation:** bad e-mail, duplicate, unsubscribed, too many rows, missing consent tick.
- **Sort:** the default is 1, featured stays 0, and the data step's four passes.
- **The code test box:** happy path, a 422 shown in words, SKU paste.
- **Every wheel option end to end.**
- **Hidden brands:** found by site search; noindex; absent from sitemaps and feeds; never a 404 for a customer.
- **Redirects:** every new redirect, and the stray-quote rule.
- **Markup:** the brand in Product markup.
- **The guard:** the new publishing rule, with the cases 12→7 pages, 1,000+ impressions falling once, and falling twice.

**Gates**
- No test that passed before may fail. Failures are judged against the f82073c control on the same copy (D1 of 17 Nov).
- phpstan level 10, lint and both storefront builds clean.

**Screenshots:** before and after, EN and AR, desktop and phone, of every changed screen. These are the dashboard's campaigns, wheel, offer form and product form; both shops' checkout, sign-up, wheel and search; and the e-mails. Taken on scratch data with no real customers.

**Delivery**
- Commits on updates as michael.
- The core deploy script with its undo and sha256 for each file.
- The storefront changes go live when Michael pushes main.
- The export script for the rehearsal (no customer rows: e-mails are replaced by test addresses on the copy).
- The day record.

## 10. Order of work

0. Michael runs facts-20261124.sh and sends the result plus the Search Console exports (D-list items marked DATA). I take the rehearsal export with a script I give him.
1. Item 4 (the code test): small, dashboard only.
2. Item 8e (the guard rule), 8a-8b (redirects, stray quotes), 8c (brand in markup).
3. Item 7 (hidden brands in site search).
4. Item 3 (sort default): code first; the data step after D6.
5. Item 2: the audit, then a STOP with the findings, then the fixes.
6. The personal-code store and consent: the base for items 1 and 5.
7. Item 1 (abandoned cart).
8. Item 5 (campaigns).
9. Item 6 (texts), written alongside from step 2 on, loaded last.
10. Validation, screenshots, rehearsal on the production export, deploy scripts, day record.

## 11. Decisions for Michael (recommendation first)

| # | Decision | Recommendation | Why |
|---|---|---|---|
| D1 | Who gets the abandoned-cart e-mail | Signed-in customers, and guests who ticked the new opt-in box | Guests typed an address for an order, not for marketing. Accounts already receive the weekly e-mail today |
| D2 | Who a campaign may reach | Only addresses with consent (the new tick, or an upload with the tick); never unsubscribed ones | Consent today is 0, so at first campaigns reach uploaded lists and new opt-ins. Alternative: also past customers who ordered (soft opt-in). Your call |
| D3 | A personal code is bound to | The e-mail of the order | The e-mail is what we sent it to; the phone is the wheel's key |
| D4 | The cart button for guests | A signed link that restores the guest cart (14 days) | Otherwise a guest on another device lands on an empty cart |
| D5 | Default discount and start state | 10 %, cap EGP 500, 7 days; the feature OFF and the audience team at deploy | You switch it on after a test send |
| D6 (DATA) | Which 0 is "featured on purpose" | 0 stays 0 only when is_featured = 1 or the product is on a list you give; every other 0 becomes 1 | The column alone cannot tell a typed 0 from the default 0. Counts first (facts section 1) |
| D7 | The placement reorder | Numbers from 1 | Otherwise the first product of a reorder becomes "featured" |
| D8 | A site search with no results | A "no results" page, 200, noindex | Never a 404 for a customer |
| D9 | Le Pliage (brand switched off) and team-hidden products | Stay as they are | The team's decisions; the brief says never to unhide |
| D10 | Watchizer's new redirects | next.config.js rules | Code reviewed in the diff, no data step. Brand Fashion uses core's table, which its middleware reads already |
| D11 | Restricted Rolex in Merchant markup | Keep no brand (the 14 Nov rule); their warning stays | Naming Rolex on products priced far below a genuine one is the risk the rule avoids |
| D12 | Generic products in Merchant markup | No brand | Google asks not to send "Generic" as a brand |
| D13 | The publishing guard | Section 8e | Pauses only on real falls |
| D14 | The list template | A real .xlsx without a package (if production has the zip extension), plus .csv accepted | No new package |
| D15 | The campaign message | Text plus product picks inside our template; no free HTML | Safer and consistent |
| D16 | The throttle | Keep 20 per 5 minutes and 600 bulk a day per shop | Order e-mails keep their 200 reserve; we stay under Hostinger's 1,000 |
| D17 | Texts this round | 24 pages (12 per shop), 48 texts | Gated, sourced, no padding |
| D18 | The Elegance finish | Tell me whether finish-elegance-20261121.sh ran; I record it as D49 | The day record stops at the stop |
| D19 | MK4508 | Its replacement if the same model is live, else the Michael Kors brand page | Closest live page |
| D20 | Dress (open since 18 Nov, D23) | Decide before /subtypes/dress gets its 301 target | Its page is empty on production |
