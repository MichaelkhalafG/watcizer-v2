# Security audit 2026-09-23: fix record

Branch `wave-4d`. The fixes are pushed as `5422a31`. The battery's PHPStan fixes to four test files, and this record's update, are uncommitted. The audit itself is `AUDIT_2026-09-23.md` in this folder.

## FIRST THING NEXT SESSION

The battery is green (below). Hand findings 1, 2 and 3 and the legacy gate to the reviewer for re-check.

## Items

| # | Item | State |
|---|------|-------|
| 1 | 🔴 A scoped admin becomes global admin | **FIXED, approved.** `Roles::isUnscopedAdmin()` / `scopeForAbility()`; `Gate::before` asks about one grant at a time. `UserRoleController` limits store, storeAccount and destroy (and the lists) to the actor's scope. Storefront edit/update routes scoped. Premise-based exemption removed from `CatalogAuthorizationTest`. `ScopedAdminTest` (10 tests; 6 fail with the fix reverted). **Open on purpose:** `PromotionController` has no scope check. Scoped grants are unused (operating decision). Close the gap before anyone issues one. |
| 2 | 🔴 `add_order` PII disclosure/IDOR, plus `add_address` | **FIXED.** The buyer comes from the JWT only; a body `user_id` that does not match gets 401. `address_id` must belong to the buyer (or to the guest token). `addAddress` no longer falls back to the body. `addressRow()` no longer emits `guest_token`. `throttle:add-order` limits to 10 per minute and 60 per hour. `CheckoutOwnershipTest` (10 tests, each verified by reverting the fix). |
| 3 | 🟠 JSON-LD stored XSS | **FIXED, approved; browser-proven broken then fixed.** `Frontend-next/src/lib/safeJsonLd.js`, applied at all 8 sinks. `JsonLdSinkTest` guards against the old pattern. |
| 4 | 🟠 Legacy API gate fails open | **FIXED in `backend/`, NOT DEPLOYED** (decision). Residual risk is recorded in runbook §9.1A. |
| 5 | 🟠 Legacy `/register` unthrottled | **FIXED in `backend/` (`throttle:5,60`), NOT DEPLOYED.** Same record as item 4. |
| 6 | Widen D-25, re-diff | **DONE.** 134 cases PASS, zero unexplained (`core/storage/compat-diff/20260923-182125/report.md`). Ran on a scratch copy; scratch database dropped afterwards. |
| 7 | Study §3.4 step b becomes a no-rebuild rule; guard notes on repair migrations | **DONE.** `CLEAN_CORE_STUDY.md` (written via safe_write, backup kept). Notes added to the 3 repair migrations. Runbook §12 now says "scratch copy only". |
| 8 | `SESSION_SECURE_COOKIE` default | **DONE.** Secure unless `APP_ENV=local`. Runbook §7 step 14 adds a live `set-cookie` check. `HardeningDefaultsTest`. |
| 9 | CORS exposes `X-Guest-Token` | **DONE.** `config/cors.php`; tested in `HardeningDefaultsTest`. |
| 10 | Secret scanner and pre-commit hook: new shapes, "working tree only" | **DONE.** Rules S1–S6 in both, plus the `*client_secret*.json` name rule. `SecretsInRepoTest`: 6 passed. Hook self-test: 52/0. Hook simulated over the whole branch: 0 failures. |
| 11 | July `api.jsx` literal added to AGENTS §3 | **DONE.** Seventh inventory item, marked DEAD (sha256 prefix `1e463e2c`, matches no current key). Deliberately not on `KNOWN`. The AGENTS notes for this round are recorded in the same pass. |

**Decisions recorded, not built:**
- Cost price visible to data-entry is intended.
- The OAuth secret in history was rotated 2026-09-07 and is dead; it waits for the history cleanup.
- `audit/` untracking is done.
- The `cp -a` window is accepted.

## Untested item: Next.js advisory GHSA-2xp9-vwfh-vxw4 (NOT bumped)

- **The advisory:** critical, unauthenticated RCE through `libheif` when the image optimizer DECODES an AVIF source. Affects `next` >=10.0.0 and <15.5.24. **We run 15.5.21, so our version is in range.**
- **Our exposure is narrow, as far as I can find:** an attacker needs AVIF bytes at a URL that `remotePatterns` allows, or at a local path. Both customer avatar paths (core `MediaStore`, legacy `AuthController`) re-encode uploads to WebP. The only AVIF on our hosts are core's own GD-made renditions.
- **Still outside our control:**
  - `cdn-images.farfetch-contents.com` is on the allow list.
  - `127.0.0.1:8000` and `localhost:8000` are allowed in production.
  - `localPatterns` is unset, so `/api/*` and `/Uploads_Images/*` go through rewrites.
- **What a bump costs:**
  - `next` 15.5.21 → 15.5.24 or later (the latest 15.5 line is 15.5.26, released 2026-09-22), plus `sharp` 0.35.3 → 0.35.4 (libvips 8.18.6). The pnpm override is `^0.35.0`, so a lockfile refresh picks it up.
  - Behaviour changes: AVIF sources are served unoptimized.
  - AVIF *output* quality is rescaled from `q-20` to `q*50/80`. With our `qualities` list, 80 goes from 60 to 50, 70 from 50 to 44, and 90 from 70 to 56. The result is smaller and slightly softer AVIFs.
  - No `.avif` src exists in the storefront code.
  - Then a full rebuild and visual check.
- **The developer decides.**

## Battery: GREEN, 2026-09-24, off one finished run of the final tree

The tree was `5422a31` plus the PHPStan fixes to four test files.

| Check | Result |
|---|---|
| Pest (full) | 1,689 passed, 38 skipped, 0 failed, 585 s |
| PHPStan level 10 | no errors |
| Pint `--test` | passed |
| core `tsc --noEmit` | 0 errors |
| core `vite build` | built |
| `next build` | compiled, 18/18 pages |
| `next lint` (12 changed storefront files) | 0 errors; 7 warnings, all of the accepted kinds listed in `next.config.js` |
| `pre-commit --test` | 52 passed, 0 failed |

**Found by the battery:** PHPStan reported 41 errors, all in the four test files added this round. They were mixed-typed Inertia props and untyped array helpers. Fixed with array shapes, `Tests\Support\Props`, and a runtime `is_array` check on the evaluated `config/session.php`. No ignores, casts or baseline entries. PHPStan analyses `tests/` too: run it on new tests before calling them done.

**The earlier red Pest run was not a defect.** One `ProductsTest` case took 17 h and ended "MySQL server has gone away", because the laptop slept mid-suite. On its own the case passes in seconds.

## Still open

The reviewer's re-check of findings 1, 2 and 3 and of the legacy gate.
