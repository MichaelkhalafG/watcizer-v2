import { requestLang, alternatesFor } from '@/src/lib/requestLang'
import { TRUST_PAGES } from './trustPages'
import { withDefaultOg } from '@/src/lib/defaultOg'

// Title, description, canonical and hreflang of a trust page, in the language of its URL (S-AR:
// metadata follows the URL — English bare, Arabic under /ar — never the shopper's cookie).
export async function trustMetadata(key) {
  const { urlLang } = await requestLang()
  const page = TRUST_PAGES[key]
  const t = page[urlLang]
  // the shop's share picture (2026-11-17): this openGraph replaces the root layout's, picture and all
  return withDefaultOg({
    title: t.metaTitle,
    description: t.description,
    alternates: alternatesFor(page.path, urlLang),
    openGraph: { title: t.metaTitle, description: t.description, type: 'website' },
  })
}
