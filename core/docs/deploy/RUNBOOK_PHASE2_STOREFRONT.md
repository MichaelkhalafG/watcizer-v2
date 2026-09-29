# PHASE 2 — pointing the Watchizer storefront at core

**Date written:** 2026-09-22 · **Status:** runbook, nothing built · **Branch:** `wave-4d`

*(Phase 1 shipped on `wave-4d`, commit `767ace2`; `storefront/v2` was proposed in the Phase 0
plan and not used. Anything still naming it means this branch.)*

This is the cutover. `watchizereg.com` stops talking to `dash.watchizereg.com` and starts talking to
core. Nothing about the storefront's **behaviour** changes: the compat layer answers the same paths
in the same shapes, and Phase 1 moved the authentication those paths depend on. What changes is
which database the shop runs on.

**The order — read this first. Most of it is NOT the night.** (Restructured 2026-09-23.)

Everything that is dormant until `.htaccess` opens `/api` can be done days early, on a quiet
afternoon, with the dashboard team told and nobody waiting on a storefront. The night is kept to
the steps that make something live, plus a three-minute re-check that the early work is still in
place. The shorter the night, the fewer things are happening at once when something surprises you.

```
DAYS BEFORE — a quiet afternoon. Nothing a shopper can see changes.
  §12  re-diff the 134-case harness on a scratch copy        ← GATE: schedule nothing until green
  §2   deploy core (code + 4 migrations, same sitting)      ← dashboard runs the new code from now
       §2.4's .htaccess restore, and both hosts /api → 404 before you walk away
  §3.1 + §3.4  the non-secret core/.env keys, config:cache  ← watch one order mail (see below)
       JWT_LEEWAY=60 in BOTH .env files (§3.3)
  §3A  Paymob credentials into the table; §3A.3 → COMPLETE: yes
  §4C.1 the workstation half of the merge checklist

THE NIGHT
  0.   re-check the early work is still there (3 minutes, below)
  §3.3 the two secrets + compat:env-parity                 ← kept for the night on purpose
  §4   .htaccess: the host-scoped block; §4.3's sixteen probes    ← /api opens HERE
  §5   merge wave-4d, push to main  (§4C.3)                ← the irreversible step
  §7   the fourteen verification steps

DAYS AFTER
  switch the legacy storefront off — NOT on the night (§4A.4)
  the "Found on the night" list below
```

**Found on the night (2026-09-24) — recorded, not all fixed.**

*What was true and is now corrected on the server:*
- **Core's log channel was broken from the first deployment until the night.** `LOG_CHANNEL` had a
  secret fused onto it (§3.3's append trap), so the channel did not exist and **nothing core logged
  on this server was ever written**: callback refusals, amount mismatches, mail failures, parked
  mail — everything this project built to be VISIBLE was not. Fixed on the night
  (`LOG_CHANNEL=daily`); from 2026-09-24 it is. Any "the log was clean" statement about production
  before that date means nothing.
- **Two `.env` append traps** — an empty key wins over an appended one, and an append with no
  leading newline fuses onto the line above. §3.3 now carries the only safe form.

*To do after the night:*
- **Rotate `JWT_SECRET`** — its value was exposed in a terminal pasted into a chat while diagnosing.
  Not done on the night because rotating signs every customer out. Rotate it WITH the storefront's
  public key and the Paymob credentials, on BOTH hosts at once while legacy is still up (tokens from
  either host must verify on core), then `config:cache`.
- **Email verification lands on bare JSON** — the same as legacy, on the API host now. Smallest fix:
  `verify()` redirects a browser to `FRONTEND_URL/account?verified=1|already|invalid|expired` (JSON
  callers unchanged), the account page shows a toast, and a "send a new link" button calls the
  existing `resend-verification` — which nothing in the storefront calls today.
- **Pin the verification link's host** in `CustomerMail::sendEmailVerification()`. It is built from
  the REQUEST host, correct only because every sender is a customer route on the API host; a CLI or
  dashboard sender would build it on `eleganceeg.com`, which §4 404s. `APP_URL` stays
  `eleganceeg.com` (mail EHLO) — §3.1's row claiming links are "built from APP_URL" is wrong.
- **§4.3's probe list:** `api/login` and `api/add_order` are POST-only and `api/auth/google` is not a
  route — probe `POST api/login` (401), `POST api/add_order` (401) and `api/auth/google/redirect`
  (401). The default-arm probe cannot pass through the CDN (it refuses unknown hosts: `000`), and
  Hostinger's shared Apache sends unknown hosts to its own vhost — untestable on this host.
- **`compat:env-parity`:** the `[key]` arm reports "could not reach" against §7.2's deliberately dead
  `COMPAT_LEGACY_BASE` — say so and suggest `--base`; and it needs a path `CheckApiMiddleware`
  actually guards (`catalog/meta` and `all_product` both answered 200 without the header). The
  `[assets]` rule should check that `MEDIA_ROOT` RESOLVES to the served tree, not that it is written
  absolute (a false positive on this host, verified by `realpath`).
- **The legacy gate question:** `dash.watchizereg.com/api/catalog/meta` answered 200 WITHOUT
  `Api-Code`, though the route sits inside `CheckApi`. Either the CDN served a cached copy
  (`Cache-Control: public`) or legacy's cached config holds an empty key and the gate is open. Two
  reads settle it (a cache-busted curl, `strlen(config('services.public_api_key'))` on legacy); if
  it is open, the residual-risk row in §9.1A is wrong as written.
- **The first verification mail (user 7) never arrived**, before the log channel was fixed. A broken
  log channel does not stop SMTP, so that one is not fully explained — check that inbox's spam folder.

**Why each early step is safe to do early.** `/api` stays shut on both hosts until §4 — that is the
one property everything rests on, and §2.4 now ends by proving it (`/api` → 404 on eleganceeg.com
and on api.watchizereg.com). While it holds: no shopper can reach a Phase 1 route, core's
`add_order` and `callback_payment` answer nobody, every real Paymob callback still goes to the
portal's URL on the legacy host, and flipping `aliasIsLive()` in §3A changes nothing live. §2.9 has
the dashboard's state in detail; the short version is that the team keeps working throughout, now on
the new code — which is the point of doing it early: days of real dashboard use before the night,
instead of discovering a dashboard regression at the same moment as a storefront one. Keep the
pre-deploy archive until after the night; redeploying it is §2's rollback.

**One early step touches live mail: `APP_URL`.** Besides signed links, it sets the SMTP `EHLO` name
(`config/mail.php`, unless `MAIL_EHLO_DOMAIN` is set) for EVERY message core sends — including the
order-status mail the dashboard sends today. Changing it to `api.watchizereg.com` early is still the
right call, because it lets you see the effect on a quiet afternoon rather than on the night: change
an order's status in the dashboard, and confirm the customer mail arrived and did not land in spam.
If it did, set `MAIL_EHLO_DOMAIN=eleganceeg.com` and `config:cache`.

**Why the two SECRETS stay on the night (§3.3), although they could go early.** It is your call,
and this is the argument. While `COMPAT_API_KEY` is blank, `CheckApiCode` refuses everything (it
requires `$expected !== ''`), so between the early deploy and the night core has TWO locks on `/api`:
the `.htaccess` block and an empty key. The `.htaccess` lock is the fragile one — §2's own trap is
that any core re-deploy overwrites that file — and if it goes while the key is blank, core answers
401 to everything; if it goes after the key is set, core's API is live on a host with real DNS days
early. Setting the secrets is two lines and one `compat:env-parity`: five minutes of the night, in
exchange for keeping the second lock until the moment you open the first.

**Step 0 of the night — re-check the early work (3 minutes).** Work done days ago can be undone
silently: a re-deploy overwrites `.htaccess`, somebody disables the Paymob contract, a config cache
goes stale. Before anything else:

```bash
cd ~/domains/eleganceeg.com/core
php artisan migrate:status | grep Ran | grep -cE '2026_10_0[6-9]_000000_(revoked_tokens|password_resets|token_epochs|social_identities)'   # 4
php artisan route:list --path=api | grep -cE 'Customer[A-Za-z]+Controller@'                               # 16
curl -s -o /dev/null -w '%{http_code}
' https://eleganceeg.com/api/catalog/meta        # 404
curl -s -o /dev/null -w '%{http_code}
' https://api.watchizereg.com/api/catalog/meta   # 404 (§4 not yet applied)
# and §3A.3's check once more: COMPLETE must still read yes
```

Any other answer: stop, and redo the early step it belongs to before touching §3.3.

**Read §8 before you start.** Five features stop at the flip, all five decided — so that an empty
banner area on cutover night is recognised as the plan rather than debugged as a fault.

---

## 0. What has to be true first

| | Check |
|---|---|
| Phase 1 is on the server | **It is not, yet — that is §2, done DAYS BEFORE the night (see the order above).** The server runs pre-Phase-1 code: `route:list` there has no `auth/google/callback` and no `auth/reset-password`. Do not skip ahead to §3 |
| `api.watchizereg.com` reaches CORE | **Done 2026-09-22.** Symlinked to eleganceeg.com's `core/public`; verified `.env` → 403, `composer.json` → 404, `/` → 302, `/manage` → 302. A Hostinger *“Default page”* at the root means the document root is still wrong — DNS and SSL can both be correct while nothing reaches the application |
| Google console | The new redirect URI registered **alongside** the legacy one, not instead of it (§1.2) |
| Paymob | Card method linked in `storefront_payment_methods` — **done 2026-09-22** |
| **Paymob credentials in the TABLE, and `aliasIsLive()` true** | **NOT a formality, and it was missing from this list until 2026-09-23 — §3A.** Without the contract row the alias falls through to the wave-3 handler, and a card payment started by core sends no callback URLs at all. Do it BEFORE §5 — or days early, see §3A — and prove it with the check in §3A.3, which must read `COMPLETE: yes` |
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

> **⚠ The Hostinger panel WINS over this file (found on the night, 2026-09-24).** The Node app's
> build environment variables override every `.env*` file in `next build`, and the panel held the
> legacy hosts — so this edit shipped, the build ignored it, and the storefront stayed on legacy
> while looking cut over. **Set the same three values in the panel's build environment variables
> as well, then rebuild**, and prove it with §7's bundle check (the API base compiled into the JS
> chunks, and `/api/v2/…` answering 200 through the storefront — legacy has no v2). See §9.1: the
> rollback has the same trap.

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
manager extracts those literally: you get files actually named
`app\Domain\Customers\CustomerAccounts.php`, the autoloader finds nothing, and every page is a 500
whose cause is invisible in the log.

