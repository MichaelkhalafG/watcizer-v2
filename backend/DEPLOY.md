# Deployment / Ops notes

## PHP `memory_limit` — MUST be ≥ 512M

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

### Production (php.ini or PHP-FPM pool)

Set in the production `php.ini`, or the PHP-FPM pool config
(`/etc/php/8.3/fpm/pool.d/www.conf` → `php_admin_value[memory_limit] = 512M`):

```ini
memory_limit = 512M
```

Reload PHP-FPM after changing it (`sudo systemctl reload php8.3-fpm`).

### `php artisan serve` / built-in server (dev / staging)

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

## Catalog cache

`/api/all_product`, `/api/all_product_image`, `/api/all_product_rating` are cached
for 10 minutes (`Cache::remember`). After a data reseed or bulk product edit, clear
it so SSR picks up fresh data:

```bash
php artisan cache:clear
```
