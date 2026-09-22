# PHASE 2 — pointing the Watchizer storefront at core

**Date written:** 2026-09-22 · **Status:** runbook, nothing built · **Branch:** `wave-4d`

*(Phase 1 shipped on `wave-4d`, commit `767ace2`; `storefront/v2` was proposed in the Phase 0
plan and not used. Anything still naming it means this branch.)*

This is the cutover. `watchizereg.com` stops talking to `dash.watchizereg.com` and starts talking to
core. Nothing about the storefront's **behaviour** changes: the compat layer answers the same paths
in the same shapes, and Phase 1 moved the authentication those paths depend on. What changes is
which database the shop runs on.

**The order of the window — read this first:**

```
§2  deploy core to the server (code + 4 migrations)   ← the server is on PRE-Phase-1 code
§3  core/.env, and the two secrets (§3.3)
§4  .htaccess: open the storefront's paths, refuse /manage on the API host
§5  merge wave-4d and push to main — the storefront deploy
§7  the eleven verification steps, on the live site
    then, and only then: switch the legacy storefront off
```

**Read §8 before you start.** Five features stop at the flip, all five decided — so that an empty
banner area on cutover night is recognised as the plan rather than debugged as a fault.

---

## 0. What has to be true first

| | Check |
|---|---|
| Phase 1 is on the server | **It is not, yet — that is §2, and it is the first step of the window.** The server runs pre-Phase-1 code: `route:list` there has no `auth/google/callback` and no `auth/reset-password`. Do not skip ahead to §3 |
| `api.watchizereg.com` reaches CORE | **Done 2026-09-22.** Symlinked to eleganceeg.com's `core/public`; verified `.env` → 403, `composer.json` → 404, `/` → 302, `/manage` → 302. A Hostinger *“Default page”* at the root means the document root is still wrong — DNS and SSL can both be correct while nothing reaches the application |
| Google console | The new redirect URI registered **alongside** the legacy one, not instead of it (§1.2) |
| Paymob | Card method linked in `storefront_payment_methods` — **done 2026-09-22** |
| Google console | Redirect URI registered alongside the legacy one — **done 2026-09-22** |
| The legacy host is still up | Rollback (§9) is a flip back to it. Do not decommission anything today |

**One thing that is NOT a precondition and should not become one:** the legacy site continuing to
sell. From the moment this cutover completes, `watchizereg.com` runs on the eleganceeg database, and
any order still arriving on the legacy host is a divergence nobody will reconcile. Turn the legacy
storefront off at the end of §7, not before — if you turn it off first you have no rollback.

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

**Both are already written on `wave-4d` (2026-09-22) and not pushed.** What follows is what they
contain, so you can read the diff rather than take it on trust.

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

## 2. DEPLOY CORE TO THE SERVER

**This comes first, and everything after it assumes it is done.** The server is running pre-Phase-1
code: `php artisan route:list` there shows no `auth/google/callback`, no `auth/reset-password`, no
`auth/verify-email`. §3 configures keys for code that is not there yet, and §4 opens paths that do
not resolve. Doing them in the other order produces 404s that look like an `.htaccess` mistake.

**Core's deploy is manual and always has been** — archive, upload, extract over SSH. A push to
`main` deploys `Frontend-next` and nothing else; it has never touched `core/`.

> ### Two things that will bite if you skim this section
>
> **1. `composer install --no-dev` is NOT optional this time.** Piece 5 added `laravel/socialite` to
> `composer.json`, so `vendor/` has genuinely changed. Upload only `app/` and `config/` and you get
> a 500 on `Socialite::driver()` with a class-not-found that nobody connects back to the deploy.
>
> **2. `core/public/` contains `.htaccess` — deploying core OVERWRITES it.** That file is what §4
> edits. It is why this section comes BEFORE §4 and not after, and why any later re-deploy needs a
> copy taken first (§2.2).

### 2.1 Build on the workstation

```bash
cd "D:/coding/watchizer website/new watchizer/core"
composer install --no-dev --optimize-autoloader
```

**This one is not optional for Phase 1.** Piece 5 added `laravel/socialite` to `composer.json`, so
`vendor/` has genuinely changed — uploading only `app/` and `config/` gives you a 500 on
`Socialite::driver()` with a class-not-found nobody will connect to the deploy.

> Afterwards run plain `composer install` again on the workstation, or your test suite will not run:
> the command above strips Pest, PHPStan, Pint and Faker.

**`npm run build` — REQUIRED, as of 2026-09-22.**

```bash
npm run build
```

Phase 1 alone touched no file under `resources/js`, so this was optional when this section was first
written. The customers-screen attach control changed that: `Manage/Customers/Show.tsx` is rebuilt,
`public/build/manifest.json` and the hashed chunks move with it, and **a server running the new PHP
against the old manifest renders a screen without the control while the route behind it exists.**

`public/build/` is therefore part of the upload in §2.2, not an optional extra.

### 2.2 What to upload — the whole set, not a delta

