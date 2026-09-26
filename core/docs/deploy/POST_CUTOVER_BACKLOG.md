# After the cutover — everything deferred, in one place

**Written 2026-09-24, the night of the Phase 2 flip.** Nothing here is broken in a way a shopper
cannot get past tonight; every item is a known, named state. Pick batches from it.

**How batches cost.** A *storefront* item ships with a push to `main` → one Hostinger rebuild →
§7 step 0's bundle check. A *core* item ships as a tar deploy (runbook §2, minus the migrations) →
`config:cache`/`route:cache`. Items in the same lane share that cost, so batch within a lane.

---

## A. Storefront lane — one rebuild carries all of these

| # | Item | What it costs | What breaks if never done |
|---|---|---|---|
| A1 | **Category filter** — `passesFilters()` never read `filters.categories`; only Watches and Fashion filtered, via a hard-coded English-name pre-split. **Done on `wave-4d`** (2 lines + `FilterPredicateTest`, which runs the real predicate under Node and fails with the fix reverted) | Nothing more — ships with the next storefront build | Every category except Watches/Fashion shows the whole catalogue, in both languages. Pre-existing since July, on legacy too |
| A2 | **Calls to deleted features.** `all_offer` runs on **every page** (`app-state-bridge.jsx` → `useOffers`), plus the product page (`all_offer`, `all_offer_rating`); `all_banner_*` on home; `all_wishlist`/`add_wishlist`/`delete_wishlist` from the heart and the account tab; `all_blog` server-side on the blog pages; `show_cart` in dead code (`api.jsx` `fetchCart`, no caller). None is on §4's allow-list, so each is a 404 | **Minimum:** stop the every-page `all_offer` call (return `[]` from `useOffers`) — an hour. **Full:** remove the offer surfaces, the wishlist heart and tab, the blog fetch — half a day, and it touches `Cart`, `CartModal` and `Checkout` (they read offers), so it needs a browser pass through checkout | One wasted request and a console 404 on every page view; empty offer/blog/wishlist UI that looks broken rather than absent. No data loss — production returns nothing for any of them |
| A3 | **Banners on `meta.banners[]`** — core already serves them (scheduled, windowed, placements); the storefront still asks `all_banner_*` | Small: switch `useBanners` to `meta.banners`, and the dashboard's banner screen feeds it | Banner areas stay empty. Fine while the season has them paused |
| A4 | **Offer the new Paymob methods at checkout** (wallet 5943059, installments 5943060, bank installments 5943061, Apple Pay 5943068). The IDs are entered and usable in core; the storefront offers only cash and card | **Largest item here, and it spans both lanes.** Core: expose `MethodList::forCustomer()` to the storefront and **validate `payment_method_id` on `add_order`** (today it is taken unvalidated and resolved against ANY enabled row — untested what a card order routed to an offline row does). Storefront: render the list, post the id. Promotions are keyed on `paymob`, not per method — decide whether that is right. **Apple Pay may need Paymob's domain-verification step for `watchizereg.com`** — confirm with Paymob before building | Four live integrations sit unused; customers see card and cash only |

| A5 | **Before promotions go on:** the storefront's account order view (`Account.jsx:453-456`) derives shipping as `total − lines`. Correct today; with a money promotion the discount would be shown as CHEAPER SHIPPING. Core's `OrderTotals` (2026-09-26) is the one definition — the view needs the promotion amount, and `me/orders` is frozen compat, so this is a sanctioned compat deviation or a v2 order endpoint | S–M, and a harness deviation if compat carries it | Wrong-looking shipping on every promoted order in a customer's history |

## B. Core lane — one tar deploy carries all of these

| # | Item | What it costs | What breaks if never done |
|---|---|---|---|
| B1 | **The rating write** (`add_product_rating`) — the week agreed in §8 | Core endpoint + tests + a compat-harness case, **and one line in §4's `.htaccess` allow-list** (it is not on it — without that line the endpoint 404s even once built). No storefront change if the shape matches legacy | "Could not send" on every review; ratings stay at zero forever |
| B2 | **Meta Conversions API for card payments** (§11.1) | About a day: one server-side `POST` beside `flush($mailIds)` in the payment callback, hashed-email `user_data`, an `event_id` that dedupes against the pixel, a retry path; a Meta system-user access token (new secret, env by name only); Events Manager's test-events tool to prove it | **Card sales never reach Meta.** Campaigns optimise on cash orders only and under-report return on ad spend — for as long as this is open |
| B3 | **Verification lands on raw JSON; no way to ask for a new link.** `verify()` returns `{"message":…}` to a browser (as legacy did); nothing in the storefront calls `resend-verification`. Smallest fix: `verify()` redirects a browser to `FRONTEND_URL/account?verified=1\|already\|invalid\|expired` (JSON callers unchanged); **spans both lanes** — the account page shows a toast and a "send a new link" button | ~15 lines in core + a toast and a button in the storefront + tests | The bare-JSON page (cosmetic). Worse: a link expires after 48 h, or a pre-flip legacy link verifies the LEGACY database — either way the customer can never re-verify, **and guest-order linking happens on verification**, so their earlier guest orders never attach to the account |
| B4 | **Pin the verification link's host** in `CustomerMail::sendEmailVerification()` | A few lines + a test (with B3) | Correct today only because every sender is a customer route on the API host. The first CLI or dashboard sender builds the link on `eleganceeg.com`, which §4 404s |
| B5 | **Reordering payment methods is not in the activity log** — the order decides which contract takes the money | Small: one `ActivityLog::record` in `reorder()` + test | "Who moved card traffic to the other contract?" has no answer |
| B6 | **`PromotionController` has no storefront-scope check** (audit, left open by decision) | Small | Nothing — until anyone issues a SCOPED grant. Close it before that day |
| B7 | **`compat:env-parity`** — the `[key]` arm should say "proxy disabled on purpose, use `--base`" and needs a path legacy actually guards; the `[assets]` rule should check the media root RESOLVES to the served tree, not that it is written absolute | Small, tooling only | The command keeps reporting NOT READY on a healthy host, and people learn to ignore it |

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
| — | **Ordering:** set `storefronts.domain` for Brand Fashion BEFORE pointing `api.brandfashionegy.com` at the server — `CompatStorefront` falls back to Watchizer for an unknown host and would serve Watchizer's catalogue | At DNS time | none |
| L4, L7 | Paymob merchant account, legal entity, owner checklist | Owner | — |

