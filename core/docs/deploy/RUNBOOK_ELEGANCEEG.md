# Deploying the dashboard to eleganceeg.com — standalone

**Follow this top to bottom. Every command is meant to be pasted.**
Written 2026-09-19 against branch `wave-4d`. Every number in it was measured on the workstation,
not estimated; where a figure differs from what you expected, it says so.

**The model:** one Hostinger account, one new database, one dashboard at `https://eleganceeg.com`.
The legacy Watchizer installation (site + database) is not touched, not read, and not written.
There is **no compat cutover in this deployment** — §7 names every connection, host and URL the
code can reach, and how each one is closed.

---

## READ FIRST — four things that differ from the plan

| | You expected | Measured here | Where |
|---|---|---|---|
| 1 | Media tree 60,000 files / 484 MB | **80,087 files / 727 MB** must travel | §4 |
| 2 | — | Dashboard images default to **loading from the legacy host** | §2.4, §7 |
| 3 | — | `/api/*` **proxies to the legacy host, unauthenticated** | §7.2 |
| 4 | — | `CORE_WRITE_SWITCH_COMPLETED` must be **true** on this server | §2.4 |

**(1) is the one that costs you a night if you miss it.** 484 MB / 58,792 files is
`backend/public/Uploads_Images` — the *legacy* tree. The application serves
`core/public/Uploads_Images`, which is **116,027 files / 875.8 MB**, because it also holds the
rendition ladder (`-160`, `-320`, `-480`, `-640`, `-960`, in webp and avif) that 7,062 image rows
name in their `renditions` column. Upload the legacy tree and roughly seven of every eight product
images on the site will 404.

Of those 116,027 files, **80,087 (727.0 MB) are named by the database and must go up**; the other
**35,940 (148.9 MB) are the importer's download cache** and can stay here. §4 has the split.

---

## 0. To hand before you start

- [ ] SSH access to the Hostinger account (Business plan or above — hPanel → Advanced → SSH Access)
- [ ] An FTP/SFTP client that can resume (FileZilla). The media upload is the long pole of the night
- [ ] hPanel open on: Databases, PHP Configuration, Cron Jobs, and the domain's Advanced settings
- [ ] This repository on the workstation, on `wave-4d`, with a clean `npm run build`
- [ ] A password-manager entry ready for the new database user — you create it in §2.1
- [ ] Roughly 3 hours, of which ~2 is the media upload running unattended

**Do not leave the media upload until last.** Start it in §4 and let it run while you do §5 and §6.

---

## 1. THE DATABASE

Your local database **is** the business's data — 4 users, 9 orders, and the full built catalogue.
So this is one dump of one database, with nothing to stitch and nothing to reconcile.

### 1.1 Before you dump — four minutes of hygiene

On the workstation:

```bash
cd "D:/coding/watchizer website/new watchizer/core"
MYSQL="C:/xampp/mysql/bin/mysql.exe -u root u591083448_watchizer"
```

**(a) Mail that has not been sent yet.** A `pending` row in the mail channel is a message the
server will send the moment the scheduler starts — to a real customer, about a test order.

```bash
$MYSQL -e "SELECT channel, status, COUNT(*) FROM integration_outbox GROUP BY channel, status;"
```

Measured now: `morabaa / pending / 4543`, and **no mail rows**. If any `mail` row has appeared
since, delete it:

```bash
$MYSQL -e "DELETE FROM integration_outbox WHERE channel='mail' AND status IN ('pending','sending');"
```

The 4,543 `morabaa` rows are harmless — that channel is drained hourly by a no-op consumer because
the connector does not exist yet. They can travel. To start clean instead:

```bash
$MYSQL -e "DELETE FROM integration_outbox WHERE channel='morabaa';"   # optional
```

**(b) Your local password.** `users` holds 4 accounts and id 1 is yours. Whatever password hash is
in that row right now is what you will log in with on the server. If you have been typing a
throwaway password locally, **change it now on the workstation** so the hash that travels is the
one you intend to use. There is no password-reset screen on the dashboard.

**(c) Your dashboard language.** `core_user_preferences` holds one row — your locale. It travels
and becomes your setting on the server. Harmless; noted so it is not a surprise.

**(d) Nothing else needs removing.** Checked: `jobs` 0 rows, `failed_jobs` 0 rows, no `sessions` or
`cache` tables (both drivers are `file`), no scratch rows elsewhere. `core_activity_log` holds
7,602 rows, most of them pre-handover build activity — **they travel on purpose**, and the activity
screen labels anything before `2026-09-16 00:00:00` as build-time rather than team activity. A log
you edit to say something it did not say is worth nothing.

### 1.2 The dump

```bash
cd "D:/coding/watchizer website/new watchizer/core"
C:/xampp/mysql/bin/mysqldump.exe -u root -p \
  --single-transaction --routines --triggers \
  --default-character-set=utf8mb4 \
  u591083448_watchizer | gzip -6 > eleganceeg-$(date +%Y%m%d).sql.gz
```

**Measured: 124 tables, 23.8 MB raw, 2.7 MB gzipped, 2 seconds.**

`--single-transaction` keeps InnoDB consistent without locking. `--routines --triggers` because a
restore without them is subtly not the same database. These are the flags `core:backup` uses,
deliberately — one dump format, one restore procedure.

**What is in it, and why all of it must be:**

- **The clean tables** (`catalog_*`, `storefront_*`, `core_*`, `promotion_*`) — the built
  catalogue. 7,714 live products, 7,087 of them from the importer. **This exists nowhere else.**
- **The legacy tables the application reads at runtime.** The new database has to stand alone and
  these are not optional:

| table | read by |
|---|---|
| `users` | authentication — `App\Models\User` reads it on the **default** connection |
| `orders`, `order_items` | the Orders screen reads *and writes* these; there is no clean orders table |
| `addresses` | customer addresses on the order detail screen |
| `shipping_cities`, `shipping_city_translations` | the Shipping screen's only source |
| `payment_statuses` | order payment state |
| `products`, `product_images`, `product_translations` + the rest of the 65 | `LegacySource`, and `media:verify`'s reference scan |

Dumping the whole database is simpler than selecting tables **and safer**: there is no list to get
wrong, and every foreign key lands with its target. At 2.7 MB there is nothing to save.

