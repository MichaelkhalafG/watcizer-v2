#!/bin/bash
# ============================================================================
# Watchizer — Laravel backend production deploy script (Hostinger Cloud)
# Run from the server after SSH'ing in. Idempotent & safe to re-run.
# ============================================================================
set -euo pipefail

echo "=== Watchizer Laravel Deploy ==="

APP_DIR="/home/u591083448/watchizer/backend"
BRANCH="feature/nextjs-migration"

cd "$APP_DIR"

# 1. Pull latest code
echo "→ Pulling latest ($BRANCH)…"
git pull origin "$BRANCH"

# 2. Install production dependencies (no dev packages, optimized autoloader)
echo "→ Installing composer dependencies (--no-dev)…"
composer install --no-dev --optimize-autoloader --no-interaction

# 3. Run migrations (non-interactive)
echo "→ Running migrations…"
php artisan migrate --force

# 4. Rebuild caches (config MUST be cached AFTER .env is in place)
echo "→ Caching config / routes / views…"
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 5. Clear stale application cache
echo "→ Clearing application cache…"
php artisan cache:clear

# 6. Ensure storage symlink exists (public/storage → storage/app/public)
php artisan storage:link || true

echo "=== Laravel Deploy Complete ==="
