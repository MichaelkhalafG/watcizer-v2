# After the cutover — everything deferred, in one place

**Written 2026-09-24, the night of the Phase 2 flip.** Nothing here is broken in a way a shopper
cannot get past tonight; every item is a known, named state. Pick batches from it.

**How batches cost.** A *storefront* item ships with a push to `main` → one Hostinger rebuild →
§7 step 0's bundle check. A *core* item ships as a tar deploy (runbook §2, minus the migrations) →
`config:cache`/`route:cache`. Items in the same lane share that cost, so batch within a lane.

---

## ▶ SESSION RECORD — end of 2026-09-28 (read this first)

**The next session starts with:** *"Report the results of the 2026-09-28 deploy checks (core half,
storefront half, and the next-morning prune/backup check) — then run C3 from its read-only checks."*

**Deployed 2026-09-28 by the developer** (merge `ed37d00` pushed to `main`; core tar
`core-2026-09-28.tar.gz` built). Its verification results had NOT been reported when the session
ended — until they are, treat these as deployed-unverified:
- **Storefront** (`149af67`): search dropdown (2 letters, only the box's own answer — verified in a
  real browser locally, 4/4 runs, EN+AR); checkout fix 2 (refused add says so and rolls back; one
  server line per product; checkout reconciles the cart first); fix 4 (cart → checkout 3.24 MB →
  2.6 KB).
- **Core:** rows cache + index warm-up (`6e8cb8f`; `catalog:warm` every 5 min + after writes;
  judge by live Server-Timing: warm `cards` under 40 ms keeps the per-product entries, 40 ms or
  more removes them); duplicate-integration-ID warning (`b5f6e04`; any amber badge on production's
  Payments screen is a real shared ID); sitemap images on the API host (`47a010f`); core's guest-cart
  prune (`ef8f9f9`, daily 03:20).

**Open, in order:**
1. The deploy checks above, and the next-morning SQL (0 guest carts idle 30+ days; signed-in carts
   untouched; 03:00 backup ran).
2. ~~C3~~ **DONE 2026-09-29, 10:41 server time** — legacy `dash.watchizereg.com` is OFF. hPanel has
   no disable action on this plan, so the legacy app's `public/.htaccess` answers **410 Gone** on
   every path (the original is saved beside it as `.htaccess.pre-c3`; undo = copy it back). Verified:
   dash `/` and `/api/all_product` 410, api `/api` 401, api `/manage` 404, eleganceeg `/manage` 302,
   sitemap 0 hits for dash; in the browser zero requests to dash across home, listing, product, cart,
   checkout and account, Google sign-in works, console clean. The legacy app had no cron, so nothing
   else to stop. **C3-delete no earlier than 2026-10-06** (and only after a quiet week + a full backup).
3. **FK step 2 — LIVE on production 2026-09-29** (developer: both keys point at `catalog_products`, a
   second run says "nothing to do"; also run on the dev DB, full suite green there). `php artisan
   core:repoint-commerce-fks` (see "FK step 2" in the live-and-unfixed list for the spec). Idempotent:
   drops a `product_id` key pointing anywhere but `catalog_products` (the legacy ones — already gone
   on production since step 1), adds `order_items → catalog_products` RESTRICT and `cart_items →
   catalog_products` CASCADE, refuses on any orphan line, prints the plan and the rollback SQL
   (`--dry-run` changes nothing). Rehearsed on a scratch copy of the dev DB: from the dev state (old
   keys present) and from production's state (no key) — both end with exactly the two new keys; a
   re-run is a no-op; the full suite passes against it with the keys in place. Nothing in core is
   blocked by RESTRICT: `ProductImporter` already refuses a hard delete once the product has an order
   line, dashboard deletes are soft (`deleted_at`), and the probes delete their order lines first.
   Tests: 5 in `CommerceForeignKeysTest` — red on the old schema (a catalog-only product refused in a
   cart line and an order line). Harness: runs it after `core:transform`.
4. Then the queue as agreed: C-1 stage 4 + home rails; Arabic S-AR 1–3 (+B8/B9); Joyroom 3a/3b;
   B1 ratings, then blogs.
- **B2 Conversions API — DEPLOYED 2026-09-29 (migration M1x ran, one as expected); WAITING ON A
  CORRECT TOKEN.** `meta:capi-check` on the server: CONFIG ok (200 chars), CHARS OK, **TOKEN FAIL —
  "Malformed access token" (HTTP 400, code 190)**, EVENTS the same, OUTBOX none. So the token's
  characters are all valid but it is not the real token — the paste lost or changed something that is
  not a look-alike letter. The client is resending it as a FILE. Then: `config:cache`,
  `meta:capi-check --send-test`, one real card order, empty the test code, rotate the token.
- **Decisions, 2026-09-29 (developer):**
  - **"Pre-Order" → "Add to cart" everywhere — DONE in the tree.** It was only the product page's
    button label (Express stock 0, Market stock > 0); the order is identical either way. Market DOES
    mean a longer delivery, but that is explained in the e-mail after ordering, deliberately NOT on
    the product page. The cards' "Market · N available" badge is jargon too — cost of rewording or
    dropping it to be decided separately.
  - **Suggestions: the add-on rule and three rails APPROVED** — cart "Complete the look" / «أكمل
    إطلالتك» (add-ons only); product page "Pairs well with" / «يتناسب مع» (add-ons) then "Similar
    styles" / «تصاميم مشابهة» (alternatives, same family). Add-ons: in stock, not in the cart, a
    DIFFERENT family; tier 1 same brand + same gender (or unisex), tier 2 same gender any brand, NO
    tier 3; within a tier cheaper-first by closeness to the anchor's price, newest breaks ties; a
    multi-item cart anchors on its most expensive line and excludes every family already in it. Plus
    fixes 1–3 (out of stock, missing values never match, the price the shopper pays), the product page
    excludes what is already in the cart, and the uniform shuffle is a DELIBERATE deviation from the
    browser's biased one. Keep quirks 4 and 5.
  - **Notify-me:** signed-in customers subscribe with ONE tap; notified rows deleted after 30 days,
    unanswered after 180; at most 5 e-mails per unit restocked. Blocked on the mailbox's daily sending
    limit (developer is getting it from Hostinger) — as is the re-engagement mail.
- **FOR THE CLIENT (their stock decision, not ours): ALL 72 electronics are out of stock** (measured
  2026-09-29) — a whole family effectively invisible on the site. They may not know.
- **Decided, design approved-pending:** re-engagement rebuilt on core (weekly, live prices, per
  storefront, honoured unsubscribe). Needs from the developer: the mailbox's daily sending limit,
  price hold or "unchanged 14 days", the client's consent position. After the current queue.
- **Launch blocker for Brand Fashion:** L8 (listing build 41 s / 206 MB for 7,579 products).
- **A7** Next 15.5.27 on or after 30 September.

**Working rules added 2026-09-28:** git is the developer's — agents never run a state-changing git
command; they hand over the commands. Before any pause, the agent saves all uncommitted work and a
`git diff HEAD` patch outside the repo, and says where.

## ▶ START HERE — the state of everything, 2026-09-28

Rewritten 2026-09-28 from a full read of this file, to replace two session records that had
drifted. Older records below are HISTORY: where they disagree with this section, this section wins.
Git history holds the text it replaced.

### 1. Live and closed
- **Payments and orders:** batch 1 (payment methods at checkout, calls to deleted features removed,
  Next 15.5.26 + sharp 0.35.4); order totals; image folders; host binding + cart option A; L5
  per-storefront URLs; the category filter (A1); FK step 1 (two foreign keys dropped by hand);
  `orders:expire-unpaid` with the same-shopper rule; `OrderCustomer`; the real phone to Paymob.
- **Storefront:** #418 hydration fix; 3D hero on demand; robots.txt (S1); link-preview JPG;
  dashboard favicon; two-tone colour batch (Gold `#D4AF37` as dashboard data; the main→band command
  moved 0 rows on production).
- **C-1 stages 1–3**, the three parity fixes, the preflight removal and `Server-Timing` (`d6da058`,
  `3fa23ae`): `/listing` 3.75 MB → 334 KB, `/brand/Rolex` → 304 KB.
- **Checkout fix 1** (`5932e05`), which closed TWO silent failures: a cart core says is invalid, and a
  validation request that cannot run at all (it used to pass silently). Confirmed deployed by a
  bundle check (both messages in the live cart chunk).
- **Closed as not defects:** WebGL "Context Lost" (a deliberate release; not suppressed, so a real
  leak would still show); Meta's currency warning (Meta's own; reopen only if a real Purchase event
  arrives without its EGP value).
- **Done by the developer 2026-09-28:** the diagnosis agent's two live guest carts deleted from
  production; the Search Console check — `https://watchizereg.com/` is INDEXED and available, so
  Googlebot is NOT blocked and the SEO section (Arabic included) is unblocked.

### 2. Committed, not deployed
- **API responses no longer stored by the CDN — the intermittent CORS failure** (core, 2026-09-29,
  built). Seen live as `No 'Access-Control-Allow-Origin' header` on `/api/all_product_rating` on
  home, product and cart pages. Cause, measured live: the compat GETs were `Cache-Control: public`
  (legacy parity) and Hostinger's CDN on `api.watchizereg.com` stores public responses keeping ONE
  copy per URL — it replaces Laravel's `Vary: Origin` with `Vary: Accept-Encoding`. After a burst of
  requests the copy went `x-hcdn-cache-status: HIT`, and a request from `www.watchizereg.com` got
  `Access-Control-Allow-Origin: https://watchizereg.com` back from it. A copy filled by the
  storefront's own server-side fetch (`serverCatalog.js`, no Origin) has no CORS header at all, and
  every browser then fails on that URL for up to 10 minutes. Not one bad deploy: public headers +
  a CDN that ignores `Vary: Origin` + origin-less server fetches, exposed since the storefront
  started calling the API host directly (the 2026-09-24 flip). **Any** public API response had it —
  the checkout's payment-methods list too (`public, s-maxage=60`). Fix: the compat cache groups are
  `private` (browser cache and ETag kept), payment-methods `private, max-age=0`;
  `NoSharedCacheTest` walks every GET the browser makes and fails on `public`/`s-maxage`
  (mutation-checked). v2's catalogue endpoints keep their CDN headers — no browser calls them.
  **Also found: production has ZERO product ratings** (`/api/all_product_rating` returns `[]`), so
  ratings render nowhere regardless — and customers cannot add one until B1 (the ratings write).
  compat:diff will now report `Cache-Control` against the legacy host: a deliberate divergence.
  **After the deploy:** purge the CDN cache for the API host (or wait 10 minutes), then two requests
  to `/api/all_product_rating` must show `cache-control: …private` and never `x-hcdn-cache-status: HIT`.
- **`149af67` — the storefront batch** (storefront only, 2026-09-28): the search dropdown fix, checkout
  fix 4 and checkout fix 2 — see "Fix 2 and fix 4" below.