Build it as a tar, which has no such ambiguity:

```bash
cd "D:/coding/watchizer website/new watchizer"
tar -czf core-phase1.tar.gz \
  --exclude='core/.env' --exclude='core/node_modules' --exclude='core/tests' \
  --exclude='core/docs' --exclude='core/.git' \
  --exclude='core/storage' \
  --exclude='core/public/Uploads_Images' --exclude='core/public/dumps' \
  --exclude='core/public/hot' \
  --exclude='core/phpunit.xml' --exclude='core/phpstan.neon' \
  core/
```

#### `core/storage` is excluded ENTIRELY — review 🟠-3

The earlier form excluded six *named* paths under `storage/` — `logs`, `app/backups`, `import`,
`transform`, `compat-diff`, `compat-edit-probe` — while §2.4's prose said "`storage/` is not in the
archive, so it is untouched". **The prose was wrong, and the two disagreed in the dangerous
direction.** Everything not on that list travelled and was then overwritten by the `cp -a`:

| what travelled | what the overwrite did |
|---|---|
| `storage/framework/{cache,sessions,views}` | replaced the server's compiled views and config cache with the **workstation's**, and dropped every logged-in dashboard session |
| `storage/app/`, minus `backups` | replaced server-side generated and uploaded files with whatever the workstation happened to hold |
| `storage/framework/cache/data` | replaced the live version-key cache, so the storefront served a stale catalogue until it expired |

None of it is code, none of it belongs in a code deploy, and enumerating the parts to leave behind
is the wrong shape: a new directory under `storage/` joins the archive silently. One exclusion of
the whole tree cannot go stale.

**The one case this changes:** on a host where `core/storage` does not already exist, the skeleton
has to be created before `artisan` will run. The live server has had it since the first deploy, so
this is a note for a NEW host, not a step for this window:

```bash
# only on a host with no core/storage yet
mkdir -p storage/framework/{cache/data,sessions,views} storage/logs storage/app/public
chmod -R 775 storage
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
```

**Prove the extraction is sane before it touches anything live.** Four checks, and the last one is
the archive-mangling check:

```bash
ls core/app/Domain/Customers/CustomerSocial.php      # must exist, NESTED under app/Domain/
ls core/vendor/laravel/socialite/composer.json       # the new dependency
ls core/resources/views/emails/password-reset.blade.php

# THE MANGLING CHECK — must print nothing at all
find . -print | grep -F '\'
```

Three things about that one line, each of them a mistake already made here:

- **`find`, not `ls`.** A mangled archive leaves the flat name in this directory *or* nested inside
  `core/`, depending on the tool that built it. `find . -print` covers both; `ls` covers neither
  reliably.
- **`grep -F '\'`, not a `-name` glob.** Single-quoted, that is one literal backslash, and `-F`
  means grep does not reinterpret it. Written as a `find -name` pattern it has to be **doubled** —
  `'*\\*'` — because find's glob reads a single `\*` as an escaped asterisk and would quietly
  search for filenames containing a `*` instead. Piping to `grep -F` removes that trap entirely.
- **Not an empty pattern.** The form in the appendix was `ls | grep -F ''`, which matches every
  line, so it printed "MANGLED ARCHIVE — STOP" on a perfectly good archive every single time. A
  check that always fails is a check nobody reads.

**If `find` prints anything, the archive is mangled.** Rebuild it as a tar and start this step
again. **Do not continue.**

**Take a copy of the live `.htaccess` first, and put it back immediately after.** The archive
contains `core/public/.htaccess` — the plain Laravel one from this repository, with **no `/api`
block at all** — so `cp -a` deletes the blanket 404 that has been shutting `/api` since the
eleganceeg deployment (review 🟠 minor; §2.9 used to claim `/api` stayed closed throughout, and it
did not):

```bash
LIVE=~/domains/eleganceeg.com/core
cp "$LIVE"/public/.htaccess ~/htaccess.before-phase2.bak

cp -a ~/deploy-staging/core/. "$LIVE"/

# PUT IT BACK, in the same breath. Not "later, in §4" — §4 is a separate window and this file
# is what keeps /api shut on BOTH hosts until you are ready to open it deliberately.
cp ~/htaccess.before-phase2.bak "$LIVE"/public/.htaccess

# and prove it, before moving on
curl -s -o /dev/null -w 'eleganceeg /api  %{http_code}\n' https://eleganceeg.com/api/catalog/meta
curl -s -o /dev/null -w 'api host   /api  %{http_code}\n' https://api.watchizereg.com/api/catalog/meta
# BOTH must be 404. A 401 means the block is gone and the application is answering.
```

A 401 on either line is the state this step exists to prevent: the application reachable on a host
whose `.htaccess` no longer refuses it, before §3's secrets and before §4's allow-list.

The window in which the dashboard can 500 is now the length of that one `cp`, not the length of an
upload. Do it in a quiet minute and tell the team not to be mid-save.

