# PHASE 2 — pointing the Watchizer storefront at core

**Date written:** 2026-09-22 · **Status:** runbook, nothing built · **Branch:** `storefront/v2`

This is the cutover. `watchizereg.com` stops talking to `dash.watchizereg.com` and starts talking to
core. Nothing about the storefront's **behaviour** changes: the compat layer answers the same paths
in the same shapes, and Phase 1 moved the authentication those paths depend on. What changes is
which database the shop runs on.

**Read §7 before you start.** Five features go dark at the flip, by design, and you should decide
that deliberately rather than discover it.

---

## 0. What has to be true first

| | Check |
|---|---|
| Phase 1 is on the server | The code below assumes `core_revoked_tokens`, `core_password_resets`, `core_user_token_epochs`, `core_social_identities` exist. `php artisan migrate --force` then `php artisan migrate:status` — the last five are M1t, M1u, M1v, M1w and the blogs one |
| `api.watchizereg.com` resolves | DNS A/CNAME to the same Hostinger host, document root the **same** `core/public` as eleganceeg.com, SSL issued and valid |
| Google console | The new redirect URI registered **alongside** the legacy one, not instead of it (§1.2) |
| Paymob | Card method linked in `storefront_payment_methods` — you have done this |
| The legacy host is still up | Rollback (§8) is a flip back to it. Do not decommission anything today |

**One thing that is NOT a precondition and should not become one:** the legacy site continuing to
sell. From the moment this cutover completes, `watchizereg.com` runs on the eleganceeg database, and
any order still arriving on the legacy host is a divergence nobody will reconcile. Turn the legacy
storefront off at the end of §6, not before — if you turn it off first you have no rollback.

---

## 1. Before the window

### 1.1 The sub-domain, and why it is not `eleganceeg.com`

`App\Compat\CompatStorefront` decides which shop a cart, order, promotion or stock movement belongs
to by matching the **request host** against `storefronts.domain`. The rule, read off the code:

- the host is lower-cased, a trailing dot is stripped, a leading `www.` is stripped;
- a storefront matches when the host **equals** its domain **or ends with `.` + its domain**;
- only `is_active` storefronts are considered; the **longest** matching domain wins;
- no match falls through to `config('compat.storefront_id')`, which is `1`.

`storefronts.domain` holds `watchizereg.com` and `brandfashionegy.com`. So:

| Host the storefront calls | Resolves to | How |
|---|---|---|
| `api.watchizereg.com` | **Watchizer (1)** | ends with `.watchizereg.com` |
| `eleganceeg.com` | **fall-through to 1** | matches nothing — correct today *by luck*, wrong the day Brand Fashion launches |

That is the whole argument for the sub-domain. On `api.watchizereg.com` the identity is a fact about
the request; on `eleganceeg.com` it is a default that happens to be right. Brand Fashion later gets
`api.brandfashionegy.com` on the same host and the same code resolves it with no configuration.

`eleganceeg.com` stays exactly as it is — the dashboard's address, `/api` still closed there.

### 1.2 Google console

Add, **do not replace**:

```
https://api.watchizereg.com/api/auth/google/callback
```

That path is confirmed against `php artisan route:list --path=api`, which shows
`api/auth/{provider}/callback`. The legacy URI must stay registered until you are past rollback:
a customer who is mid-flow at the moment of the flip comes back to whichever URI *started* their
sign-in, and if that one has been deleted they land on an error Google generates, not one you can
fix.

### 1.3 Code that has to be committed before the deploy

Two files, both in `Frontend-next/`, and **both are a push to `main`, which is a production deploy**.
Do them in one push, not two.

**(a) `Frontend-next/.env.production`** — three keys move:

```
NEXT_PUBLIC_API_BASE=https://api.watchizereg.com/api
LARAVEL_ORIGIN=https://api.watchizereg.com
NEXT_PUBLIC_ASSET_BASE=https://api.watchizereg.com
```