- **`6e8cb8f` — the rows cache + index warm-up** (core, 2026-09-28): see "The rows cache" in section 3.
  After the deploy: `config:cache`, then `php artisan catalog:warm` once; judge it by live
  Server-Timing (bar: warm `cards` clearly under 40 ms).
- **The duplicate-integration-ID warning** (core + dashboard, 2026-09-28, built — commit pending):
  saving a method whose integration ID another method under the same contract already carries still
  saves, and now says so ("Saved — but integration ID … is also used by …"); the methods screen marks
  both rows "Same ID as …". Warn, not refuse. 5 tests, mutation-checked. The dev copy holds no
  integration IDs, so the first real look is production's screen after the deploy — any row already
  sharing an ID shows its badge there. Needs the dashboard assets rebuilt (`npm run build`) with the
  core deploy.
- **The search dropdown fix** (in `149af67`): it never shows another search's results and starts at 2
  letters. **Verified 2026-09-28 on a fresh local build of `149af67`, real browser, pass/fail:**
  typing "rolex" at 150 ms a letter, then deleting back to "ro", the page sampled every 40 ms —
  4/4 runs pass (2 English, 2 Arabic), ~1,260 samples showing results, every one the server's answer
  for the text in the box; one letter shows "Type at least 2 letters" / "اكتب حرفين على الأقل" and
  sends nothing. Two earlier runs with a 2.5 s wait saw an answer arrive late (never a wrong one):
  local core is PHP's one-request-at-a-time server, and the next search queued behind the previous
  results' images; the live host serves concurrently. Still hard-reload before the live check. Deploy with the next
  storefront build; hard-reload before checking (runbook §7 step 0).

### Done by the developer 2026-09-28 (afternoon)
- **C1: `JWT_SECRET` rotated on BOTH hosts**, fingerprints verified matching each other and different
  from the exposed value. Every customer was signed out once; nothing else depends on it.
- **`LOG_LEVEL` back to `error`**, config re-cached.
- **The two stock units from orders 000001 / 000002** returned from the dashboard.
- **CDN caching: CLOSED — not available.** The panel (api.watchizereg.com → Performance → CDN →
  Manage) offers only Analytics, Website optimisation (image compression/resizing), Traffic blocking
  and Security — no cache rules, no host/path/query-string scoping. Nothing enabled. Notes from the
  same screens: do NOT touch Traffic blocking (its "allow only specific countries" view shows none
  allowed — saving that would take the API down); Security level "Medium" is what challenges plain
  `curl`; "Smart image optimisation" is ON for the API host (max 1600/800 px, quality 85/70) on top
  of the storefront's own image optimiser — double compression, low priority.
- **fb:app_id: CLOSED** — Watchizer has no Facebook App, only pixels.

### Fix 2 and fix 4 — built and proven 2026-09-28, committed, not deployed
- **Found first (the "duplicate cart line" check):** a double tap is guarded (the button is disabled
  while adding) and a repeat add merges — the duplicate line did not reproduce. But two REAL faults:
  (1) an add the server REFUSED ("Requested quantity exceeds available stock", 422) still showed
  "Added to cart!" and left the tab at the higher quantity — the shopper walks away believing they
  have it, and the tab and server disagree; (2) the tab keys a line by product, the server by product
  + colours + stock type, and a product CARD sends no colours while the product PAGE does — so a card
  add on a line the page created made a SECOND server row.
- **Fix 2 (cartStore, Cart page, product page, product card):** the server's answer wins. A refused
  add or quantity change is rolled back in the tab and the shopper is told why in both languages
  ("Not enough stock for that quantity" / "لا يوجد مخزون كافٍ لهذه الكمية"); a repeat add reuses the
  existing line's colours and stock type; before checkout `reconcile()` pushes what the shopper sees
  to the server at today's prices, removes stray server rows, and rebuilds the tab from the server's
  answer; an empty or unreadable server cart while the tab has lines is a stated failure, never a pass.
  Source of truth, as written in the code: the SERVER cart; the tab is a display cache.
- **Fix 4:** cart, checkout and account share ONE layout (`app/(main)/(shop)/layout.jsx`, route
  group, no URL change), so moving between them no longer re-sends the catalogue: cart → checkout
  RSC payload 3.24 MB → **2,602 bytes**.
- **Proven (local, real taps, English AND Arabic), `fix2_proof`:** A refused add → message, tab
  rolled back, server unchanged; B page-then-card on an in-stock product (526) → ONE server line,
  qty 2, colours kept; C two drifted server prices → healed, reaches /checkout; D guest token lost
  (server empty) → lines pushed back, reaches /checkout; E the payload above. Allow-list test passes
  (the new `delete_cart` call is on the list). Not run: the full Pest suite and the harness — no core
  change.
- Noted, not chased: in one run a tap target was briefly covered (probably the "Added to cart" toast
  over a button); it did not recur.

### 3. Open
**Waiting on the developer (minutes each, no code):**
- ~~C1~~ **DONE 2026-09-28** (see above). Kept for the record: **C1 — rotate `JWT_SECRET` on BOTH hosts** (core and the legacy app share it by design, and the
  legacy host is still up). Rotating it signs every customer out and nothing else: customer tokens
  are the only thing signed with it (`CustomerTokens` issues, `LegacyJwt` verifies); the dashboard
  uses sessions, password resets and verification links have their own tokens / `APP_KEY`, and the
  per-customer epoch cannot protect against a leaked secret (the holder writes their own `iat`).
  **The public API key half of C1 is DROPPED** (2026-09-28): the key is public by design (it ships
  in the storefront bundle, and since `d6da058` it is in every catalogue URL), so rotating it buys
  nothing, and without a two-key overlap in core it would 401 the whole storefront until the rebuild
  finished.
- ~~`LOG_LEVEL`~~ **DONE 2026-09-28.** **`LOG_LEVEL` back to `error`** in `core/.env` (+ `config:cache`). It was lowered to `warning`
  temporarily on 2026-09-26 while diagnosing; nothing tracked it until now.
- ~~CDN caching~~ **CLOSED 2026-09-28: not available in the panel** (see above). **CDN caching of the catalogue reads** (hPanel → CDN). Measured 2026-09-28: the network is the
  largest part of a new filter tap (133–341 ms) and the edge caches none of it
  (`x-hcdn-cache-status: DYNAMIC`) although core marks the responses `public, max-age=600`. Cache
  `GET /api/catalog/listing`, `/api/catalog/cards` and `/api/catalog/nav` by FULL URL (the query
  string is the whole request; the answers do not depend on any header). **Never cache**
  `/api/catalog/meta` (its answer depends on `Accept-Language`), `/api/me/*`, the cart and checkout
  routes (`add_to_cart`, `remove_from_cart`, `cart/*`, `add_order`, `add_address`, `delete_cart`),
  `/api/auth/*`, `/api/login`, `/api/register`, `/api/callback_payment`, `/api/pay/*`. Cost of
  caching: a price or stock change reaches a cached answer up to 10 minutes late — the same as the
  browser's own cache today.
- ~~Orders 000001 / 000002~~ **DONE 2026-09-28.** Two units the legacy dashboard never returned to stock. Dashboard →
  Inventory → adjust, per line (steps in the 2026-09-26 record, item 4).
- **Paymob:** when the remaining integration IDs arrive, enter them in the dashboard — no code. Do the
  duplicate-ID warning (below) BEFORE entering them. The intention-expiry unit still needs
  `scripts/paymob-expiry-probe.php` run on the server; Apple Pay waits on Paymob's domain check.
- **Record the Hostinger deploy key.** The repository is private and redeploy works, but nothing
  here says which key Hostinger uses or what to do if it stops. Needed: where the key lives
  (GitHub → repo Settings → Deploy keys, read-only; and its pair in hPanel → Git), and the recovery —
  generate a new key in hPanel, add its public half as a read-only deploy key on GitHub, delete the
  old one, redeploy.
- **Server leftovers (C10)** once the rollback window is declared over (it ends with C3).
- **Colour data entry** for ~7,090 products (the team). An importer colour mapping is CLOSED: the
  export carries colour on 7 of 8,614 rows, so this is data entry, not import.
- **Owner decision:** whether the "authentic, certified" claims stand (before any Merchant Center feed).

**Mine, not started (the order is the sequence in the session report of 2026-09-28):**
- **Checkout fix 2** — make the server cart match what the shopper sees before validating, and
  treat an EMPTY or missing server cart while the tab has lines as a FAILURE, not a pass (today it
  validates as `valid:true` on nothing — why clearing site data looked like a cure). Source of
  truth, to be written into the code: the SERVER cart; the tab's `sessionStorage.user_cart` is a
  display cache rebuilt from it; no third copy.
- **Checkout fix 4** — the /cart → /checkout navigation re-sends a 3.24 MB RSC payload (681 KB
  compressed, ~5 s on Slow 4G) because `checkout/layout.jsx` embeds the catalogue again. CAUSED BY
  STAGE 3. One shared layout for cart + checkout, or stage 4, whichever lands first.
- **The rows cache + index warm-up — BUILT 2026-09-28, not yet committed** (core). Was: to return a
  page, core read the WHOLE cached catalogue to pick 24 rows (live: 40–67 ms warm, **404 ms cold**,
  slowest tap 695 ms), and the listing index (~1 s) was rebuilt by whichever shopper tapped first
  after it expired (every 10 min) or after any dashboard write. Now:
  - each product's card row + gallery is its own cache entry (`compat_card`); a page reads its 24,
    and a missing one is read live for just that product and stored;
  - `catalog:warm` rebuilds the index, the catalogue and every card entry every 5 minutes
    (`routes/console.php`, the existing `schedule:run` cron) — inside the 10-minute TTL, so a shopper
    never meets an expired index — and again right after any request that flushed the cache has
    sent its response (`compat.warm_on_write`); only the storefronts in `COMPAT_WARM_STOREFRONTS`
    (default `1`: see L8 for why not Brand Fashion);
  - `LegacyJson::ts` (a `Carbon::parse` per timestamp) is memoised — identical output.
  **Measured locally, real HTTP, file cache (Server-Timing):** cold, right after a flush with no
  warm-up: index 763 → **394 ms**, cards 359 → **67 ms**. Warm pages: cards **34–58 ms new vs 37–59
  ms old — no gain on this machine**, one new request spiked to 490 ms. In-process a warm page of
  cards costs ~11 ms, but the FIRST read of 24 entries costs ~47 ms: each page opens 24 files it
  has not opened before, and this Windows machine has real-time antivirus scanning on the project
  (likely cause, not proven). Linux on the host should not pay that; **live Server-Timing after the
  deploy is the real measurement** (baseline above). If warm `cards` is not clearly under 40 ms
  live, the per-product entries are not worth their 698 small files per warm-up and should go,
  keeping the warm-up and the memo.