Upload the same list the first deployment used (eleganceeg runbook §3.2):

```
core/app/            core/bootstrap/       core/config/
core/database/       core/lang/            core/public/       ← INCLUDING public/build/
core/resources/      core/routes/          core/vendor/       ← socialite is new here
core/artisan         core/composer.json    core/composer.lock
```

`core/public/` carries `build/` (the dashboard's compiled JavaScript, rebuilt in §2.1) and
`.htaccess` (the file §4 edits). Both ride in this one directory, which is why the order of this
runbook is what it is.

**A curated delta is the wrong instinct here**, even though only 39 files changed. Phase 1 added two
Blade templates under `resources/views/emails/`, eight keys to `lang/en/manage.php`, a new
`config/customers.php` and a new dependency in `vendor/` — forget any one and the failure is a 500
on a path nobody exercises until a customer does. The full set costs upload time and nothing else.

**Exclusions are unchanged** (eleganceeg runbook §3.3), and two of them matter more than the rest:

- `core/.env` — **never**. The server's copy is the one you are about to edit in §3;
- `core/public/Uploads_Images/` — **never**. 727 MB that must not ride along with code.

Measured on the workstation, 2026-09-22, so the `--exclude` list below is worth its length rather
than being copied on faith: `node_modules` **137 MB**, `storage/logs` **46 MB**, `storage/import`
**7.4 MB** (supplier CSVs and fetched covers), `storage/app/backups` **5.2 MB** — **database dumps,
customer PII and password hashes**, `tests` 2.4 MB, `docs` 749 KB. That is 199 MB of upload avoided,
and one directory that must never leave this machine.

#### The trap in this order

`core/public/` contains `.htaccess`, and that is the file §4 edits. **Deploying core OVERWRITES it.**
The order in this runbook is therefore deliberate — deploy first (§2), edit `.htaccess` after (§4).
If you ever re-deploy afterwards, take a copy first:

```bash
cp ~/domains/eleganceeg.com/core/public/.htaccess ~/htaccess.$(date +%Y%m%d-%H%M).bak
```

### 2.3 Make an archive that survives extraction

A zip built by Windows tooling can store its entries with **backslash** separators. Hostinger's file
manager extracts those literally: you get a flat directory containing files actually named
`app\Domain\Customers\CustomerAccounts.php`, the autoloader finds nothing, and every page is a 500
whose cause is invisible in the log.

Build it as a tar, which has no such ambiguity:

```bash
cd "D:/coding/watchizer website/new watchizer"
tar -czf core-phase1.tar.gz \
  --exclude='core/.env' --exclude='core/node_modules' --exclude='core/tests' \
  --exclude='core/docs' --exclude='core/.git' --exclude='core/storage/logs' \
  --exclude='core/storage/app/backups' --exclude='core/storage/import' \
  --exclude='core/storage/transform' --exclude='core/storage/compat-diff' \
  --exclude='core/storage/compat-edit-probe' --exclude='core/public/Uploads_Images' \
  --exclude='core/public/dumps' --exclude='core/public/hot' \
  --exclude='core/phpunit.xml' --exclude='core/phpstan.neon' \
  core/
```

Upload `core-phase1.tar.gz` by SFTP. **Extract over SSH, never with the panel's extractor.**

### 2.4 Extract to a staging directory FIRST, then copy over

Extracting straight over a live application replaces files one at a time while the team is using it,
and a partial state is a 500 for whoever clicks during those seconds. Worse, it puts a possibly
mangled archive directly into the live tree.

```bash
# on the server
mkdir -p ~/deploy-staging && cd ~/deploy-staging
rm -rf core
tar -xzf ~/core-phase1.tar.gz

# PROVE the extraction is sane before it touches anything live
ls core/app/Domain/Customers/CustomerSocial.php     # must exist, nested
ls core/vendor/laravel/socialite/composer.json      # the new dependency
ls core/resources/views/emails/password-reset.blade.php
ls | grep -F '\'                                    # must print NOTHING
```

If the third command prints a filename containing a backslash, the archive is mangled — rebuild it
and start this step again. **Do not continue.**

Then copy over the live tree. `.env` and `storage/` are not in the archive, so they are untouched:

```bash
LIVE=~/domains/eleganceeg.com/core
cp -a ~/deploy-staging/core/. "$LIVE"/
```

The window in which the dashboard can 500 is now the length of that one `cp`, not the length of an
upload. Do it in a quiet minute and tell the team not to be mid-save.

### 2.5 Permissions and the dev markers

```bash
cd ~/domains/eleganceeg.com/core
chmod -R 775 storage bootstrap/cache
rm -f public/hot public/fonts-manifest.dev.json
ls public/build/manifest.json            # must exist
ls -l public/storage                     # the storage symlink must still be there
```

`public/hot` is the one that fails invisibly: if it exists, every dashboard page tries to load its
JavaScript from your workstation and the site appears completely broken.

### 2.6 Migrate — exactly four, and nothing else

```bash
php artisan migrate --force
```

**Expect exactly these four, in this order:**

```
2026_10_06_000000_revoked_tokens ............ DONE   (M1t)
2026_10_07_000000_password_resets ........... DONE   (M1u)
2026_10_08_000000_token_epochs .............. DONE   (M1v)
2026_10_09_000000_social_identities ......... DONE   (M1w)
```

**If a fifth runs, stop and read it.** Every earlier migration was applied when the dashboard was
deployed; one running now means the server's `core_migrations` ledger disagrees with the tree, and
the reason matters more than the migration does.

**If NONE runs**, the code did not actually land — go back to §2.4.

Confirm the four tables exist and the ledger agrees:

```bash
php artisan migrate:status | tail -6
php artisan tinker --execute="
  foreach (['core_revoked_tokens','core_password_resets','core_user_token_epochs','core_social_identities'] as \$t)
    echo str_pad(\$t, 28), Illuminate\Support\Facades\Schema::hasTable(\$t) ? 'OK' : 'MISSING', PHP_EOL;"
```

### 2.7 Cache

```bash
php artisan config:clear && php artisan config:cache
php artisan route:clear && php artisan route:cache
php artisan view:clear
```

`view:clear` matters this time: two new Blade templates arrived, and a stale compiled view is a
password-reset e-mail that renders the old file or fails outright.

### 2.8 Verify Phase 1 is actually on the server

```bash
php artisan route:list --path=api | grep -E "auth/(me|login|register|forgot-password|reset-password|verify-email|resend-verification)|auth/\{provider\}|updateProfile|updatePassword|me/avatar"
```

**Eleven route lines.** If `auth/{provider}/callback` is absent, the routes are cached from the old
tree — re-run `route:clear && route:cache`.

```bash
php artisan schedule:list | grep tokens:prune      # 15 3 * * *
```

### 2.9 What state the dashboard is in between this and the flip

The team is using eleganceeg.com throughout, so this is worth being precise about.

| | |
|---|---|
| **Does the dashboard keep working?** | **Yes, unchanged.** Phase 1 altered no dashboard screen's behaviour. The `users` write lock moved from `DashboardAccounts` into `UserWrites`, and the two dashboard operations — create an account, change your own password — behave identically; `AuthTest` asserts a full login/logout still leaves the legacy digest byte-identical |
| **Does anyone get signed out?** | No. `APP_KEY` does not change and sessions are file-driven; `config:cache` does not touch them |
| **Is anything customer-facing live?** | **No.** Every Phase 1 route is under `/api`, which the existing block still 404s on eleganceeg.com, and the storefront still points at the legacy host. The code is present and **dormant** until §4 and §5 |
| **The one gap that matters** | Between `cp -a` (§2.4) and `migrate` (§2.6) the new code is live against tables that do not exist yet. Nothing under `/manage` reads them and `/api` is shut, so the exposure is nil — but **do not open `/api` before migrating**, and do not leave the gap open overnight: `tokens:prune` runs at 03:15 and would error on a missing table |
| **What is NEW and visible to the team** | One thing: `POST /manage/customers/{customer}/attach` exists (§8, guest-order linking by hand). **It has no button yet** — see the note below |

### 2.10 The Piece 6 control — CLOSED 2026-09-22

This section previously recorded a gap: the by-hand guest-order attach shipped as a route, a
controller action, its refusals and its strings, with **no control on the screen**. I had reported
that piece as delivering the manual fallback, and what shipped was the half behind it.

**It is built.** `Manage/Customers/Show.tsx` now carries the card, on the developer's instruction
(*"the manual attach is needed from the first day customers register here"*):

- **admin only**, matching the route's own `manage-users` gate, and shown only on a GUEST customer —
  a registered one has nothing to merge;
- the operator types an account number and **the server names the person**, through a partial reload
  of one prop on this same screen: no second route, no lookup endpoint, nothing new to authorise;
- the confirmation names **both sides** — the orders by number, the account by name, how many orders
  that account already has — and says there is no undo. It uses `ConfirmAction`, so it states the
  consequence rather than asking "are you sure?";
- a number that resolves to nobody leaves the confirm button unreachable, so the refusal the writer
  would give is never met after committing.

**The only thing this changes about the deploy** is that `npm run build` is now required and
`public/build/` must be uploaded — both already folded into §2.1 and §2.2.

---

## 3. `core/.env` on the server

Keys by NAME. **Never write a secret into this file, a commit, or a message** — the values for
anything marked *(secret)* come from you or from the legacy `.env`, directly on the server.

### 3.1 Keys that CHANGE for Phase 2

| Key | What it must become | Why |
|---|---|---|
| `APP_URL` | `https://api.watchizereg.com` | Signed verification links are built from it. A link built on the wrong host verifies nowhere |
| `FRONTEND_URL` | `https://watchizereg.com` | Two readers: the CORS allow-list (`config/cors.php`) and `customers.storefront_url`, which is where a reset link and an OAuth callback send the customer. Wrong here and every password-reset e-mail points at the wrong site |
| `COMPAT_ASSET_BASE` | `https://api.watchizereg.com` | The host in image URLs inside **compat** payloads (`all_product`, `products/{id}`) |
| `STOREFRONT_ASSET_BASE` | `https://api.watchizereg.com` | The same for **v2** payloads and order e-mails |
| `MEDIA_URL_BASE` | leave unset | It derives from `STOREFRONT_ASSET_BASE`. Set it only if you want the dashboard's own previews on a different host |
| `GOOGLE_REDIRECT_URI` | `https://api.watchizereg.com/api/auth/google/callback` | Must match the console byte for byte. Read whole from env, never built from `APP_URL`, so a doubled slash cannot creep in |
| `COMPAT_PAYMENT_RETURN_URL` | `https://watchizereg.com/` | Where Paymob sends the shopper after paying — the **storefront**, not the API |

### 3.2 Keys that must ALREADY be right, and are worth re-reading

| Key | Must be | Consequence if wrong |
|---|---|---|
| `COMPAT_API_KEY`, `JWT_SECRET` *(secret)* | → **§3.3**, which is their own step | Both are blank on this server by design. They are the two most consequential values of the whole cutover and each fails differently; §3.3 has the copy step, the parity check and what each failure looks like |
| `COMPAT_LEGACY_BASE` | `http://127.0.0.1:1` | Anything that still reaches the proxy fails fast instead of arriving somewhere real. **Do not point this back at the legacy host** — see §8 |
| `CORE_WRITE_SWITCH_COMPLETED` | `true` | Already set |
| `ORDER_MAIL_INLINE` | **absent**, or `true` | Absent is right. `false` is a local-only setting that would delay every order confirmation to the cron, and if the cron is ever missing they never arrive at all |
| `ORDER_ADMIN_EMAILS` | the real addresses | An empty list writes a visible `failed` outbox row rather than silently not telling anybody |
| `APP_DEBUG` | `false` | A stack trace on a public host is a map of the application |
| `BCRYPT_ROUNDS` | `10` | Matches every hash the legacy app wrote |

### 3.3 THE TWO SECRETS — set these yourself, at the window, and check them immediately

`COMPAT_API_KEY` and `JWT_SECRET` are **blank on the server by design**, and have been since the
eleganceeg deployment closed `/api` (that runbook §7.2). With `/api` shut they were not needed;
Phase 2 needs both, and neither may pass through this repository, a commit, or a message. **You copy
them from the legacy `.env` directly on the server.**

```bash
# on the server — read the two values from the legacy application's own .env
grep -E '^(PUBLIC_API_KEY|JWT_SECRET)=' ~/path/to/legacy/.env
```

Set the matching keys in `core/.env`:

| Core key | Copy from the legacy `.env` key |
|---|---|
| `COMPAT_API_KEY` | `PUBLIC_API_KEY` |
| `JWT_SECRET` | `JWT_SECRET` |

Then, **before anything else**:

```bash
php artisan config:clear && php artisan config:cache
php artisan compat:env-parity --token="<paste a JWT from a live storefront session>"
```

Get the token from a browser signed in to `watchizereg.com` **while it is still on the legacy host**:
DevTools → Application → Session Storage → `token`. That is the point of the check — it proves core
can verify a credential the LEGACY host issued, which is what every already-signed-in customer will
present the moment you flip.

#### What each failure looks like, so you recognise it instead of debugging it

| Wrong key | Symptom | Why it is hard to read |
|---|---|---|
| **`COMPAT_API_KEY` wrong or blank** | **Every** storefront request answers `401 {"error":"Unauthorized"}`. The home page renders with empty product rails; the browser console is a wall of 401s | It looks like the API host is down or the deploy failed. It is neither — the application is answering perfectly, and refusing. This is the single most likely total failure of the cutover |
| **`JWT_SECRET` wrong or blank** | The shop works for anybody signed OUT. Every already-signed-in customer is silently a guest: their cart survives (it is guest-token keyed), their account page 401s, `me/orders` is empty | **Nothing looks broken.** No error page, no log line — `LegacyJwt` treats an unverifiable token exactly as it treats no token, which is the safe direction and also the quiet one. You would find out from a customer, not from the site |
| **Both blank** | The first symptom hides the second entirely | You will fix the key, see the site come back, and not know the secret is still wrong until somebody signs in |

`compat:env-parity` separates them: it reports the API key and the token verification as two
answers. **Do not proceed past a red one.** A wrong `JWT_SECRET` is recoverable — customers sign in
again — but it will read as "the cutover broke accounts", and it is a thirty-second fix before the
flip rather than an incident after it.

### 3.4 Keys Phase 1 added that must be present

`JWT_ALGO`, `JWT_LEEWAY`, `JWT_TTL`, `GOOGLE_CLIENT_ID` *(secret)*, `GOOGLE_CLIENT_SECRET`
*(secret)*. `MICROSOFT_*` only if you intend to offer it — the button is in the storefront and the
route answers `500 {"error":"Could not start social login."}` when the provider is unconfigured,
which is a refusal at the door rather than a customer stranded at a provider.

### 3.5 After editing

```bash
php artisan config:clear && php artisan config:cache
php artisan route:clear && php artisan route:cache
```

**`config:cache` is not optional here.** Once it runs, `env()` returns null at runtime and only the
cached config is read — so a key you edited and did not re-cache is a key the application cannot
see. Re-run both after *every* `.env` edit today, including the ones in §9.

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

## 4. The `.htaccess` change — one file, TWO hosts

**The fact that shapes this whole section:** `api.watchizereg.com` and `eleganceeg.com` now share
one document root, so they share one `.htaccess`. An unconditional rule applies to both. That is
what makes the rules below host-scoped rather than simply edited — and it is also the answer to
"should the API host serve `/manage`?" (decided below: **no**).

Today the file carries the blanket block from the eleganceeg runbook §7.2:

```apache
RewriteRule ^api(/|$) - [R=404,L]
```

Left alone, that would keep `/api` shut on BOTH hosts and the storefront could not call anything.
Removed, it would open `/api` on the DASHBOARD host too, which §7.2 closed deliberately. So it
becomes two blocks, each anchored to one host.

### 4.1 Replace the single line with this

```apache
    # ── Phase 2 (2026-09-22): one document root, two hosts, two policies ───────
    #
    # api.watchizereg.com  = the storefront's API. Serves the storefront's /api
    #                        paths, the media tree and the sitemap. NOTHING else,
    #                        and specifically not /manage.
    # eleganceeg.com       = the dashboard. /api stays closed exactly as the
    #                        eleganceeg runbook §7.2 left it.
    #
    # Both rules are anchored to an exact host. A condition that fails to match
    # blocks NOTHING, which is the safe direction: a typo here costs a gap, not
    # an outage. Verify both hosts in §4.3 before moving on.

    # ── the API host: an allow-list of the storefront's own surface ────────────
    RewriteCond %{HTTP_HOST} ^api\.watchizereg\.com$ [NC]
    RewriteCond %{REQUEST_URI} !^/api/v2/
    RewriteCond %{REQUEST_URI} !^/api/(catalog/meta|all_product|all_product_image|all_product_rating|show_shipping_city)$
    RewriteCond %{REQUEST_URI} !^/api/products(/|$)
    RewriteCond %{REQUEST_URI} !^/api/(add_to_cart|remove_from_cart|me/cart|cart/validate|cart/merge|add_order|add_address)$
    RewriteCond %{REQUEST_URI} !^/api/(delete_cart|me/addresses)(/|$)
    RewriteCond %{REQUEST_URI} !^/api/me/(orders|addresses|avatar)$
    RewriteCond %{REQUEST_URI} !^/api/(login|register|logout|updateProfile|updatePassword)$
    RewriteCond %{REQUEST_URI} !^/api/auth/
    RewriteCond %{REQUEST_URI} !^/api/(callback_payment|pay/)
    RewriteCond %{REQUEST_URI} !^/Uploads_Images/
    RewriteCond %{REQUEST_URI} !^/[a-z]{2}/sitemap\.xml$
    RewriteCond %{REQUEST_URI} !^/(sitemap\.xml|robots\.txt|favicon\.ico)$
    RewriteRule ^ - [R=404,L]

    # ── the dashboard host: /api stays shut (eleganceeg runbook §7.2) ──────────
    RewriteCond %{HTTP_HOST} ^(www\.)?eleganceeg\.com$ [NC]
    RewriteRule ^api(/|$) - [R=404,L]
```

### 4.2 Why the API host refuses `/manage` — the decision, and what it costs

**Decision (2026-09-22): yes, refuse it.** The API host answers the storefront's surface and nothing
else.

There is **no hole today.** The `/api` routes authenticate with a bearer token and an API key, not
with the dashboard session; `config/cors.php` sets `supports_credentials => false`, so a browser will
not attach cookies to a cross-origin call from `watchizereg.com` in the first place. The reasons are
about what the arrangement would become, not what it is:

1. **A credential-accepting form on a second hostname.** `/manage/login` takes a password. Exposing
   it on a host whose whole job is to be called by a public storefront doubles the surface for
   credential stuffing and splits the rate-limit and the logs across two vhosts.
2. **The CORS-permitted origin and the admin session would share a hostname.**
   `api.watchizereg.com` deliberately allows `https://watchizereg.com` as an origin, and would also
   set `XSRF-TOKEN` and the session cookie for `/manage`. The day ANY `/api` route starts using the
   session guard — a future "sign in with your dashboard account", a Sanctum route, a convenience
   somebody adds — that adjacency becomes exploitable, and nobody will re-derive this paragraph
   before adding it.
3. **One dashboard, one address.** Two working URLs for the same admin means a bookmark on the wrong
   one, a password manager with two entries, and an operator reporting an outage on a host you were
   not watching.

**What it costs:**

- **Ten lines of `.htaccess`, and the risk lives in the host condition.** If `^api\.watchizereg\.com$`
  is mistyped so it matches nothing, nothing is blocked — you get today's behaviour, not an outage.
  If it were written loosely enough to match `eleganceeg.com`, **the team cannot log in.** That is
  why both rules are exact-anchored and why §4.3 probes BOTH hosts.
- **One more file to revert** if the window goes wrong. It is independent of the rollback in §9:
  putting the storefront back on the legacy host does not require touching this.
- **Nothing else.** The dashboard is unaffected on its own host, and the storefront never asks the
  API host for anything outside the allow-list.

### 4.3 Prove it — BOTH hosts, before moving on

```bash
echo "── api host: the storefront's surface ──"
for p in api/catalog/meta api/v2/watchizer/meta en/sitemap.xml; do
  printf '  %-26s ' "$p"; curl -s -o /dev/null -w '%{http_code}\n' "https://api.watchizereg.com/$p"
done
echo "── api host: everything else ──"
for p in manage manage/login api/all_wishlist build/manifest.json; do
  printf '  %-26s ' "$p"; curl -s -o /dev/null -w '%{http_code}\n' "https://api.watchizereg.com/$p"
done
echo "── dashboard host: unchanged ──"
for p in manage api/catalog/meta; do
  printf '  %-26s ' "$p"; curl -s -o /dev/null -w '%{http_code}\n' "https://eleganceeg.com/$p"
done
```

Expected, and **every line matters**:

| Host | Path | Expect | Reading it |
|---|---|---|---|
| api | `api/catalog/meta` | **401** | Reached the application and was refused by the API-key gate. A `404` means the allow-list is wrong |
| api | `api/v2/watchizer/meta` | **200** | v2 needs no key |
| api | `en/sitemap.xml` | **200** | The Next.js rewrite depends on it |
| api | `manage`, `manage/login` | **404** | The decision in §4.2, working |
| api | `api/all_wishlist` | **404** | Not on the allow-list; also §8 |
| api | `build/manifest.json` | **404** | The dashboard's assets are not this host's business |
| **eleganceeg** | `manage` | **302** | **The dashboard still works.** If this is 404, the host condition is too loose — revert the block immediately |
| **eleganceeg** | `api/catalog/meta` | **404** | §7.2's property preserved |

**The eleganceeg `manage` line is the one to read first.** A 404 there is a dashboard outage, and the
fix is to delete the two blocks and restore the single original line while you work out the typo.

## 5. The STOREFRONT deploy

```bash
git push origin main          # after merging wave-4d — see the merge checklist
```

Hostinger rebuilds `Frontend-next` on every push to `main` (Node 22, `pnpm install` **without**
`--frozen-lockfile`). Watch the build finish before testing; PM2 runs two clustered instances on
port 3000 and a half-finished build serves the old bundle.

---

## 6. What is now true about identity

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

## 7. Verification, on the live site

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

## 8. Five features stop at the flip — all five DECIDED, 2026-09-22

`compat.proxy_paths` still lists 12 paths and `COMPAT_LEGACY_BASE` points nowhere, so these stop
working the moment the storefront is repointed. Every one of them was put to the developer before
the cutover and answered; this section records the answers, not the risk.

| Feature | Decision (2026-09-22) | What the customer sees |
|---|---|---|
| **Home banners** (`all_banner_*`) | **Accept.** Not in use — paused for the season. Rebuilt **after Phase 2** on `meta.banners[]`, which core already serves (scheduled, windowed, with placement and `type_show`) | Banner areas render empty. `fetchBanners` uses `Promise.allSettled` and swallows the failure, so nothing else on the page is affected |
| **Ratings** (`add_product_rating`) | **Accept for about a week.** The rating WRITE is built in core immediately after the cutover, as its own piece | Submitting a review shows *"Could not send"*. Reading is unaffected — `rating.avg`/`count` come from the catalogue — and production holds **zero** ratings, so there is nothing to display either way |
| **Wishlist** (`all_wishlist`, `add_wishlist`, `delete_wishlist/*`) | **Intended outcome.** Decided as a DELETION in the Phase 0 plan (G6); going dark is the deletion taking effect | The heart button fails silently and the account tab is empty. The rows stay in the legacy database, unread |
| **Legacy offers** (`all_offer`, `all_offer_rating`) | **Intended outcome.** Decided as a DELETION in Phase 0 (G11); promotions replace them | Offer pages and the slider render empty. Production already returns `[]`, so nothing changes in practice |
| **Blogs** (`all_blog`) | **Accept.** Empty in production, nothing lost. Built **per storefront** later — `core_blogs` exists and needs the storefront-scoping decision first (Phase 0, G9) | Blog pages render empty, exactly as they do today |

**The option that was considered and REJECTED:** pointing `COMPAT_LEGACY_BASE` back at the legacy
host so those 12 paths keep answering. It would restore banners and the wishlist — and it would
re-open the split-identity hazard the whole of Phase 1 exists to close: the wishlist would write
`wishlists` on the LEGACY database for a customer whose account now lives here, and nothing would
ever reconcile the two. A cosmetic loss is the cheaper of the two.

**Consequence for the verification in §7:** do not treat an empty banner area, an empty wishlist tab
or an empty blog list as a failed cutover. They are the plan. The eleven steps in §7 are the things
that must work.

## 9. Rollback

Nothing here is irreversible while the legacy host is up. Rollback is **one file**.

### 9.1 Put the storefront back

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

### 9.2 What you do NOT have to undo

- `core/.env` on the server — the dashboard keeps working with it, and it is what you will re-use;
- the `.htaccess` allow-list — with the storefront pointed elsewhere nothing calls those paths;
- the Google console — both URIs registered is the correct state during a rollback window;
- any migration. Phase 1 added tables; none of them is read by the legacy application.

### 9.3 The one thing rollback does NOT undo

**Orders, accounts and stock movements created on core between the flip and the rollback stay on the
eleganceeg database.** They are real and they are correct; they are simply not in the legacy one. If
you roll back after taking orders, those orders must be re-entered by hand on the legacy side —
exactly the obligation running in the other direction today.

That is the reason §7 has eleven steps and the legacy site stays up until they pass: **the cheapest
rollback is the one you do before the first customer order.**

---

## 10. After the window

1. Write down the moment the legacy storefront was switched off.
2. Tell the client in writing, as runbook §9 of the eleganceeg document says: the old site is off,
   and turning it back on is a decision with a cost.
3. `php artisan core:backup --keep=7` by hand once, and confirm the nightly cron ran the next day.
4. Watch `storage/logs` for `customer account e-mail failed` and `social callback failed` — both log
   by reference, so a pattern in them is a configuration problem, not a leak.
5. The hand-entry backlog: every order and registration taken on the legacy host since 2026-09-21
   still has to be re-entered. That list stops growing today.
6. Start the three deferred pieces in §11 — the rating write, the Conversions API for card
   payments, and the banners. Until §11's piece 2 lands, **card sales record no `Purchase` event**;
   read the pixel's numbers with that in mind rather than as a fault.

---

## 11. Pieces scheduled for immediately after the cutover

Three pieces are deliberately deferred past the window. None of them blocks the flip; all three are
things the cutover leaves in a known, named state rather than a surprise.

| # | Piece | Why it waits | State it is left in |
|---|---|---|---|
| 1 | **The rating write in core** (`add_product_rating`) | §8 decision, 2026-09-22. Production holds zero ratings, so a week dark costs nothing real | Submitting a review shows *"Could not send"*. Reading is unaffected |
| 2 | **Meta Conversions API from the payment callback** | Decided 2026-09-22 with the pixel work. Needs a core change, and a core change does not belong in a cutover window | **Card orders record no `Purchase` event at all.** Under-counted, never over-counted — see below |
| 3 | **Home banners on `meta.banners[]`** | §8 decision, 2026-09-22. Not in use — paused for the season | Banner areas render empty |

**Blogs** are a fourth, but they are not scheduled: `core_blogs` exists and the per-storefront
scoping decision (Phase 0, G9) has to be made before anything is built.

### 11.1 The card-payment analytics gap — why piece 2 exists

**Stated plainly: there is no `Purchase` event for a card order, and there cannot be one from the
browser.** This is recorded here because it is the kind of gap that gets discovered six weeks later
as "the pixel is broken", and it is neither broken nor an oversight.

The mechanism, end to end:

1. `Checkout.jsx` posts `add_order`. For a card order the response carries `redirect_url`, and the
   storefront leaves the site — `window.location.href = data.redirect_url`.
2. The shopper pays (or does not) on Paymob.
3. `PaymentCallbackController::done()` — and the legacy alias in `CheckoutCompatController` — send
   them back to `config('compat.payment_return_url')`, which is `https://watchizereg.com/`.
4. On **failure** the callback appends `?payment_error=1`. **On success it appends nothing.**

So the browser is never told *which* order was paid, or that any order was. The storefront therefore
fires nothing, deliberately:

> **A `Purchase` that fires for a failed card payment makes every campaign report a lie.** Firing at
> the Paymob hand-off (step 1) would count every abandoned basket and every declined card as a sale.
> Under-counting is recoverable; a poisoned conversion history is not.

Cash on delivery is unaffected — that order is confirmed at creation, reaches
`/order-confirmation`, and fires `Purchase` there, once per order number, with the record persisted
so a reload does not report the sale twice.

**The fix, and why it is the Conversions API rather than the cheaper option.** The callback is the
only place in the system that knows a payment cleared, so that is where the event belongs:

- it cannot be forged by typing a URL, which a success marker on the return URL can;
- it is not lost to an ad-blocker or a tracking-prevention default;
- and it does not depend on the shopper returning to the site at all — a large share of Paymob
  returns are simply abandoned, and every one of those is a real sale the browser never sees.

It needs a Meta system-user access token (an env secret, by name only, like every other secret in
§3), a hashed-e-mail `user_data` block, and one `POST` placed beside the existing `flush($mailIds)`
call — which already sits exactly where "the money is confirmed" is known.

The cheaper option — appending an order marker to `payment_return_url` and having the storefront
verify it — was considered and is worse on all three counts above, and still requires the same core
change. It is not the recommendation.

### 11.2 The two Meta pixels

Recorded so nobody reads it as a bug. `NEXT_PUBLIC_META_PIXEL_ID` is a **comma-separated list** and
carries two ids:

```
NEXT_PUBLIC_META_PIXEL_ID=1611910119460872,1614877760150035
```

`1611910119460872` is the incumbent — it ran on the Vite storefront and holds the campaign history;
`1614877760150035` is the new one. Both are initialised; both receive every event. Dropping either
later is one line in `.env.production`.

**There is still exactly one event per action.** `fbq('track', ...)` delivers to every initialised
pixel — that is fbevents.js's own behaviour — so nothing in the codebase loops over the ids, the
init guard is a single flag, and the persisted `Purchase` dedupe is keyed on the order number and
knows nothing about how many pixels are listening.

**In the Pixel Helper each event therefore appears once per pixel, with DIFFERENT ids.** Two rows
carrying the same id would be a real double-fire; two rows carrying different ids are two pixels.

One asymmetry, measured 2026-09-22 and not caused by this code: after a **link or button click**,
the incumbent pixel records **one more** event than the new one. That is Meta's own automatic
click-event detection, which is switched on in `1611910119460872`'s Events Manager settings and off
in the new pixel's. A route change with no click increments both by exactly one. Turning automatic
event logging on or off for both pixels in Events Manager is the way to make them match — it is a
Meta-side setting, not a code change.

---

## Appendix — every command, in order

```bash
# §2 — on the workstation
npm run build                        # REQUIRED: the customers-screen control changed public/build/
composer install --no-dev --optimize-autoloader
tar -czf core-phase1.tar.gz --exclude='core/.env' --exclude='core/node_modules'   --exclude='core/tests' --exclude='core/docs' --exclude='core/.git'   --exclude='core/storage/logs' --exclude='core/storage/app/backups'   --exclude='core/storage/import' --exclude='core/storage/transform'   --exclude='core/storage/compat-diff' --exclude='core/storage/compat-edit-probe'   --exclude='core/public/Uploads_Images' --exclude='core/public/dumps'   --exclude='core/public/hot' --exclude='core/phpunit.xml' --exclude='core/phpstan.neon' core/
composer install                     # put the dev tools back locally

# §2 — on the server: stage, PROVE, then copy
mkdir -p ~/deploy-staging && cd ~/deploy-staging && rm -rf core && tar -xzf ~/core-phase1.tar.gz
ls core/app/Domain/Customers/CustomerSocial.php && ls core/vendor/laravel/socialite/composer.json
ls | grep -F '' && echo "MANGLED ARCHIVE - STOP"
cp -a ~/deploy-staging/core/. ~/domains/eleganceeg.com/core/
cd ~/domains/eleganceeg.com/core
chmod -R 775 storage bootstrap/cache && rm -f public/hot public/fonts-manifest.dev.json
php artisan migrate --force          # exactly four: M1t M1u M1v M1w
php artisan view:clear

# on the server, after editing core/.env
php artisan config:clear && php artisan config:cache
php artisan route:clear && php artisan route:cache
php artisan migrate:status | tail -6

# prove the configuration the application actually sees
php artisan tinker --execute="echo config('app.url'),PHP_EOL,config('customers.storefront_url'),PHP_EOL,config('services.google.redirect'),PHP_EOL,config('compat.legacy_base'),PHP_EOL;"

# prove the .htaccess shape — BOTH hosts (§4.3)
for p in api/catalog/meta api/v2/watchizer/meta en/sitemap.xml manage api/all_wishlist; do
  printf 'api %-24s ' "$p"; curl -s -o /dev/null -w '%{http_code}
' "https://api.watchizereg.com/$p"
done
for p in manage api/catalog/meta; do
  printf 'ele %-24s ' "$p"; curl -s -o /dev/null -w '%{http_code}
' "https://eleganceeg.com/$p"
done
# expect api: 401 200 200 404 404   |   eleganceeg: 302 404
# a 404 on eleganceeg/manage is a DASHBOARD OUTAGE — restore the single original rule at once

# the two secrets, then the parity check (§3.3)
php artisan config:clear && php artisan config:cache
php artisan compat:env-parity --token="<a JWT from a live storefront session>"

# the deploy
git push origin main

# rollback
git revert <cutover commit> && git push origin main
```