### 1.3 Import on the server

Create the database and user first (§2.1), then:

```bash
cd ~
gunzip -c eleganceeg-20260919.sql.gz | mysql -u USERNAME -p DATABASE_NAME
```

**Expect 2–4 minutes.** Use SSH, not phpMyAdmin — a browser upload that times out halfway leaves a
half-imported database that looks fine until it does not.

### 1.4 Prove the import

```bash
mysql -u USERNAME -p DATABASE_NAME -e "
SELECT 'tables'   k, COUNT(*) v FROM information_schema.tables WHERE table_schema=DATABASE()
UNION ALL SELECT 'products live', COUNT(*) FROM catalog_products WHERE deleted_at IS NULL
UNION ALL SELECT 'images',        COUNT(*) FROM catalog_product_images
UNION ALL SELECT 'translations',  COUNT(*) FROM catalog_product_translations
UNION ALL SELECT 'search rows',   COUNT(*) FROM catalog_product_search
UNION ALL SELECT 'users',         COUNT(*) FROM users
UNION ALL SELECT 'orders',        COUNT(*) FROM orders
UNION ALL SELECT 'migrations',    COUNT(*) FROM core_migrations;"
```

**Must match exactly:**

| | |
|---|---:|
| tables | 124 |
| products live | 7,714 |
| images | 10,018 |
| translations | 15,002 |
| search rows | 15,502 |
| users | 4 |
| orders | 9 |
| migrations | 25 |

A short count anywhere means the import did not finish. Drop the database and import again — do
not patch it.

### 1.5 Then delete the dump from the server

```bash
rm ~/eleganceeg-20260919.sql.gz
```

It holds every customer's name, e-mail, phone, address and password hash. It must not sit in your
home directory, and it must **never** be placed anywhere under the web root.

---

## 2. THE SERVER, BEFORE THE FILES ARRIVE

### 2.1 Database and user

hPanel → **Databases → MySQL Databases** → create a database and a user, grant all privileges on
that database only. Hostinger prefixes both with your account id.

Keep the name, user and password in your password manager. You type them into `.env` in §2.4 and
nowhere else.

### 2.2 PHP

hPanel → **Advanced → PHP Configuration**.

**Version: 8.3 or 8.4.** `composer.json` requires `php: ^8.3`; 8.2 will not boot.

**Extensions — tick all of these:**

| extension | what breaks without it |
|---|---|
| `pdo_mysql` | everything |
| `mbstring` | Arabic strings truncate mid-character |
| `openssl` | HTTPS, session encryption, password hashing |
| `gd` | **every image upload and every rendition** |
| `fileinfo` | upload MIME validation refuses valid files |
| `json`, `ctype`, `tokenizer`, `xml`, `curl` | Laravel itself |
| `zip` | composer, if you ever run it there |

`intl` is **not** required — nothing here uses `Collator`, `Normalizer` or `IntlChar` (checked).
The Arabic search normalisation is plain PHP.

**Settings:** `memory_limit` ≥ 256M, `upload_max_filesize` ≥ 10M, `post_max_size` ≥ 12M (the
dashboard accepts 8 MB images — `MEDIA_MAX_KB` defaults to 8192), `max_execution_time` ≥ 120.

Once the files are up, confirm what GD can actually do:

```bash
cd ~/domains/eleganceeg.com/core && php artisan media:capabilities
```

`write_avif: no` is survivable — the pipeline falls back to WebP. `write_webp: no` means stop and
fix PHP before anyone uploads an image.

### 2.3 Document root

The document root must be **`core/public`** — never `core`, never the account root.

```
~/domains/eleganceeg.com/
  core/                 <- the application (NOT web-accessible)
    app/ bootstrap/ config/ database/ lang/ public/ resources/ routes/ storage/ vendor/
    .env
  public_html  ->  core/public        (symlink, or set the root in hPanel)
```

hPanel → **Websites → eleganceeg.com → Advanced → Change website's root directory** → set it to
`domains/eleganceeg.com/core/public`. If the plan does not offer that control, replace
`public_html` with a symlink:

```bash
cd ~/domains/eleganceeg.com
mv public_html public_html.bak
ln -s core/public public_html
```

**Prove it before going further.** With the root wrong, `https://eleganceeg.com/.env` serves your
database password to anyone who asks:

```bash
curl -s -o /dev/null -w '.env          -> %{http_code}\n' https://eleganceeg.com/.env
curl -s -o /dev/null -w 'laravel.log   -> %{http_code}\n' https://eleganceeg.com/storage/logs/laravel.log
curl -s -o /dev/null -w 'composer.json -> %{http_code}\n' https://eleganceeg.com/composer.json
```

All three must be 403 or 404. **Any 200 and you stop and fix the root.**

### 2.4 `.env`

Create `~/domains/eleganceeg.com/core/.env` by hand. **Never upload the workstation's `.env`** — it
carries local paths, local credentials and a local `APP_KEY`.

Start from `.env.example` (it is in the repository) and set the following. Keys marked **secret**
are named only: put the values in from your password manager and never paste them into a chat, a
ticket or a commit.

**Application**

| key | value |
|---|---|
| `APP_NAME` | `Elegance` |
| `APP_ENV` | `production` |
| `APP_KEY` | **secret** — generate on the server with `php artisan key:generate` |
| `APP_DEBUG` | `false` — a stack trace on a 500 shows your database name and file paths |
| `APP_URL` | `https://eleganceeg.com` |
| `APP_TIMEZONE` | `Africa/Cairo` |
| `APP_LOCALE` | `ar` |
| `APP_FALLBACK_LOCALE` | `en` |

**Database — the main connection**

| key | value |
|---|---|
| `DB_CONNECTION` | `mariadb` |
| `DB_HOST` | `127.0.0.1` |
| `DB_PORT` | `3306` |
| `DB_DATABASE` | from §2.1 |
| `DB_USERNAME` | from §2.1 |
| `DB_PASSWORD` | **secret** |

**Database — the legacy connection: SET NOTHING.**

