# RUNBOOK — the Brand Fashion launch deploy (core + both storefronts), written and rehearsed 2026-10-19

**What this deploys.** Core, from the 2026-10-15 tree (`333eef0`) to `wave-4d` as committed today — 0 migrations, 0
`composer.lock` change, 0 dashboard-bundle change; the v2 routes Brand Fashion's new app needs (home, related, offers,
cart, orders, `me/orders`, `auth/providers`), the per-storefront Google sign-in, the `v2-order` limiter, the v2 cache
made private. Then BOTH storefronts at once (a push to `main` builds both Node apps): Brand Fashion's first build that
takes orders (cart, checkout and account on v2), and Watchizer's three changes (the sidebar, the true returns line and
Arabic trust labels, the second branch on the contact page).

**Its shape is the 2026-10-15 deploy's** (`RUNBOOK_PHASE2_STOREFRONT.md` §2 and §13, the commands proven that day) —
numbered phases, each ending at a **STOP gate**; a gate that does not read as written stops the deploy there. Every
phase has its own way back, and a later phase's way back never needs an earlier phase undone first.

**Record as you go:** every figure below that says *measured* was measured on 2026-10-19 (`RUN_20261019_DAY.md`); on the
day, write each gate's actual answer next to it.

---

## The phases on one screen

| # | Phase | Where | STOP gate (the deploy stops here if it does not read so) | Reversible? |
|---|---|---|---|---|
| 0 | Prepare (any day before) | Google console, hPanel mail, the dashboard | Brand Fashion's pair saved; the mail password rotated; nothing on the live site changed | yes — nothing is live yet |
| 1 | Build the archive | workstation | gate NEW 0 / stale 0 / CHANGED 0, phpstan clean, archive 7–9 MB, archive code digest = tree digest | yes — local only |
| 2 | Pre-flight, read-only | server + workstation | the live code digest = `333eef0`'s; the snapshot over 1 GB; Watchizer's Google body hash recorded | yes — reads only |
| 3 | **CORE** — the one step that touches Watchizer | server | four-host check 401/401/404/404; "Nothing to migrate"; Watchizer's Google body hash UNCHANGED; v2 18 routes; CORS on the wire | **yes, code only** — no migration, no data change (§3.9) |
| 4 | Watchizer proves it is unchanged on the new core | the live site | a Watchizer COD order and a Google sign-in, both clean | — (a check) |
| 5 | `core/.env`: Brand Fashion's Google pair; CORS read | server | `auth/providers` lists Google for Brand Fashion only if its pair is set | yes — two lines |
| 6 | **Both storefronts** — merge and push `main` | workstation → Hostinger | the bundle check on BOTH sites: the API, the storefront code, today's marker string | yes — a revert push (one build each) |
| 7 | Data: storefront 2's mailbox, the 14 home rails, COD | server / dashboard | the mailbox `--show`; 14 rails; COD offered | yes — each has its undo |
| 8 | Smoke tests — Watchizer, then Brand Fashion | the live sites | Watchizer: order + Google; Brand Fashion: a real COD order end to end | — (a check) |
| 9 | Pre-launch list on the real domain | the live site | every row's check reads as written | — |

> **✅ PHASES 0–8 SIGNED OFF — 2026-10-19**, executed by Michael with the owner; Brand Fashion is deployed and took a
> real cash-on-delivery order end to end (phase 8). The run, phase by phase, with what was verified and by whom:
> `RUN_20261019_DAY.md` → **THE DEPLOY**. **Phase 9 is NOT done** — it belongs to the real domain. Two errors in this
> runbook were found during the run and are corrected below (§2.3 / §3.3's `.htaccess` counts, and §1.2's gate); what
> is still open is in `POST_CUTOVER_BACKLOG.md`, starting with `BF-GOOGLE-PUBLISH`.

**What cannot be undone:** a real order once placed (its number is used from the ONE sequence both shops share, its
stock moved, its e-mails sent), any e-mail once sent, and the old mail password once rotated (on purpose). Nothing in
the CODE path is one-way: there is no migration in this deploy.

**The phase most likely to go wrong: 6** — see §6.0.

---

## 0. Prepare — days before is fine; nothing here changes the live site

### 0.1 Brand Fashion's Google project (Michael)

Create the project and an OAuth client of type **Web application**. Register EXACTLY (derived from the code —
`CustomerSocial::callbackUrl()`: the storefront's API host + the callback route; pinned by
`tests/Feature/Customers/SocialPerStorefrontTest.php`):

| Field | Value |
|---|---|
| Authorised redirect URI (one) | `https://api.michaelkhalaf.com/api/auth/google/callback` |
| Authorised JavaScript origins | none — the flow is a server-side redirect; no Google script runs in the page |
| OAuth consent screen → authorised domain | `michaelkhalaf.com` |
| Home page / privacy / terms links | `https://michaelkhalaf.com` · `https://michaelkhalaf.com/privacy-policy` · `https://michaelkhalaf.com/terms-and-conditions` |
| Scopes | `openid`, `…/auth/userinfo.email`, `…/auth/userinfo.profile` (non-sensitive: no verification review) |
| Publishing status | **In production** — in "Testing" only listed test users can sign in |

At the real domain (`brandfashionegy.com`) the URI becomes `https://api.brandfashionegy.com/api/auth/google/callback`
(it follows `storefronts.domain`): add it then, keep the old one until nobody can be mid-flow on it.

Keep the client id and secret for §5. **Watchizer's project is not touched.** Check, only, that it lists
`https://api.watchizereg.com/api/auth/google/callback` (what production sends today — measured, §2.4).

**Until §5 sets the pair, Brand Fashion shows NO Google button** (login and register: the e-mail form alone, no "or";
the privacy page does not mention Google) — the honest state, measured in the rehearsal both ways.

### 0.2 Rotate Brand Fashion's mail password (Michael — backlog BF-MAIL-PW, 🔴 blocks the first Brand Fashion e-mail)

In hPanel → Emails → `no-reply@bf.michaelkhalaf.com` → change the password (the development value is burned). Keep the
new value for §7.1 — it goes into the mailbox ROW, never into `.env`, a commit or a message.

### 0.3 What is ALREADY true on production (measured 2026-10-19, read-only — re-check, do not redo)

- **Cash on delivery for storefront 2**: `GET https://api.michaelkhalaf.com/api/v2/brandfashion/payment-methods` →
  `{"data":[{"id":5847,"method":"cod",…"Cash on delivery"…}]}`.