Unchanged: `NEXT_PUBLIC_PUBLIC_API_KEY` (it must keep matching `COMPAT_API_KEY` on the server),
`NEXT_PUBLIC_IMAGE_CDN_BASE`, `NEXT_PUBLIC_PAYMOB_ENABLED`.

**(b) `Frontend-next/next.config.js`** — add the new host to `images.remotePatterns`:

```js
{ protocol: 'https', hostname: 'api.watchizereg.com' },
```

**This one is easy to miss and fails loudly in exactly one place.** `next/image` refuses any hostname
not on that list, so without it every optimised product image 400s while the raw `<img>` tags keep
working — a half-broken catalogue that looks like a CDN problem. Leave `dash.watchizereg.com` on the
list until you are past rollback.

### 1.4 What to have open when you start

- an SSH session on the Hostinger host,
- the Google console,
- `https://watchizereg.com` in a browser you are **not** signed into,
- this file.

---

## 2. `core/.env` on the server

Keys by NAME. **Never write a secret into this file, a commit, or a message** — the values for
anything marked *(secret)* come from you or from the legacy `.env`, directly on the server.

### 2.1 Keys that CHANGE for Phase 2

| Key | What it must become | Why |
|---|---|---|
| `APP_URL` | `https://api.watchizereg.com` | Signed verification links are built from it. A link built on the wrong host verifies nowhere |
| `FRONTEND_URL` | `https://watchizereg.com` | Two readers: the CORS allow-list (`config/cors.php`) and `customers.storefront_url`, which is where a reset link and an OAuth callback send the customer. Wrong here and every password-reset e-mail points at the wrong site |
| `COMPAT_ASSET_BASE` | `https://api.watchizereg.com` | The host in image URLs inside **compat** payloads (`all_product`, `products/{id}`) |
| `STOREFRONT_ASSET_BASE` | `https://api.watchizereg.com` | The same for **v2** payloads and order e-mails |
| `MEDIA_URL_BASE` | leave unset | It derives from `STOREFRONT_ASSET_BASE`. Set it only if you want the dashboard's own previews on a different host |
| `GOOGLE_REDIRECT_URI` | `https://api.watchizereg.com/api/auth/google/callback` | Must match the console byte for byte. Read whole from env, never built from `APP_URL`, so a doubled slash cannot creep in |
| `COMPAT_PAYMENT_RETURN_URL` | `https://watchizereg.com/` | Where Paymob sends the shopper after paying — the **storefront**, not the API |

### 2.2 Keys that must ALREADY be right, and are worth re-reading

| Key | Must be | Consequence if wrong |
|---|---|---|
| `COMPAT_API_KEY` *(secret)* | identical to `NEXT_PUBLIC_PUBLIC_API_KEY` in the storefront build | **Every** storefront request answers `401 {"error":"Unauthorized"}`. This is the single most likely cause of a total failure at the flip, and it looks like an outage rather than a mismatch. `php artisan compat:env-parity` exists for this |
| `JWT_SECRET` *(secret)* | identical to the legacy app's | Customers arrive holding tokens the legacy host issued. A different secret signs every one of them out at the moment of the flip — recoverable (they sign in again) but it will look like the cutover broke accounts |
| `COMPAT_LEGACY_BASE` | `http://127.0.0.1:1` | Anything that still reaches the proxy fails fast instead of arriving somewhere real. **Do not point this back at the legacy host** — see §7 |
| `CORE_WRITE_SWITCH_COMPLETED` | `true` | Already set |
| `ORDER_MAIL_INLINE` | **absent**, or `true` | Absent is right. `false` is a local-only setting that would delay every order confirmation to the cron, and if the cron is ever missing they never arrive at all |
| `ORDER_ADMIN_EMAILS` | the real addresses | An empty list writes a visible `failed` outbox row rather than silently not telling anybody |
| `APP_DEBUG` | `false` | A stack trace on a public host is a map of the application |
| `BCRYPT_ROUNDS` | `10` | Matches every hash the legacy app wrote |

### 2.3 Keys Phase 1 added that must be present

