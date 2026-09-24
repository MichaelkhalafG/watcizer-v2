# Review handoff — three confirmations before the window is scheduled

**Date:** 2026-09-23 · **Branch:** `wave-4d`, uncommitted · **From:** the implementing session ·
**For:** the reviewer

The developer will schedule nothing until these three come back. Each has a pass condition that
can be read off a finished run; none of them is a judgement call.

Last full battery on this tree: **Pest 1661 passed, 38 skipped (34921 assertions)**, Pint clean,
PHPStan level 10 clean, `tsc` clean, both builds green.

---

## 1. Re-diff the 134-case harness on a scratch copy

**Recipe:** runbook §12, exactly. In short: restore a production dump into a scratch database, point
`core/.env` at it, `config:clear`, prove the database name with the one-line tinker in §12.2, rebuild
the clean core on it, then `pwsh -File scripts/run-compat-harness.ps1`. Drop the scratch database
afterwards (§12.5).

`WriteTarget` will refuse any non-local target. Do **not** use `--allow-remote` for this.

**Pass:** `134 cases, PASS`, every difference on a rule in `App\Compat\Diff\DeviationRules`.

**What is new since the contract was last diffed (127 cases, before Phase 1):**

| | |
|---|---|
| **7 new cases** | `cart:remove:no-keys:ar`, `cart:remove:bad-type:ar`, `address:invalid:ar`, `address:bad-city:ar`, `address:short-phone:ar`, `checkout:no-address:ar`, `checkout:bad-method:ar` — the same refusals from an Arabic browser |
| **1 new rule, D-25** | Arabic field-error PHRASING on those three routes only, scoped to `$.errors.*[*]`. Verified not to absorb `$.message`, an English case, or `add_to_cart`. A difference anywhere else in those bodies is still a failure |
| **Why this run matters more than usual** | Core's `APP_LOCALE` became `ar` on 2026-09-20 (LIVE_REVIEW), AFTER the last run. Before this round's fix, the header-less cases `address:invalid`, `cart:remove:*` and `checkout:no-address` would have diffed Arabic (core) against English (legacy). A green run is the harness's confirmation that the fix in §2 below holds end to end, against the running legacy app rather than against a unit test |
| **Other behaviour the harness will see** | A callback GET now always redirects, whatever its `Accept` header — legacy parity, developer decision 2026-09-23. No rule was added because nothing should differ |

**If it fails:** report the case name and the finding path. Do not add a DeviationRules entry to
make it pass; every rule there has a written reason and a new one is a decision.

---

## 2. The three locale routes: English by default, Arabic on request

**The claim:** `add_address`, `remove_from_cart` and `add_order` now negotiate `Accept-Language`
exactly as the legacy host does (default `en`). `add_to_cart` deliberately does not — legacy catches
`\Exception` around it and answers a 500 with a ref, so no field text reaches the shopper.

**What to check:**

```bash
cd core
php vendor/bin/pest tests/Feature/Compat/WriteLocaleTest.php          # 5 passed
php artisan tinker --execute="foreach (['api/add_address','api/remove_from_cart','api/add_order','api/add_to_cart'] as \$u) { \$r = collect(app('router')->getRoutes()->getRoutes())->first(fn (\$x) => \$x->uri() === \$u); echo str_pad(\$u, 22), in_array('legacy.locale', \$r->gatherMiddleware(), true) ? 'negotiates' : 'does NOT negotiate', PHP_EOL; }"
```

**Pass:** the test file is green, and the tinker line prints

```
api/add_address       negotiates
api/remove_from_cart  negotiates
api/add_order         negotiates
api/add_to_cart       does NOT negotiate
```

(Not `route:list -v`: on this Laravel version it does not print middleware, so it would show nothing
either way. The tinker line was run on this tree and printed exactly the above.)

**The test that proves it, and how to make it fail.** The ENGLISH test is the one that matters.
With `->middleware('legacy.locale')` removed from those routes it fails and names all six probes
(three routes × no header / `en-US`), each answering in Arabic — because without negotiation core
answers in `APP_LOCALE`, which is `ar`. The ARABIC tests pass either way, for the same reason, and
are there to pin the other direction, not to prove the fix. The first draft of this file got that
backwards; it is written down in the file's header.

**What the locale does NOT touch on these paths** (checked, worth re-checking): the payment-method
list LEFT JOINs its labels and falls back to the key, so no locale can drop a method; the order-mail
path reads no locale; the order's city name is pinned to `en` (`CheckoutCompatController` ~l.825).
Nothing persisted depends on the negotiated value.

---

## 3. `credentialsComplete()` gates BOTH the check and the switch

**The claim:** one definition of "this Paymob contract is usable" —
`StorefrontPaymentProvider::credentialsComplete(array $required)`, with `$required` from
`ProviderRegistry::credentialFields()` — and every place that decides on it asks that method.

**What to check:**

```bash
cd core
grep -rn "credentialsComplete\|credentialsSet()" app/ | grep -v "function credentials"
```

The output also lists DOCBLOCK lines that explain the change (in the model, `aliasIsLive()` and the
settings controller); read past those. **Pass:** the CODE lines are exactly —

| | |
|---|---|
| `PaymentCallbackController.php:142` — `aliasIsLive()` | `credentialsComplete(...)` — the switch |
| `PaymentInitiator.php:84` — `initiate()` | `credentialsComplete(...)` — the gate for starting a payment |
| `PaymentSettingsController.php:504` | `credentialsComplete($fields)` — the screen's `credentials_complete` badge |
| `PaymentSettingsController.php:499` | `credentialsSet()` — the screen's separate "anything saved at all" boolean, and its ONLY remaining code use; it gates nothing |

(Line numbers as of this handoff.) Then the runbook: §3A.3 and the appendix both print `COMPLETE:` from the same
method. Both commands were run from the runbook text against a local database with no contract and
read correctly red.

**The test, and how to make it fail:**

```bash
php vendor/bin/pest tests/Feature/Payment/AliasCutoverTest.php --filter="HALF-entered"
```

A contract holding `secret_key` and `public_key` with no `hmac_secret`, and a callback signed with
the wave-3 GLOBAL secret. It must fall through to the wave-3 handler and confirm the order. Put
`aliasIsLive()` back on `credentialsSet()` and it answers **403** — the exact failure the gate
exists to prevent. That reversal was run on 2026-09-23 and did 403.

Also in that file: `reports exactly which required keys a contract is missing, by NAME`, which pins
that a whitespace-only value is missing, not present.

---

## For the security audit that follows

Files new this round, which have had one reviewer pass or none:

```
core/app/Domain/Access/UserWriteGuard.php          (🟠-7)
core/app/Domain/Customers/SocialNonce.php          (🟠-8)
core/app/Domain/Payment/CallbackDestination.php    (🔴-2)
Frontend-next/src/lib/socialNonce.js               (🟠-8)
```

and the changed deciders above. The runbook's order block was restructured on 2026-09-23 so that
§2 and §3A happen days before the night; the argument for why that is safe — `/api` shut on both
hosts until §4, proven at the end of §2.4 — is in the block itself, and it is the kind of claim an
audit should try to break.