- **C-1 stage 4** (home, product, cart, checkout, account off the client catalogue; related from the
  server; remove the remaining catalogue copies and the client transform), with **the home-page
  rail ordering** (new, below). Shipped in slices (2026-09-29 estimate: ~4–5 days in all — A cart /
  checkout / account ~1, B product + offer pages ~1, C home + rails ~2–2.5, D cleanup ~0.5).
  - **Slice A — BUILT 2026-09-29, not committed.** Core: `GET /api/catalog/related` (`CompatRelated`)
    — the product page's related products (`?product=ID`) and the cart's suggestions (`?cart=ID,…`,
    one id per cart line) as cards, scored on the server; both rules ported BUG FOR BUG (JS null and
    string-truthiness semantics included) and pinned by `CatalogRelatedTest`, which runs a FROZEN
    verbatim copy of both JavaScript rules (`tests/Fixtures/related-reference.js`, from 149af67) in
    node over the storefront's own transformed catalogue and compares every candidate's score
    (mutation-checked; the null semantics the real data never exercises are pinned by hand). The
    listing index gained the raw price strings those rules read. Storefront: cart, checkout and the
    account's order history fetch only their own products' cards (`useCards`), the cart's
    suggestions come from `catalog/related` (asked only when the section scrolls near), and the
    `(shop)` layout that embedded the catalogue is gone. **Measured locally: /cart, /checkout,
    /account 3.09 MB → ~117 KB of HTML each.** Browser check (`slice_a_check`), 5/5 PASS: both lines
    named and priced, only `catalog/cards` + `catalog/related` requested (never `all_product`),
    12 suggestions rendered, checkout lines named. Found on the way and fixed: the suggestions'
    IntersectionObserver was attached by an effect keyed on the item count — once the cards stopped
    arriving with the page, one run in five never requested suggestions; now a callback ref.
    Not browser-checked: the account's order history (needs a signed-in customer) — lint + the same
    `useCards` path. **Deploy: CORE FIRST, and widen the API host's `.htaccess` allow-list line to
    `catalog/(meta|nav|listing|cards|related)` in the same deploy** (runbook §4.1.1 updated) — else
    production 404s the new read; probe `/api/catalog/related?product=1` without the key → 401.
    Then the storefront.