> `config/database.php` defines a second connection called `legacy`, and **every one of its
> settings falls back to the main connection's**: `LEGACY_DB_HOST` → `DB_HOST`,
> `LEGACY_DB_DATABASE` → `DB_DATABASE`, and so on down. Leave all five `LEGACY_DB_*` keys out of
> the file entirely and the legacy connection points at your new standalone database — which is
> exactly right, because the legacy tables are *in* it.
>
> **Setting any `LEGACY_DB_*` key to the old host is the single most dangerous thing you can do in
> this file.** It would hand a live application a connection into the legacy database. §7.1 proves
> they are unset.

**The three keys that stop images loading from the legacy host**

| key | value |
|---|---|
| `STOREFRONT_ASSET_BASE` | `https://eleganceeg.com` |
| `MEDIA_URL_BASE` | `https://eleganceeg.com/Uploads_Images` |
| `MEDIA_ROOT` | `public/Uploads_Images` |

> **Why these are not optional — this is a failure that hides for months.**
>
> `App\Storefront\ImageUrl::src()` builds every `<img>` in the dashboard as
> `config('storefront.asset_base') . '/Uploads_Images/' . $path`, and that config key
> **defaults to `https://dash.watchizereg.com` — the legacy host.**
>
> Leave `STOREFRONT_ASSET_BASE` unset and here is exactly what happens, in order:
>
> 1. You deploy. Every screen looks right. Every product image loads.
> 2. The smoke test passes, because the images *are* there — they are being fetched from the
>    legacy host, which is still switched on.
> 3. The team works for weeks or months. Nothing is wrong. Nothing is logged. No error appears
>    anywhere, because nothing is failing.
> 4. **You switch off the legacy site** — the whole point of this migration — and every image in
>    the dashboard goes blank at once, on a catalogue of 7,714 products.
> 5. By then nobody remembers this key exists, the connection between "we turned off the old site"
>    and "the images died" is not obvious, and you are debugging it under pressure.
>
> That is the shape of it: **a dashboard that works perfectly while silently serving all 80,087 of
> its images from the one host this whole migration exists to switch off.** Step 4 of the smoke
> test (§6) is there specifically to catch it — open DevTools and read the image URL, because that
> is the only place the difference is visible.
>
> `MEDIA_ROOT` is a separate key and a separate job: it is where uploads are *written*. Its default
> is `../backend/public/Uploads_Images`, which is the workstation's layout and does not exist on
> the server — so with it unset, image uploads fail outright. Relative paths resolve from the
> application root, so `public/Uploads_Images` means `core/public/Uploads_Images`: the same
> directory the URL above serves.

**The write switch — set this to true**

| key | value |
|---|---|
| `CORE_WRITE_SWITCH_COMPLETED` | `true` |
| `CORE_PRE_SWITCH_GATES` | `warn` |

> **Why true here, when it has been false all along.** The flag means *"legacy has stopped writing
> and core is the only writer"* — and on this server that is now a fact: the legacy site is
> stopped, and no transform will ever run here to rebuild over typed work. Three things change and
> all three are what you want:
>
> 1. `PreSwitch` stops gating and stops showing "the next rebuild will delete this" caveats. There
>    is no next rebuild.
> 2. `ConversionGuard` allows converting a live product to sell through variants. That was refused
>    because legacy wrote `products.quantity` directly and a conversion mid-flight corrupts stock.
>    Legacy writes nothing now.
> 3. `syncsSecondaryTrees()` becomes false for ever — the category-tree mirror stops. Correct,
>    because nothing is mirroring.
>
> **This depends entirely on §8 staying true.** If the legacy site is ever switched back on, this
> flag is wrong, and stock is what you lose.

**Compat — off**

| key | value |
|---|---|
| `COMPAT_API_KEY` | leave **empty** |
| `COMPAT_LEGACY_BASE` | `http://127.0.0.1:1` |
| `COMPAT_ASSET_BASE` | `https://eleganceeg.com` |
| `JWT_SECRET` | leave **unset** |

> An empty `COMPAT_API_KEY` makes `CheckApiCode` answer 401 to every compat route — it requires a
> non-empty configured key *and* a match. An unset `JWT_SECRET` makes every authenticated compat
> path 401. `COMPAT_LEGACY_BASE` pointed at a port nothing listens on means the proxy fails closed
> with a 502 rather than reaching a real host. §7.2 blocks the path at the web server as well; this
> is the backstop behind that.

**Drivers — shared hosting**

| key | value |
|---|---|
| `SESSION_DRIVER` | `file` |
| `SESSION_SECURE_COOKIE` | `true` |
| `SESSION_LIFETIME` | `480` (an eight-hour shift) |
| `CACHE_STORE` | `file` |
| `QUEUE_CONNECTION` | `sync` |
| `FILESYSTEM_DISK` | `local` |
| `LOG_CHANNEL` | `stack` |
| `LOG_LEVEL` | `warning` |

> `QUEUE_CONNECTION=sync` is deliberate and is how this application is built: order e-mail sends
> **inline**, so the confirmation arrives while the customer is still on the thank-you page. There
> is no queue worker to keep alive on shared hosting, and `mail:drain` on the schedule is what
> retries anything the inline send could not deliver.

**Mail** — all **secret** except the addresses:
`MAIL_MAILER` (`smtp`), `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`,
`MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`, and `ORDER_ADMIN_EMAILS` — a
comma-separated list of the people who get a copy of every order.

For Hostinger's own mailboxes: host `smtp.hostinger.com`, port 465, encryption `ssl`.

**Payments** — `PAYMOB_SECRET_KEY`, `PAYMOB_PUBLIC_KEY`, `PAYMOB_HMAC_SECRET` are **secret**, and
**leave all three unset for this deployment**. Nothing on this host takes payment: the storefront
is not here, and the client's Paymob account still calls back to the legacy host. An unset
credential means the payment contract holds nothing and the callback route does nothing, which is
the safe direction.

Finally:

```bash
chmod 600 ~/domains/eleganceeg.com/core/.env
```

### 2.5 Permissions and the storage symlink

```bash
cd ~/domains/eleganceeg.com/core
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views \
         storage/logs storage/app/backups
chmod -R 775 storage bootstrap/cache
chmod 700 storage/app/backups          # the nightly dumps: PII, owner-only
php artisan storage:link
```

`storage:link` creates `public/storage → storage/app/public`. The dashboard does not depend on it
today — product images are served straight out of `public/Uploads_Images` — but it costs nothing
and its absence is a confusing 404 later.

