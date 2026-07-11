# Watchizer Next.js — Deploy Guide

## Requirements
- Node.js 20+
- pnpm
- PM2
- 3GB+ RAM

## Environment
Copy .env.production values or set them in the hosting environment. NEXT_PUBLIC_PUBLIC_API_KEY must be provided (it is a client-exposed public key) — set it in the host env or .env.production on the server.

## Deploy
```bash
cd Frontend-next
pnpm install --frozen-lockfile
NODE_OPTIONS=--max-old-space-size=4096 pnpm build
pm2 start ecosystem.config.js
```

## PM2 Commands
- Status: pm2 status
- Logs: pm2 logs watchizer-next
- Restart: pm2 reload watchizer-next
- Stop: pm2 stop watchizer-next

## Nginx Config (if needed)
```nginx
server {
    server_name watchizereg.com www.watchizereg.com;

    location / {
        proxy_pass http://127.0.0.1:3000;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection 'upgrade';
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_cache_bypass $http_upgrade;
    }
}
```

## Rollback
If issues arise:
```bash
pm2 stop watchizer-next
```

## Memory Notes
- Build needs: --max-old-space-size=4096 (8192 was needed locally on a RAM-tight machine; 4096 is typically enough on a 3GB+ server, but bump to 8192 if the build OOMs)
- Runtime: 2048 per PM2 instance (cluster mode x2)
- PHP backend: memory_limit >= 512M
- Note: the root layout reads a language cookie, so all routes render dynamically (no static pre-render) — PM2 cluster mode + Nginx in front is the recommended runtime.
