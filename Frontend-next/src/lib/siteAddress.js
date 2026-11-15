// THE SITE ADDRESS of Watchizer — ONE literal (2026-11-14), as Brand Fashion's src/lib/siteAddress.js. requestLang
// re-exports it; a module the CLIENT bundle reaches (src/lib/shopSchema.js, through the hero product page) imports it
// from here, because requestLang imports next/headers and a client component may not (the production build fails).
// No imports here. Not a twin: each shop's own address.
export const SITE = 'https://watchizereg.com'
// The shop's name as its pages print it (2026-11-14, SEO Phase 3: the article page and the feeds are twins and read it).
export const SHOP_NAME = 'Watchizer'