### 2.6 Hostinger specifics

- **SSH** is on Business plans and above. Without it, §1.3 becomes a phpMyAdmin import and §5
  becomes impossible — the artisan commands there are not optional. Confirm SSH before the night.
- **`mysqldump` may not be on the cron user's `PATH`.** The failure has the worst shape available:
  the schedule keeps running, `core:backup` fails inside it, and the only signal is that no dump
  ever appears. Find out now, while you are already in the shell:
  ```bash
  which mysqldump
  ```
  Silence here is not a problem, it is just an answer — **§9.3 walks through four ways to locate the
  binary** and what to do with the path once you have it. Note the result and carry on.
- **Cron granularity.** Hostinger's minimum is one minute on most plans; §9 needs that.
- **`composer install` on the server** needs `zip` and enough memory. §3 avoids it by uploading
  `vendor/`, which is the safer route on shared hosting.

---

## 3. THE BUILD, AND WHAT TO UPLOAD

### 3.1 Build here

```bash
cd "D:/coding/watchizer website/new watchizer/core"
npm run build
```

Writes `public/build/` — the manifest and the hashed assets. **npm never runs on the server**;
there is no `node_modules` there and no reason for one.

Then install production dependencies to upload:

```bash
composer install --no-dev --optimize-autoloader
```

> This strips Pest, PHPStan, Pint and Faker out of `vendor/`. **Afterwards run `composer install`
> again on the workstation**, or your test suite will not run.

### 3.2 Upload these

```
core/app/            core/bootstrap/       core/config/
core/database/       core/lang/            core/public/
core/resources/      core/routes/          core/vendor/
core/artisan         core/composer.json    core/composer.lock
```

`core/storage/` — upload the **directory structure only**, empty. Not its contents.

### 3.3 Exclude these — every one for a reason

| path | why |
|---|---|
| `.env` | local credentials, local `APP_KEY`, local paths |
| `node_modules/` | 400+ MB, never read at runtime |
| `tests/` | not needed, and it describes your schema |
| `docs/` | internal notes, including this file |
| `storage/logs/` | **41 MB** of local logs |
| `storage/app/backups/` | **5.2 MB — database dumps. Customer PII and password hashes.** |
| `storage/import/` | **7.3 MB** — supplier CSVs and fetched cover images |
| `storage/transform/` | local run artefacts |
| `storage/compat-diff/`, `storage/compat-edit-probe/` | local probe output |
| `public/dumps/` | empty, but it is a directory named *dumps* inside the web root — anything that ever lands there is one guessed URL from being downloaded |
| `public/hot`, `public/fonts-manifest.dev.json` | Vite dev-server markers — see below |
| `.git/`, `phpunit.xml`, `phpstan.neon` | development only |
| `public/Uploads_Images/` | **§4 handles this separately — do not let it ride along with the code** |

`public/hot` deserves a second look: if it exists on the server, every page tries to load its
JavaScript from your workstation. The site appears completely broken and the cause is invisible.

```bash
# on the server, after uploading:
rm -f ~/domains/eleganceeg.com/core/public/hot \
      ~/domains/eleganceeg.com/core/public/fonts-manifest.dev.json
ls ~/domains/eleganceeg.com/core/public/build/manifest.json   # must exist
```

---

## 4. THE MEDIA TREE

### 4.1 What goes up

**Source:** `core/public/Uploads_Images/` on the workstation — **not** `backend/public/Uploads_Images`.
**Destination:** `~/domains/eleganceeg.com/core/public/Uploads_Images/`

Measured, folder by folder:

| folder | files | size |
|---|---:|---:|
| `Product` | 113,588 | 692.4 MB |
| `Product_image` | 2,297 | 179.4 MB |
| `Brand` | 69 | 0.9 MB |
| `Sub_type` | 31 | 1.2 MB |
| `Banner_Bottom` | 10 | 0.3 MB |
| `Banner_home` | 8 | 0.9 MB |
| `Banner_Side` | 7 | 0.2 MB |
| `Blog_image` | 5 | 0.1 MB |
| `Category` / `Offer` / `User` | 3 each | ~0.4 MB |
| `Grade` | 2 | — |
| `Blog` | 1 | — |
| `Category_type` | 0 | — |
| **whole tree** | **116,027** | **875.8 MB** |
| **named by the database** | **80,087** | **727.0 MB** |
| *not named (importer cache)* | *35,940* | *148.9 MB* |

**Upload the whole tree (876 MB) if bandwidth and time allow** — it is simpler, it cannot be got
wrong, and the surplus is a cache that costs only disk. If you want the 149 MB back, run
`media:prune` on the server *after* §6 passes. Do not try to filter during the upload.

### 4.2 Upload it

Start this **now** and leave it running while you do §5 and §6.

FileZilla → Transfer settings → **4 concurrent transfers** (more and Hostinger throttles). Enable
resume — with 116,027 files the connection will drop at least once.

Preserve the folder names exactly, **including their capitalisation**: `Banner_Side` and
`Banner_home` really are capitalised differently, the database stores filenames that assume it, and
**the server's filesystem is case-sensitive where Windows was not.** This is the single most likely
way for images to go missing.

### 4.3 Prove every path resolves

The command already exists:

```bash
cd ~/domains/eleganceeg.com/core
php artisan media:verify --show=20
```

It reads every `catalog_product_images.path` plus the 13 legacy image columns and checks each one
against the tree.

**The workstation's baseline, which the server must match:**

```
db      10,058 referenced, 10,003 present, 55 absent
```

### **55 absent is the CORRECT answer. Do not stop.**

You will see `55 absent` on the server too, and it is not a sign that anything went wrong with your
upload. Read this before you run the command so it does not stop you at 2am.

**What the 55 are.** They are 55 rows in the database — mostly in `catalog_product_images`, folder
`Product_image` — that name a filename which **has never existed in this copy of the tree**. Not
"was lost in transit": never here in the first place. This workstation holds a *partial* copy of the
production image library (it has always been partial; it is recorded in `AGENTS.md` from wave 1),
and these 55 rows point at files that live only in the full production tree.