> **Measured on the live hosts, 2026-09-23 (pre-window, from SSH).** The blanket block this step
> depends on is in place on the server right now, so the "shut on both hosts until §4" claim is no
> longer an assumption:
>
> ```
> https://eleganceeg.com/api/all_product       -> 404
> https://api.watchizereg.com/api/all_product   -> 404
> grep -n "api" core/public/.htaccess           -> 7: RewriteRule ^api(/|$) - [R=404,L]
> ```
>
> The host-agnostic `^api(/|$) -> 404` on line 7 is what closes `/api` on **every** host pointed at
> this document root, including `api.watchizereg.com`, until §4 replaces it with the host-aware
> allow-list. **The one gap this does NOT close is the `cp -a` window above** — for the length of
> that single `cp`, core's stock `.htaccess` (no `/api` block) is live and the API host answers
> `/api`. During that window the public API key ships in the storefront bundle, so `api.code` is no
> barrier to anyone who has read the repo, and `POST /api/add_order` is reachable (see the security
> audit's `add_order` finding). Keep the window to one `cp` and run the two curls immediately after.

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
php artisan route:list --path=api | grep -cE 'Customer[A-Za-z]+Controller@'
```

**Expect `16`.** Matched by CONTROLLER rather than by a list of paths, deliberately — review
🟠 minor. The earlier form greped a hand-typed list of path fragments, and it was wrong in both
directions at once:

- it claimed **"Eleven route lines"** while actually returning **12**;
- and it matched none of the three bare legacy aliases — `api/login`, `api/logout`,
  `api/register` — because those have no `auth/` prefix. `logout` was missing from the pattern
  altogether, so a deploy that had lost the sign-out route would have passed this check.

A path list has to be edited whenever a route is added, and silently under-reports when it is not.
The CONTROLLER cannot drift: every Phase 1 customer route is on one of the five
`Customer…Controller` classes and nothing else is.

The pattern matches the controller NAME and not its namespace, on purpose — a namespace separator
has to survive the shell, and `'Customer\\Customer…'` loses a backslash on the way to `grep` and
silently returns **0**, which reads as "Phase 1 is not deployed". The `@` anchors it to the action,
and the required letter between `Customer` and `Controller` is what keeps the dashboard's own
`Manage\CustomerController` out of the count. Both commands here were run against real
`route:list` output before being written down.

The sixteen, for reading rather than for grepping:

```
api/login            api/register          api/logout            (the bare legacy aliases)
api/auth/login       api/auth/register     api/auth/logout       api/auth/me
api/auth/forgot-password                   api/auth/reset-password
api/auth/verify-email/{id}/{hash}          api/auth/resend-verification
api/auth/{provider}/redirect               api/auth/{provider}/callback
api/updateProfile    api/updatePassword    api/me/avatar
```

If the count is lower, list them and see which is missing:

```bash
php artisan route:list --path=api | grep -E 'Customer[A-Za-z]+Controller@'
```

**If `auth/{provider}/callback` is absent**, the routes are cached from the old tree — re-run
`route:clear && route:cache`.

```bash
php artisan schedule:list | grep tokens:prune      # 15 3 * * *
```

### 2.9 What state the dashboard is in between this and the flip

The team is using eleganceeg.com throughout, so this is worth being precise about.

| | |
|---|---|
| **Does the dashboard keep working?** | **Yes, unchanged.** Phase 1 altered no dashboard screen's behaviour. The `users` write lock moved from `DashboardAccounts` into `UserWrites`, and the two dashboard operations — create an account, change your own password — behave identically; `AuthTest` asserts a full login/logout still leaves the legacy digest byte-identical |
| **Does anyone get signed out?** | No. `APP_KEY` does not change and sessions are file-driven; `config:cache` does not touch them |
| **Is anything customer-facing live?** | **No — but only because §2.4 restores `.htaccess`.** This row used to say the existing block "still 404s on eleganceeg.com", and that was false (review 🟠 minor): the archive carries `core/public/.htaccess`, the plain Laravel one with no `/api` block, so `cp -a` DELETED the blanket 404. For the seconds between the copy and the restore, `/api` answers on **both** hosts — and `api.watchizereg.com` has live DNS. Key-guarded routes still refuse (a blank `COMPAT_API_KEY` fails closed: `CheckApiCode` requires `$expected !== ''`, so everything answers 401), but **`/api/v2/*` needs no key** and would be publicly live early. §2.4 now copies the file aside, restores it straight after, and probes both hosts for a 404 |
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

### 3.3 THE TWO SECRETS — set these yourself, ON THE NIGHT, and check them immediately

`COMPAT_API_KEY` and `JWT_SECRET` are **blank on the server by design**, and have been since the
eleganceeg deployment closed `/api` (that runbook §7.2). With `/api` shut they were not needed;
Phase 2 needs both, and neither may pass through this repository, a commit, or a message. **You copy
them from the legacy `.env` directly on the server.**

**Find the legacy `.env` rather than typing a path.** This runbook used to say
`~/path/to/legacy/.env`, which is a placeholder somebody would eventually run as if it were real
(review 🟠 minor). Nothing in this repository can know where that file is, so the command discovers
it:

```bash
# on the server — every Laravel root on the account, excluding core's own
find ~/domains -maxdepth 3 -name .env -not -path '*/eleganceeg.com/*' 2>/dev/null

# CONFIRM it is the live legacy application before reading it: APP_URL must be the legacy host
grep -E '^APP_URL=' <the path find printed>
```

Only then read the two values:

```bash
grep -E '^(PUBLIC_API_KEY|JWT_SECRET)=' <the path find printed>
```

If `find` prints more than one candidate, the `APP_URL` check is what tells them apart — an old
staging copy will name a different host, and copying secrets out of a stale `.env` produces exactly
the two silent failures tabled below.

Set the matching keys in `core/.env`:

| Core key | Copy from the legacy `.env` key |
|---|---|
| `COMPAT_API_KEY` | `PUBLIC_API_KEY` |
| `JWT_SECRET` | `JWT_SECRET` |

> **⚠ Both keys already EXIST in `core/.env`, EMPTY — so appending a line does nothing.**
> Found on the night, 2026-09-24. The eleganceeg deployment (§7.2) closed `/api` by blanking these
> two keys, not by removing them, so the server file carries `JWT_SECRET=` with nothing after it.
> Laravel's `.env` reader keeps the FIRST definition of a key and ignores later ones, so an appended
> `JWT_SECRET=<value>` sits underneath the blank line, is never read, and nothing reports it —
> `config('compat.jwt_secret')` is still `''`.
>
> **And the append bit a SECOND way the same night:** the server file had no trailing newline, so an
> appended `JWT_SECRET=…` fused onto the end of the line above it — `LOG_CHANNEL=dailyJWT_SECRET=…`.
> That left core with a log channel that does not exist, so nothing core logged was written anywhere
> (see "Found on the night" below), while the key itself still read correctly from a later line.
>
> **The only safe form for ANY `.env` edit on the server — delete, append WITH a leading newline,
> then look:**
>
> ```bash
> sed -i '/^JWT_SECRET=/d' .env
> printf '\nJWT_SECRET=%s\n' "$V" >> .env
> grep -n '^JWT_SECRET=' .env            # exactly one line, and it is its own line
> grep -nE '^[A-Z0-9_]+=[^#]*[A-Z][A-Z0-9_]{2,}=' .env   # fused lines: must print nothing you did not expect
> ```
>
> The same holds for every key §7.2 blanked and every `.env` edit made by appending.

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

#### A parity check can pass and still leave signed-in customers out — the CLOCK

**Set `JWT_LEEWAY=60` in `core/.env` AND in the legacy `.env`, at this same step.** Both default to
`0`, and `0` means "these two machines share a clock", which they do not (review 🟠 minor).

`compat:env-parity` verifies a token that already exists, so it exercises the SECRET and says
nothing about the clock. The failure it cannot see:

> the legacy host mints a token whose `iat`/`nbf` is one second ahead of core's clock; core's
> `LegacyJwt::claims()` refuses it as not-yet-valid; the customer signs in successfully on the
> legacy host, lands on the storefront, and is a guest. **Nothing is logged** — an unverifiable
> token is treated exactly as no token, which is the safe direction and also the silent one.

That is the same symptom as a wrong `JWT_SECRET`, from a different cause, which is why it belongs
next to it here rather than in a footnote. Both hosts mint tokens core verifies for as long as the
legacy app is up, so the tolerance has to exist on both:

```bash
# core/.env and the legacy .env — the SAME value in both
JWT_LEEWAY=60
# then, on core:
php artisan config:clear && php artisan config:cache
php artisan tinker --execute="echo config('compat.jwt_leeway'), PHP_EOL;"   # expect 60
```

Sixty seconds is a tolerance, not a grace period: on a thirty-day token it is noise, and it covers
any clock skew two hosts in the same data centre will actually have. It applies to `exp` as well as
`nbf` (`LegacyJwt` uses one value for both), so a token is honoured for one minute past expiry —
which is the right trade against signing a shopper out mid-checkout.

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

## 3A. Paymob credentials into the TABLE — the step this runbook did not have

**Do this before §5.** It is the step that makes `aliasIsLive()` true, and until it is done the
callback arrangement §4A describes is not the one running.

**It can be done DAYS early, and should be — it takes a step off the night.** Entering the contract
touches only what is already deployed (`/manage/storefronts/1/payments` on eleganceeg.com), and
while §4 has not been applied nothing live can reach the switch it flips: `/api` is shut on both
hosts, so neither `callback_payment` nor `add_order` answers from core, and every real Paymob
callback still goes to the portal's URL on the legacy host. Flipping `aliasIsLive()` early therefore
changes nothing a shopper can see.

**One ordering constraint:** the §3A.3 PROOF calls `missingCredentials()` and `credentialsComplete()`,
which arrive with this round's core deploy (§2). Enter the credentials whenever you like; run the
proof after §2 has landed — and §2 is itself dormant until §4 (§2.9), so it can go early too.

### 3A.1 What is wrong without it

`PaymentCallbackController::alias()` asks one question — does a Watchizer Paymob contract exist,
enabled, with credentials? — and when the answer is no it hands the request to the proven wave-3
handler. That fall-through is deliberate and it is correct **as long as the legacy app is the one
taking payments.** After the flip it is not, and two things follow from it at once:

1. **Core's own intentions lose their callback URLs.** `PaymentInitiator::initiate()` returns null
   when there is no contract, and `CheckoutCompatController::paymob()` then falls back to
   `CompatCheckout::createPaymobIntention()` — the wave-3 builder, which sends **neither**
   `notification_url` nor `redirection_url`. So the destination reverts to whatever the merchant
   portal holds, which is the legacy host, which §4 closes `/api` on. That is exactly the defect
   §4A exists to close, reappearing through the back door.
2. **The callback lands in the wave-3 handler.** That is fine for a payment the legacy app started
   and it is now correct for a nested POST as well (review 🔴-2 fixed its four identifier reads),
   but it is the OLD arrangement: no per-storefront contract, no scoped signature check, and the
   ownership check of study §3.9.2 never runs.

Neither is visible from outside. The shopper pays, the browser comes back, and the order sits
`pending` with the money taken.

### 3A.2 The step

Credentials are **write-only in the dashboard and encrypted at rest** (`encrypted:array` under
`APP_KEY`, `$hidden` on the model). They are the developer's, they never pass through this
repository, and there is no console command that takes them — deliberately, because a command that
accepts a secret as an argument puts it in the shell history.

So this is done on the screen, signed in as an admin on **eleganceeg.com** (not the API host — §4
refuses `/manage` there):

```
/manage/storefronts/1/payments
```

1. Add the **Paymob** provider for Watchizer if the row is not there, and paste `secret_key`,
   `public_key` and `hmac_secret` from the legacy `.env` (`PAYMOB_*`). The fields show PRESENCE only
   afterwards — no value and no part of one, not even a last-4 (`PaymobCredentialLeakTest`,
   2026-09-24; this line used to promise a last-4 the screen never had).
2. Leave the contract **enabled**. Disabling it is the rollback — it puts the alias straight back
   on the wave-3 path with no deploy.
3. Confirm the **card** method under it carries its `integration_id` (done 2026-09-22 — this is the
   row §0 already lists, and it is NOT a credential).

### 3A.3 Prove it, on the server — USABLE, not merely present

```bash
php artisan tinker --execute="
  \$c = App\Models\Storefront\StorefrontPaymentProvider::query()->where('storefront_id', 1)->where('provider', 'paymob')->first();
  \$req = app(App\Domain\Payment\ProviderRegistry::class)->credentialFields('paymob');
  echo 'contract:     ', \$c ? 'present' : 'MISSING', PHP_EOL;
  echo 'enabled:      ', \$c && \$c->is_enabled ? 'yes' : 'NO', PHP_EOL;
  echo 'keys present: ', \$c ? (implode(', ', \$c->credentialKeys()) ?: '(none)') : '-', PHP_EOL;
  echo 'required:     ', implode(', ', \$req), PHP_EOL;
  echo 'missing:      ', \$c ? (implode(', ', \$c->missingCredentials(\$req)) ?: '(none)') : implode(', ', \$req), PHP_EOL;
  echo 'COMPLETE:     ', \$c && \$c->credentialsComplete(\$req) ? 'yes' : 'NO', PHP_EOL;
  echo 'card method:  ', \$c ? (\$c->methods()->where('method', 'card')->where('is_enabled', 1)->value('integration_id') ?: 'NONE') : '-', PHP_EOL;"
```

**Expect exactly this shape:**

```
contract:     present
enabled:      yes
keys present: secret_key, public_key, hmac_secret
required:     secret_key, public_key, hmac_secret
missing:      (none)
COMPLETE:     yes
card method:  <an integration id>
```

**`COMPLETE: yes` is the line that matters, and it is the reason this check was rewritten
(2026-09-23).** The earlier form printed `credentials: set` from `credentialsSet()`, which only asks
whether the array is non-empty. A contract holding `secret_key` and `public_key` with `hmac_secret`
not yet pasted read **green** — and that contract cannot verify a single signature, so every
callback would have answered **403** with the money taken. That is precisely the failure §3A.1 was
written to prevent, reached through the check meant to rule it out.

`COMPLETE` is not computed here. It is `StorefrontPaymentProvider::credentialsComplete()`, the ONE
definition that the providers screen's badge, `aliasIsLive()` and `PaymentInitiator` all ask — so
this line, the dashboard and the switch cannot disagree. The code gate changed with it: a
half-entered contract now keeps the proven wave-3 path until the last key is in, instead of going
live and refusing everything (`AliasCutoverTest`, verified to 403 under the old gate).

A whitespace-only paste is **not** present: `credentialKeys()` trims before it counts, so a stray
space shows up under `missing:` rather than as a key.

Anything other than the shape above and the alias is still falling through — go back to §3A.2
rather than on to §4. The command prints no secret: key NAMES, a boolean, and the integration id,
which is a public routing label (§2.19).

**What this does NOT require:** touching the Paymob merchant portal. §4A.3 is unchanged — leave the
portal's URL pointing at the legacy host. Every intention core creates now carries its own
destination, and the portal's value only governs payments the legacy app started.

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

### 4.1 WHERE the block goes — read this before the block itself

**It goes ABOVE `RewriteRule ^ index.php [L]`, and it needs `!^/index\.php` as its first
condition. Both halves are load-bearing, and each was measured on Apache 2.4.58 (2026-09-22)
against this exact file.**

`mod_rewrite` in `.htaccess` context re-runs the whole ruleset after an internal rewrite. So a
request to `/api/catalog/meta` is processed **twice**:

| pass | `%{REQUEST_URI}` | what the allow-list sees |
|---|---|---|
| 1 | `/api/catalog/meta` | on the allow-list → the rule does **not** fire → falls through to the front controller |
| 2 | `/index.php` | matches **none** of the `!^/api/...` exclusions, so every condition passes → **`R=404` fires** |

**Without `!^/index\.php`, every dynamic path on the API host returns 404** — not some of them.
Measured, with the block as it was first written:

```
api/catalog/meta 404   api/v2/watchizer/meta 404   api/login 404   api/auth/google 404
api/add_order    404   en/sitemap.xml        404   sitemap.xml 404  robots.txt   404
Uploads_Images/x.txt 200   ← the only survivor: a real FILE, so it is never rewritten
```

That one 200 is the trap. `/Uploads_Images/` and `/build/` are real files and behave correctly, so
a quick "images load, the API is down" reads as a Laravel fault rather than an `.htaccess` one.

**And below the front-controller rule the same corrected block is dead code.** Measured, block moved
below `RewriteRule ^ index.php [L]`:

```
api.watchizereg.com/manage            200   ← the whole point of §4.2, not blocked
api.watchizereg.com/api/all_wishlist  200
api.watchizereg.com/some/other/page   200
api.watchizereg.com/build/manifest.json 404 ← a real file, so it never reached the front
                                              controller and the block DID run on it
```

Same asymmetry, opposite direction: static paths look blocked, everything through the application
is open. **Neither mistake is visible without probing both a static and a dynamic path**, which is
what §4.3 does and why it is a mandatory step rather than a suggestion.

### 4.1.1 Replace the single line with this

> **This block is the repository's copy of the server's allow-list, and a test reads it**
> (`tests/Feature/Storefront/ApiAllowListTest.php`): every API call in the storefront's source must
> pass one of these patterns. Edit the server file and this block TOGETHER, in the same change.
>
> **ANY new storefront API route needs this allow-list widened, or production 404s it.** Apache
> answers before Laravel, so the route is in `route:list`, passes every local test and is still
> invisible in production. Measured 2026-09-27: `catalog/nav` (C-1 stage 2) shipped without it and
> 404'd until the `catalog/meta` line became `catalog/(meta|nav)`. Deploy note for such a change:
> widen the line on the server, then probe the new path WITHOUT the key (401 from core = it got
> through; 404 = Apache stopped it) and `/manage` on the API host (must stay 404).

```apache
    # ── Phase 2 (2026-09-22): one document root, two hosts, two policies ───────
    #
    # api.watchizereg.com  = the storefront's API. Serves the storefront's /api
    #                        paths, the media tree and the sitemap. NOTHING else,
    #                        and specifically not /manage.
    # eleganceeg.com       = the dashboard. /api stays closed exactly as the
    #                        eleganceeg runbook §7.2 left it.
    # anything else        = /api closed. Fails CLOSED on a Host nobody planned.
    #
    # THIS BLOCK MUST SIT ABOVE `RewriteRule ^ index.php [L]` — see §4.1.
    # The `!^/index\.php` line is why it survives the second rewrite pass. Do not
    # remove it to "simplify"; without it the API host 404s everything.
    #
    # The two named rules are anchored to an exact host. A condition that fails to
    # match blocks NOTHING, which is the safe direction: a typo costs a gap, not an
    # outage. Verify all four host cases in §4.3 before moving on.

    # ── the API host: an allow-list of the storefront's own surface ────────────
    RewriteCond %{REQUEST_URI} !^/index\.php
    RewriteCond %{HTTP_HOST} ^api\.watchizereg\.com$ [NC]
    RewriteCond %{REQUEST_URI} !^/api/v2/
    RewriteCond %{REQUEST_URI} !^/api/(catalog/(meta|nav|listing|cards|related|product|home)|all_product|all_product_image|all_product_rating|show_shipping_city)$
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

    # ── any OTHER Host: /api closed. The DEFAULT ARM ───────────────────────────
    #
    # Without this, a Host that is neither of the two above matches no rule and
    # /api is fully OPEN — that is how the server's own IP, the shared-hosting
    # default vhost, and any sub-domain later pointed at this document root would
    # answer. Measured 2026-09-22: `Host: 127.0.0.1` → /api/catalog/meta 200
    # before this arm, 404 after it.
    #
    # It needs no `!^/index\.php` guard: the RULE pattern is `^api(/|$)`, which
    # `index.php` does not match, so the second pass cannot reach it.
    #
    # KNOWN CONSEQUENCE: a request to the server by IP or over the loopback can no
    # longer reach /api. `compat:diff` against 127.0.0.1 ON THE SERVER would 404 —
    # run it with a Host header, or from the workstation (§C).
    RewriteCond %{HTTP_HOST} !^api\.watchizereg\.com$ [NC]
    RewriteCond %{HTTP_HOST} !^(www\.)?eleganceeg\.com$ [NC]
    RewriteRule ^api(/|$) - [R=404,L]
```

**If a third host is ever added**, it needs its own named rule above the default arm *and* an
exception in both of the default arm's conditions. The arm is deliberately the thing you have to
edit, so adding a host cannot silently inherit an open `/api`.

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

### 4.3 Prove it — MANDATORY, all four host cases, before moving on

**This step is not optional and not a spot-check.** It is what caught the missing
`!^/index\.php` (§4.1), and it caught it because it probes a **dynamic** path and a **static** path
on the same host. Either mistake in §4.1 leaves one of those two looking perfectly healthy.

```bash
probe() { printf '  %-22s %-26s ' "$1" "/$2"; curl -s -o /dev/null -w '%{http_code}\n' -H "Host: $1" "https://$1/$2"; }

echo "── api host: the storefront's surface ──"
for p in api/catalog/meta api/v2/watchizer/meta api/login api/auth/google \
         api/add_order en/sitemap.xml sitemap.xml robots.txt; do probe api.watchizereg.com "$p"; done
echo "── api host: everything else ──"
for p in manage manage/login api/all_wishlist build/manifest.json some/other/page; do
  probe api.watchizereg.com "$p"; done
echo "── dashboard host: unchanged ──"
for p in manage manage/login api/catalog/meta build/manifest.json; do probe eleganceeg.com "$p"; done
echo "── the default arm: an unexpected Host ──"
curl -s -o /dev/null -w '  unexpected Host        /api/catalog/meta          %{http_code}\n' \
  -H 'Host: not-a-real-host.invalid' https://api.watchizereg.com/api/catalog/meta
```

Expected, and **every line matters**:

| Host | Path | Expect | Reading it |
|---|---|---|---|
| api | `api/catalog/meta` | **401** | Reached the application and was refused by the API-key gate. **A 404 here is the `!^/index\.php` bug** — go back to §4.1 |
| api | `api/v2/watchizer/meta` | **200** | v2 needs no key |
| api | `api/login` | **405** or **422** | Reached the application; it is a POST route. A **404** is the same bug as above |
| api | `api/auth/google` | **302** | The OAuth redirect. Proves the `!^/api/auth/` arm |
| api | `api/add_order` | **405** or **401** | Reached the application. The storefront cannot check out without it |
| api | `en/sitemap.xml` | **200** | The Next.js rewrite depends on it |
| api | `sitemap.xml` | **302** | Redirects to `/en/sitemap.xml` |
| api | `robots.txt` | **200** | |
| api | `manage`, `manage/login` | **404** | The decision in §4.2, working. **A 200 here means the block is BELOW the front-controller rule** — go back to §4.1 |
| api | `api/all_wishlist` | **404** | Not on the allow-list; also §8 |
| api | `build/manifest.json` | **404** | A real file, so this one tests the block on the FIRST pass. 200 here with 404s above = the block never runs on rewritten requests |
| api | `some/other/page` | **404** | The catch-all. A 200 means the whole allow-list is being skipped |
| **eleganceeg** | `manage`, `manage/login` | **302** / **200** | **The dashboard still works.** If either is 404, the host condition is too loose — revert the block immediately |
| **eleganceeg** | `api/catalog/meta` | **404** | §7.2's property preserved |
| **eleganceeg** | `build/manifest.json` | **200** | The dashboard's own assets, untouched |
| **unexpected Host** | `api/catalog/meta` | **404** | The default arm. A 200 means the arm is missing or its conditions are wrong |

**Read the four in this order, because they fail in different directions:**

1. **eleganceeg `manage`** — a 404 is a dashboard outage for the team. Delete the two blocks, restore
   the single original line, then work out the typo.
2. **api `api/catalog/meta`** — a 404 is the storefront dead on arrival after the flip.
3. **api `manage`** — a 200 is §4.2 not actually in force.
4. **unexpected Host `api/...`** — a 200 is `/api` answering on the server's IP.

**The two readings that look fine and are not:** every static path 200 with every dynamic path 404
(the missing `index.php` exclusion), and every dynamic path open with only static paths blocked (the
block placed too low). Both are in the table above; neither is visible if you probe one path.

## 4A. Paymob: the callback destination now travels with the transaction

**Decision (2026-09-22, review 🔴-2, option (a)):** every intention core creates carries its own
`notification_url` **and** `redirection_url`, built per storefront. Nothing about a payment this
application starts depends on what the merchant portal holds.

### 4A.1 What this fixed, and why it was a blocker for THIS window

Core sent neither URL. The destination was therefore whatever Paymob's portal holds — and the
portal holds the **legacy host**, on which §4 closes `/api`. So after the flip:

> a card callback goes to `…eleganceeg.com/api/callback_payment`, `.htaccess` answers **404**, and
> the order sits `pending` with the money taken, the stock reserved and nothing in any log that
> names a cause.

The URLs are now built by `App\Domain\Payment\CallbackDestination` from the host that served the
`add_order` request — the host the storefront is already talking to, and the one §4's allow-list
opens `/api/pay/` on. Observed, not configured, for the same reason `CompatStorefront` resolves the
shop from the request host. `storefront_payment_providers.settings->callback_base` overrides it per
contract if a private origin ever differs from the public API host.

**It fails closed.** If no usable `https` base can be determined, the intention is REFUSED and the
shopper is told the payment could not be started. Omitting the URLs instead would quietly restore
the portal's authority, which is the defect. Money not taken beats money taken and lost.

### 4A.2 The POST route, and a worse bug it uncovered

Paymob delivers one payment event **twice**: the shopper's browser on `redirection_url` (a GET,
fields flattened into the query string) and a server-to-server call on `notification_url` (a POST,
the same fields nested under `obj.*` as real JSON). **Both callback routes were registered GET
only**, so the processed callback got a 405 and the order depended entirely on the shopper coming
back — which is exactly what the processed callback exists to cover.

Registering the POST exposed the real defect underneath it. `CallbackPolicy::outcomeFromPaymob()`
read its flags with top-level array access, so on a nested payload it found **none** of them —
including `success`. A payment that had SUCCEEDED came out as a decline, and on a `pending` order a
decline is `ACT_CANCEL`:

> **the order cancelled and its stock released, with the money taken.**

Adding the route without that fix would have converted a stuck order into a destroyed one. Both
now read through `CompatCheckout::paymobField()`, the one extractor that handles both payload
shapes, which is also what makes the two deliveries of one payment reach the same verdict — the
property idempotency rests on. `tests/Feature/Payment/ProcessedCallbackTest.php` covers it: eleven
tests, including the nested payload verifying end to end and the two deliveries collapsing to one
attempt row.

**And a SECOND reader had the same shape, which the first fix missed (2026-09-23).** Fixing
`outcomeFromPaymob()` and stopping there left four more top-level reads one method away, in the
wave-3 handler the alias falls through to — `id`, `merchant_order_id`, `amount_cents` and `order`.
The signature verified (that check has always used the extractor) and then every identifier beside
it read NULL on the same payload:

> the order stayed `pending`, a `payment_statuses` row was written naming no order, no transaction
> and no amount, the answer was a **302** rather than the 200 a provider stops retrying on — and
> because the idempotency lookup keys on that null transaction id, **every retry wrote another
> orphan row.**

All four now read through `paymobField()`, which grew one arm for `merchant_order_id` (the merchant
reference sits a level deeper again, at `obj.order.merchant_order_id`; it is not one of the twenty
signed fields, so that arm cannot affect the signature). The handler answers a POST with JSON 200
through the same `PaymentCallbackController::done()` the scoped handler uses — one rule, not two
copies — while the GET arm redirects exactly as it always has, which the compat harness compares.

`tests/Feature/Payment/AliasFallThroughTest.php` is the coverage that did not exist: nine tests
driven with **no contract live**, which is the state `ProcessedCallbackTest` seeds away before every
one of its cases. Six of the nine go red against the old reads; the other three are guards on the
flat GET and the signature, which must not move and do not.

### 4A.3 What the merchant portal's configured URL still controls

| | |
|---|---|
| **Intentions created by CORE, after this change** | **Nothing.** The URLs on the intention win. The portal value is not consulted |
| **Payments started by the LEGACY app** | **Everything.** The legacy checkout builds its own Paymob order and sends no URLs, so its callbacks go wherever the portal points — which is the legacy host, and correct, for as long as the legacy app is up |
| **A provider-side retry of an old callback** | The URL recorded with that transaction, i.e. the portal's, for anything created before the window |
| **Do you have to change the portal?** | **No, and do not.** Leave it pointing at the legacy host. Changing it has no atomic cutover — a transaction in flight at that moment calls back to the old address — and after this change core does not need it |

So the portal stays as it is, and becomes dead configuration once the legacy app is switched off.

### 4A.4 A card payment started on the LEGACY host and completed after the flip

This is a real sequence, not a hypothetical: a shopper reaches Paymob at 22:58, the storefront is
repointed at 23:05, and they pay at 23:11.

1. The intention was created by the legacy app, so the callback goes to **the portal's URL — the
   legacy host.** Not to core, and not through §4's block.
2. **While the legacy app is still up, that works.** The legacy order is confirmed on the legacy
   database, exactly as it would have been. It joins the hand-entry backlog (decision 2,
   2026-09-21): it must be re-entered here by hand, like every other order taken on the legacy host.
3. **If the legacy app has been switched off, it does not.** The callback 404s, the money is taken,
   and the legacy order stays pending with nobody to confirm it. The shopper's card is charged and
   no system knows.

**Therefore — and this is the operational rule, not a note:**

> **Do not switch the legacy storefront off on the same day you flip.** Leave it answering for a
> settlement window, and before switching it off, check the legacy app for `pending` card orders
> created in the hours before the flip. §7's last step and §10 item 1 already say to record the
> moment you switch it off; this says when that moment may be.

A card payment started on the legacy host **before** the window and never completed simply expires
at Paymob and leaves a pending legacy order, which is the same outcome it would have had with no
cutover at all.

---

## 4B. The client IP behind Hostinger's CDN — F-08, ANSWERED on this host

**Measured by the developer on the live host, 2026-09-22, with a temporary probe on
`api.watchizereg.com`:**

```
REMOTE_ADDR      156.204.134.241
X-Forwarded-For  156.204.134.241
```

That address is the developer's own Egyptian ISP address — **not** an edge address belonging to
Hostinger. So on this host the CDN restores the real client IP before PHP sees the request, and both
values already name the visitor. The probe file has been deleted.

**Decision: no `trustProxies` change.** Core sets no trusted proxies today and does not need to.

### 4B.1 Why this needed measuring rather than reasoning

`X-Forwarded-For` is the header a proxy is *supposed* to set, and the reflex is to trust it. Both
mistakes are real, and each is worse in its own direction:

| if the truth had been … | and we had … | the result |
|---|---|---|
| the edge does NOT restore the IP | left `trustProxies` unset | **every visitor keyed as one IP.** The 60/min limiter, `login`'s per-address counter and the two new per-endpoint limiters would all throttle the whole country together, and the first busy hour would look like an outage |
| the edge DOES restore the IP (what was measured) | trusted the header anyway | **the limiter keys on a value the CLIENT controls.** Anyone could send a fresh `X-Forwarded-For` per request and never be rate-limited at all — the login throttle, the reset-password throttle and the registration ceiling would all be decoration |

The second is the one the reflex leads to, and it is the one that silently removes protection rather
than visibly breaking traffic. **`trustProxies` is not a hardening step here; on this host it would
be the vulnerability.**

### 4B.2 What this means for the limiters

Everything that keys on an IP is keying on a real visitor: the global 60/min, `login`'s
`email|ip` counter, `throttle:customer-reset` and `throttle:customer-register` (`AppServiceProvider::boot()`, review
🟠-4). That was the
assumption those were written under, and it is now a measurement rather than an assumption.

### 4B.3 It is re-checked at every window — see §7 step 12

A CDN configuration is not a property of this application, and nothing in this repository would
notice it changing. Hostinger could alter their edge, the plan could move, a new sub-domain could be
routed differently. So the probe is now one line of §7's verification, run from a phone or any
connection whose public address you know:

```bash
curl -s https://api.watchizereg.com/api/v2/watchizer/meta -o /dev/null -w '%{http_code}
'
# then, on the server, read ONE line of the access log and compare the IP it recorded
tail -1 ~/domains/eleganceeg.com/logs/access.log
```

If the recorded address is Hostinger's rather than yours, the edge has stopped restoring the client
IP and **every limiter in the application has become one shared bucket.** That is the moment
`trustProxies` becomes correct, and not before.

**Deliberately not a probe file.** The original measurement used a temporary PHP file that echoed
the two values, and it was deleted immediately — correctly, because a file that prints request
headers is a reconnaissance tool once you forget it. The access log already records the address the
application saw, so nothing needs to be deployed to ask the question again.

---

## 4C. THE MERGE CHECKLIST — the one irreversible step

**Everything else in this runbook can be undone. This cannot.** `wave-4d` merging into `main`
triggers Hostinger's auto-deploy of `Frontend-next` on the push, so the merge and the storefront
going live are **one action**, not two. There is no staging build to look at in between.

Rollback afterwards is a new commit (§9.1), never an undo — which is why this is a checklist and
not a paragraph (review: the missing merge checklist).

### 4C.1 Before you merge — on the workstation

```bash
cd "D:/coding/watchizer website/new watchizer"
git status --short                    # expect CLEAN. An unstaged file is one that will not deploy.
git log --oneline main..wave-4d       # read every commit that is about to become live
```

- [ ] **The full battery is green off a FINISHED run** — not a tail, not a partial: Pest, Pint,
      PHPStan level 10, `tsc`, and `npm run build` in both `core/` and `Frontend-next/`.
- [ ] **The Hostinger panel's build environment variables name `api.watchizereg.com` too** — they
      override `.env.production` (§1.3's box). A correct file with a stale panel ships legacy.
- [ ] **`Frontend-next/.env.production` names `api.watchizereg.com`** in all three host values, and
      carries `NEXT_PUBLIC_META_PIXEL_ID`. This file IS the rollback (§9.1), so read it rather than
      trusting it.
- [ ] **`next.config.js` `remotePatterns` still lists BOTH** `api.watchizereg.com` and
      `dash.watchizereg.com`. Dropping the second removes the rollback's image path.
- [ ] **`core/public/build/` is current** (`npm run build` in `core/`). It is gitignored, so it
      travels in the deploy archive and NOT in the merge — which means the merge cannot tell you
      it is stale.
- [ ] **No `.env` file is staged.** `git diff --cached --name-only | grep -E '\.env$'` must print
      nothing. `.env.production` is tracked on purpose and is the one exception.

### 4C.2 Before you merge — on the server

Core has to be deployed FIRST (§2), because the push makes the storefront call it immediately.

- [ ] **§2 done**: core deployed, four migrations applied, caches rebuilt, `.htaccess` restored
      (§2.4) and both hosts still answering **404** on `/api`.
- [ ] **§2.8 returns 16.**
- [ ] **§3 done**: both secrets set, `JWT_LEEWAY=60` on both hosts, `compat:env-parity` green on
      both answers.
- [ ] **§4 done**: the host-scoped `.htaccess` in place and **all four host cases in §4.3 pass** —
      including `eleganceeg.com/manage` still answering, which is the dashboard the team is using
      while you do this.
- [ ] **Google console**: the redirect URI registered for `api.watchizereg.com` alongside the legacy
      one (developer, done 2026-09-22).

### 4C.3 The merge

```bash
git checkout main
git pull                              # the Hostinger bot commits dependency patches to main
git merge --no-ff wave-4d             # --no-ff: one merge commit, so §9.1 has a boundary to name
git log --oneline -1                  # WRITE THIS SHA DOWN. §9.1 needs it.
git push origin main                  # ← the storefront goes live HERE
```

`--no-ff` matters for the rollback: §9.1 restores one file from "the commit before the cutover",
and a fast-forward merge leaves no single commit that is the boundary.

**Write the SHA and the wall-clock time somewhere outside the terminal.** §10 asks for the moment
the legacy site went off; this is the moment the new one came on, and the two are different.

### 4C.4 Immediately after the push

- [ ] **Watch the Hostinger build finish.** PM2 runs two clustered instances and a half-finished
      build serves the old bundle — so a page that still looks right proves nothing yet.
- [ ] **§7, all fourteen steps.** In order. Step 1 is the one that fails loudest and step 11 is the
      one that proves you are on the right database.
- [ ] **Do NOT switch the legacy storefront off today** (§4A.4). Its card callbacks still go to the
      merchant portal's URL, and switching it off with a payment in flight charges a customer into
      a 404.

---

## 5. The STOREFRONT deploy

```bash
git push origin main          # after merging wave-4d — the checklist is §4C
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

**Step 0 — the bundle check. Before any browser step, and after EVERY rebuild (added 2026-09-24).**
On the night the storefront looked cut over and was still on legacy (§1.3's panel box), and a
working page proves nothing about which host served it. These three lines do:

> **HARD-RELOAD FIRST (added 2026-09-28).** After any storefront build, the first check from a
> browser that already had the site open is unreliable: the tab can keep running the OLD JavaScript.
> Before judging anything, hard-reload the page (Ctrl+Shift+R / Cmd+Shift+R; on a phone, close the tab
> and reopen it) or use a fresh private window. Two false trails on 2026-09-28 came from skipping this.
>
> **The `curl` lines can be blocked (seen 2026-09-28).** Hostinger's bot protection may answer plain
> `curl` with a "Checking your browser" JavaScript page instead of the file, so the checks below can
> print nothing without meaning anything. If they come back empty, run the check from a real browser:
> the page's own scripts (DevTools → Network → JS), or `scripts/`-style CDP tooling.

```bash
S=https://watchizereg.com; P="probe=$(date +%s%N)"
# a) what the BROWSER calls — the API base compiled into the JS chunks (https-prefixed, so the
#    bare hostnames in next/image's allow-list, where dash. stays on purpose, cannot count)
curl -s "$S/?$P" | grep -oE '/_next/static/chunks/[^"]+\.js' | sort -u | sed "s#^#$S#" | xargs -n1 curl -s \
  | grep -oE 'https://(api|dash)\.watchizereg\.com/api' | sort | uniq -c        # ONLY api.watchizereg.com/api
# b) what the storefront SERVER rewrites to (LARAVEL_ORIGIN) — /api/v2 exists ONLY on core
curl -s -o /dev/null -w 'v2 via storefront: %{http_code}\n' "$S/api/v2/watchizer/meta?$P"   # 200 (legacy: 404)
# c) the image host inside real data — set by CORE's COMPAT_ASSET_BASE, not by the bundle
curl -s "$S/?$P" | grep -oE 'https(://|%3A%2F%2F)(api|dash)\.watchizereg\.com(/|%2F)Uploads_Images' | sort | uniq -c   # only api.
```

(a) or (b) wrong → the BUILD is wrong (panel variables, then rebuild). (c) alone wrong → core's
`.env` (`COMPAT_ASSET_BASE`, one line, `config:cache`, then `cache:clear`). Do not judge an image
URL in the page as evidence of the API host: it comes from the payload, i.e. from core's setting.

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
12. **The access log records YOUR address, not Hostinger's** (§4B). One request from a connection
    whose public address you know, then one line of the log:

    ```bash
    curl -s -o /dev/null https://api.watchizereg.com/api/v2/watchizer/meta
    tail -1 ~/domains/eleganceeg.com/logs/access.log
    ```

    If it records an edge address instead, every rate limiter in the application has become one
    shared bucket for all visitors — and that, not before, is when `trustProxies` becomes correct.
13. **A card payment, end to end** (§4A). Place one, let Paymob return, and confirm the order's
    payment status in the dashboard. Then check the intention actually carried its own callback
    URLs rather than relying on the merchant portal:

    ```bash
    grep -c 'payment initiation refused' storage/logs/laravel.log     # expect 0
    ```

    A non-zero count means `CallbackDestination` could not build an https base and **refused to
    start payments** — shoppers are being told the payment could not be started. Read §4A.1.

    And confirm the payment went through the CONTRACT rather than the fall-through, which is the
    §3A precondition doing its job:

    ```bash
    php artisan tinker --execute="
      \$r = Illuminate\Support\Facades\DB::table('payment_statuses')->latest('id')->first();
      echo 'provider: ', \$r->provider ?? 'NULL', PHP_EOL, 'order_id: ', \$r->order_id ?? 'NULL', PHP_EOL,
           'txn: ', \$r->pay_transaction_id ?? 'NULL', PHP_EOL, 'amount: ', \$r->amount_cents ?? 'NULL', PHP_EOL;"
    ```

    **All four must be populated.** A row with `order_id`, `pay_transaction_id` and `amount_cents`
    all NULL is the signature of a nested callback read by a handler that could not see it — the
    defect of review 🔴-2. It cannot happen now, and this is the check that says so rather than
    assuming it.

14. **The dashboard session cookie actually carries `Secure`** (security audit Finding 9). The
    config now defaults to Secure unless `APP_ENV=local` — but a config default is a claim until the
    response header shows it (a stray `SESSION_SECURE_COOKIE=false`, or `APP_ENV=local` left on the
    server, would silently undo it):

    ```bash
    curl -sI https://eleganceeg.com/manage/login | grep -i '^set-cookie'
    ```

    **Every `Set-Cookie` line — the session cookie and `XSRF-TOKEN` — must contain `secure`**
    (case-insensitive), alongside `httponly` on the session cookie and `samesite=lax`. A session
    cookie without `secure` means stop: check `APP_ENV` and `SESSION_SECURE_COOKIE` in `core/.env`,
    `config:cache`, and look again.

**Only when all fourteen pass:** turn the legacy storefront off, and write down the moment you did.

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
or an empty blog list as a failed cutover. They are the plan. The fourteen steps in §7 are the things
that must work.

## 9. Rollback

Nothing here is irreversible while the legacy host is up. Rollback is **one file**.

### 9.1 Put the storefront back — EDIT THREE LINES AND PUSH

> **⚠ Since the night (2026-09-24) the file below is NOT what the build reads for these names.**
> The Hostinger Node app's **build environment variables** define `NEXT_PUBLIC_API_BASE`,
> `NEXT_PUBLIC_ASSET_BASE`, `LARAVEL_ORIGIN`, `NEXT_PUBLIC_PUBLIC_API_KEY` and
> `NEXT_PUBLIC_PAYMOB_ENABLED`, and a real environment variable ALWAYS beats a `.env*` file in
> `next build`. The cutover's `.env.production` edit was silently overridden by stale panel values —
> the merge looked done and the storefront stayed on legacy. **A rollback done by editing this file
> alone therefore changes nothing.**
>
> **Rollback is now: set those names in the panel back to the legacy hosts, then rebuild** (the
> panel's redeploy, or an empty commit pushed to `main`). Change `.env.production` too, so the two
> stay identical — but the panel is the one that counts. Verify with §7's bundle check, never by eye.
> Still read from the FILE, because the panel does not define them: `NEXT_PUBLIC_META_PIXEL_ID`,
> `NEXT_PUBLIC_IMAGE_CDN_BASE`. The cleaner end state, after the season: delete the panel's
> variables so the tracked file is the single source again, and this box can go.

```bash
cd "D:/coding/watchizer website/new watchizer/Frontend-next"
# edit .env.production: the three hosts back to the legacy application
#   NEXT_PUBLIC_API_BASE=https://dash.watchizereg.com/api
#   LARAVEL_ORIGIN=https://dash.watchizereg.com
#   NEXT_PUBLIC_ASSET_BASE=https://dash.watchizereg.com
git add .env.production
git commit -m "rollback: storefront back on the legacy host"
git push origin main
```

Then wait for the Hostinger build. **Time to recover: one build.**

#### Not `git revert` — review 🟠-6

The line that used to sit here recommended `git revert <the cutover commit>`, on the grounds that it
"cannot mistype a URL". It would have reverted **the whole merge**, and the cutover is one commit
containing several unrelated things:

| also in that commit | what a revert would do to it |
|---|---|
| `next.config.js` `remotePatterns` for `api.watchizereg.com` | removed — and §9.2 says to leave it, because it is harmless and re-adding it is another build |
| the Meta Pixel (`NEXT_PUBLIC_META_PIXEL_ID`, `app/analytics.jsx`, the four e-commerce events) | **removed.** Analytics would go dark during the one incident you most want measured |
| the cart, checkout and product-page changes the pixel work touched | removed, silently, because they travelled with it |

A revert of a mixed commit undoes everything in it, not the part that is failing. **Rollback is the
three hosts in one file** — which is what the sentence two paragraphs up has always said, and what
the header of `.env.production` itself says (*"ROLLBACK IS THIS FILE"*). The prose and the command
now agree.

**If you would rather not hand-type the hosts**, revert the one file rather than the commit:

```bash
git checkout <commit before the cutover> -- Frontend-next/.env.production
git commit -m "rollback: storefront back on the legacy host"
git push origin main
```

That takes the file back verbatim with no typing and leaves everything else in place. Confirm what
you are about to ship before pushing:

```bash
git diff --stat HEAD~1          # must be ONE file: Frontend-next/.env.production
grep -E 'API_BASE|LARAVEL_ORIGIN|ASSET_BASE' Frontend-next/.env.production
```

### 9.1A The rollback target is NOT patched — decided, with the risk stated

Rolling back puts the storefront back on the legacy app at `dash.watchizereg.com`, and that app is
running its **current** code. The security audit (2026-09-23) found two defects in it; both are
fixed on `wave-4d` in `backend/`, and **neither will be deployed** (developer decision,
2026-09-23): the legacy app is retired once the storefront is on the new system, so deploying to it
is work with no future.

What that leaves in the rollback target, for as long as a rollback lasts:

| | |
|---|---|
| **The API gate fails OPEN on an empty key** (audit Finding 4) | `CheckApiMiddleware` compares with `==`, so an unset or empty `PUBLIC_API_KEY` lets a caller with no `Api-Code` header through every `CheckApi` route. **Latent:** `PUBLIC_API_KEY` is set on production, and with a key set the gate behaves correctly (measured against the real class: a missing or wrong header is 401). It becomes real only if that key is ever blanked on the legacy host — so do not blank it |
| **`POST /register` is open and unthrottled** (audit Finding 5) | Anonymous account creation into the shared `users` table on the dashboard host. It grants no dashboard access (`type = User`), but it is unbounded row creation in the table holding every customer, and core's `UserWriteGuard` cannot constrain another process |

The exposure is bounded by the rollback window — hours, not days — and exists today regardless of
the cutover. If a rollback ever has to last longer than that, deploy the two `backend/` changes
first: they are a two-line middleware guard and a one-line throttle, isolated on purpose.

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

That is the reason §7 has fourteen steps and the legacy site stays up until they pass: **the cheapest
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

## 12. Running `compat:diff` safely before the window

The reviewer could not run the harness without writing legacy tables, and reported that as a
blocker. **It is the design, not a defect** — but the contract (127 cases then, **134** since 2026-09-23) has not been re-diffed
since Phase 1, and it should be. Here is how, on a scratch copy.

### 12.1 Why it writes, and why the guard will not let you point it at production

`compat:diff` places genuine **cash-on-delivery orders on both hosts** and creates addresses and
carts. That is the only way to prove the ledger and the checkout contract; a read-only harness
would diff the easy half. Two local runs on 2026-09-15 left 12 orders, 12 order_items, 6 carts and
6 addresses behind.

So `App\Support\WriteTarget` refuses any target that is not a local copy, and it asks about the
**database** rather than the URL — because a loopback run still writes production rows if
`core/.env` has `DB_HOST` pointed at production. This, which is exactly what somebody copies at
02:00, is REFUSED:

```bash
php artisan compat:diff --legacy=https://dash.watchizereg.com --compat=https://api.watchizereg.com   # ✗
```

`--allow-remote --subject-user=<existing id>` exists as the deliberate override, and **this is not
the window to use it.** Placing test orders on the live database hours before a cutover puts rows
in front of the team that nobody can tell from real ones.

### 12.2 The scratch copy — what makes the run safe

The rehearsal protocol already describes this (`CLEAN_CORE_STUDY` §2.9.4); the point here is that
the harness needs **one** database, holding a restored production dump, that is not production.

```bash
# 1. a scratch database, on the workstation's MariaDB
mysql -u root -e "CREATE DATABASE wz_scratch CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root wz_scratch < "D:/coding/_private-data/<the production dump>.sql"

# 2. point core/.env at it — the ONE edit that decides where every row lands
#      DB_DATABASE=wz_scratch
php artisan config:clear          # a CACHED config makes the edit inert, guard included

# 3. prove where you are pointed before running anything that writes
php artisan tinker --execute="echo config('database.connections.mariadb.database'), PHP_EOL;"
```

**Step 3 is not ceremony.** It is the only thing between a harness run and production rows, and the
guard reads the same value — so if this prints the production database name, the run will be
refused rather than performed, which is the correct outcome but a worse way to find out.

Then apply core's own migrations and transform to the scratch copy:

```bash
php artisan migrate --force
php artisan core:drop-clean && php artisan migrate --force   # SCRATCH COPY ONLY — production is NEVER rebuilt (study §3.4 b)
php artisan core:transform --audit && php artisan core:transform
```

### 12.3 The run

```bash
pwsh -File scripts/run-compat-harness.ps1
```

The launcher is preferred over the bare command because it knows the whole recipe, and each thing
it checks is something that has already gone wrong once:

- it boots the **legacy** app from `backend/` on 8011 and **core** on 8001, both pointed at core's
  database (same data, two implementations — which is what a compat contract compares);
- it overrides the legacy app's DB credentials **in the environment only**, never by editing
  `backend/.env`, which holds production values;
- it refuses to start if `core/.env` names a non-local database, if the two hosts' shared secrets
  disagree (the run would be meaningless), if a config cache would make the overrides inert, or if
  either port is already held by somebody else's server;
- it **parks every outbox row the run writes**, because each checkout enqueues `pending` mail
  addressed to real people.

Expected: **134 cases, PASS**, with every difference on the sanctioned list in
`App\Compat\Diff\DeviationRules`. Anything else exits 1 and names the case.

**Why 134, and why a re-diff before the window is not optional this time.** Seven Arabic cases were
added on 2026-09-23 (`address:*:ar`, `cart:remove:*:ar`, `checkout:*:ar`) after a review found that
`add_address`, `remove_from_cart` and `add_order` answered their field errors in `APP_LOCALE` —
which has been `ar` on the server since 2026-09-20 — while legacy negotiates `Accept-Language`
with an `en` default. Every shopper, English browser included, would have seen Arabic errors after
the flip. Those three routes now negotiate exactly as legacy does. **The change of `APP_LOCALE` came
after the last re-diff**, so the header-less cases `address:invalid`, `cart:remove:*` and
`checkout:no-address` would have FAILED the next run — which is the strongest argument there is for
running it before the window rather than after. The one remaining difference, Arabic PHRASING where
the two `lang/ar/validation.php` files word a rule differently, is sanctioned as **D-25** and scoped
to `errors.*` only.

### 12.4 What a re-diff can and cannot tell you now

| | |
|---|---|
| **Covers** | the 8 moved legacy read paths, the 21 gone-410s, the proxy whitelist, the cart and checkout write contract, and the CORS and `Vary` headers |
| **Does NOT cover** | **anything Phase 1 added.** `login`, `register`, the password reset, social sign-in and the customer profile writes have no legacy counterpart running in core to diff against — the legacy app still owns those on its own host. They are covered by `tests/Feature/Customers` (143 tests) and by §7's live steps, not by this harness |
| **Also does not cover** | the payment callbacks. `PaymentProveCallbackRaceCommand` is the probe for those, and it is under the same `WriteTarget` guard |

So a green re-diff says "the compat layer still answers as the legacy app did". It does not say
"Phase 1 works" — and it never did.

### 12.5 Afterwards

```bash
mysql -u root -e "DROP DATABASE wz_scratch;"
# and put core/.env back to the local development database
php artisan config:clear
```

Dropping the scratch database is part of the run, not tidying: a scratch copy left on disk with a
production dump in it is the thing that ends up being pointed at by accident next month.

---

## Appendix — every command, in order

```bash
# §2 — on the workstation
npm run build                        # REQUIRED: the customers-screen control changed public/build/
composer install --no-dev --optimize-autoloader
tar -czf core-phase1.tar.gz --exclude='core/.env' --exclude='core/node_modules'   --exclude='core/tests' --exclude='core/docs' --exclude='core/.git'   --exclude='core/storage'   --exclude='core/public/Uploads_Images' --exclude='core/public/dumps'   --exclude='core/public/hot' --exclude='core/phpunit.xml' --exclude='core/phpstan.neon' core/
composer install                     # put the dev tools back locally

# §2 — on the server: stage, PROVE, then copy
mkdir -p ~/deploy-staging && cd ~/deploy-staging && rm -rf core && tar -xzf ~/core-phase1.tar.gz
ls core/app/Domain/Customers/CustomerSocial.php && ls core/vendor/laravel/socialite/composer.json
find . -print | grep -F '\'   # must print NOTHING; any output at all = mangled archive, STOP
cp ~/domains/eleganceeg.com/core/public/.htaccess ~/htaccess.before-phase2.bak   # §2.4 — BEFORE
cp -a ~/deploy-staging/core/. ~/domains/eleganceeg.com/core/
cp ~/htaccess.before-phase2.bak ~/domains/eleganceeg.com/core/public/.htaccess   # §2.4 — AFTER
curl -s -o /dev/null -w 'ele /api %{http_code}
' https://eleganceeg.com/api/catalog/meta       # 404
curl -s -o /dev/null -w 'api /api %{http_code}
' https://api.watchizereg.com/api/catalog/meta  # 404
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

# §3A — the Paymob contract, entered on /manage/storefronts/1/payments (days early is fine; the
# proof needs §2 deployed). COMPLETE must read yes — `credentials: set` was the old, insufficient check.
php artisan tinker --execute="\$c = App\Models\Storefront\StorefrontPaymentProvider::query()->where('storefront_id',1)->where('provider','paymob')->first(); \$req = app(App\Domain\Payment\ProviderRegistry::class)->credentialFields('paymob'); echo 'contract: ', \$c ? 'present':'MISSING', PHP_EOL, 'enabled: ', \$c && \$c->is_enabled ? 'yes':'NO', PHP_EOL, 'keys present: ', \$c ? (implode(', ', \$c->credentialKeys()) ?: '(none)') : '-', PHP_EOL, 'missing: ', \$c ? (implode(', ', \$c->missingCredentials(\$req)) ?: '(none)') : implode(', ', \$req), PHP_EOL, 'COMPLETE: ', \$c && \$c->credentialsComplete(\$req) ? 'yes':'NO', PHP_EOL, 'card method: ', \$c ? (\$c->methods()->where('method','card')->where('is_enabled',1)->value('integration_id') ?: 'NONE') : '-', PHP_EOL;"

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

# rollback (§9.1) — the THREE HOSTS in ONE FILE, never a revert of the mixed cutover commit
git checkout <commit before the cutover> -- Frontend-next/.env.production
git diff --stat HEAD            # must show ONE file
git commit -m "rollback: storefront back on the legacy host" && git push origin main
```