- **CORS**: core answers `Access-Control-Allow-Origin` for `https://michaelkhalaf.com` AND `https://www.michaelkhalaf.com`,
  and none for a stranger (measured with a cache-busting query, so it is the application's answer, not a CDN copy).
- **Brand Fashion's panel** builds with `NEXT_PUBLIC_API_BASE=https://api.michaelkhalaf.com/api` and
  `NEXT_PUBLIC_STOREFRONT_CODE=brandfashion` (both compiled into the live chunks today) and no GA4 id.

---

## 1. Build the archive — workstation (proven commands of 2026-10-15)

**1.1 The commits are in, and nothing else is.** `git status --short` → empty (or only the stat-cache phantom you know).
The untracked `Frontend-next/public/og-image - Copy.jpg` (appeared 2026-10-19, not ours) is NOT in any commit.

**1.2 phpstan, then the suite gate — never together with a build, never with a dev server running.**

```bash
cd "D:/coding/watchizer website/new watchizer/core"
composer phpstan                                   # [OK] No errors
bash tests/baseline/suite-baseline.sh               # NEW 0, stale 0, CHANGED 0 (the known set by name)
```

**STOP** unless phpstan is clean and the gate prints no NEW failure, no stale entry, no changed table.

> **The gate pins its own terminal width since 2026-10-19 — and on this deploy it tripped before it did.** It reported
> **40 new failures and the same 40 as newly passing**, on a tree that was green: Pest cuts each `FAILED` name to the
> terminal width, the baseline file stores the cut form, so the comparison was the operator's console width in
> disguise (120 here, 187 on Michael's). `suite-baseline.sh` line 99 now runs the suite as `COLUMNS=120 php artisan
> test …`, which overrides the console, and the gate is green at 80, 187 and 400. **If it ever trips on 40 again,
> read `GATE-WIDTH-20261019` before believing it** — and if the same two sections ever list the same tests, that is
> the signature, because a test cannot be both new and fixed. The real fix is `GATE-JUNIT-NAMES`, required before this
> gate runs on another machine or in CI.

> **STALE — corrected 2026-10-21.** The dashboard bundle changed in BOTH waves deployed on 2026-10-21 (`resources/js`
> is edited whenever a dashboard screen is). The rule is the standing runbook's (`RUNBOOK_PHASE2_STOREFRONT.md` §2.1):
> `npm run build` on the workstation and ship `public/build/` in the archive; the proof is that the live
> `manifest.json` digest equals the ARCHIVE's, never that it equals a value written here. `699aab9e…` below was the
> 2026-10-15 bundle; deploy 2 of 2026-10-21 left `64f727ab…`.

**1.3 The dashboard bundle does NOT change — prove it, do not rebuild it.** `resources/js` is untouched since
2026-10-14; the bundle on disk is the one live since 2026-10-15:

```bash
sha256sum public/build/manifest.json                      # 699aab9e75db6a4eace7fc2b7478f2b81c06f9c8e78aeaaf0ba2778ecb5d71fd
curl -s https://eleganceeg.com/build/manifest.json | sha256sum   # the same digest
```

**STOP** if they differ — then `npm run build` and §13 step 1 of the old runbook apply.

**1.4 Strip the dev tooling, archive, size gate** (§2.1 step 1 and §2.3 — PowerShell, absolute paths, `-C`):

```powershell
Set-Location 'D:\coding\watchizer website\new watchizer\core'
composer install --no-dev --optimize-autoloader
$D = Get-Date -Format yyyyMMdd
# The exclusion list is a TRACKED FILE since 2026-10-20 — core/docs/deploy/tar-exclude.list, twelve lines, LF, no BOM,
# no blank line. It used to be typed by hand into C:\temp\exclude.txt, which is how a 29-byte archive was built and
# uploaded twice (RUNBOOK_PHASE2_STOREFRONT §2.3). Read it from the repository; never retype it. The name is not
# `exclude.txt` because .gitignore line 81 ignores that name everywhere — deliberately, to keep throwaway deploy
# artifacts out of the tree — and the one authoritative list should not share a name with the scratch file it replaces.
tar -czf "C:\temp\core-$D.tar.gz" -X 'D:\coding\watchizer website\new watchizer\core\docs\deploy\tar-exclude.list' -C 'D:\coding\watchizer website\new watchizer' core
(Get-Item "C:\temp\core-$D.tar.gz").Length          # 7–9 MB. Rehearsal: 7,493,758 bytes, 7,831 files
(Get-FileHash "C:\temp\core-$D.tar.gz" -Algorithm SHA256).Hash
composer install                                    # put the dev tools back on the workstation
```

**STOP** outside 7–9 MB (over 15 MB = the dev tooling is in `vendor/`).

**1.5 The code digest of the tree you are shipping** — Git Bash; the same command runs on the server in §2 and §3:

```bash
cd "D:/coding/watchizer website/new watchizer/core"
LC_ALL=C find app bootstrap/app.php bootstrap/providers.php config database lang resources/views routes -type f \
  | LC_ALL=C sort | xargs -d '\n' sha256sum --tag | sha256sum
```

Write the value down: **the NEW digest**. The tree as handed over at the close of 2026-10-19 (the commits of that
day's command set, nothing else) digests to `c36333a71b79f014b5ef918cf2cce8b858177bc2e24e2aef60a113bbfbff0c56` (502 files) —
if your value differs, something besides those commits is in the tree: find it before the tar. (The rehearsal's
archive, built before the v2 cache change, digested to the tree of that moment once extracted — the method is proven.)
`--tag` makes the output identical on Windows and Linux (plain `sha256sum` prints `*path` on Windows, ` path` on Linux).

**Way back:** nothing to undo — the archive is a local file.

---

## 2. Pre-flight — read-only, on the server and from the workstation

> **STALE — corrected 2026-10-21.** The digest below is the pre-2026-10-19 tree. Deploy 1 of 2026-10-21 left the live
> tree digest `7941eadf…`, deploy 2 left `02c575b8…`. Expect the digest of the tree you are deploying FROM, computed
> the same way on the workstation on the day — never a value written into this file.

**2.1 The live code is the tree we think it is (`333eef0`).** Core deploys from the WORKSTATION tree, not from `main`
(`main` is older: the 2026-10-15 asset-host fix `4a24d67` is live and not on `main`). So prove it:

```bash
# on the server
cd ~/domains/eleganceeg.com/core
LC_ALL=C find app bootstrap/app.php bootstrap/providers.php config database lang resources/views routes -type f \
  | LC_ALL=C sort | xargs -d '\n' sha256sum --tag | sha256sum
# expect the 333eef0 digest:  f225ac87506f3d2634efd7c0db03bd72db8021bd2eec578dce96ad275640ca2f  (487 files)
```

**If it differs: STOP and find out why before anything else** — the delta this runbook lists (22 committed files +
today's 5) is exact only from `333eef0`. To see which files: on the server write the list
(`… | xargs -d '\n' sha256sum --tag > ~/live-manifest.txt`), and on the workstation build `333eef0`'s from git
(read-only — `git archive` changes nothing):

```bash
rm -rf /tmp/base && mkdir -p /tmp/base && cd "D:/coding/watchizer website/new watchizer" \
  && git archive 333eef0 core/app core/bootstrap/app.php core/bootstrap/providers.php core/config core/database core/lang core/resources/views core/routes | tar -x -C /tmp/base \
  && cd /tmp/base/core && LC_ALL=C find app bootstrap/app.php bootstrap/providers.php config database lang resources/views routes -type f \
  | LC_ALL=C sort | xargs -d '\n' sha256sum --tag > /tmp/manifest-333eef0.txt   # then: diff it with the server's list
```

A file present on the server and absent from git (a leftover an older `cp -a` never deleted) is the likely answer,
and is harmless if nothing routes to it.

**2.2 The snapshot — the way back for §3** (old runbook §2.4; expect **over 1 GB**, 2026-10-15: 1,520,345,734 bytes):

```bash
cd ~/domains/eleganceeg.com
TAG=a   # UNIQUE PER DEPLOY OF THE DAY (a, b, c …): on 2026-10-21 the afternoon deploy's backup would have OVERWRITTEN the morning's; it was taken as core-before-20261021b
tar -czf ~/core-before-$(date +%Y%m%d)-$TAG.tar.gz core
ls -l ~/core-before-*.tar.gz && sha256sum ~/core-before-$(date +%Y%m%d)-$TAG.tar.gz
du -sh core/public core/vendor core/storage core/storage/logs
```

**2.3 The `.htaccess` baseline** — the content gate's three counts, BEFORE (expect **1 / 1 / 3**, **64 lines** — **corrected 2026-10-21, measured on the day:** `api\.` matches ONE line, line 8, a comment; the live file diffed IDENTICAL to `~/htaccess.before-20261015.bak`; this said 2 / 1 / 3). **Corrected 2026-10-19:** this said `2 / 1 / 1`, which was my misreading of the live file — `R=404,L`
occurs **three** times in it, not once. Nothing changed on the server: the live file was diffed against
`~/htaccess.before-20261015.bak` on the day and came back **IDENTICAL**.

```bash
F=~/domains/eleganceeg.com/core/public/.htaccess
grep -c 'api\\.(watchizereg|michaelkhalaf)' "$F"; grep -c 'assets/brand/' "$F"; grep -c 'R=404,L' "$F"; wc -l "$F"
```

**2.4 Watchizer's Google answer, BEFORE** — from the workstation (Git Bash); `$K` is the storefront's PUBLIC API key
(the `NEXT_PUBLIC_PUBLIC_API_KEY` every page ships — read it from `Frontend-brandfashion/.env.local`, do not paste it):

```bash
K=$(grep -E '^NEXT_PUBLIC_PUBLIC_API_KEY=' "D:/coding/watchizer website/new watchizer/Frontend-brandfashion/.env.local" | cut -d= -f2- | tr -d '\r"')
for h in api.watchizereg.com api.michaelkhalaf.com; do printf '%-24s ' $h
  curl -s -A "Mozilla/5.0" -H "Api-Code: $K" -H "Accept: application/json" \
    "https://$h/api/auth/google/redirect?nonce=RehearsalNonce20261019aaaa" | sha256sum | cut -c1-64; done
# body sha256 measured 2026-10-19, BOTH hosts:  4f29f613a50995be11aab71e685a6196c69b980bfb411c8bd05a335b67105bcf
```

The route is stateless (the nonce is signed, never stored): this GET changes nothing. The body is deterministic for a
fixed nonce while `APP_KEY` and the Google keys are unchanged — so after §3 Watchizer's line must print the SAME hash.

**STOP** unless 2.1 matches and 2.2 is over 1 GB. **Way back:** nothing changed.

---

## 3. CORE — the one step that touches Watchizer

> **STALE — corrected 2026-10-21.** This table described the 2026-10-19 release only. On 2026-10-21 deploy 1 ran FOUR
> migrations (`DEPLOY_20261023.md`) and deploy 2 none (`DEPLOY_20261023_B.md`); the dashboard bundle changed in both;
> compat was touched (`routes/api.php`, the cache groups) and Watchizer's cart, checkout and orders tab moved to v2.
> What a release changes is in its dated deploy runbook; this table is history.

**3.0 What changes, exactly, and what could break Watchizer.**

| | |
|---|---|
| Migrations | **none** (`git diff 333eef0 HEAD -- core/database/migrations` is empty) |
| `composer.lock`, `vendor/` | unchanged packages (the archive's `vendor/` is the `--no-dev` set, as on 2026-10-15) |
| Dashboard bundle | unchanged (`699aab9e…`, §1.3) |
| Compat (`app/Compat/*`, Watchizer's live API) | **0 files changed** |
| Watchizer's order and payment path | **0 files changed** (compat checkout, `Domain/Payment`, `Domain/Orders`, the callbacks) |
| Watchizer's sign-in controller | `CustomerSocialController` + `CustomerSocial`: ADDITIVE — the default storefront with no pair of its own calls `Socialite::driver()` on the global keys exactly as before; its Google URL measured character-identical (sha256 of the URL before = after, 0 database writes); 41 sign-in tests green |
| `routes/api.php` | additions inside the v2 groups only (10 routes: 8 → 18) |
| `config/services.php` | one new block (`social_storefronts`), read only by the new code |
| `config/storefront.php` | the v2 cache header `public, s-maxage=600 …` → `max-age=60, private` — read only by v2's cached group, which Watchizer's app never calls (its only v2 read is `payment-methods`, not in that group) |
| `AppServiceProvider` | one new rate limiter, `v2-order` (boot) |
| New classes | v2 controllers (Account, Auth, Cart, Home, Offer, Order, Related), `StorefrontCart` / `Checkout` / `Home` / `Offers`, four seeders (the two demo ones refuse unless `APP_ENV=local`) |

**What could break Watchizer is the COPY, not the code** (the history: the `.htaccess` overwrite, `umask`, the dev
tooling, a mangled archive) — plus three boot-time failures that would take EVERY request down: a PHP error in
`AppServiceProvider` or a route file, and a failing `config:cache` / `route:cache`. All three are covered by the gate
(1.2: the suite boots the app 2,000 times) and by 3.6's immediate checks.

**3.1 Upload and verify** — SFTP the archive, then `sha256sum ~/core-<D>.tar.gz` on the server = 1.4's hash.

**3.2 Stage and prove** (old runbook §2.4):

```bash
umask 022
mkdir -p ~/deploy-staging && cd ~/deploy-staging && rm -rf core && tar -xzf ~/core-<D>.tar.gz
find core -type f ! -perm -o=r | wc -l                       # 0
ls core/app/Http/Controllers/V2/AuthController.php core/app/Storefront/StorefrontCheckout.php core/vendor/laravel/socialite/composer.json
find . -print | grep -F '\'                                  # prints NOTHING
ls -d core/vendor/phpstan core/vendor/laravel/pint core/vendor/pestphp 2>/dev/null | wc -l   # 0
sha256sum core/public/build/manifest.json                    # 699aab9e… (the live one)
cd core && LC_ALL=C find app bootstrap/app.php bootstrap/providers.php config database lang resources/views routes -type f \
  | LC_ALL=C sort | xargs -d '\n' sha256sum --tag | sha256sum   # = the NEW digest of 1.5
```

Rehearsed 2026-10-19 on the workstation: 7,831 files, mangling 0, dev tooling 0, excluded paths 0, manifest `699aab9e…`,
the staged digest equal to the tree's. **STOP** on any line that differs.

**3.3 Copy — the `.htaccess` pair ONCE before, ONCE after** (old runbook §2.4's box; the archive's `.htaccess` is the
25-line stock file with NO allow-list — measured in the rehearsal archive):

```bash
LIVE=~/domains/eleganceeg.com/core
cp "$LIVE"/public/.htaccess ~/htaccess.before-$(date +%Y%m%d).bak
cp -a ~/deploy-staging/core/. "$LIVE"/
cp ~/htaccess.before-$(date +%Y%m%d).bak "$LIVE"/public/.htaccess
for h in api.watchizereg.com api.michaelkhalaf.com; do
  curl -s -o /dev/null -w "$h /api/catalog/meta  %{http_code}\n" "https://$h/api/catalog/meta"   # 401
  curl -s -o /dev/null -w "$h /manage            %{http_code}\n" "https://$h/manage"             # 404
done
F="$LIVE"/public/.htaccess; grep -c 'api\\.(watchizereg|michaelkhalaf)' "$F"; grep -c 'assets/brand/' "$F"; grep -c 'R=404,L' "$F"   # 1 1 3, and wc -l 64 (corrected 2026-10-21: api. matches only line 8, a comment; this said 2 1 3 from 2026-10-19; it read 2 1 1, a misreading — the live file is unchanged)
```

**STOP — and restore the backup at once — on a 404 for `/api/catalog/meta`**: the allow-list is gone.

**3.4 Permissions, dev markers, migrations, keys:**

```bash
cd ~/domains/eleganceeg.com/core
chmod -R 775 storage bootstrap/cache && rm -f public/hot public/fonts-manifest.dev.json
php artisan migrate --pretend                    # see the box below: this release HAS migrations
php artisan core:repoint-commerce-fks --dry-run  # nothing to do
```

> **STALE — corrected 2026-10-21.** "Exactly these two" was true on 2026-10-19. The rule that survives: on production,
> `php artisan migrate:status | grep -i pending` is the authority; `--pretend` must list exactly the set the dated
> deploy runbook names (four on 2026-10-21 deploy 1, none on deploy 2) and any other name is a STOP.

**3.4a MIGRATIONS — A SEPARATE STEP, RUN IT DELIBERATELY (2026-10-20).** Until this release the line above was
expected to print "Nothing to migrate." **It is not any more.** `--pretend` must list EXACTLY these two and nothing
else; a third name means an unreviewed migration is on the server, and that is a **STOP**:

```
2026_10_22_000000_banner_device_and_crop          # +device, +image_original_path, +crop on storefront_banners
2026_10_22_000100_storefront_home_hero            # settings.home.hero per storefront
```

Both are **additive and reversible**: the first adds three `hasColumn`-guarded columns and drops exactly those three
on `down()`; the second inserts ONE key inside each storefront's existing `settings` JSON — reading and writing back
every other key untouched — and `down()` removes exactly that key. **No data is rewritten and no column is dropped or
retyped.** The second writes `three_d` for Watchizer and `banner` for Brand Fashion, which is what each shop renders
today, so **nothing a shopper sees changes when it runs**.

```bash
php artisan migrate --force                      # run it; expect the two names above, each DONE
php artisan tinker --execute="foreach (DB::table('storefronts')->orderBy('id')->get(['code','settings']) as \$r) { echo \$r->code.' '.(json_decode(\$r->settings, true)['home']['hero'] ?? 'MISSING').PHP_EOL; }"
# watchizer three_d
# brandfashion banner          <- anything else, especially `watchizer banner`, is a STOP: roll back per 3.9
```

**3.5 Caches** — `php artisan config:cache && php artisan route:cache && php artisan view:clear`. **STOP** on any error:
a failed cache command is the one way this deploy takes Watchizer down; restore per 3.9 immediately.

**3.6 Prove it — immediately, in this order:**

```bash
php artisan route:list --path=api/v2 --json | grep -o '"uri"' | wc -l            # 18 (was 8)
php artisan route:list --path=api | grep -cE 'Customer[A-Za-z]+Controller@'       # 16 (unchanged)
php artisan schedule:list | head -20                                              # the same schedule as before
# bootstrap/cache MUST STILL BE PRODUCTION'S (2026-10-20). `cp -a` copies whatever the archive holds, and until
# 2026-10-20 `core/bootstrap/cache` was NOT excluded — so a workstation that had run `php artisan config:cache`
# shipped ITS config.php and routes-v7.php over production's, silently, every value in them wrong. On 2026-10-19
# that did not happen only because the workstation had neither file to send. Both must predate today's copy, and
# both are regenerated by §3.5 a moment later:
ls -l --time-style=long-iso bootstrap/cache/ | grep -E 'config.php|routes-v7.php|packages.php|services.php'
```

**STOP if `config.php` or `routes-v7.php` carries today's timestamp from the copy rather than from §3.5's
`config:cache` / `route:cache`** — a development configuration is live. Restore them with §3.9 and rebuild the archive
with the tracked exclusion list.

From the workstation (Git Bash, `$K` as in 2.4):

```bash
# (a) WATCHIZER'S GOOGLE ANSWER — must be the SAME hash as 2.4 (4f29f613…); Brand Fashion's must now DIFFER
for h in api.watchizereg.com api.michaelkhalaf.com; do printf '%-24s ' $h
  curl -s -A "Mozilla/5.0" -H "Api-Code: $K" -H "Accept: application/json" \
    "https://$h/api/auth/google/redirect?nonce=RehearsalNonce20261019aaaa" | sha256sum | cut -c1-64; done
curl -s -A "Mozilla/5.0" -H "Api-Code: $K" -H "Accept: application/json" "https://api.michaelkhalaf.com/api/auth/google/redirect?nonce=RehearsalNonce20261019aaaa"; echo
#     → {"error":"Could not start social login."} until §5 sets Brand Fashion's pair (no flow starts; the button is hidden)
# (b) the new v2 routes answer (they were 404 this morning)
for p in home offers auth/providers payment-methods; do printf '%-16s ' $p; curl -s -o /dev/null -A "Mozilla/5.0" -w '%{http_code}\n' "https://api.michaelkhalaf.com/api/v2/brandfashion/$p"; done   # 200 x4
curl -s -A "Mozilla/5.0" https://api.michaelkhalaf.com/api/v2/brandfashion/auth/providers; echo     # {"providers":[]}
curl -s -A "Mozilla/5.0" https://api.watchizereg.com/api/v2/watchizer/auth/providers; echo         # {"providers":["google"]}
# (c) THE CDN CANNOT STORE A v2 ANSWER ANY MORE (the 09-29 incident, found again on v2 in the rehearsal):
r=$RANDOM$RANDOM; U="https://api.michaelkhalaf.com/api/v2/brandfashion/products?per_page=1&probe=$r"
curl -s -A "node" -D - -o /dev/null "$U" | grep -i '^cache-control'                         # max-age=60, private
curl -s -A "Mozilla/5.0" -H "Origin: https://michaelkhalaf.com" -D - -o /dev/null "$U" | grep -i '^access-control-allow-origin\|^x-hcdn-cache-status'
#     → access-control-allow-origin: https://michaelkhalaf.com, and NOT "x-hcdn-cache-status: HIT"
```

**Why (c) exists — measured on production 2026-10-19, before this deploy:** v2's cached group answered
`public, s-maxage=600`; Hostinger's CDN keeps ONE copy per URL and ignores `Vary: Origin`. A URL first read with no
`Origin` (Brand Fashion's own server, a crawler) was then served to the browser from the CDN WITH NO
`Access-Control-Allow-Origin` (`x-hcdn-cache-status: HIT`), and a URL warmed from `michaelkhalaf.com` was served to
`www.michaelkhalaf.com` with the apex's header. The browser refuses both. Today nothing live reads v2's cached group
from a browser; Brand Fashion's new build does (the listing, search, the cart's rails) — without the fix, its listing
would fail at random after §6. The fix is the 2026-09-29 one: `private` (the browser keeps its cache and ETag; the CDN
stores nothing). Cost: Brand Fashion's catalogue reads reach core every time; core's own cache serves the payloads.

**STOP** unless (a) prints Watchizer's hash unchanged, (b) 200s, (c) private and not a HIT.

**3.7 Watchizer's frontend is still the old build at this point** — `main` is not pushed until §6. That is deliberate:
§4 proves Watchizer on the new core alone.

**3.8 Measurements to write down:** the three counts, the two route counts, the Google hashes, the
`storage/logs` size (rules out an error flood — `du -sh storage/logs`, compare with 2.2), and the last 20 lines of
`storage/logs/laravel-$(date +%Y-%m-%d).log` (expect nothing new at ERROR).

> **STALE — corrected 2026-10-21.** "No migration to reverse" held on 2026-10-19 only. `php artisan migrate:rollback`
> is forbidden in this application (`DB::prohibitDestructiveCommands()`), so a release with migrations carries TESTED
> SQL rollbacks in its dated runbook — `DEPLOY_20261023.md` §5 for the four of 2026-10-21 deploy 1.

**3.9 Way back for §3 alone — code only, no data (there is no migration to reverse):**

```bash
cd ~ && rm -rf ~/rollback-staging && mkdir ~/rollback-staging && cd ~/rollback-staging
tar -xzf ~/core-before-<D>-<tag>.tar.gz core/app core/bootstrap core/config core/database core/lang core/resources core/routes core/vendor core/artisan core/composer.json core/composer.lock core/public/build
cp -a ~/rollback-staging/core/. ~/domains/eleganceeg.com/core/        # .htaccess is NOT in this list — the live one stays
cd ~/domains/eleganceeg.com/core && php artisan config:cache && php artisan route:cache && php artisan view:clear
# prove: the code digest of 2.1 again = f225ac87… EXCEPT for the files this deploy ADDED (cp -a never deletes):
#   app/Http/Controllers/V2/{Account,Auth,Cart,Home,Offer,Order,Related}Controller.php, app/Storefront/Storefront{Cart,Checkout,Home,Offers}.php,
#   database/seeders/{BrandFashionHomeRails,BrandFashionHomeRailsRemove,DemoVariants,DemoVariantsRemove}Seeder.php —
#   inert: the restored routes/api.php names none of them. Delete them only if you want the digest to match exactly.
```

Then 3.3's four-host check and 2.4's Google hash. Time: minutes. **Not needed for a §6 problem** — §6 has its own.

---

## 4. Watchizer proves it is unchanged on the new core (before any storefront build)

In a private window, on **watchizereg.com** (its frontend is still the 10-15 build):

1. **A COD order** — add a product, check out with cash on delivery, the confirmation page shows a number. In the
   dashboard (eleganceeg.com → Orders, filter Watchizer): the order is there, `storefront` Watchizer, stock moved, the
   confirmation e-mail arrived from Watchizer's mailbox. Cancel it afterwards as you always do with a test order.
2. **Sign in with Google** — "Continue with Google" → Google → back on `watchizereg.com/auth/callback` → signed in.
   (§3.6 (a) already proved the URL Watchizer sends is byte-identical; this proves the round trip.)
3. An old customer signs in with a password and sees their orders.

**STOP** if any fails — §3.9 restores core in minutes; nothing else has moved yet.

---

## 5. `core/.env` — Brand Fashion's Google pair (when §0.1 is done); CORS read

```bash
cd ~/domains/eleganceeg.com/core
# add (never in a commit or a message):
#   BRANDFASHION_GOOGLE_CLIENT_ID=<Brand Fashion's client id>
#   BRANDFASHION_GOOGLE_CLIENT_SECRET=<Brand Fashion's client secret>
php artisan config:cache
php artisan tinker --execute="echo json_encode(App\Domain\Customers\CustomerSocial::ownCredentials('google', 2) ? 'own pair: set' : 'own pair: NOT set'), PHP_EOL, App\Domain\Customers\CustomerSocial::callbackUrl('google', 2), PHP_EOL, json_encode(config('cors.allowed_origins')), PHP_EOL;"
#   "own pair: set" · https://api.michaelkhalaf.com/api/auth/google/callback · the origins incl. both michaelkhalaf hosts
```

Then from the workstation: `…/api/v2/brandfashion/auth/providers` → `{"providers":["google"]}`; the redirect GET of
§3.6 (a) on `api.michaelkhalaf.com` now answers a URL whose `redirect_uri` is
`https%3A%2F%2Fapi.michaelkhalaf.com%2Fapi%2Fauth%2Fgoogle%2Fcallback` and whose `client_id` is Brand Fashion's; and
Watchizer's hash is STILL `4f29f613…`.

**CORS_EXTRA_ORIGINS — what it is and what breaks if it is wrong.** `config/cors.php` allows `FRONTEND_URL`
(Watchizer), `www.watchizereg.com`, the local origins and whatever `CORS_EXTRA_ORIGINS` lists. Brand Fashion's browser
calls core cross-origin (`https://api.michaelkhalaf.com` from `https://michaelkhalaf.com`) for the cart, the order, the
listing, sign-in and the account — so a missing origin means **every one of those fails in the browser** (the pages
render on the server, then nothing a shopper does works: "Add" does nothing, the checkout cannot send). Measured today:
both `https://michaelkhalaf.com` and `https://www.michaelkhalaf.com` are allowed — **no change needed**. At the real
domain both `https://brandfashionegy.com` and `https://www.brandfashionegy.com` must be added (+ `config:cache`).

**Way back:** delete the two lines, `config:cache` — Brand Fashion's button disappears on the next read (60 s).

---

## 6. Both storefronts — merge and push `main`

### 6.0 Why this is the phase most likely to go wrong

Everything before it was rehearsed with the values used; this one is not and cannot be. **The build runs on
Hostinger, from the PANEL's values, not from anything in this repository** (the panel wins over every `.env*` file —
measured 2026-09-24 and 2026-10-14). Twice before, a storefront build went wrong in a way that LOOKED fine: on
2026-09-24 the build ignored an edited file and kept the old API host, and on 2026-10-05 the GitHub link broke and no
build ran at all — the old site kept answering. A wrong `NEXT_PUBLIC_*` is inlined silently and the build succeeds; a
build that never ran leaves Brand Fashion's OLD app (compat cart) on the NEW core, which still half-works. Only the
bundle check below tells the three apart. And ORDER matters: pushed before §3, Brand Fashion's new app calls v2 routes
production did not have this morning (`home`, `cart`, `orders` — 404) and its home and cart break.

### 6.1 Before the push — the panels (read them; change nothing on Watchizer's)

**Brand Fashion's Node app → environment variables** (root `Frontend-brandfashion`, pnpm, `pnpm run build`):

| Key | Value |
|---|---|
| `NEXT_PUBLIC_API_BASE` | `https://api.michaelkhalaf.com/api` |
| `LARAVEL_ORIGIN` | `https://api.michaelkhalaf.com` |
| `NEXT_PUBLIC_STOREFRONT_CODE` | `brandfashion` (the code also falls back to it since 2026-10-19 — still set it) |
| `NEXT_PUBLIC_ASSET_BASE` | `https://api.michaelkhalaf.com` |
| `NEXT_PUBLIC_PAYMOB_ENABLED` | `false` |
| `NEXT_PUBLIC_PUBLIC_API_KEY` | the storefront public key (as today) |
| `STOREFRONT_SERVER_KEY` | as today (one API-wide key — BF-SERVER-KEY) |
| `NEXT_PUBLIC_META_PIXEL_ID`, `NEXT_PUBLIC_GA4_MEASUREMENT_ID`, `NEXT_PUBLIC_TIKTOK_PIXEL_ID` | **absent** until the client's own accounts exist (BF-ANALYTICS-OWN) — never Watchizer's |

**Watchizer's panel: unchanged.**

### 6.2 The push (Michael)

```powershell
Set-Location 'D:\coding\watchizer website\new watchizer'
git checkout main
git merge --no-ff wave-4d -m "Merge wave-4d: Brand Fashion's cart, checkout and account on v2; Google sign-in per storefront; Watchizer's returns line, Arabic trust labels and second branch - CHANGES THE LIVE WATCHIZER APP"
git push origin main
git checkout wave-4d
```

### 6.3 The bundle check — BOTH sites, after BOTH builds (hard-reload first; old runbook §7 step 0)

```bash
for S in https://michaelkhalaf.com https://watchizereg.com; do P="probe=$(date +%s%N)"; echo "== $S"
  curl -s -A "Mozilla/5.0" "$S/?$P" | grep -oE '/_next/static/chunks/[^"]+\.js' | sort -u | sed "s#^#$S#" | xargs -n1 curl -s -A "Mozilla/5.0" > /tmp/chunks.js
  grep -oE 'https://api\.[a-z]+\.com/api' /tmp/chunks.js | sort | uniq -c      # BF: only api.michaelkhalaf.com/api · WZ: only api.watchizereg.com/api
  grep -oE '"(brandfashion|watchizer)"' /tmp/chunks.js | sort | uniq -c         # BF: brandfashion only
  grep -c 'auth/providers' /tmp/chunks.js                                       # BF: ≥ 1 — TODAY'S build (absent from the 10-15 one)
  grep -c '14-day exchange on watches' /tmp/chunks.js                           # WZ: ≥ 1 — TODAY'S build
done
```

If `curl` gets a "Checking your browser" page, run the same check from a browser (DevTools → Network → JS).
**STOP** unless each site shows ITS API only, Brand Fashion's code, and today's marker string.

**Way back for §6 alone:** `git revert -m 1 <the merge commit>` on `main` and push — both apps rebuild on the 10-15
code (one build each, minutes). Core stays: the old Brand Fashion app on the new core is what this morning's checks
already prove works (its v2 reads are `meta` and `payment-methods`, unchanged). Watchizer's three changes go back
with it — they are copy and layout, nothing that holds data.

---

## 7. Data — Michael's checklist (dashboard and artisan), after §3

**7.1 Storefront 2's mailbox** (needs §0.2's new password; the row is what every Brand Fashion e-mail is sent from):

```bash
cd ~/domains/eleganceeg.com/core
 WZ_BF_MAIL_PW='<the rotated password>' php artisan mail:mailbox brandfashion --host=smtp.hostinger.com --port=465 --encryption=ssl \
   --username=no-reply@bf.michaelkhalaf.com --password-env=WZ_BF_MAIL_PW --from=no-reply@bf.michaelkhalaf.com --from-name="Brand Fashion" \
   --recipients=<the team's order addresses> --cap=800 --reserve=200 --enable
php artisan mail:mailbox brandfashion --show      # every field, the password as "set" — never its value
```

(The leading space keeps the line out of bash history; `--password-env` takes the variable's NAME.) Or the same on
the dashboard: eleganceeg.com → Storefronts → Brand Fashion → Edit → Mailbox (the password field is write-only).
**Check:** the wordmark the mails embed answers — `curl -sI https://api.michaelkhalaf.com/assets/brand/brandfashion-mail-wordmark@2x.png`
→ `HTTP/2 200`, `content-type: image/png`. **Undo:** `php artisan mail:mailbox brandfashion --disable` (mail waits with
a reason; nothing falls back to Watchizer's).

**7.2 The 14 home rails** (the seeder arrives with §3):

```bash
BF_RAILS_DRY_RUN=1 php artisan db:seed --class=BrandFashionHomeRailsSeeder --force   # the plan, writes nothing
php artisan db:seed --class=BrandFashionHomeRailsSeeder --force                      # creates what is missing; a second run creates 0
```

**Check:** `https://api.michaelkhalaf.com/api/v2/brandfashion/home` → 14 `rails` (a browser — `curl` may be challenged).
**Undo:** `php artisan db:seed --class=BrandFashionHomeRailsRemoveSeeder --force` (removes exactly those; keeps `offers`).

**7.3 Cash on delivery for storefront 2** — already offered (§0.3). **Check only:** dashboard → Storefronts → Brand
Fashion → Payments: the `cod` method enabled; the GET of §0.3 lists it.

**7.4 Google credentials, both projects** — Brand Fashion's: §0.1 + §5. Watchizer's: nothing to change; §4 step 2
proved it.

**7.5 The mail password** — §0.2 (rotated) + §7.1 (on the row). The old value must no longer sign in to hPanel's
webmail.

---

## 8. Smoke tests — two halves, in this order

### 8.1 Watchizer is unchanged — on its NEW build (after §6)

Hard-reload, private window, **watchizereg.com**:

1. Home with products; a product page; the trust block in Arabic on `/ar/…` (ضمان الأصالة · دفع آمن · تواصل معنا).
2. **A COD order** end to end, as §4 step 1 (dashboard: Watchizer, stock, e-mail). Cancel it after.
3. **Google sign-in** round trip, as §4 step 2.
4. The cart and checkout say "4-day returns · 14-day exchange on watches" (Arabic: إرجاع خلال ٤ أيام · استبدال الساعات خلال ١٤ يوماً);
   `/contact-us` and `/ar/contact-us` list both branches, the hours under Arkadia only.

### 8.2 Brand Fashion takes a real cash-on-delivery order end to end

Hard-reload, private window, **michaelkhalaf.com**, on a phone AND a desktop (the rehearsal did both widths):

1. Home: the banner, the 14 rails, the deals band. No tracker loads (DevTools: no `connect.facebook.net`, no
   `googletagmanager`, no `analytics.tiktok`).
2. A product page → **Add** → the drawer opens with the line and "4-Day Returns · Secure Checkout" (Arabic:
   «إرجاع خلال ٤ أيام · دفع آمن»).
3. Cart → checkout: one payment row, "Cash on delivery", chosen; the total inside the button.
4. **Place the order** with a real name, phone and address you control → the confirmation page with its number.
5. Dashboard → Orders, filter **Brand Fashion**: the order, `storefront` Brand Fashion, its number from the shared
   sequence (it follows Watchizer's last); the product's stock moved; the team's admin copy arrived; the customer's
   confirmation e-mail arrived **from `no-reply@bf.michaelkhalaf.com`** with Brand Fashion's wordmark (not broken).
6. Sign in (or register) on Brand Fashion → My orders lists this order and **no Watchizer order**.
7. Register → the verification e-mail from Brand Fashion's mailbox, its link back on Brand Fashion.
8. If §5 is done: **Continue with Google** → Google shows Brand Fashion's consent screen → back on
   `michaelkhalaf.com/auth/callback` → signed in. If §5 is NOT done: no Google button, no "or", and the privacy page
   does not mention Google.
9. Cancel the test order in the dashboard as you always do.

**Rehearsed 2026-10-19** on the production build pointed at a scratch copy: steps 1–4 in English at 1440 and in Arabic
at 390 (scratch orders `000030`, `000031`; Watchizer `000032`), the login and privacy pages in both Google states.
What the rehearsal could not cover: real e-mail (the scratch copy logs mail), Google's round trip, the CDN, Hostinger's
build.

---

## 9. Every pre-launch item — and how to verify it on the real domain

| # | Item | State after this deploy | Verify on the real domain |
|---|---|---|---|
| P1 | Google sign-in | Built per storefront (§0.1, §5); hidden on Brand Fashion until its pair is set | §8.2 step 8; §3.6 (a) for Watchizer |
| P2 | The server-side Meta event | Card orders only, to ONE pixel (Watchizer's): Brand Fashion takes cash only, so it sends nothing — the privacy sentence describes a card future (BF-CAPI-PIXEL) | Events Manager shows no Brand Fashion `Purchase`; before cards: a pixel and token per storefront |
| P3 | The new-product e-mails | Run per storefront through its own mailbox (§7.1) | `php artisan reengagement plan` — the team preview for Brand Fashion names Brand Fashion and unsubscribes on its domain |
| P4 | The phone-confirmation line | Unchanged (Michael: it stays) | The team confirms Brand Fashion orders by phone from the one Orders queue |
| P5 | The build environment | §6.1 | §6.3 |
| P6 | Cash on delivery for storefront 2 | Already offered (§0.3) | §7.3 |
| P7 | The sitemap | Brand Fashion's `/sitemap.xml` is compat's: 1,059 URLs on `https://watchizereg.com` (BF-SITEMAP-20261019) | Not blocking an order. `https://michaelkhalaf.com/sitemap.xml` — decide v2's sitemap or a storefront-aware compat one before submitting it to Search Console |
| P8 | The contact address | DONE 2026-10-19: both branches, both languages, both shops | `/contact-us`, `/ar/contact-us` on both sites |
| P9 | `www.michaelkhalaf.com` | Serves the app (200), as `www.watchizereg.com` does. After §3 the CDN stores no v2 answer, so both hosts work (CORS allows both). Two hosts for one site is still a duplicate for search engines; the canonical tags name the apex | Optional: an hPanel redirect `www` → apex (301). Check: `curl -sI https://www.michaelkhalaf.com/` → 301 to `https://michaelkhalaf.com/` |
| P10 | Brand Fashion is crawlable (BF-NOINDEX, decision pending since 2026-10-14) | `robots.txt` allows crawling | Decide before launch; `https://michaelkhalaf.com/robots.txt` |
| P11 | Brand Fashion's own analytics (BF-ANALYTICS-OWN) | None (the correct state until the client's accounts exist) | §8.2 step 1 |
| P12 | The About pages | Both still say "visit our store at …" (one store) | Michael: name both branches? (copy only) |
| P13 | **Brand Fashion's payment badges** (BF-CARD-BADGE) | Its offer pages print **Card** and **Vodafone Cash**; Brand Fashion takes **cash on delivery only** until the Paymob contract. **Decided 2026-10-19: leave them**, and re-check here on launch day | Open a Brand Fashion offer page. If cards are still not accepted, remove the two entries from `Frontend-brandfashion/src/config/trust.config.js` `payments` (one line each, one build). If the Paymob contract is live by then, they are already true — check the checkout offers a card row |
| P14 | Watchizer's social accounts in its structured data | Fixed 2026-10-19: `sameAs` imports the same constants the contact page uses, so the markup and the site are one pair. The header and the footer still carry their own copies of the same two URLs | `https://watchizereg.com/` → the `application/ld+json` block names `facebook.com/profile.php?id=…` and `instagram.com/watchizer_eg`, the same accounts the footer links |

---

## 10. How the rehearsal was kept safe — and what that means on the day

Written because last night nearly put a production-pointing build in front of a browser, and because the same
traps exist on the day in a different form.

1. **`next build` inlines every `NEXT_PUBLIC_*` it can find, and `.env.local` fills every key the process does not
   set.** Brand Fashion's `.env.local` names the PRODUCTION API; Watchizer's carries its REAL Meta and TikTok pixel
   ids. So the rehearsal set EVERY key in the build process (`scratchpad day/build-rehearsal.ps1`), and the three
   pixel keys to ONE SPACE — PowerShell cannot hold an empty variable (assigning `''` deletes it, and then
   `.env.local` wins), and the code trims a space to "no id". `NEXT_PUBLIC_IMAGE_CDN_BASE` and `STOREFRONT_SERVER_KEY`
   are read untrimmed, so they were left to `.env.local`, where both are empty. Proved in the chunks: the API named is
   the scratch core's only, 0 chunks carry a production API, 0 carry Watchizer's pixel ids; proved in the browser:
   `fbq`, `gtag`, `ttq` all undefined, and the only outside host requested was the image host (GETs of pictures).
2. **The API pointed at a SCRATCH copy of the dev database** (`scratch_rehearsal_20261019`: made with a dump that names
   no database, proved by a boot that finds the dev name nowhere, used, DROPPED; the dev checksum identical before and
   after). Brand Fashion's build called `127.0.0.1:8020` (storefront 2 there, as `api.michaelkhalaf.com` is on
   production); Watchizer's `localhost:8020` (no storefront there = storefront 1, as `api.watchizereg.com`).
3. **`next start` reads `LARAVEL_ORIGIN` at RUN time** and falls back to `.env.local` — the serve script sets it too,
   and refuses to serve any build whose chunks name a production API. The one build made with the exact production
   values was built to be INSPECTED, refused by that script, and deleted (`.next` removed) minutes later.
4. **Git Bash rewrites an environment value that starts with `/`** into a Windows path (`/product/x` →
   `C:/Program Files/Git/product/x`): the rehearsal's first Arabic pass "failed" on a page that was never requested.
   `MSYS_NO_PATHCONV=1` for any such value.

**On the day none of that machinery exists — the build runs on Hostinger from the panel.** The equivalent safety is
§6.1 (read every panel value before the push), §6.3 (the bundle check proves what was inlined) and the order of the
phases (core proven with Watchizer BEFORE any storefront builds).

---

## Appendix — the artifact

| | |
|---|---|
| Core archive (rehearsal, 2026-10-19) | `core-20261019.tar.gz`, **7,493,758 bytes**, 7,831 files; `vendor/` 46 MB after `--no-dev` (144 MB before); `public/build/manifest.json` = the live `699aab9e…`; the archive's `.htaccess` the 25-line stock one |
| Brand Fashion build (production values, inspect-only) | `.next/static` 2.1 MB (111 files), `.next/server` 5.1 MB, webpack cache 1.93 GB (wherever the build runs — Hostinger's disk); first-load JS: home 151 KB, listing 181, product 143, cart 155, checkout 152, shared 103; every route rendered on request (ƒ) — the build itself calls no API |
| Watchizer build (scratch values) | home 173 KB, cart 168, checkout 154, product 191, contact 119, shared 103 |
| The tree to ship (2026-10-19 close) code digest | `c36333a71b79f014b5ef918cf2cce8b858177bc2e24e2aef60a113bbfbff0c56` (502 files) |
| Production tree (`333eef0`) code digest | `f225ac87506f3d2634efd7c0db03bd72db8021bd2eec578dce96ad275640ca2f` (487 files) |
| Watchizer's Google answer (nonce `RehearsalNonce20261019aaaa`), body sha256 | `4f29f613a50995be11aab71e685a6196c69b980bfb411c8bd05a335b67105bcf` |