`JWT_ALGO`, `JWT_LEEWAY`, `JWT_TTL`, `GOOGLE_CLIENT_ID` *(secret)*, `GOOGLE_CLIENT_SECRET`
*(secret)*. `MICROSOFT_*` only if you intend to offer it — the button is in the storefront and the
route answers `500 {"error":"Could not start social login."}` when the provider is unconfigured,
which is a refusal at the door rather than a customer stranded at a provider.

### 2.4 After editing

```bash
php artisan config:clear && php artisan config:cache
php artisan route:clear && php artisan route:cache
```

**`config:cache` is not optional here.** Once it runs, `env()` returns null at runtime and only the
cached config is read — so a key you edited and did not re-cache is a key the application cannot
see. Re-run both after *every* `.env` edit today, including the ones in §8.

Then prove the application agrees with you:

```bash
php artisan tinker --execute="
  echo config('app.url'), PHP_EOL;
  echo config('customers.storefront_url'), PHP_EOL;
  echo config('storefront.asset_base'), PHP_EOL;
  echo config('services.google.redirect'), PHP_EOL;
  echo config('compat.legacy_base'), PHP_EOL;"
```

---

## 3. The `.htaccess` change

Today `core/public/.htaccess` carries the blanket block from the eleganceeg runbook §7.2:

```apache
RewriteRule ^api(/|$) - [R=404,L]
```

That must become an **allow-list**, not a removal. The catch-all proxy route
(`Route::any('{path}', ProxyController::class)`) is still in `routes/api.php`, and 12 paths are still
whitelisted in `compat.proxy_paths`. With `COMPAT_LEGACY_BASE` pointing nowhere they fail rather than
reach anything — but a blanket opening plus a future mis-edit of that variable is the shape of an
accident, and braces cost nothing.

Replace the single line with:

```apache
    # ── Phase 2: the storefront's own paths, and nothing else ──────────────────
    # Everything the running Frontend-next actually calls, plus the payment
    # callbacks and the OAuth round trip. The catch-all proxy in routes/api.php
    # stays unreachable: COMPAT_LEGACY_BASE is the belt, this is the braces.
    RewriteCond %{REQUEST_URI} !^/api/v2/
    RewriteCond %{REQUEST_URI} !^/api/(catalog/meta|all_product|all_product_image|all_product_rating|show_shipping_city)$
    RewriteCond %{REQUEST_URI} !^/api/products(/|$)
    RewriteCond %{REQUEST_URI} !^/api/(add_to_cart|remove_from_cart|me/cart|cart/validate|cart/merge|add_order|add_address)$
    RewriteCond %{REQUEST_URI} !^/api/(delete_cart|me/addresses)(/|$)
    RewriteCond %{REQUEST_URI} !^/api/me/(orders|addresses|avatar)$
    RewriteCond %{REQUEST_URI} !^/api/(login|register|logout|updateProfile|updatePassword)$
    RewriteCond %{REQUEST_URI} !^/api/auth/
    RewriteCond %{REQUEST_URI} !^/api/(callback_payment|pay/)
    RewriteRule ^api(/|$) - [R=404,L]
```

**Two things that are NOT under `/api` and must keep working** — the block never touched them and
must not start to:

- `/{locale}/sitemap.xml` and `/sitemap.xml` — the Next.js rewrite fetches
  `LARAVEL_ORIGIN/en/sitemap.xml`, which is `SitemapCompatController`;
- `/Uploads_Images/…` — the Next.js rewrite proxies the media tree from the same origin. This is the
  727 MB you uploaded.

Prove the shape before you move on (from anywhere):

```bash
for p in catalog/meta all_product me/cart auth/me v2/watchizer/meta; do
  printf '%-22s ' "$p"; curl -s -o /dev/null -w '%{http_code}\n' "https://api.watchizereg.com/api/$p"
done
curl -s -o /dev/null -w 'blocked path   -> %{http_code}\n' https://api.watchizereg.com/api/all_wishlist
curl -s -o /dev/null -w 'sitemap        -> %{http_code}\n' https://api.watchizereg.com/en/sitemap.xml
```