**Why they are not a fault to fix tonight.** Nothing you can do during this deployment would
produce those files — they are not on the machine you are uploading from. A product whose image row
is one of the 55 shows a broken thumbnail, exactly as it does on the workstation today. That is a
pre-existing gap in the source data, not damage you caused, and chasing it mid-deploy is time spent
on the wrong problem. Note it and move on; fill them from the production tree later if the client
wants those 55 images back.

**Why the number is useful anyway.** Because it is *stable*, it is the measurement that proves the
copy landed whole:

| what you see | what it means | what to do |
|---|---|---|
| **exactly 55 absent** | the copy is faithful — the only missing files are the ones that were already missing | **carry on** |
| **more than 55** | that many files did not land. The command prints the first 20 paths | re-upload; check folder capitalisation (§4.2) first — that is the usual cause |
| **fewer than 55** | you uploaded a *more complete* tree than the workstation has. Unexpected, but not a problem | carry on |
| **0 referenced** | the database did not import | go back to §1.4 |

This is why `media:verify` reports the absent files as a **baseline** rather than failing on them:
the same number before and after a copy is the real proof, and a verifier that cried "missing
files!" on every run of a partial tree would be ignored by the one run where it mattered.

The `disk … unreferenced` figure is the rendition ladder plus the importer's download cache. It is
expected to be very large (~106,000) and means nothing is wrong.

If you ever move the tree again, `--against=<old tree>` compares file-by-file with sizes:

```bash
php artisan media:verify --against=/path/to/the/old/tree
```

**A second check worth ten seconds** — fetch a real product image over HTTPS:

```bash
mysql -u USER -p DB -e "SELECT path FROM catalog_product_images WHERE is_cover=1 LIMIT 1;"
curl -s -o /dev/null -w '%{http_code} %{size_download}\n' \
  "https://eleganceeg.com/Uploads_Images/PASTE_THE_PATH_HERE"
```

200 and a non-zero size. 403 means directory permissions; 404 means the path case is wrong.

---

## 5. FIRST RUN

In this order. Each step says what it does and what "right" looks like.

### 5.1 Migrations — are any needed?

**No — and running them anyway is the point.**

The data arrives already built, and `core_migrations` travels in the dump with all 25 rows. So:

```bash
cd ~/domains/eleganceeg.com/core
php artisan migrate:status
```

**Every line must say `Ran`, and none may say `Pending`.** That is not a formality: it is the proof
that the schema in the dump matches the code you uploaded. If anything is `Pending`, the dump and
the branch disagree — stop and find out why before running it.

Only then:

```bash
php artisan migrate --force      # expect: "Nothing to migrate."
```

### 5.2 The seeder

```bash
php artisan db:seed --class=StorefrontSeeder --force
```

Inserts the two storefront rows with fixed ids — Watchizer is always 1, Brand Fashion always 2. It
is **insert-only and idempotent**: it guarantees the rows exist with the right id and code, then
leaves their contents alone, so it cannot revert the storefront-settings screen. Both rows are
already in your dump, so expect it to do nothing. Run it anyway — it is how you find out if they
are missing.

`DatabaseSeeder` is deliberately empty. Never run `db:seed` without `--class`.

### 5.3 Your admin account

The accounts already exist — they are rows in `users` and came across in the dump. What does not
exist is dashboard *authorisation*.

```bash
php artisan manage:role list
php artisan manage:role grant maikelkhalaf100@gmail.com admin
```

> `users.type = 'SuperAdmin'` grants **nothing** here. The legacy admin enum is not this
> application's authorisation: `EnsureDashboardAccess` requires a row in `core_user_roles`, and
> without one a perfectly valid account gets a 403 at `/manage`. Your dump carries exactly **one**
> role row, so run `manage:role list` before assuming you are covered.

### 5.4 The team's accounts

Two roles: `admin` and `data_entry`.

```bash
php artisan manage:role grant someone@example.com data_entry
php artisan manage:role grant someone@example.com admin --storefront=1   # scoped to one shop
```

**The account must already exist in `users`.** The dashboard has no "create user" screen — a
deliberate decision, because `users` is a legacy table this application does not write. For a team
member with no account, they register on the storefront, or you insert the row by hand.

There is a third role, `media_pruner`, which is **restricted**: an administrator does not receive
it through `Gate::before` and it must be granted by name. It permits permanently deleting image
files. Grant it to nobody for now — including yourself. Grant it for the ten minutes you need it.

### 5.5 Cache the configuration — last

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

**Order matters, and this must come after `.env` is final.** `config:cache` freezes the environment
into a PHP file, and `env()` returns null everywhere afterwards. Change `.env` later and nothing
happens until you re-run this.

After any `.env` edit, from then on:

```bash
php artisan config:clear && php artisan config:cache
```

---

## 6. SMOKE TEST

Work through these in order. A failure at any step is easier to diagnose than the same failure
found three steps later.

**1. The front door.** `https://eleganceeg.com/manage/login` — the login screen renders, in Arabic,
styled. Unstyled means `public/build/` did not upload, or `public/hot` is still there.

**2. Log in.** Your e-mail and the password from §1.1(b). You land on the dashboard home.
*A 403 reading "This account has no dashboard access" means §5.3 did not take — run
`manage:role list`.*

**3. The catalogue is there.** `/manage/storefronts/1/products` — the header count reads **7,714**.
Rows show a name, a code, a price and a thumbnail.

**4. Images load.** Those thumbnails are real photographs, not broken icons.
*Open DevTools → Network and check one image URL begins `https://eleganceeg.com/`. If it begins
`https://dash.watchizereg.com/`, `STOREFRONT_ASSET_BASE` is unset — stop, fix §2.4, re-run §5.5.
**This is the failure that hides, because the images will load either way.***

**5. Arabic search.** In the products search box type **ساعه** — with ه, the way people type it,
not the correct ة. Results come back. Then **رولكس**. Then an English brand, **Rolex**.
*The normalisation lives in `catalog_product_search.body`, which travelled in the dump. Empty
results for all three mean that table did not import — re-check §1.4.*

**6. Edit a product.** Open any product, change the Arabic description, save. The success message
appears and the change survives a reload.

