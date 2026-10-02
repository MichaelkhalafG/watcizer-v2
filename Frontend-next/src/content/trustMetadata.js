import { requestLang, alternatesFor } from '@/src/lib/requestLang'
import { TRUST_PAGES } from './trustPages'

// Title, description, canonical and hreflang of a trust page, in the language of its URL (S-AR:
// metadata follows the URL — English bare, Arabic under /ar — never the shopper's cookie).
export async function trustMetadata(key) {
  const { urlLang } = await requestLang()
  const page = TRUST_PAGES[key]
  const t = page[urlLang]
  return {
    title: t.metaTitle,
    description: t.description,
    alternates: alternatesFor(page.path, urlLang),
    openGraph: { title: t.metaTitle, description: t.description, type: 'website' },
  }
}
