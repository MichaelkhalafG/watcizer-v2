import { headers, cookies } from 'next/headers'
import { localePath } from '../utils/localePath'

export { localePath }

// The request's languages (S-AR stage 1), set by middleware.js:
//   lang    — what the page SHOWS: 'ar' under /ar/, else the shopper's wz-lang cookie.
//   urlLang — which URL this is: 'ar' under /ar/, else 'en'. Metadata (title, canonical,
//             hreflang, JSON-LD) follows THIS, because it describes the URL Google indexes.
// Without the middleware headers (a route the matcher skips), the cookie alone decides `lang`.
export async function requestLang() {
  const h = await headers()
  const urlLang = h.get('x-wz-url-lang') === 'ar' ? 'ar' : 'en'
  let lang = h.get('x-wz-lang')
  if (lang !== 'ar' && lang !== 'en') lang = (await cookies()).get('wz-lang')?.value === 'ar' ? 'ar' : 'en'
  return { lang, urlLang }
}

export const SITE = 'https://watchizereg.com'

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
