# core

The Laravel 13 core application of the platform: clean catalog / storefront / inventory schema, the versioned public API, the legacy-compatibility layer, and the Inertia + React administration dashboard (Arabic-first, RTL).

## Stack

- PHP 8.3+, Laravel 13, MariaDB (`mariadb` driver, strict mode)
- Inertia 2 + React 18 + TypeScript, Tailwind CSS 3.4 + shadcn/ui, Vite 6
- astrotomic/laravel-translatable (locales `ar`, `en`; no fallback)
- Pest 4, Laravel Pint, PHPStan/Larastan level 10

## Database

One database is shared with the first-generation application. This app creates and writes only its own tables (`catalog_*`, `storefront_*`, `inventory_*`, `integration_*`, plus the `core_migrations` repository table). Two connections are configured: `mariadb` (default, clean tables) and `legacy` (same database, read-only by policy; models under `App\Models\Legacy` refuse writes). Destructive artisan commands (`migrate:fresh`, `db:wipe`, …) are prohibited in every environment.

## Setup

```bash
composer install
npm ci                      # never --omit=dev: frontend deps are build-time by design
cp .env.example .env        # point DB_* at a local database
php artisan key:generate
php artisan migrate
npm run build
```

CI/builds must run a full `npm ci` (never `--omit=dev`); Vite, Inertia, React and Tailwind are build-time dependencies and live in `devDependencies` by Laravel convention.

## Checks

```bash
vendor/bin/pest             # feature tests run inside rolled-back transactions on a local DB
vendor/bin/pint --test
vendor/bin/phpstan analyse
npm run typecheck
npm run build
```
