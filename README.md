# Watchizer platform

A multi-storefront e-commerce platform: one shared product catalog served through several branded storefronts, each with its own visibility rules, category tree, ordering and domain. Built for an Arabic-first market with full English support (RTL-first UI, bilingual content and URLs).

## Architecture

- **Core application** — Laravel 11: the catalog and storefront schema, a versioned public API (`/api/v2/{storefront}/…`), an inventory service with an append-only movement ledger, and an Inertia + React administration dashboard (Arabic-first, RTL).
- **Storefront frontends** — Next.js applications, one per storefront, rendering from the v2 API with server-side pagination, locale-prefixed routes, structured data and per-locale sitemaps.
- **Database** — MariaDB 11.8, single database; catalog, storefront placement and inventory tables are separate concerns with explicit foreign keys and soft deletes.
- **Legacy** — `backend/` (Laravel 10 API + admin) and `Frontend-next/` (the current Next.js storefront) are the first-generation applications that the core replaces incrementally through a compatibility layer.

## Repository layout

| Path | Contents |
|---|---|
| `backend/` | Laravel 10 legacy API and admin dashboard |
| `Frontend-next/` | Next.js storefront (current) |
| `Frontend/` | earlier React storefront (archived) |
| `scripts/` | developer tooling (git hooks, installers) |
| `docs/` | technical documentation |

## Development conventions

- A pre-commit guard (`scripts/git-hooks/pre-commit`, install with `scripts/install-hooks.ps1`) keeps data dumps, environment files and credentials out of the repository.
- Legacy code is read-only reference; new work goes into the core application.

## License

Proprietary. All rights reserved.
