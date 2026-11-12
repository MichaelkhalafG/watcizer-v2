// THE SITE ADDRESS of Brand Fashion — ONE literal (BF_DOMAIN_SWITCH_PLAN §3 item 1, written 2026-11-12 overnight).
// Every canonical, hreflang, Open Graph URL, JSON-LD URL and the sitemap base reads it (requestLang SITE, both
// SEO_DOMAINs, app/layout.jsx, app/(main)/page.jsx). A literal on purpose, not a build variable: a canonical that
// depends on an env value can be wrong on one deploy. SWITCH DAY changes this ONE line's
// address to https://www.brandfashionegy.com (the www canonical, D-3) together with public/robots.txt's Sitemap line — switch-day script bf-switch-code.py.
// No imports here: the client bundle may read it without pulling in next/headers.
export const SITE = 'https://www.brandfashionegy.com'