- **Arabic S-AR stages 1–3** (see S-AR below; unblocked by the Search Console check).
- **B2 Meta Conversions API** — about a day; STOP and report if it grows (developer's condition).
  **Scope (2026-09-28): ONE pixel, `1614877760150035`** — the client administers only the new one;
  the incumbent `1611…872` keeps its browser-only events (status quo, no regression; its purchase
  count stays under-reported and the two pixels will disagree — expected, not a fault).
  **BUILT 2026-09-29 (core, not deployed):** the card `Purchase` is enqueued in the payment-success
  transaction on BOTH callback paths (scoped + legacy alias) as an `integration_outbox` row on channel
  `meta` (`dedupe_key meta:purchase:{order}` = once per order), sent right after the commit, retried
  by `meta:drain` every minute with a backoff (1, 5, 15, 60, 240 min, then `failed`). A token Meta
  rejects (OAuth 190) fails the row at once with "THE TOKEN IS WRONG OR EXPIRED" — never retried
  forever. Declined cards send nothing. Customer data is hashed at enqueue (e-mail, phone as
  20XXXXXXXXXX, first/last name, country, account id) — no raw PII in the outbox or on the wire. Meta
  requires the shopper's user agent on a website event and the callback has no browser in it, so
  `add_order` now records the browser's user agent, IP and `_fbp`/`_fbc` in a new core table
  `core_order_signals` (migration M1x, on the never-dropped list); an order placed before the deploy
  goes as `action_source: other`. Event id `purchase-{order number}` (a future browser event must use
  the same). `meta:capi-check` prints separate verdicts for the token's CHARACTERS (every non-alphanumeric
  one by position and Unicode name — a Cyrillic "х" shows up), whether Meta ACCEPTS the token and it
  can see the pixel, and (`--send-test`, refused without a test code) one test Purchase; it never
  prints the token, only a fingerprint. `.env`: `META_CAPI_TOKEN` (set), `META_CAPI_TEST_EVENT_CODE`
  (set to `TEST24178` for the test; empty = events count as real), optional `META_CAPI_PIXEL_ID`
  (defaults to the new pixel) and `META_GRAPH_API_VERSION` (defaults to v23.0). 9 tests
  (`MetaConversionsTest`), the callback hook and the token verdict mutation-checked.
  **Decision (developer, 2026-09-29): `core_order_signals` has NO retention rule — the IP and user
  agent are kept indefinitely.** Raised because they are personal data about a shopper's device and
  B2 only needs them until the order's Purchase is sent (a card payment settles within hours, the
  retries end within a day), so they could be deleted after that. Kept by choice, not oversight. If a
  rule is ever wanted, it is a prune command like `carts:prune` (e.g. signals of orders older than N
  days), scheduled after the 03:00 backup.
  **Not in B2:** COD purchases stay browser-only (they already fire `Purchase` at confirmation); the
  storefront does not yet send `_fbp`/`_fbc` in `add_order` (the columns accept them — a small
  storefront follow-up that would raise Meta's match rate).
- **C3 switch the legacy storefront OFF — disable, not delete** (developer, 2026-09-28: nothing
  pending on the legacy host). The legacy app is **`dash.watchizereg.com`** — NOT `eleganceeg.com`,
  which is core's dashboard and shares core's document root with `api.watchizereg.com`. Found while
  planning it (2026-09-28):
  - **Prerequisite, code:** the live sitemap (`watchizereg.com/sitemap.xml` → core) writes every
    product and blog image as `https://dash.watchizereg.com/Uploads_Images/…` —
    `compat.sitemap_image_host` is a hard-coded constant. Off without this fix = every image URL
    Google reads is dead. Fix: the API host (same image tree, crawlers already allowed). **BUILT
    2026-09-28** (`COMPAT_SITEMAP_IMAGE_HOST`, default `https://api.watchizereg.com`; test in
    `CompatEndpointsTest`, red against the legacy host). Deploy it, `config:cache`, and confirm
    `watchizereg.com/sitemap.xml` contains no "dash" BEFORE switching anything off.
  - **CORRECTION 2026-09-29: the legacy app has NO cron line on the server** (`crontab -l` holds one
    line, core's). The two jobs below exist in the legacy code but **never ran here** — so the
    "guest carts deleted 7 days after creation" finding was NEVER live (a code reading taken for
    production behaviour; the 30 → 19 cart drop on 2026-09-29 was core's own prune), and there is
    no cron line for C3 to remove. Kept below as what the code would have done:
    - `emails:re-engagement` at 10:00 — mails customers whose last LEGACY sign-in is 30+ days old,
      at most once per 30 days each (`last_reengagement_at`). `last_login_at` stopped advancing at
      the storefront flip, so the eligible set grows by whoever crosses 30 days since their last
      legacy sign-in — not "everyone at once" (an overstatement corrected 2026-09-28). The six
      products it offers come from the FROZEN legacy `products` table (prices and stock as at the
      write switch), the mail has NO unsubscribe link, and its logo loads from `dash.`.
      **Checked on production 2026-09-28: it has never sent anything.** No customer stamped in
      `last_reengagement_at` in the last 60 days, zero `ReEngagementMail` rows in `jobs`, zero in
      `failed_jobs`. The job existed and the stale-price fault was genuine, but **zero customers were
      affected**. Removing the cron line with C3 is prevention, not damage control.
    - `carts:prune` — deletes guest carts where `expires_at < now()`. Core stamps `expires_at` =
      creation + 7 days and never extends it, so IF it had run, every guest cart would have been
      deleted 7 days after creation, even mid-shopping. It never ran (see the correction above).
  - **Core's own prune goes IN the sequence (developer, 2026-09-28) — BUILT:** `carts:prune` in core,
    daily 03:20 (after the 03:00 backup). Guest carts only, idle = no activity on the cart OR any of
    its lines for 30 days (`expires_at` is not read), batches of 500, `--dry-run`. 4 tests; the
    still-shopping case mutation-checked. **Deployed; first night 2026-09-29: 30 → 19 carts, exactly
    the 11 the dry run predicted.**
  - **Never touch:** the MySQL database (core runs on it — refuse any hPanel offer to remove a
    database with the site), `eleganceeg.com` and `api.watchizereg.com`, core's cron line
    (`domains/eleganceeg.com/core`), the DNS record and SSL of `dash.watchizereg.com` (kept for the
    grace week so re-enabling is one click), the Paymob portal URL, the Google OAuth redirect URIs.
  - Once off, the storefront's §9 rollback is no longer a one-file change: it needs the site
    re-enabled first. The rollback window closes with this.
- **C3-delete — delete the legacy site: NOT BEFORE 7 days after the recorded "off" moment**, and
  only if that week was quiet (no report, nothing in core's logs, no Paymob callback failure for a
  legacy-created transaction) **and after a full backup of the legacy site's files and of the
  database taken that day.** Then: delete the website, the `dash` DNS record and its certificate,
  the `dash` Google OAuth redirect URI; point the Paymob portal URL at core's
  callback or clear it; drop `dash.watchizereg.com` from `config/cors.php`, `next.config.js`
  `remotePatterns` and the config defaults. **Never the database** — it is core's.
  Off moment: **2026-09-29 10:41 server time** (legacy `public/.htaccess` → 410; original in
  `.htaccess.pre-c3`) → earliest delete: **2026-10-06**. The delete also removes that `.htaccess`
  pair with the site.
- **Re-engagement, rebuilt on core — DECIDED 2026-09-28 (developer), design first, after the current
  queue.** The legacy job is removed with C3 now: a daily mail at stale prices is worse than no mail.
  The new one must be: WEEKLY; live prices and stock from core, never a frozen table (never a price
  we don't honour); never out of stock or not visible on the customer's storefront; a real "last
  seen" that core owns and writes; per storefront (Watchizer products from Watchizer's sender); a
  working, honoured unsubscribe; never the same customer two weeks running, never the same products
  twice. Design (cost, who chooses the products, failure modes at scale) goes to the developer
  BEFORE any build.
- **FK step 2** (`core:repoint-commerce-fks`), **Joyroom 3a/3b**, ~~the duplicate-integration-ID
  warning~~ (built 2026-09-28, see section 2), **B1 ratings write** (+ its `.htaccess` line), **B8/B9 sitemap**
  (fold into S-AR stage 3), **blogs per storefront** (needs the G9 scoping decision), **A7 Next
  15.5.27** (dated: on or after 30 September).
- ~~fb:app_id~~ **CLOSED 2026-09-28** (no Facebook App). **fb:app_id** (Facebook's debugger lists it missing): only meaningful if Watchizer has a Facebook
  App (a pixel is not an app). Without one there is nothing to put there; the preview works without
  it. Close unless the developer has an App ID.
- ~~Unverified~~ **CHECKED 2026-09-28 — see "Fix 2 and fix 4".** A diagnosis run on 2026-09-28 left ONE guest cart with TWO lines for
  the same product, added 3 s apart. If a double tap on Add to Cart creates a second line instead of
  raising the quantity, that is a real bug. A five-minute look in `add_to_cart`.
- **Minor, noted:** superseded dropdown searches are not cancelled (each keystroke's request runs to
  the end); `Server-Timing` with `Timing-Allow-Origin: *` shows core's phase timings to any site
  (timings only, no data — accepted).

**New item — ordering the home-page rails from the dashboard (developer, 2026-09-28).** NOT the
banners (A3, paused). Today the home page shows the category tiles, an offers rail, a featured block
(5 random products) and then ONE RAIL PER GRADE, in the grades table's order, 8 cards each — all
derived in the browser from the full catalogue. Grades have no sort column; `storefront_product`
already has `is_featured` and `sort_order`; the dashboard has no screen for the home page. Build:
a `storefront_home_rails` table (storefront, kind — grade / brand / category type / offers /
featured / newest — its target, optional titles in both languages, position, active, card count),
a dashboard screen to add, toggle and reorder rails (activity-logged, cache-busting), and a core
read that returns each rail with its cards, so the home page stops needing the whole catalogue.
~2 days on top of stage 4, and it belongs INSIDE stage 4: stage 4 moves the home rails to the
server anyway, so doing it separately would build the rails twice.

### 4. Deferred by decision — and what makes each urgent
- **Hero 3D: one-finger vertical swipe scrolls the page** (decided 2026-09-29, NOT built). Today the
  hero canvas is `touch-action: none` (three's `OrbitControls` and `WatchHero.css`, deliberately:
  one finger rotates), so on a phone a swipe that starts on the 50vh hero rotates the watch instead
  of scrolling. **The fix, when triggered:** a one-finger VERTICAL swipe on the hero scrolls the
  page; rotating needs a HORIZONTAL drag. A behaviour change. **Trigger: the day a shopper says the
  page sticks.** Not the cause of anything today: the console's "non-passive `wheel` listener"
  warning comes from `OrbitControls` (it cancels the wheel to zoom) and costs nothing on a phone —
  touch scrolling fires no `wheel` events. Left alone, unmeasured (developer, 2026-09-29).
  **One thing to measure, only when next in `WatchCanvas.jsx` anyway:** the warning repeats, so the
  controls are being connected more than once — count the connects and find why.
- **A3 banners** (paused for the season) — when the developer wants seasonal banners.
- **A5 / A6** (account shipping display; installment fee) — before any promotion goes on.
- **A9 Next 16 / React 19** — after Brand Fashion launches; sooner if 15.5 stops getting security
  fixes or the 3D stack breaks on the unsupported React.
- **L3 / L2** — when Brand Fashion's frontend starts; L2 last before its launch.
- **B6** promotions scope — before anyone is given a SCOPED dashboard grant.
- **B3 / B4** verification landing and host — when customers start verifying at scale, or the
  first CLI/dashboard sender of a verification mail.
- **S-AR stage 4** (Arabic slugs) — after stage 1's Search Console data.
- **Strip flick stutter** — reopen only if a shopper reports it.
- **Real GPU context loss while the hero is on screen** (fallback to the poster untested) — reopen
  if the hero is ever reported blank.

### 5. Closed or dropped 2026-09-28 (developer)
- DROPPED: C6 (git history purge — private repo, every value dead or rotated), C9 (user 7's first
  mail), section D (documentation nits), the horizontal header logo, the public-key half of C1.
- CLOSED WITH C3 when it is done: C4 (legacy gate question), B7 (`compat:env-parity` tooling).
- CLOSED as overtaken: the filter-tap cost and the `useUIStore()` selector work (stage 3 removed
  them); the 2026-09-26 record's items 1, 3, 5 and 7 (shipped or closed); A1, A2, C2, S1, S4 as
  pending items (shipped).
- CLOSED: the importer colour mapping (data entry, as recorded under Colours).

### Checkout — how the cause was confirmed (2026-09-28), kept for the next person
`scripts/checkout-repro.mjs`, local, 390×844 touch, every tap verified on the button: a fresh guest
cart reaches /checkout; the same cart with its SERVER line set to an old price got `cart/validate`
→ `{"valid":false,"warnings":[{"message":"Price changed to 5200"}]}` and the page stayed on /cart
with nothing shown, tap after tap — the developer's symptom. The guard dates from the cutover
(c1447d3, 2026-07-06); stage 3 did not cause it. The script now refuses non-local origins, is
bilingual, and deletes its own dev-DB carts on exit.

---

## ▶ HISTORY — session record, 2026-09-26 (evening)

### State of the code, exactly

- **Batch 1 is fully live.** Commits `23e2b5a` (core) and `3333a1f` (storefront) are on `main` and
  `origin/main` (and `wave-4d`). The core half is DEPLOYED — verified: production answers
  `GET https://api.watchizereg.com/api/v2/watchizer/payment-methods` with the live methods. The
  storefront half is built and live.
- **Written, tested, NOT committed, NOT deployed** (working tree of `wave-4d`, on top of `5704077`):
  items 1 and 3 below, plus two small fixes found on the way. Code is complete, not half-written.
  *(Corrected 2026-09-27: this line first said "full battery green (see Checks)" — no such run
  had happened and no Checks section existed. The real results are in "Checks, 2026-09-27" at the
  end of this record.)* Files:
  `core/app/Domain/Orders/{UnpaidOrders,OrderCustomer}.php`,
  `core/app/Console/Commands/OrdersExpireUnpaidCommand.php`, `core/routes/console.php`,
  `core/config/compat.php` (`compat.unpaid.*`), `core/app/Domain/Payment/PaymobProvider.php`,
  `core/app/Http/Controllers/Compat/CheckoutCompatController.php`,
  `core/app/Http/Controllers/Manage/OrderController.php`,
  `core/app/Domain/Notifications/{OrderEmailData,OrderMailer}.php`,
  `core/tests/Feature/Orders/{UnpaidOrdersTest,OrderCustomerTest}.php`,
  `core/tests/Feature/Payment/CheckoutMethodsTest.php` (+3 cases), and this file.
  Core lane only — no storefront change, no migration, no new `.env` key required (defaults apply).
  Ships as one core tar + `config:cache` + `route:cache`; the new scheduled command rides the
  existing `schedule:run` cron.

### LIVE AND UNFIXED IN PRODUCTION — in this order (STATUS 2026-09-28: 1, 3, 5 shipped; 7 closed; 2, 4, 6 still open — see START HERE)

1. **Abandoned card payments hold stock forever.** `add_order` reserves stock for every order; a
   card order then waits `pending`, and a shopper who leaves Paymob's page produces NO callback, so
   nothing released it (only a Paymob decline, a failed session, a dashboard cancel, or the
   reconciler for orders already `cancelled` do). Reproduced: open Paymob, come back, pick cash →
   "Insufficient stock". **Built (uncommitted):**
   - `orders:expire-unpaid`, every minute: cancels + releases (`payment_failed`, note
     `payment_expired`) card orders `pending` with a core reservation, no successful attempt, older
     than `compat.unpaid.expire_after_minutes` (default 60; refuses < 15; `--dry-run`). Pre-switch
     legacy orders and WhatsApp orders are excluded by construction. Claim-based: cannot race a
     callback or a dashboard cancel.
   - Same-shopper rule in `add_order`: the SAME account/guest token's unpaid card orders are
     cancelled and released (note `payment_superseded`) inside the new order's transaction, so the
     unit comes back for the shopper who returned; another shopper still waits for the expiry.
   - Intention `expiration`: **sent only when configured, and unset as shipped** (changed
     2026-09-27 — first draft sent 1800 on an assumed unit). Paymob's Create Intention reference
     lists the field with no unit, default or limits, and 1800 is 30 minutes in seconds but 30
     days in minutes. Measure with `scripts/paymob-expiry-probe.php` on the server; then set
     `PAYMOB_INTENTION_EXPIRATION_SECONDS` (must be under the 60-min window, or it is refused).
     Until then a payment landing on an expired order is never lost silently: `CallbackPolicy`
     never reopens a `cancelled` order and records a finding (→ refund or reopen by hand).
   - No customer e-mail on expiry: a card order's confirmation is only sent when the money arrives.
   - Tests: 7 in `UnpaidOrdersTest`; the developer's exact scenario goes RED with the same-shopper
     rule disabled.
2. **FK step 2 — `core:repoint-commerce-fks`, still to BUILD.** Step 1 done by the developer today:
   `order_items_product_id_foreign` and `cart_items_product_id_foreign` (→ legacy `products`)
   dropped by hand; orders for the 159 catalog-only products work. Rollback SQL saved on the server
   at `~/fk-rollback-*.sql`. Background: this is the study's never-built M2 (CLEAN_CORE_STUDY §2.8.2,
   risk R2-02); the Phase 2 runbook never ran it. Build it as an idempotent one-off COMMAND, not a
   migration (the harness runs `migrate` on a bare dump before the catalog is filled, so M2's
   orphan pre-flight would fail there): orphan pre-flight against `catalog_products` → add
   `order_items.product_id → catalog_products` RESTRICT and `cart_items.product_id →
   catalog_products` CASCADE (one deliberate deviation from the spec: `ProductImporter` can
   hard-delete a fresh product sitting in a cart). Test first: order + cart add for a catalog-only
   product, red on the legacy schema. Run it in the harness after `core:transform`;
   `core:drop-clean` already disables FK checks, so rehearsals keep working. Out of scope on
   purpose: `wishlist_items` (gone), `offers` (frozen), legacy junctions (unwritten),
   `product_ratings` — **B1 (ratings) must repoint that key when it is built**, or it hits the same
   bug.
3. **Dashboard order detail shows Name/Phone/Email "—" for a REGISTERED customer.** Cause, verified:
   `Manage\OrderController` (list lines ~185, detail ~282) and the list search read ONLY `guest_*`,
   which the storefront fills for guests alone. **Built (uncommitted):** `OrderCustomer` — the one
   answer (account name/e-mail, else guest; phone = order address → guest → account → address's
   second) — used by the order list, the detail, the search (now matches address and account
   phones), the order e-mails, the mailer's recipient and the Paymob billing. Tests: 5 in
   `OrderCustomerTest`, red on the old controller.
   - Found on the way, fixed in the same change: the Paymob billing sent `phone_number` and the
     provider read `phone`, so EVERY intention went out with phone `-` — a mobile wallet pays by that
     number. Test red on the old code.
4. **Orders 000001 and 000002 — two units cancelled in the legacy (Blade) dashboard that never came
   back** (legacy defect #6: its cancel only set the status). They cannot be cancelled again and the
   reconciler skips them (no core reservation). Correct via Dashboard → Inventory → adjust, per
   order LINE: mode **adjust** (relative, +quantity — never *set*, which would overwrite any sale in
   between), the line's **bucket** (`type_stock` Express → express, anything else → market), the
   variant if the line has one, reason **`adjustment`** ("Stock-count correction"), note e.g.
   "Legacy order 000001 cancelled on <date> in the old dashboard; legacy never returned the stock
   (defect #6)". Only for units that physically exist. (Order 000012 is a REAL order — leave it.)
5. **React hydration mismatch (#418) on the storefront.** Not investigated yet; not reproduced in the
   one home-page load checked tonight. Next: reproduce with the non-minified dev build to get the
   differing node, then look for render-time `Date`/`Math.random`/locale formatting/`window` reads.
6. **BUILT 2026-09-28 (warn, not refuse — see START HERE section 2).** **The methods screen accepts the same integration ID on two methods silently** (cause of today's
   bank_installment = 5943060 slip). Warn, don't refuse: after save in
   `PaymentSettingsController::storeMethod/updateMethod`, flash "this ID is already used by <key>"
   when another method of the same contract carries it, and mark such rows on the screen
   (`MethodList::forAdmin` can carry a `shares_integration_id_with`). ~1–2 h with a test.
7. **Meta Pixel "Invalid parameter format for currency" — CLOSED 2026-09-27: Meta's, not ours.**
   Verdict: on the home page our code makes only `init` ×2 + `PageView` with no currency, replaying
   `PageView` does not trigger it, and it fires once per pixel inside Meta's code beside Meta's own
   "conflicting pixel versions" warning — so it comes from Meta's per-pixel config scripts (an
   inference: the captured stack URL was redacted). Our ViewContent / AddToCart / InitiateCheckout
   / Purchase send `currency: 'EGP'` and a numeric `value`. **The one check that would prove this
   wrong:** a real Purchase in Events Manager arriving WITHOUT its value in EGP — look at the first
   real order's event; if it is missing, reopen this with `trackPurchase` as the suspect.
   Evidence as recorded the night before: fires twice per home-page
   load = once per pixel (1611910119460872, 1614877760150035); our code makes only `init` ×2 +
   `PageView` there, with no currency; replaying `PageView` alone does not trigger it; neither
   pixel's served config (`signals/config/<id>`) sets a currency rule; the page declares no currency
   (JSON-LD, meta, microdata); the warning comes from Meta's `standardParamChecks` plugin, which
   DELETES the failing parameter from that one event. Meta also reports "multiple pixels with
   conflicting versions" — the two pixels' configs are built on different releases. So it is
   probably Meta's own per-pixel config, not anything we send; our ViewContent/AddToCart/
   InitiateCheckout/Purchase send `currency: 'EGP'` and a numeric `value`. **Next:** check both
   pixels in Events Manager (a value/currency default, or a conversion rule); then prove in the
   browser that an event we send keeps its currency — fire one AddToCart in a probe frame with
   `facebook.com/tr` intercepted so nothing reaches the pixels, `EGP` vs a bad currency as control.
   Also noted, no action: `THREE.Clock` is deprecated (→ `THREE.Timer`) — part of the Next 16 / React
   19 work (A9). The 3D reflow/rAF timings the developer saw are unmeasured on a mid-range phone.

### Lesson: "the third party accepted it" is not "we sent the right thing"

The Paymob billing sent `phone_number`, the provider read `phone`, and every intention since the
switch went out with phone `-`. Nothing on our side could catch it: Paymob accepted the payload,
payments worked, and the only symptom was a field on someone else's page — no phone on any card
transaction in Paymob's dashboard (the day we trace one with their support), and harder wallet /
installment flows. For every OUTGOING integration payload (Paymob intention, Meta/TikTok events,
mail, and CAPI/ERP when built), a test must assert the payload's MEANINGFUL fields carry the
customer's real values end to end — built from a real checkout request, not from a hand-made array
handed to the provider — and a placeholder (`-`, `Guest`, `01000000000`, `no-reply@…`) reaching
the wire for a customer who gave the real value is a failure. `CheckoutMethodsTest` now does this
for the phone; the same assertion is owed for name, e-mail and street.

### Lesson: cleaning git wipes the stash too — restore first, then clean (2026-09-28)

To remove AI trailers from pushed commits, the working tree was cleared with a stash, the history
rewritten, and the old objects purged with `git reflog expire --expire=now --all` + `git gc
--prune=now`. **The stash lives in the reflog (`refs/stash`), so that purge deleted it** —
`git fsck --unreachable` found nothing. Thirteen uncommitted files of item 4 were gone from git.

- **The order is: restore, then clean.** `git stash pop` (and check `git status`) BEFORE any
  `reflog expire` or `gc --prune`; purge last, when nothing you still need lives only in a stash.
- **What saved it:** before pausing, the agent had copied every uncommitted file, plus `git diff HEAD`
  as a patch, to a folder OUTSIDE the repository. Recovery was: `git apply --check` of the patch
  against the new HEAD (read-only — proves the base files are identical), copy the files back,
  confirm `git diff HEAD` is byte-for-byte the saved patch. Five minutes, nothing rebuilt.
- **Standing practice:** before any pause for a git operation the developer will run, the agent saves
  a copy of all uncommitted work outside the repo and says where.

### Lesson: a browser pass writes real rows, and nothing rolls them back

The Pest suite runs every test inside a transaction that is rolled back, and the compat harness
builds its own scratch database and drops it. A BROWSER pass against the running app does neither:
every account it registers, role it grants, cart it fills and product it saves is a real row in
whatever database `.env` points at. On 2026-09-27 that left a throwaway account, its admin grant,
an activity-log entry, a cart and a re-saved product in the local dev copy — found and removed the
same day, but only because someone looked. Unchecked, the dev copy drifts from production one
session at a time, and the drift is invisible until a count does not match.

**Rule:** any browser testing plans, from the start, EITHER a scratch database (clone the dev copy,
point `.env` at it with a restore trap, drop it afterwards — the harness's own procedure) OR an
inventory-and-clean step: list every row created (accounts, roles, carts, orders, products,
colours, activity log) and delete them before the report, which states what was created and that
it is gone. Row timestamps cannot be restored, so a record of what was touched is part of the
report too.

### C-1 — the catalogue moves to the server (developer's go, 2026-09-27: stages 1–4 in order, then Arabic; ship each as ready)

- **Stage 1 — SHIPPED, verified live:** `/brand/Rolex` 14,250,000 → 3,746,271 bytes (−74%). See S4.
- **Stage 2 — SHIPPED 2026-09-27**, after an `.htaccess` step the deploy notes missed: the API host's
  allow-list named `catalog/meta` only, so Apache 404'd `catalog/nav` before core saw it. The
  developer widened it to `catalog/(meta|nav)` (nav 401 / meta 401 without the key, `/manage` still
  404). Now guarded: `ApiAllowListTest` fails when any storefront call is outside runbook §4.1.1's
  block. **Stage 3 adds routes: each one needs that block AND the server file widened.**
  The header menu, the mobile drawer and the category tiles no longer
  read the catalogue. They read the lookup tables plus core's new `GET /api/catalog/nav` (the brands
  with products, the sub-types and brands per category type, the genders with both names), which is
  derived from the `all_product` rows themselves (`CompatCatalog::nav`, cache family `compat_nav`,
  the same invalidation events as `compat_all_product`), with no legacy counterpart. Proof: every entry
  of the menu and the drawer (203, hovered and opened in a browser) is identical in English. Arabic
  differs only in the gender labels, which is a FIX: the trimmed catalogue copy dropped the localised
  gender name, so the Arabic menu said "رولكس Men".
  - **Prediction that did NOT hold:** the menu scanning the catalogue on every render was expected
    to make filter taps faster once removed. Measured, no change beyond run-to-run noise (±20%). The
    tap cost is the listing itself: the grid, the filter pass and the facet counts. That is stage 3.
  - **Moved to stage 3: the search box.** Its "View all results (N)" leads to `/listing?q=`, which
    still searches in the browser by substring. Server search (FULLTEXT, Arabic folding) matches
    differently, so moving only the dropdown would promise N results and list a different number.
    Both move together in stage 3.
- **Stage 3 — SHIPPED (live 2026-09-27; parity fixes and preflight removal live 2026-09-28):** the listing, the sidebar, the chip strip and the search dropdown
  read core's `GET /api/catalog/listing` (one page of rows + every facet count, `CompatListing`),
  and the cart drawer `GET /api/catalog/cards?ids=`. The (main) layout no longer embeds the
  catalogue. Only home, product/offer detail, cart, checkout and account do (`CatalogBoundary`),
  until stage 4.
  - Contract = the storefront's previous behaviour, bug for bug. `CatalogListingTest` runs the
    storefront's own `transformProduct.js` + `filterPredicate.js`, plus the old ListingClient /
    SideBar glue, in node over the same data for 36 scenarios and compares the totals, the page ids
    in order and every facet count. Mutation-checked: breaking the facet rule, the default order,
    the blank-price handling or the substring search each fails it.
  - Shipped in stage 3 as inherited, then FIXED by the developer's decision (2026-09-27): these were
    bugs, not decisions. (1) Facet counts follow the search. (2) The price filter and sorts use what
    the shopper pays (`CompatCart::catalogPrice`: sale only when 0 < sale < list), so a blank sale
    price is the list price, not 0. 438 products elsewhere in the catalogue have no sale price
    today; Brand Fashion would have hit this. (3) Search reads both languages with Arabic spelling
    folded, and only when nothing matches exactly it tolerates one typo per word of 4+ letters (2 for
    8+, a neighbour swap is one) against title and brand words. A search that matches never gains
    results. Parity reference rewritten to the new rules; `CatalogSearchTest` pins each case and is
    mutation-checked (always-add-near-misses and two-typos-on-short-words both fail it).
  - Browser, stage-2 build vs stage-3 build, 10 real interactions (sidebar, chips, colour, clear,
    page 2, sort, brand page): count, cards, every sidebar entry and count, chips and tags
    IDENTICAL. Search "rol": identical, "View all results (95)" = what `/listing?q=rol` shows.
  - HTML (local build): `/listing` 3.23 MB → 0.31 MB, `/brand/Rolex` 3.23 → 0.30, `/blogs`
    3.09 → 0.12. The ~45 KB target is NOT met: every page still carries the lookup tables (61 KB
    of JSON) plus the chrome, and the page's 24 rows (trimmed to card fields, 51 KB). That is stage 4
    work and beyond.
  - Taps (local, same machine): sidebar 196–238 → 104 ms, strip 284–351 → 160 ms to updated
    results; the main thread is no longer blocked by the filtering. **Live adds the network:**
    measured to api.watchizereg.com from the workstation, ~90 ms per request on a reused
    connection, and EVERY listing request is preceded by a CORS preflight (`Api-Code` is a custom
    header, `cors.max_age` = 0), another ~90 ms. Expect ~300 ms tap-to-results live, against today's
    ~240 ms synchronous filtering on desktop, and much less than today on a slow phone. Removing
    the preflight (the key is public in the JS bundle; send it without a custom header, or serve
    the catalogue reads same-origin) would save ~90 ms per tap. **Developer's decision, open.**
  - **SHIPPED 2026-09-27, verified live:** `/listing` 3.75 MB → 334 KB, `/brand/Rolex` → 304 KB.
  - **Taps MEASURED LIVE (desktop, 2026-09-27), until the result count changes:** a filter
    combination already fetched once answers in 106–221 ms (the query cache, and the CDN: responses
    are `public, max-age=600`). A NEW combination took 295, 343, 579, 580 ms — worse than the ~240 ms
    of the old in-browser filtering on desktop, and worse than the ~300 ms predicted. Breakdown, same
    day: CORS preflight ~90–130 ms; the listing GET ~90–170 ms to first byte (occasionally ~400);
    the body is brotli-compressed (58 KB → 8 KB, so the transfer is small); render ~90 ms. The page
    never blocks while it waits. (A measurement trap fixed on the way: the script's "updated" had
    fired on the first DOM change, which since stage 3 is the checkbox, not the results; it now waits
    for the result count to change.)
  - The preflight is being removed: the catalogue reads go header-less with `?api_code=` (see the
    parity-fix batch).
- Stage 4 (by-ids for cart, checkout and account; related products; home rails; drop the remaining
  catalogue copies and the client transform) follows.

### "THREE.WebGLRenderer: Context Lost" — CLOSED, not a defect (measured 2026-09-27)

The message is the 3D hero RELEASING its GPU context on purpose. `@react-three/fiber` 9.6.1's
`unmountComponentAtNode` waits 500 ms after the canvas unmounts and calls `gl.forceContextLoss()`
(`node_modules/@react-three/fiber/dist/events-*.esm.js`), and three.js logs that line on the
`webglcontextlost` event. That is why it appears just after you leave the home page. It is library
code on the unmount path and does not depend on the frameloop change.

Measured live, 8 round trips home → `/category/Watches` (two chip taps) → home, by in-app navigation,
with every WebGL context counted: at most ONE context alive at any time, each one released when you
leave (8 departures, 8 "Context Lost" lines), no browser "too many active WebGL contexts" warning,
and the watch still drawn on the 8th return (screenshot). The tab's context cap is never approached.

Not tested, and a separate question: a REAL loss while the hero is on screen (GPU reset, driver
crash). Whether the hero then falls back to its poster is unverified.

### Listing interaction — measured 2026-09-27

- **Filter taps (sidebar and quick-filter strip): the same cost, the same place.** Live, desktop, real clicks:
  sidebar median 239 ms (199–296), strip median 271 ms (241–426). Every tap re-renders ~630 components,
  192 of them the 24 product cards, in one synchronous task. The developer tested on a phone and it
  feels instant, so it is NOT treated as a defect. It disappears by construction in C-1 stage 3.
- **Strip scrolling is not a React problem.** Phone profile (390×844 @3×, touch, CPU ×4 and ×6), 20
  drags + 18 flicks: 0 grid renders, 0 long tasks, exactly ONE component re-rendered per gesture (the
  carousel's own position). Worst frame 50 ms at ×6, on the first flicks only, while the chip logos
  load. The whole-store subscriptions (`useUIStore()` with no selector, 33 call sites) are NOT what
  stutters it. **OBSERVED BUT UNREPRODUCED** (developer's decision, 2026-09-27): a fast flick of the
  strip hitches on the developer's phone; nobody else has reported it and emulation does not show it
  (candidates it cannot model: raster, image decode, Embla moving the strip from JavaScript every
  frame). Do NOT chase it now: re-check it on a real phone AFTER C-1 lands, because C-1 stage 3
  changes how that page works. If it is still there, take a phone trace first (`chrome://inspect`
  over USB); native `overflow-x` scrolling for the strip is the likely fix.
- Also left, by decision: the 239–271 ms filter taps (not felt on a phone; C-1 stage 3 removes them)
  and moving the 33 `useUIStore()` call sites to selectors (C-1 stage 3 makes it moot).
- **Measurement trap: a Chrome window that is covered or unfocused LIES.** It reports taps under 16 ms
  (it never paints), and it throttles animation frames to one per second while reporting
  `visibilityState: visible`. Launch with `--disable-features=CalculateNativeWinOcclusion
  --disable-backgrounding-occluded-windows --disable-renderer-backgrounding`, call
  `Page.bringToFront`, and throw away any run whose frame intervals jump to ~1000 ms.

### Colours — decided 2026-09-27

- **The ~7,090 products with no colour are a DATA-ENTRY job, not an import fix.** Measured: the
  WooCommerce export (8,614 rows, the bulk of the catalogue) carries a colour attribute on 7 rows.
  Re-importing cannot recover a colour the source never recorded — do not propose an import fix
  again. The team fills them in from the actual products, with the dashboard's colour picker
  (several colours per role since 2026-09-27).
- **Do not infer colours from product titles.** 1,065 product names contain a colour word; guessing
  from them is right most of the time and wrong in a way nobody notices until a customer receives
  the wrong item. Rejected by the developer.
- **Importer colour mapping: CLOSED 2026-09-28** — data entry, as above.
- Open: the Joyroom sheet does carry a colour per row — backfill and linking its variant colours to
  the taxonomy are scoped separately.
- **`catalog:colours-main-to-band` MOVED NOTHING on production.** The developer counted it before
  the deploy (2026-09-27): 0 products, 0 colours in the `main` role on non-watch families. Those
  families never had a colour entered at all. The command ships as a PREVENTIVE fix (the dashboard
  now asks these families for `band`, so nothing new lands in `main`). Its existence is NOT
  evidence that any colour data was migrated: there was none to migrate.
- **Gold is `#D4AF37`, not `#FFD700`** (developer's call, 2026-09-27). `#FFD700` was also Yellow's
  hex, so every gold watch showed a yellow swatch on the product page and in the filter. Changed as
  DATA in the dashboard (Lookups → Colours → Gold → hex), not in code, so it is in the activity
  log. Consequence: an order line saved before the change stores `#FFD700`, and the dashboard's
  hex→name lookup now names it "Yellow". The swatch colour on those lines is unchanged. Count them:
  `SELECT COUNT(*) FROM order_items WHERE color_band LIKE '%#FFD700%' OR color_dial LIKE '%#FFD700%';`

### Fixed today, for context

Image folders (`78a43a6`), host binding + cart option A (`5c3586e`), order totals (`704ac61`), L5
per-storefront URLs (`101d57c`), category filter (`7ff3a26`), batch 1 (`23e2b5a`, `3333a1f`), and
the bank_installment integration ID — the developer's data-entry slip, corrected to 5943061 in the
dashboard. Code was never at fault there: a test now pins that bank installments sends `[5943061]`
and CAGG `[5943060]`.

### Checks, 2026-09-27 — off finished runs, items 1 + 3 in the tree

Full Pest suite: 1753 passed, 38 skipped, 0 failed. PHPStan level 10: no errors. Pint: passed.
`tsc`: clean. Commit-hook self-test: 58/58. Compat harness on a scratch copy of the 2026-09-17
dump: 134 cases, zero unexplained differences; `.env` restored (fingerprint `43fffada70388afe`),
scratch database dropped. No storefront change in this batch, so no `next build` was needed.

**Intention expiry, as shipped:** NOT sent. `PAYMOB_INTENTION_EXPIRATION_SECONDS` is unset, so no
`expiration` goes to Paymob until its unit is measured with `scripts/paymob-expiry-probe.php` on the
server (two 1-EGP test intentions; it reads the lifetime from Paymob's own payment key). Exposure
until then: a shopper can still pay on Paymob's page after `orders:expire-unpaid` has cancelled the
order (after 60 min); that payment is recorded as a finding and needs a refund or a manual re-open
— never lost silently.

---

## A. Storefront lane — one rebuild carries all of these

| # | Item | What it costs | What breaks if never done |
|---|---|---|---|
| A1 | **SHIPPED.** **Category filter** — `passesFilters()` never read `filters.categories`; only Watches and Fashion filtered, via a hard-coded English-name pre-split. **Done on `wave-4d`** (2 lines + `FilterPredicateTest`, which runs the real predicate under Node and fails with the fix reverted) | Nothing more — ships with the next storefront build | Every category except Watches/Fashion shows the whole catalogue, in both languages. Pre-existing since July, on legacy too |
| A2 | ~~**Calls to deleted features.**~~ **Done in batch 1 (2026-09-26), on `wave-4d`.** Removed: `all_offer` on every page, `all_wishlist` on every signed-in page, `all_blog` server-side, dead `show_cart`/`fetchBanners`/`fetchOffers`, the unused `useBanners` (`all_banner_*`). `all_offer_rating`/`add_offer_rating` are unreachable (no offers → `/offer/…` 404s). **The wishlist interface is removed** (developer decision: a heart that never saves reads as a broken site) — the product-page heart, the cart's "Move to wishlist" (which REMOVED the item from the cart and then failed to save it), the account tab, the header/footer links; `/wish-list` now redirects to `/account`. Left: `add_product_rating` (B1) | — | — |
| A3 | **Banners — deferred, NOT dropped** (paused for the season; the developer will use them). Production has zero banner rows (2026-09-17 dump) and the storefront has no banner slot, so this is a build, not a switch. **What it needs:** (1) **the home-page slot** — a component that renders `meta.banners[]` with `placement=home`, picks the desktop or mobile image by `type_show` (`pc`/`mob`), links to `link_url` / the product (`product_id`) / the category (`category`, a tree reference), and renders NOTHING when the list is empty (no empty frame); where it sits on the home page is a design decision to make with the developer; (2) **the first v2 client** — the storefront reads only compat today; fetch `GET /api/v2/{STOREFRONT_CODE}/meta` (`STOREFRONT_CODE` exists since batch 1 in `src/lib/env.js`), mind v2's `http.cache` (10 min + 1 h stale, so a CDN purge after scheduling one) and that an image comes back as an `ImageUrl::object`, not a URL string; (3) **the dashboard screen that feeds it** — core already has it (placement is always `home`, by decision; scheduled with `starts_at`/`ends_at`, active flag, sort); check it uploads both a desktop and a mobile image | M: one component, one fetch, a browser pass on desktop and phone | The season's banners cannot be shown |
| A4 | ~~**Offer the new Paymob methods at checkout.**~~ **Built in batch 1 (2026-09-26), on `wave-4d`.** Core: `GET /api/v2/{storefront}/payment-methods` (usable rows only — usable integration id, contract enabled with complete credentials; both labels; per-method `min_total`/`max_total` from the dashboard; `Cache-Control: public, max-age=0, s-maxage=60`), and `add_order` checks `payment_method_id` BEFORE anything is written (`CheckoutMethods`): this shop's, offered, key agrees with `payment_method`, inside its limits — else a 422 in both languages that says the order was not placed and what to do. The wave-3 fallback is closed when the contract is live. Cash is always offered, independent of the rows. Storefront: the list, disabled-with-reason outside a limit, `payment_method_id` posted, Cash + "Pay Online" if the list fails. **Apple Pay:** its dashboard switch is the flag (leave the row disabled until Paymob confirms the domain verification for `watchizereg.com`); the storefront also shows it only where `ApplePaySession.canMakePayments()` is true. **2026-09-28: Paymob will make the remaining integrations live and send the IDs — entering them in the dashboard is all it takes; the screen and the checkout are already ready for them** | — | — |

| A5 | **Before promotions go on:** the storefront's account order view (`Account.jsx:453-456`) derives shipping as `total − lines`. Correct today; with a money promotion the discount would be shown as CHEAPER SHIPPING. Core's `OrderTotals` (2026-09-26) is the one definition — the view needs the promotion amount, and `me/orders` is frozen compat, so this is a sanctioned compat deviation or a v2 order endpoint | S–M, and a harness deviation if compat carries it | Wrong-looking shipping on every promoted order in a customer's history |
| A6 | **Before promotions go on: raise the installment fee.** Promotions are keyed on `paymob` (`PromotionRules::PAYMENT_METHODS` = cash, paymob, whatsapp), not on the method row. Since batch 1 (2026-09-26) every Paymob method — card, wallet, valU/CAGG, bank installments, Apple Pay — posts `payment_method=card` and so counts as `paymob` for a promotion. Installment providers charge the merchant a higher fee, and shops commonly exclude installments from discounts. Kept as is by decision; decide per promotion whether an installment order may take it, and if not, key the rule on the method row (the id `add_order` validates; today it is recorded only on the payment attempt, `payment_statuses.storefront_payment_method_id`) | S: one more condition in `PromotionRules`, one dashboard field | A promotion stacked on an installment order the margin was never meant to carry |
| A7 | **Next.js 15.5.27 on or after 30 September** (Vercel's scheduled security release: 1 critical, 2 high, 5 medium, 1 low). Batch 1 ships 15.5.26; this is its own follow-up, decided 2026-09-26. **A ten-minute job:** the `next` line in `package.json` → `pnpm install` (lockfile) → one storefront build → a check of product images (AVIF served, not broken) and one checkout to the payment page | ~10 min + the build | A published critical left open on the storefront |
| A8 | **AVIF quality — decided 2026-09-26: keep 15.5.2x's lower AVIF quality.** Since 15.5.24 Next encodes AVIF at `quality × 50/80` (was `quality − 20`): measured on 40 product photos, files 20–33% smaller, the only visible difference a slightly softer dial print at 2× zoom. **The one-line undo if anyone complains:** raise the `quality` props 80→96, 70→80, 75→88, 90→100 and `images.qualities` in `next.config.js` to match. **Do not misread #97954** (the 15.5.24 follow-up that "re-enables AVIF"): it re-enables DECODING AVIF *sources* when sharp's libheif is ≥ 1.23.2 (`isAvifDecodeSafe`) — that is why the sharp override is now `^0.35.4` (libheif 1.23.2). It does NOT change the output quality, and our masters are WebP anyway | One line per component | — |
| A9 | **Next.js 16 — after Brand Fashion's launch, not before** (decided 2026-09-26). 15.5 is Maintenance LTS (security fixes only); 16.x is Active LTS. **Estimate: about a week, mostly testing.** The real work is **React 18 → 19.2** (Next 16 requires it) — and it is OVERDUE rather than optional: `@react-three/fiber` 9 and `drei` 10 already require React 19 and are unmet peers today (`pnpm peers check`), i.e. the 3D home runs on a combination nobody supports. MUI 6, emotion, framer-motion 12, TanStack Query and zustand support 19; `prop-types` is used in one file. Already compatible: `params` awaited, no `middleware` file, no `next lint`, `eslint-config-next` on 16, `qualities`/`minimumCacheTTL` set explicitly. **Watch:** Turbopack becomes the default build — check the React-resolution note for the 3D packages in `next.config.js` (`transpilePackages`, "do NOT alias react"). **Then:** a browser pass through home (3D), listing, product, cart and checkout. **Also in this work:** `THREE.Clock` is deprecated (console warning on every home load, 2026-09-27) — move to `THREE.Timer` with the three/fiber upgrade | ~1 week | Security fixes only on 15.5; the 3D stack stays on an unsupported React |

## B. Core lane — one tar deploy carries all of these

| # | Item | What it costs | What breaks if never done |
|---|---|---|---|
| B1 | **The rating write** (`add_product_rating`) — the week agreed in §8 | Core endpoint + tests + a compat-harness case, **and one line in §4's `.htaccess` allow-list** (it is not on it — without that line the endpoint 404s even once built). No storefront change if the shape matches legacy | "Could not send" on every review; ratings stay at zero forever |
| B2 | **Meta Conversions API for card payments** (§11.1) | About a day: one server-side `POST` beside `flush($mailIds)` in the payment callback, hashed-email `user_data`, an `event_id` that dedupes against the pixel, a retry path; a Meta system-user access token (new secret, env by name only); Events Manager's test-events tool to prove it | **Card sales never reach Meta.** Campaigns optimise on cash orders only and under-report return on ad spend — for as long as this is open |
| B3 | **Verification lands on raw JSON; no way to ask for a new link.** `verify()` returns `{"message":…}` to a browser (as legacy did); nothing in the storefront calls `resend-verification`. Smallest fix: `verify()` redirects a browser to `FRONTEND_URL/account?verified=1\|already\|invalid\|expired` (JSON callers unchanged); **spans both lanes** — the account page shows a toast and a "send a new link" button | ~15 lines in core + a toast and a button in the storefront + tests | The bare-JSON page (cosmetic). Worse: a link expires after 48 h, or a pre-flip legacy link verifies the LEGACY database — either way the customer can never re-verify, **and guest-order linking happens on verification**, so their earlier guest orders never attach to the account |
| B4 | **Pin the verification link's host** in `CustomerMail::sendEmailVerification()` | A few lines + a test (with B3) | Correct today only because every sender is a customer route on the API host. The first CLI or dashboard sender builds the link on `eleganceeg.com`, which §4 404s |
| B5 | **Reordering payment methods is not in the activity log** — the order decides which contract takes the money | Small: one `ActivityLog::record` in `reorder()` + test | "Who moved card traffic to the other contract?" has no answer |
| B6 | **`PromotionController` has no storefront-scope check** (audit, left open by decision) | Small | Nothing — until anyone issues a SCOPED grant. Close it before that day |
| B7 | **CLOSES WITH C3 (2026-09-28).** **`compat:env-parity`** — the `[key]` arm should say "proxy disabled on purpose, use `--base`" and needs a path legacy actually guards; the `[assets]` rule should check the media root RESOLVES to the served tree, not that it is written absolute | Small, tooling only | The command keeps reporting NOT READY on a healthy host, and people learn to ignore it |

## B+. Sitemap and SEO (found 2026-09-26, after resubmitting the sitemap)

| # | Item | What it costs | What breaks if never done |
|---|---|---|---|
| B8 | **The sitemap sets cookies.** `/sitemap.xml` and `/{locale}/sitemap.xml` are web routes (`routes/web.php:500-501`) that opt out of Inertia but not of the session and CSRF middleware — every crawl gets `XSRF-TOKEN` and a session cookie. The XML itself is correct (200, `application/xml`, same for Googlebot); Search Console's first "couldn't fetch" was transient | S: take both routes out of the session, cookie and CSRF middleware (or move them to a cookieless group) + a test that the response carries no `Set-Cookie`. No harness change — the body is untouched | Every crawl opens a server session that is never used, and no cache or CDN can store the response |
| B9 | **The sitemap lists `/offers` and `/blogs`**, hard-coded in the static list (`CompatSitemap.php:31`). `/offers` is the deleted legacy offers page and now a REDIRECT (`next.config.js` → `/listing?offers=true`); `/blogs` has no articles | S: drop both from the static list (put `/blogs` back when articles exist). **The harness compares `sitemap:en` and `sitemap:ar` byte for byte**, so this is a new sanctioned deviation (core omits two legacy URLs) + a 134-case rerun | Google crawls and indexes an empty page and a redirect every time; both dilute the sitemap's signal |

## BF. Brand Fashion launch prerequisites — FUNCTIONAL only (decided 2026-09-26)

**Decision, not open: hiding that the two brands share an owner is NOT pursued.** It is understood
and accepted that a deliberate observer can connect them — shared e-mail templates and sender, one
Google consent screen, one image tree served on both API hosts, cookie and dashboard names, the
dashboard's login branding, the shared sending server, DMARC at `p=none`. Closed as accepted: L1's
templates and mail identity, N3b, L6, B10, B11, L1b. **Do not re-propose them as privacy work.**
Already done and kept, because each also fixed something real: the repo is private; v2 and the
payment callback answer only their own host; Watchizer's EHLO is `watchizereg.com`.

What remains is what would otherwise be BROKEN on Brand Fashion:

| # | Item | When | Cost |
|---|---|---|---|
| L5 | **Per-storefront values** — the post-payment return URL (a Brand Fashion shopper must land back on `brandfashionegy.com`), the password-reset and sign-in landing host, CORS for Brand Fashion's origin, and the asset host its payloads name | **Now** | S–M |
| L3 | **Google sign-in per storefront** — a redirect URI (and credentials) per shop, or sign-in cannot complete on Brand Fashion. The consent screen's name is irrelevant | When Brand Fashion's frontend starts | M |
| L2 | **Accounts per storefront** — design first; built last, immediately before launch, so it is the freshest change when the second shop goes up | Last | M–L |
| **L8** | **🔴 LAUNCH BLOCKER — Brand Fashion's catalogue is too big for how core builds the listing** (measured 2026-09-28, local). The listing index, the header menu's facts (`compat_nav`) and the `all_product` payload are each built from the WHOLE catalogue in ONE request and stored as ONE cache entry. Watchizer: 698 products → ~1.5 s, 60 MB peak. **Brand Fashion: 7,579 products → 41 s and 206 MB**, over PHP's 128 MB `memory_limit` (the warm-up died on it). So the day a domain points at Brand Fashion, its first listing or menu request dies with a fatal error — and because nothing gets cached, **so does every request after it**. 11× the products took 27× the time, so something in the build scales worse than linearly; it has NOT been profiled. **What it takes:** (1) build the listing index and `compat_nav` from a lean SQL read of only the fields they filter, sort and search on — never from full `all_product` rows; (2) cards already come from per-product entries (item 4, 2026-09-28); (3) nothing on the request path may read the whole catalogue — which means C-1 stage 4 (the storefront's remaining client catalogue copies and the wholesale `all_product` read) has to be done first; (4) warm Brand Fashion off the request path (`COMPAT_WARM_STOREFRONTS=1,2`, item 4's warmer), chunked if one build is still too big; (5) prove it with Brand Fashion's real 7,579 products under `memory_limit=128M` and the host's execution-time limit, cold. Raising `memory_limit` is not a fix: it does nothing about 41 s. Profile first, then decide (1)'s shape | **Before Brand Fashion's domain is pointed; after C-1 stage 4** | M (~1–2 days, estimate before profiling) |
| — | **Ordering:** set `storefronts.domain` for Brand Fashion BEFORE pointing `api.brandfashionegy.com` at the server — `CompatStorefront` falls back to Watchizer for an unknown host and would serve Watchizer's catalogue | At DNS time | none |
| L4, L7 | Paymob merchant account, legal entity, owner checklist | Owner | — |

## SEO. Audit of the live storefront, 2026-09-27 (read-only; M = measured, I = inferred)

**RESOLVED 2026-09-28: Googlebot is NOT blocked** — the developer's Search Console check shows `https://watchizereg.com/` indexed and available. What follows is kept as the record. **Before anything else — the bot wall (M, cause; I, effect on Google).** Hostinger's CDN answers
any request that asks for compressed content (`Accept-Encoding`) with a 403 "Checking your
browser" challenge, whatever the user agent — which is why Lighthouse/PageSpeed get 403 and plain
curl gets 200. Real Googlebot is PROBABLY let through by IP (Hostinger's docs), but that cannot be
proven from outside. **Owner check (minutes):** Search Console → URL Inspection → Test live URL on
the home page and a product; Settings → Crawl stats → 403 count. If Googlebot is refused, turn off
the bot protection / whitelist verified bots in hPanel → CDN — nothing below matters until then.

| # | Finding | Fix | Cost |
|---|---|---|---|
| S1 | ~~Every product image blocked: `core/public/robots.txt` on api.watchizereg.com was `Disallow: /`~~ **FIXED 2026-09-27** (`Allow: /Uploads_Images/`, everything else still closed; `RobotsTxtTest`) — ships with the next core deploy | — | 15 min |
| S2 | **Arabic is invisible to Google** — language comes only from the `wz-lang` cookie, the server always renders English, no `/ar` URLs, no hreflang. Scoped as a staged project below (S-AR) | see S-AR | project |
| S3 | Product `meta_title` / `meta_description` never used (titles are a template in `src/lib/detailSeo.js`); Arabic filled on ~8–10%, English 83–92%; about half the English meta titles end in the junk text " \| Select…" | S-AR stage 2 | hours–1 day |
| S4 | **Stages 1–3 SHIPPED (listing and facet pages ~0.3 MB); the rest is stage 4.** HTML weight: 3.4–4 MB on home/product pages, 12.8–14 MB on every facet page (`/category/*`, `/brand/*`, `/grade/*`, `/subtypes/*`); HTML is `private, no-store` | **C-1 stage 1 DONE 2026-09-27 (in the tree, ships with the next storefront build):** the facet pages' second, unprojected catalogue copy is gone from `src/lib/facetListing.jsx`. Measured on the local build: every facet route 10.65 MB → 3.22 MB, the same as `/listing`; same counts, same 24 cards, same titles; no catalogue refetch in the browser. Live before: 14.25 MB, 9.8–11 s. The remaining 3.2–4 MB on every page goes in C-1 stage 4. | stages 2–4: days |
| S5 | Sitemap (`core/app/Compat/CompatSitemap.php`): 5 static URLs 404 (`/products`, `/about-us`, `/contact-us`, `/privacy-policy`, `/terms-and-conditions`); 28 multi-word brands encoded with `%20` → 404; `/offers` redirects; `/blogs` empty but indexable; 15 duplicate `<loc>`; zero-product brands listed | drop/fix entries, slugify brands with `LegacySlug`, dedupe — or move to the v2 sitemap in S-AR stage 3 (the compat sitemap is a harness case, so fixing it in place is a sanctioned deviation) | hours |
| S6 | Category/brand landing pages linked only from the sitemap (nav links go to `/listing?…`, canonicalised to `/listing`); listing pagination is `<button>`, not links; no related products on product pages | point nav/footer/breadcrumbs at `/category/*`, `/brand/*`; `<a href="?page=n">` pager | 1–2 days |
| S7 | Fuzzy/case-insensitive facet matching makes `/brand/rol`, `/brand/Rolex`, `/brand/rolex` separate self-canonical pages; facet titles say "Watches" for bags/belts | canonicalise to the slug + 308; titles by category type | hours |
| S8 | Product JSON-LD missing `hasMerchantReturnPolicy`, `shippingDetails`, `itemCondition`, `mpn`; says `InStock` where the page shows "Pre-Order" (market stock); home `Store` block has wrong `sameAs` and generic geo; no `WebSite`/`Organization` | complete the markup; `PreOrder` for market-only stock | hours |
| S9 | `www.watchizereg.com` serves 200 (not a 301 to the apex); root layout's default metadata is Arabic while pages are English; `/blogs` should be noindex | redirects / metadata | hours |

**Owner decision (not an open item):** several products are priced far below genuine retail while
every page and description says "Authentic, certified & guaranteed". Whether those claims stand is
the client's call, raised with them by the developer (2026-09-27). It matters before any Google
Merchant Center feed (counterfeit / misrepresentation policies), which also requires the trust
pages that currently 404 (about, contact, privacy, terms, returns).

### S-AR — Arabic for Google, in stages that each ship

Decision to take first: **English keeps the bare URLs** (they carry today's ranking and links) and
Arabic moves under `/ar/…` — the reverse of the v2 sitemap's current default-locale-unprefixed
rule, which must be flipped for Watchizer.

1. **Arabic pages that exist for Google (4–6 days).** `/ar/…` for home, product, category/brand
   facet pages and listing, served by a middleware rewrite onto the existing routes with the locale
   passed down. The server renders Arabic — the server catalogue already carries `productsAr` — and
   the client store starts from the URL's language, not only after mount (the cookie becomes a
   preference for the language switch, not the source of truth). Per-locale `<title>`, description,
   H1, alt text and JSON-LD names from the Arabic title + short description (≈100% filled, so no
   hand-written meta needed); self-canonical per language; hreflang ar/en/x-default on every page;
   the language switch links between the two URLs. **Buys on its own:** every product and category
   becomes indexable in Arabic — the Arabic queries that are most of Egyptian search volume.
   Browser pass in both languages (RTL), and a check that the English pages are byte-for-byte
   unchanged apart from the hreflang tags.
2. **Metadata quality (1–2 days).** Clean the " | Select…" junk out of `meta_title` (one-off
   command, dry run first); wire `meta_title`/`meta_description` with the fallback
   meta[locale] → title[locale] + brand, and meta_description[locale] → short_description[locale]
   → template. **Buys:** better snippets and click-through in both languages.
3. **Sitemap per language (1–2 days).** Serve the v2 per-locale sitemaps (flipped prefix rule) with
   `xhtml:link` alternates; fold in S5's dead-URL fixes; image host = the host pages use. **Buys:**
   faster, complete discovery of the Arabic URLs, and no 404s in the sitemap.
4. **Optional, later: Arabic slugs** (`/ar/product/ساعة-…`) and Arabic landing copy for
   categories/brands. Marginal ranking gain, real redirect/duplicate risk — decide after stage 1's
   Search Console data.

Total ≈ 1.5–2.5 weeks, stage 1 alone ≈ one week. Prerequisite for all of it: the bot-wall check
above.

## C. Operations and security — no code, or not ours

| # | Item | What it costs | What breaks if never done |
|---|---|---|---|
| C1 | **Rotate `JWT_SECRET` on BOTH hosts — the public-key half DROPPED 2026-09-28 (see START HERE).** ~~Rotate `JWT_SECRET` and the storefront's public API key~~ — the only two values pasted into a chat. **Paymob is NOT part of this:** nothing exposed its credentials (corrected 2026-09-24; an earlier note here listed it by mistake) | Every customer is signed out (tokens last 30 days). While legacy is up it must change on BOTH hosts at once; **after C3 it is core alone — simpler.** The public key means panel + `.env.production` + core's `COMPAT_API_KEY` together, then a rebuild (a short window of 401s) | **Anyone holding `JWT_SECRET` can mint a valid token for ANY customer id** — full access to that account's profile, addresses and orders. This is the one item whose risk is not cosmetic. Recommend days, not next season |
| C2 | **SHIPPED in batch 1.** **Next.js 15.5.21 → 15.5.26** (critical RCE advisory GHSA-2xp9-vwfh-vxw4, fixed in 15.5.24) + `sharp` 0.35.3 → 0.35.4 | A lockfile refresh, a build, a visual check. Behaviour changes: AVIF *sources* served unoptimised; AVIF *output* quality rescaled (80 → 50, 70 → 44, 90 → 56 — smaller, slightly softer). Cheap hardening in the same build: remove `127.0.0.1:8000` and `localhost:8000` from production `remotePatterns`, and the Farfetch host if nothing uses it | Exposure is narrow — our uploads are re-encoded to WebP, so no outsider can place an AVIF on our hosts — but an allowed third-party host or a future upload path turns it into unauthenticated remote code execution on the storefront server |
| C3 | **Switch the legacy storefront off** (§4A.4) — after any card payment started there has settled | Small. It also retires C4 and the undeployed `backend/` fixes, and makes C1 single-host | Legacy keeps answering; anyone who reaches it writes orders into the database nobody reads any more |
| C4 | **CLOSES WITH C3 (2026-09-28).** **The legacy gate question** — `dash.watchizereg.com/api/catalog/meta` answered 200 without `Api-Code` | Two reads (cache-busted curl; the key's length in legacy's cached config) | Moot once C3 is done. Until then, if the gate is open, runbook §9.1A's residual-risk row is wrong as written |
| C5 | **One source for the storefront's build values** — the Hostinger panel now overrides `.env.production` for five names (and the pixel list is in both) | Delete the panel variables, rebuild, run §7 step 0 | Two sources drift; rollback must be done in the panel (§9.1's box), and §11.2's "dropping a pixel is one line in `.env.production`" is no longer true |
| C6 | **DROPPED 2026-09-28.** **Git history purge** — the Google OAuth secret, the audit key, the July `api.jsx` literal | A history rewrite, a force-push, every clone re-cloned. All three are already dead or rotated | Nothing live — dead values stay readable in a public repo's history |
| C7 | **55 missing media files** — `media:verify` found 10,003 of 10,058 | Identify the 55, re-upload or clear the references | Up to 55 image slots show a broken image |
| C8 | **Confirm the logs are being written** — the log channel was dead from the first deploy until the night | One look tomorrow: `ls ~/domains/eleganceeg.com/core/storage/logs/` should show a `laravel-2026-09-2*.log` | Callback refusals, amount mismatches and mail failures go unseen again |
| C9 | **DROPPED 2026-09-28.** **User 7's first verification mail never arrived** (before the log fix; a broken log channel does not stop SMTP) | Check that inbox's spam folder | Probably nothing — later mails arrive. Unexplained, so recorded |
| C10 | **Server leftovers** — `~/core-phase1.tar.gz`, `~/deploy-staging/`, `~/s4-block.txt`, the `.bak` snapshots | Delete once the rollback window closes (keep `core-before-phase2.*.tar.gz` and `env.before-phase2.*.bak` until then) | Disk, and one `.env` copy (mode 600) lying in the home directory |

## D. Documentation — DROPPED 2026-09-28 (developer)

- §3.1's `APP_URL` row says signed links are "built from" it — they are built from the request host.
  `APP_URL` stays `eleganceeg.com` (it sets the mail EHLO name).
- §4.3's probe list — `api/login` and `api/add_order` are POST-only, `api/auth/google` is not a
  route (corrections are in "Found on the night"; fold them into the table).
- §11.2 — the pixel list now also lives in the panel (C5).

---

**Holding, not deciding:** blogs stay unscheduled until the per-storefront scoping decision
(Phase 0, G9). The hidden payment-method icon field comes back with A4, labelled with whatever the
checkout accepts.