**7. Add a product.** Create one with a name, a code and a price. It saves.
*If you are refused with a note about the next rebuild deleting it, `CORE_WRITE_SWITCH_COMPLETED`
is not `true` — §2.4, then §5.5.*
Then upload an image to it: the image appears, and `Uploads_Images/Product/` gains files on the
server.

**8. Place a test order.** There is no storefront on this host, so work from `/manage/orders`: open
an order and advance its status.

**9. The e-mail arrives.** Advancing a status sends mail **inline**. Check the inbox of an address
in `ORDER_ADMIN_EMAILS`.
*Nothing after two minutes:*
```bash
php artisan mail:drain --reclaim
tail -50 storage/logs/laravel.log
mysql -u USER -p DB -e "SELECT channel,status,attempts,last_error FROM integration_outbox WHERE channel='mail' ORDER BY id DESC LIMIT 5;"
```
*The row tells you whether it was never queued (configuration) or refused by the relay
(credentials).*

**10. The activity log recorded it.** `/manage/activity` — your product edit, the new product, the
order status change and the image upload are all there, with **your name**, the time and a
before/after. Filter by type to prove the filter works.

**11. Both languages render.** Profile → switch the dashboard language to English. Every screen
reads in English — the products list, the category tree, the activity log, the product form's
category picker. Switch back to Arabic. Neither language shows the other's strings.

**12. A last look at the front door.**
```bash
curl -s -o /dev/null -w '.env    -> %{http_code}\n' https://eleganceeg.com/.env
curl -s -o /dev/null -w '/manage -> %{http_code}\n' https://eleganceeg.com/manage
```
`.env` → 403/404. `/manage` → 302 to the login screen.

---

## 7. WHAT COULD GO WRONG

### 7.1 Every connection, host and URL the code can reach

Produced by reading the configuration and grepping the tree, not from memory.

**Database connections — there are exactly two, and they are the same database.**

| connection | where it points | used by |
|---|---|---|
| `mariadb` (default) | `DB_*` | everything |
| `legacy` | `LEGACY_DB_*`, **each falling back to its `DB_*` twin** | `App\Domain\Customers`, `App\Compat\*`, `LegacySource` |

Leave the `LEGACY_DB_*` keys unset (§2.4) and both point at your new database. **Prove it:**

```bash
cd ~/domains/eleganceeg.com/core
php artisan tinker --execute="
foreach (['mariadb','legacy'] as \$c) {
    \$x = config('database.connections.'.\$c);
    printf('%-8s %s @ %s:%s as %s%s', \$c, \$x['database'], \$x['host'], \$x['port'], \$x['username'], PHP_EOL);
}
printf('%-8s %s%s', 'assets',  config('storefront.asset_base'), PHP_EOL);
printf('%-8s %s%s', 'media',   config('media.url_base'), PHP_EOL);
printf('%-8s %s%s', 'root',    config('media.root'), PHP_EOL);
printf('%-8s %s%s', 'proxy',   config('compat.legacy_base'), PHP_EOL);
printf('%-8s %s%s', 'compat',  config('compat.asset_base'), PHP_EOL);
printf('%-8s %s%s', 'api key', config('compat.api_key') === '' ? 'EMPTY (good)' : 'SET', PHP_EOL);
printf('%-8s %s%s', 'jwt',     config('compat.jwt_secret') === null ? 'UNSET (good)' : 'SET', PHP_EOL);
"
```

**Every line must name `eleganceeg.com` or your own database. Not one may say `watchizereg.com` or
`dash.watchizereg.com`.** Expected:

```
mariadb  <your db> @ 127.0.0.1:3306 as <your user>
legacy   <your db> @ 127.0.0.1:3306 as <your user>      <- the SAME database. Correct.
assets   https://eleganceeg.com
media    https://eleganceeg.com/Uploads_Images
root     public/Uploads_Images
proxy    http://127.0.0.1:1
compat   https://eleganceeg.com
api key  EMPTY (good)
jwt      UNSET (good)
```

**Outbound HTTP — three places the code can call out.**

| what | where it goes | state in this deployment |
|---|---|---|
| **`ProxyController`** — `/api/{anything}` | `compat.legacy_base` | **the one real risk. §7.2.** |
| `PaymobProvider` | Paymob's API | dormant — credentials unset (§2.4) |
| the importer's cover-image fetch | the supplier's site | only when you run `import:catalogue` by hand |

**Hard-coded hosts no environment variable can change** — listed for completeness, so nobody is
surprised later. All three sit inside the compat layer, which is closed:

- `config/compat.php` → `sitemap_domain` = `https://watchizereg.com`
- `config/compat.php` → `sitemap_image_host` = `https://dash.watchizereg.com`
- `config/cors.php` allows the `watchizereg.com` origins — **inbound** CORS. It permits a browser on
  that origin to call this host; it does not make this host call anything.

### 7.2 The one path that can reach the legacy installation — and how to close it

`routes/api.php` ends with a catch-all:

```php
Route::any('{path}', ProxyController::class)->where('path', '(?!v2/).*');
```

`ProxyController` forwards method, query, headers **and body** to
`compat.legacy_base . '/api/' . $path` for any path matching `config('compat.proxy_paths')` — which
includes **`login`, `register`, `auth/*`, `updateProfile`, `updatePassword`, `add_wishlist`,
`delete_wishlist/*`, `add_product_rating`**. Several are POSTs that *write*. And this route sits
**outside** the `api.code` middleware group, so it is unauthenticated.

`compat.proxy_paths` is a config array with no environment override, so it cannot be emptied from
`.env`. Three things close it, and you should do all three.

**(a) Point it nowhere** — done in §2.4 (`COMPAT_LEGACY_BASE=http://127.0.0.1:1`). A request that
reaches the proxy now fails with a 502 instead of arriving somewhere real.

**(b) Block `/api` at the web server.** The dashboard lives entirely under `/manage` and makes **no
requests to `/api` at all** (verified: no `/api` string anywhere in `resources/js`). Blocking it
costs the dashboard nothing.

Add this to `core/public/.htaccess`, immediately after `RewriteEngine On`:

```apache
    # ── This deployment serves the dashboard only ───────────────────────────────
    # /api carries the legacy-compat layer, whose fall-through route is a reverse
    # proxy to the legacy Watchizer host. There is no storefront on this host and
    # nothing under /manage calls /api, so the whole prefix is closed here rather
    # than trusted to configuration. Remove this block on the day the v2 storefront
    # is pointed at this database — and not before.
    RewriteRule ^api(/|$) - [R=404,L]
```