## C. Operations and security — no code, or not ours

| # | Item | What it costs | What breaks if never done |
|---|---|---|---|
| C1 | **Rotate `JWT_SECRET` and the storefront's public API key** — the only two values pasted into a chat. **Paymob is NOT part of this:** nothing exposed its credentials (corrected 2026-09-24; an earlier note here listed it by mistake) | Every customer is signed out (tokens last 30 days). While legacy is up it must change on BOTH hosts at once; **after C3 it is core alone — simpler.** The public key means panel + `.env.production` + core's `COMPAT_API_KEY` together, then a rebuild (a short window of 401s) | **Anyone holding `JWT_SECRET` can mint a valid token for ANY customer id** — full access to that account's profile, addresses and orders. This is the one item whose risk is not cosmetic. Recommend days, not next season |
| C2 | **Next.js 15.5.21 → 15.5.26** (critical RCE advisory GHSA-2xp9-vwfh-vxw4, fixed in 15.5.24) + `sharp` 0.35.3 → 0.35.4 | A lockfile refresh, a build, a visual check. Behaviour changes: AVIF *sources* served unoptimised; AVIF *output* quality rescaled (80 → 50, 70 → 44, 90 → 56 — smaller, slightly softer). Cheap hardening in the same build: remove `127.0.0.1:8000` and `localhost:8000` from production `remotePatterns`, and the Farfetch host if nothing uses it | Exposure is narrow — our uploads are re-encoded to WebP, so no outsider can place an AVIF on our hosts — but an allowed third-party host or a future upload path turns it into unauthenticated remote code execution on the storefront server |
| C3 | **Switch the legacy storefront off** (§4A.4) — after any card payment started there has settled | Small. It also retires C4 and the undeployed `backend/` fixes, and makes C1 single-host | Legacy keeps answering; anyone who reaches it writes orders into the database nobody reads any more |
| C4 | **The legacy gate question** — `dash.watchizereg.com/api/catalog/meta` answered 200 without `Api-Code` | Two reads (cache-busted curl; the key's length in legacy's cached config) | Moot once C3 is done. Until then, if the gate is open, runbook §9.1A's residual-risk row is wrong as written |
| C5 | **One source for the storefront's build values** — the Hostinger panel now overrides `.env.production` for five names (and the pixel list is in both) | Delete the panel variables, rebuild, run §7 step 0 | Two sources drift; rollback must be done in the panel (§9.1's box), and §11.2's "dropping a pixel is one line in `.env.production`" is no longer true |
| C6 | **Git history purge** — the Google OAuth secret, the audit key, the July `api.jsx` literal | A history rewrite, a force-push, every clone re-cloned. All three are already dead or rotated | Nothing live — dead values stay readable in a public repo's history |
| C7 | **55 missing media files** — `media:verify` found 10,003 of 10,058 | Identify the 55, re-upload or clear the references | Up to 55 image slots show a broken image |
| C8 | **Confirm the logs are being written** — the log channel was dead from the first deploy until the night | One look tomorrow: `ls ~/domains/eleganceeg.com/core/storage/logs/` should show a `laravel-2026-09-2*.log` | Callback refusals, amount mismatches and mail failures go unseen again |
| C9 | **User 7's first verification mail never arrived** (before the log fix; a broken log channel does not stop SMTP) | Check that inbox's spam folder | Probably nothing — later mails arrive. Unexplained, so recorded |
| C10 | **Server leftovers** — `~/core-phase1.tar.gz`, `~/deploy-staging/`, `~/s4-block.txt`, the `.bak` snapshots | Delete once the rollback window closes (keep `core-before-phase2.*.tar.gz` and `env.before-phase2.*.bak` until then) | Disk, and one `.env` copy (mode 600) lying in the home directory |

## D. Documentation — owed, cheap

- §3.1's `APP_URL` row says signed links are "built from" it — they are built from the request host.
  `APP_URL` stays `eleganceeg.com` (it sets the mail EHLO name).
- §4.3's probe list — `api/login` and `api/add_order` are POST-only, `api/auth/google` is not a
  route (corrections are in "Found on the night"; fold them into the table).
- §11.2 — the pixel list now also lives in the panel (C5).

---

**Holding, not deciding:** blogs stay unscheduled until the per-storefront scoping decision
(Phase 0, G9). The hidden payment-method icon field comes back with A4, labelled with whatever the
checkout accepts.
