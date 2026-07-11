# Watchizer — Laravel Backend Deploy Guide (Hostinger Cloud)

Production API host: **https://dash.watchizereg.com**
Storefront (Next.js): **https://watchizereg.com**

## Requirements
- PHP **8.3+** (project requires `^8.1`; 8.3 recommended)
- MySQL 8+
- Composer 2
- `memory_limit >= 512M` — see [PHP `memory_limit`](#php-memory_limit--must-be--512m) (this is a hard requirement, not a suggestion)

---

## First Deploy
```bash
cd backend
cp .env.production .env          # or copy .env.production.example and fill it in
# Fill in: DB creds, JWT_SECRET, PUBLIC_API_KEY, Google/Paymob/SMTP keys
composer install --no-dev --optimize-autoloader
php artisan key:generate         # ONLY if APP_KEY is empty (skip if carrying an existing key)
php artisan migrate --force
php artisan storage:link         # public/storage → storage/app/public
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## Routine Deploy
Use the provided script (edit `APP_DIR`/`BRANCH` if paths differ):
```bash
bash deploy.sh
```
It pulls, installs `--no-dev`, migrates `--force`, rebuilds caches, clears app cache.
Restart the queue worker afterwards (see [Queue Worker](#queue-worker)).

---

## Environment Variables
All required variables are documented in **`.env.production.example`** (committed).
The filled **`.env.production`** is gitignored — never commit real secrets.

Key notes:
- **`APP_DEBUG=false`** — CRITICAL. Never `true` in production (leaks stack traces).
- **`APP_ENV=production`** — also disables localhost CORS origins automatically.
- **`JWT_SECRET`** and **`PUBLIC_API_KEY`** are *separate* secrets — keep both, don't reuse one for the other.
- **`ASSET_BASE`** — absolute host prepended to image URLs in API responses.
- Re-run `php artisan config:cache` after ANY `.env` change (cached config ignores `.env` at runtime).

---

## Google OAuth
1. Google Cloud Console → APIs & Services → Credentials.
2. Authorized redirect URI:
   `https://dash.watchizereg.com/api/auth/google/callback`
3. Authorized JavaScript origins:
   `https://watchizereg.com`
   `https://dash.watchizereg.com`
4. Set `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` in `.env`.
   (`config/services.php` reads all three straight from env — no code change needed.)

---

## CORS
Configured in `config/cors.php`, driven by `FRONTEND_URL`:
- `https://watchizereg.com` (from `FRONTEND_URL`)
- `https://www.watchizereg.com`
- `https://dash.watchizereg.com`
- `http://localhost:3000` / `:5173` — **only when `APP_ENV=local`** (auto-excluded in production).

After changing `FRONTEND_URL`, run `php artisan config:cache`.

---

## Queue Worker
The app uses `QUEUE_CONNECTION=database`, so a worker must run continuously.

### Option A — Supervisor (recommended)
```bash
sudo cp supervisor-worker.conf /etc/supervisor/conf.d/
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start watchizer-worker:*
```

### Option B — Cron (if Supervisor is unavailable on the plan)
Add to `crontab -e`:
```cron
* * * * * cd /home/u591083448/watchizer/backend && php artisan schedule:run >> /dev/null 2>&1
```
Then register a drained worker in `app/Console/Kernel.php` (`schedule()` method):
```php
$schedule->command('queue:work database --stop-when-empty --max-time=55')
         ->everyMinute()
         ->withoutOverlapping();
```
> Use EITHER Supervisor OR cron — not both, to avoid double-processing jobs.
> Restart the worker after every deploy (`supervisorctl restart watchizer-worker:*`)
> so it picks up new code.

---

## PHP Settings
Apply via hPanel → Advanced → PHP Configuration, or rely on the committed
`public/.user.ini` (Hostinger honours per-directory `.user.ini`):
```ini
memory_limit = 512M
max_execution_time = 120
upload_max_filesize = 10M
post_max_size = 12M
max_input_vars = 3000
```
Reference copy: `php-production.ini`. See the rationale for the 512M floor below.

### PHP `memory_limit` — MUST be ≥ 512M

The catalog endpoint `GET /api/all_product` returns the full product catalog
(~2150 products with `feature`, `gender`, `dialColor`, `bandColor`, `translations`
relations). The Next.js SSR layer (`Frontend-next/src/lib/serverCatalog.js`) depends
on this endpoint for every server-rendered page — **if it 500s, the whole site
renders empty**.

Under the PHP default `memory_limit = 128M`, building + caching this response has
historically triggered a fatal OOM in `Illuminate\Cache\FileStore` (the cache blob
is serialized/stored on disk). The controller has since been optimized (column
`select()` + caching a plain array instead of an Eloquent collection) so it no
longer OOMs at 128M, **but production must still be provisioned with headroom.**

#### Production (php.ini or PHP-FPM pool)

Set in the production `php.ini`, or the PHP-FPM pool config
(`/etc/php/8.3/fpm/pool.d/www.conf` → `php_admin_value[memory_limit] = 512M`):

```ini
memory_limit = 512M
```

Reload PHP-FPM after changing it (`sudo systemctl reload php8.3-fpm`).

#### `php artisan serve` / built-in server (dev / staging)

`artisan serve` does **not** propagate `php -d memory_limit=…` to the spawned
worker, and the framework's built-in router resolves the public path from the
current working directory. Launch the built-in server from `backend/public/` with
an explicit limit:

```bash
cd backend/public
php -d memory_limit=512M -S 127.0.0.1:8000 \
    "../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"
```

(Any value ≥ 512M is safe; the optimized endpoint also succeeds at 128M, but 512M
gives headroom for concurrent requests and future catalog growth.)

---

## Sitemap & Robots
- `GET /sitemap.xml` → served dynamically by Laravel (`SitemapController`), includes
  products, brands, category types, sub-types, grades, and blogs.
- `robots.txt` for the storefront is served by **Next.js** (`Frontend-next/public/robots.txt`).
  Laravel also exposes `/robots.txt` for the admin host, but the public SEO robots is the Next.js one.

---

## Cache Management
```bash
php artisan config:cache    # after .env changes
php artisan route:cache     # after route changes
php artisan view:cache      # cache Blade views
php artisan cache:clear     # clear application cache
php artisan optimize:clear  # nuke all caches (config/route/view/compiled) if something is stale
```

### Catalog cache
`/api/all_product`, `/api/all_product_image`, `/api/all_product_rating` are cached
for 10 minutes (`Cache::remember`). After a data reseed or bulk product edit, clear
it so SSR picks up fresh data:

```bash
php artisan cache:clear
```

---

## Security Checklist (verified in this repo)
- [x] `APP_DEBUG=false` in `.env.production`
- [x] No controller returns raw `getMessage()/getLine()/getFile()` to clients (all logged)
- [x] `JWT_SECRET` ≠ `PUBLIC_API_KEY` (separate env vars)
- [x] `Product` model hides `purchase_price`, `wa_code`, `hs_code` (+ `sku_unique`, audit cols)
- [x] No hardcoded secrets in `app/` (all via `env()`/config)
- [x] CORS restricted to production origins (no `*` in production)

---

## Rollback
```bash
git checkout <previous-commit>
composer install --no-dev --optimize-autoloader
php artisan migrate --force        # only if the bad deploy added migrations; review first
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo supervisorctl restart watchizer-worker:*
```
