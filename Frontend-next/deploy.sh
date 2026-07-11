#!/bin/bash
set -e

echo "=== Watchizer Next.js Deploy ==="

# Pull latest
git pull origin feature/nextjs-migration

# Install deps
cd Frontend-next
pnpm install --frozen-lockfile

# Build
NODE_OPTIONS=--max-old-space-size=4096 pnpm build

# Restart PM2
pm2 reload ecosystem.config.js --env production

echo "=== Deploy complete ==="
echo "Check: pm2 status"
echo "Logs:  pm2 logs watchizer-next"