Expect: `401` for the first four (the API-key gate — which means they **reached the application**),
`200` for the v2 one, **`404`** for `all_wishlist`, `200` for the sitemap.

A `404` on `catalog/meta` means the allow-list is wrong. A `502` anywhere means something reached
the proxy.

---

## 4. The deploy

```bash
git push origin main          # after merging storefront/v2 — see the merge checklist
```

Hostinger rebuilds `Frontend-next` on every push to `main` (Node 22, `pnpm install` **without**
`--frozen-lockfile`). Watch the build finish before testing; PM2 runs two clustered instances on
port 3000 and a half-finished build serves the old bundle.

---

## 5. What is now true about identity

Worth reading once before you test, so the verification steps mean something.

Every cart, order, promotion evaluation and inventory movement made through the compat layer is
attributed to the storefront that `CompatStorefront` resolved from the request host. After the flip
that host is `api.watchizereg.com`, which ends with `.watchizereg.com`, so it resolves to **storefront
1 — as a fact, not as a default.**

`CompatStorefront::unresolved()` reports when the fall-through was used instead. It is not an error
today, because the harness calls `127.0.0.1` and a health check calls whatever the load balancer
uses. It becomes one the day a second storefront is live.

The v2 routes are unaffected either way: `/api/v2/{storefront}/…` carries the shop in the path.

---

## 6. Verification, on the live site

In a browser you are **not** signed into, and in this order. Each step is chosen because it fails
differently from the ones around it.

1. **Home page loads with products.** Proves `catalog/meta` + `all_product` + the API key. If the
   page renders with empty rails, the key is wrong — check the browser console for 401s.
2. **A product image is sharp and not broken.** Proves `NEXT_PUBLIC_ASSET_BASE` and the
   `remotePatterns` entry. A broken *optimised* image with a working raw one is §1.3(b).
3. **Open a product page.** Proves `products/by-name/{name}`.
4. **Add to cart, reload, cart still there.** Proves `add_to_cart` + `me/cart` + the guest token.
5. **Register a brand-new account.** Proves the Phase 1 write path end to end. Then check the inbox:
   a verification e-mail should arrive.
6. **Click the verification link.** It should land on `api.watchizereg.com` and answer
   *"Email verified successfully."*
7. **Sign out, sign in again** with the same password.
8. **Sign in with Google.** Proves the console registration and `GOOGLE_REDIRECT_URI`. If it returns
   you to `/auth/callback` with a generic failure, the URI does not match byte for byte.
9. **Place a COD order.** Then, in the dashboard on eleganceeg.com, confirm the order is there, its
   stock moved, and the confirmation e-mail arrived.
10. **Place a card order** through Paymob and let it return. Confirm the order's payment status.
11. **An OLD customer signs in** — somebody with orders from before today — and their order history
    is on the account page. This is the one that proves you are on the right database.

**Only when all eleven pass:** turn the legacy storefront off, and write down the moment you did.

---

## 7. Five things go dark at the flip — decide this before, not after

`compat.proxy_paths` still lists 12 paths, and `COMPAT_LEGACY_BASE` points nowhere, so they fail:

| Feature | Storefront behaviour | Severity |
|---|---|---|
| **Home banners** (`all_banner_*`) | `fetchBanners` uses `Promise.allSettled` and swallows failures — the banner areas are simply empty | Cosmetic, but the home page loses its hero |
| **Wishlist** (`all_wishlist`, `add_wishlist`, `delete_wishlist/*`) | The heart button fails silently; the account tab shows nothing | **Visible.** Data is not lost — it is in the legacy database, unreachable |
| **Ratings** (`add_product_rating`) | Submitting a review shows *"Could not send"* | Low — production has **zero** ratings |
| **Offers** (`all_offer`) | Offer pages and the slider render empty | None in practice — production returns `[]` |
| **Blogs** (`all_blog`) | Blog pages render empty | None in practice — production returns `[]` |

