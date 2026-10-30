import { TrustFaq } from '@/src/Components/Trust/TrustPage'
import { FAQ } from '@/src/content/faq'
import { safeJsonLd } from '@/src/lib/safeJsonLd'
import { requestLang, alternatesFor } from '@/src/lib/requestLang'

// /faq and /ar/faq — the questions and answers (SEO item 5, 2026-10-30). The text is the storefront's own
// src/content/faq.js; this page is a twin. Metadata and the FAQPage JSON-LD follow the URL's language (S-AR), the
// page body the shopper's, as on the other trust pages.

/** The answer as plain text for the JSON-LD: `**bold**` and `[label](href)` reduced to their words. */
const plain = (text) =>
  String(text)
    .replace(/\[([^\]]+)\]\([^)\s]+\)/g, '$1')
    .replace(/\*\*([^*]+)\*\*/g, '$1')
    .replace(/\*([^*]+)\*/g, '$1')

export async function generateMetadata() {
  const { urlLang } = await requestLang()
  const t = FAQ[urlLang]
  return {
    title: t.metaTitle,
    description: t.description,
    alternates: alternatesFor(FAQ.path, urlLang),
    openGraph: { title: t.metaTitle, description: t.description, type: 'website' },
  }
}

export default async function Page() {
  const { urlLang } = await requestLang()
  const ld = {
    '@context': 'https://schema.org',
    '@type': 'FAQPage',
    inLanguage: urlLang,
    mainEntity: FAQ[urlLang].groups.flatMap((g) =>
      g.items.map((item) => ({
        '@type': 'Question',
        name: item.q,
        acceptedAnswer: { '@type': 'Answer', text: plain(item.a) },
      })),
    ),
  }
  return (
    <>
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: safeJsonLd(ld) }} />
      <TrustFaq />
    </>
  )
}