**(c) Prove it:**

```bash
curl -s -o /dev/null -w 'GET  /api/all_product   -> %{http_code}\n' https://eleganceeg.com/api/all_product
curl -s -o /dev/null -w 'POST /api/login         -> %{http_code}\n' -X POST https://eleganceeg.com/api/login
curl -s -o /dev/null -w 'GET  /api/v2/.../meta   -> %{http_code}\n' https://eleganceeg.com/api/v2/watchizer/meta
```

All three must be **404**. Then confirm from the other side: the legacy host's access log should
show **no requests from this server's IP**, ever.

### 7.3 Rolling back

Nothing here touches the legacy installation, so "rollback" means stopping this dashboard — never
repairing anything over there.

**If the dashboard misbehaves but the data is sound** (bad config, broken asset path):

```bash
cd ~/domains/eleganceeg.com/core
php artisan config:clear && php artisan cache:clear && php artisan view:clear
# fix .env, then:
php artisan config:cache
```

Read `storage/logs/laravel.log` first. With `APP_DEBUG=false` the browser shows a bare 500 and the
log holds the actual exception.

**If the data is wrong** (a bad import, a bulk action that went further than intended):

```bash
ls -lh ~/domains/eleganceeg.com/core/storage/app/backups/
mysql -u USER -p DATABASE < storage/app/backups/<the dump you want>.sql
```

Restoring is a full replace. Take a dump of the current state *first*, even when you are sure —
`php artisan core:backup` takes seconds and gives you a way back from the way back.

**If you need the dashboard off entirely, immediately:**

```bash
php artisan down --secret="a-long-random-string-you-choose"
```

Everyone sees a maintenance page; you keep access at
`https://eleganceeg.com/a-long-random-string-you-choose`. Reverse it with `php artisan up`.

**The nuclear option** is to point the domain's document root back at `public_html.bak` (§2.3). The
database and the media tree are untouched by that, so it is reversible.

**What rollback never involves:** turning the legacy site back on. See §8.

---

## 8. WHY A STANDALONE DATABASE IS SAFE — AND WHEN IT STOPS BEING

**The condition that makes all of this correct:** the legacy Watchizer site is **stopped**. No new
orders, no registrations, no stock movement, nothing writing to the legacy database. The client has
been told the dashboard is where the team works from now.

That single fact is what permits everything above:

- **There is no divergence to reconcile.** Two live systems on two databases would drift within
  hours — an order taken there and not here, stock decremented there and not here — and there is no
  mechanism in this codebase to merge them back. With one writer there is nothing to merge.
- **`CORE_WRITE_SWITCH_COMPLETED=true` is true rather than merely convenient** (§2.4). The flag
  means *legacy has stopped writing*. It has.
- **The copy is now the original.** From the moment this dashboard takes its first edit, this
  database is the only current copy of the business's catalogue. The legacy database becomes a
  historical record: read it, never write it.

### This stops being true the moment anyone turns the old site back on

If the legacy site is restored to service — even briefly, even "just to check something":

1. **Two systems write two databases.** Every order, registration and stock change on either side is
   invisible to the other. There is no reconciliation tool, and writing one after the fact means
   resolving conflicts by hand, row by row.
2. **`CORE_WRITE_SWITCH_COMPLETED=true` becomes a lie**, and it is the dangerous kind. Legacy writes
   `products.quantity` directly while this dashboard keeps stock in a ledger. A product converted to
   variants in that window has its stock corrupted, and **stock is not recoverable by typing it
   again** — you cannot know what it should have been.
3. **The activity log will not tell you it happened.** It records changes made *here*. Changes made
   over there leave no trace in this database at all.

**If it does happen:** set `CORE_WRITE_SWITCH_COMPLETED=false`, run
`php artisan config:clear && php artisan config:cache`, stop anyone converting products to variants,
and treat the two databases as having diverged from the moment the old site answered its first
request. Write that moment down.

**Put this to the client in writing**, not just in conversation: the old dashboard is read-only from
*(date)*, and switching it back on is a decision with a cost, not a convenience.

---

## 9. BACKUPS — THE FIRST THING AFTER DEPLOY

From the moment the team makes its first edit, this database is the only copy of the business's
catalogue. Do this on deployment night, not the next morning.

### 9.1 The cron line

Everything is scheduled inside Laravel, so the host needs **exactly one** crontab entry.

hPanel → **Advanced → Cron Jobs** → new job, **every minute**:

```
* * * * * cd /home/USERNAME/domains/eleganceeg.com/core && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Replace `USERNAME` with your Hostinger account name, and confirm the PHP binary first:

```bash
which php          # if it is not /usr/bin/php, use the path it prints
php -v             # confirm 8.3+, NOT the system default
```

> On Hostinger the CLI PHP is often older than the web PHP. If `php -v` shows 8.1, find the right
> binary (`/usr/bin/php8.3` or similar) and use its full path in the cron line. The schedule fails
> silently otherwise.

That one entry runs everything:

| command | when | what it does |
|---|---|---|
| `core:backup --keep=7` | 03:00 | the nightly dump |
| `inventory:verify` | 03:30 | Σ ledger = the stock column |
| `mail:drain --reclaim` | every minute | retries mail the inline send could not deliver |
| `inventory:reconcile-cancellations` | every minute | returns reserved stock from cancelled orders |
| `integration:drain --channel=morabaa` | hourly | keeps the outbox bounded |

Backup at 03:00 and verify at 03:30 is deliberate: a night that goes wrong leaves the dump taken
*before* the verifier's findings rather than after them.

### 9.2 Where the dumps land, and how many are kept

| | |
|---|---|
| directory | `~/domains/eleganceeg.com/core/storage/app/backups/` |
| filename | `<database>-YYYY-MM-DD-HHMMSS.sql` |
| retention | **7 days** (`--keep=7`); older dumps deleted automatically each run |
| mode | `0600` — the site's own account, nobody else on the shared host |
| size | expect **~25 MB** each, growing with the catalogue |
| encrypted | **no, by decision** — a key in `.env` beside the dump on the same host protects nothing |

The command **refuses to write anywhere beneath the public directory**, so a dump cannot end up
downloadable by guessing a URL. It writes under a `.part` name, checks mysqldump's completion
marker, and only then renames into place — so a dump killed halfway never becomes a file that looks
like a backup and restores into a half-empty database.

Confirm the directory is unreachable anyway:

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://eleganceeg.com/storage/app/backups/
```