Three of those five are already empty in production and cost nothing. **Banners and the wishlist are
real losses**, and they are the price of the cutover unless you decide otherwise. The options, in
increasing order of work:

- **accept it** — banners go dark until Phase 6 builds them on `meta.banners[]`, which core already
  serves; the wishlist waits for its core implementation;
- **point `COMPAT_LEGACY_BASE` back at the legacy host** so those 12 paths keep working — this
  **re-opens the split-identity hazard** and I would not do it: the wishlist writes `wishlists` on the
  legacy database for a customer whose account now lives here;
- **build banners first** (small: `meta.banners[]` exists, the storefront needs a reader).

My recommendation is to accept it and schedule banners, because the second option trades a cosmetic
loss for a data-integrity one.

---

## 8. Rollback

Nothing here is irreversible while the legacy host is up. Rollback is **one file**.

### 8.1 Put the storefront back

Revert `Frontend-next/.env.production` to the three legacy values and push to `main`:

```
NEXT_PUBLIC_API_BASE=https://dash.watchizereg.com/api
LARAVEL_ORIGIN=https://dash.watchizereg.com
NEXT_PUBLIC_ASSET_BASE=https://dash.watchizereg.com
```

`git revert <the cutover commit> && git push origin main` is the safest form — it keeps the history
honest and cannot mistype a URL. Wait for the Hostinger build.

**Time to recover: one build.** Leave `next.config.js` alone; the extra `remotePatterns` entry is
harmless.

### 8.2 What you do NOT have to undo

- `core/.env` on the server — the dashboard keeps working with it, and it is what you will re-use;
- the `.htaccess` allow-list — with the storefront pointed elsewhere nothing calls those paths;
- the Google console — both URIs registered is the correct state during a rollback window;
- any migration. Phase 1 added tables; none of them is read by the legacy application.

### 8.3 The one thing rollback does NOT undo

**Orders, accounts and stock movements created on core between the flip and the rollback stay on the
eleganceeg database.** They are real and they are correct; they are simply not in the legacy one. If
you roll back after taking orders, those orders must be re-entered by hand on the legacy side —
exactly the obligation running in the other direction today.

That is the reason §6 has eleven steps and the legacy site stays up until they pass: **the cheapest
rollback is the one you do before the first customer order.**

---

## 9. After the window

1. Write down the moment the legacy storefront was switched off.
2. Tell the client in writing, as runbook §8 of the eleganceeg document says: the old site is off,
   and turning it back on is a decision with a cost.
3. `php artisan core:backup --keep=7` by hand once, and confirm the nightly cron ran the next day.
4. Watch `storage/logs` for `customer account e-mail failed` and `social callback failed` — both log
   by reference, so a pattern in them is a configuration problem, not a leak.
5. The hand-entry backlog: every order and registration taken on the legacy host since 2026-09-21
   still has to be re-entered. That list stops growing today.

---

## Appendix — every command, in order

```bash
# on the server, after editing core/.env
php artisan config:clear && php artisan config:cache
php artisan route:clear && php artisan route:cache
php artisan migrate:status | tail -6

# prove the configuration the application actually sees
php artisan tinker --execute="echo config('app.url'),PHP_EOL,config('customers.storefront_url'),PHP_EOL,config('services.google.redirect'),PHP_EOL,config('compat.legacy_base'),PHP_EOL;"

# prove the .htaccess shape (from anywhere)
curl -s -o /dev/null -w 'meta        -> %{http_code}\n' https://api.watchizereg.com/api/catalog/meta
curl -s -o /dev/null -w 'v2 meta     -> %{http_code}\n' https://api.watchizereg.com/api/v2/watchizer/meta
curl -s -o /dev/null -w 'wishlist    -> %{http_code}\n' https://api.watchizereg.com/api/all_wishlist
curl -s -o /dev/null -w 'sitemap     -> %{http_code}\n' https://api.watchizereg.com/en/sitemap.xml

# the deploy
git push origin main

# rollback
git revert <cutover commit> && git push origin main
```
