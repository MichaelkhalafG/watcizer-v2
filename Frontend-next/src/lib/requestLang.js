import { headers } from 'next/headers'
import { localePath } from '../utils/localePath'
import { SITE } from './siteAddress'

export { localePath }

// The request's language, set by middleware.js: 'ar' under /ar/, else 'en'. THE ADDRESS DECIDES (Michael,
// 2026-11-14): `lang` (what the page shows) and `urlLang` (which URL this is — metadata, canonical, hreflang, JSON-LD)
// are the same value now. Until then a bare URL showed the Arabic body to a visitor holding the wz-lang cookie under an
// English title and canonical; the cookie no longer changes what any URL shows (middleware.js: it only sends a visitor
// who chose Arabic from the bare home page to /ar). Both names stay, so no caller had to change.
export async function requestLang() {
  const h = await headers()
  const urlLang = h.get('x-wz-url-lang') === 'ar' ? 'ar' : 'en'
  return { lang: urlLang, urlLang }
}

export { SITE }

// Next `alternates` for a page available in both languages: self-canonical in `lang`, plus
// hreflang for both and x-default (English). `path` is the English path, query included if any.
export const alternatesFor = (path, lang) => ({
  canonical: `${SITE}${localePath(path, lang)}`,
  languages: {
    en: `${SITE}${path}`,
    ar: `${SITE}${localePath(path, 'ar')}`,
    'x-default': `${SITE}${path}`,
  },
})