403 or 404.

### 9.3 Find the `mysqldump` binary — the one value you cannot guess from the workstation

`core:backup` shells out to `mysqldump`. On shared hosting it is often not on the cron user's
`PATH`, and the failure is silent: the schedule keeps running, the command fails inside it, and the
only symptom is that no dump ever appears. Settle it now, in four escalating steps. **Stop at the
first one that answers.**

**Step 1 — ask the shell.**
```bash
which mysqldump || command -v mysqldump
```
An answer like `/usr/bin/mysqldump` means it is on the `PATH` and **you need to do nothing** —
leave `DB_DUMP_BINARY` unset. Go to §9.4.

**Step 2 — look where it normally lives.** If step 1 printed nothing:
```bash
ls -l /usr/bin/mysqldump /usr/local/bin/mysqldump /opt/alt/mysql*/usr/bin/mysqldump 2>/dev/null
```
On Hostinger's CloudLinux hosts the MariaDB client tools are frequently under an `/opt/alt/…` path
rather than `/usr/bin`, which is exactly why the `PATH` lookup misses them.

**Step 3 — search for it.** If step 2 found nothing:
```bash
find / -name mysqldump -type f 2>/dev/null | head
```
Errors are suppressed because most of the filesystem is not yours to read. This takes 10–30 seconds.

**Step 4 — ask PHP where the client tools are.** If the search came back empty:
```bash
php -r "echo shell_exec('ls -l \$(dirname \$(readlink -f \$(which mysql 2>/dev/null) 2>/dev/null)) 2>/dev/null');"
```
`mysql` and `mysqldump` ship in the same package, so finding one locates the other.

**Then verify what you found actually runs** — a path that exists is not a path that works:
```bash
/full/path/to/mysqldump --version
```
It must print a version. If it prints "permission denied", that path is not usable and you continue
searching.

**Then set it:**
```bash
# in ~/domains/eleganceeg.com/core/.env
DB_DUMP_BINARY=/full/path/to/mysqldump
```
```bash
php artisan config:clear && php artisan config:cache
```

> `DB_DUMP_BINARY` was added to `config/database.php` for this deployment. `CoreBackupCommand`
> always read `config('database.mysqldump')`, but nothing declared the key — so on a host where the
> binary was not on the `PATH` there was no way to point at it without editing the command.

**If all four steps fail and no `mysqldump` exists on the host**, stop and open a ticket with
Hostinger support asking for the path to the MySQL client binaries on your plan. Do not go live
without a working backup — §9 is the section that makes the rest of this runbook recoverable, and a
deployment with no backup is a deployment you cannot undo.

### 9.4 Run one now, by hand

```bash
cd ~/domains/eleganceeg.com/core
php artisan core:backup --keep=7
ls -lh storage/app/backups/
```

You want one `.sql` file of roughly 25 MB, dated now. If the command reports a non-zero exit or
mentions the binary, go back to §9.3.

### 9.5 Verify that a backup actually happened

This is the different question, and the one that matters at 9am. `core:backup` working is a property
of the code, and it is tested. What you need to know is a property of the **disk**.

```bash
php artisan backup:verify --max-age=36 --min-mb=10
```

It asks three things of the newest file on disk — **does a dump exist**, **is it plausibly sized**
(not a 4 KB stub), and **is it recent** (not from three weeks ago, when the cron entry was last
correct). It exits non-zero and names which of the three is wrong. It writes nothing and dumps
nothing, so it is safe to run at any time.

`--min-mb=10` rather than the default 1: your dumps are ~25 MB, so a 2 MB file is a truncated dump
that would otherwise pass.

**Run it on the morning after deployment.** Then put a reminder in your calendar for one week later,
and another for a month later. The failures this catches — a crontab lost in a host migration, a
disk that filled, a PHP upgrade that moved the binary — all leave `core:backup` perfectly functional
and the shop with no backups, and the only signal is silence, which reads exactly like success.

### 9.6 Get one copy off this host

Seven days of dumps on the same disk as the database is not a backup strategy — it is protection
against a mistake, not against losing the host. Once a week:

```bash
# from the workstation
scp USERNAME@eleganceeg.com:~/domains/eleganceeg.com/core/storage/app/backups/*.sql ./offsite/
```

That file holds every customer's name, e-mail, phone, address and password hash. Keep it encrypted,
keep it off shared drives, and delete the copies you no longer need.

---

## Appendix — every command, in order

```bash
# ── workstation ───────────────────────────────────────────────────────────────
npm run build
composer install --no-dev --optimize-autoloader
mysqldump -u root -p --single-transaction --routines --triggers \
  --default-character-set=utf8mb4 u591083448_watchizer | gzip -6 > eleganceeg.sql.gz
#    upload code (§3.2) · start the media upload (§4.2) · upload the dump
composer install                      # put the dev dependencies back

# ── server ────────────────────────────────────────────────────────────────────
cd ~/domains/eleganceeg.com/core
gunzip -c ~/eleganceeg.sql.gz | mysql -u USER -p DB && rm ~/eleganceeg.sql.gz
#    write .env (§2.4)
chmod 600 .env
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views \
         storage/logs storage/app/backups
chmod -R 775 storage bootstrap/cache && chmod 700 storage/app/backups
rm -f public/hot public/fonts-manifest.dev.json
php artisan key:generate
php artisan storage:link
php artisan migrate:status                       # all Ran, none Pending
php artisan migrate --force                      # "Nothing to migrate."
php artisan db:seed --class=StorefrontSeeder --force
php artisan manage:role grant YOUR@EMAIL admin
php artisan media:capabilities                   # write_webp must be yes
php artisan media:verify --show=20               # 55 absent, no more
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan core:backup --keep=7
php artisan backup:verify --max-age=36 --min-mb=10
#    then §6 (smoke test), then the cron line in §9.1
```
