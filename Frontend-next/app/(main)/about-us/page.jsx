import TrustPage from '@/src/Components/Trust/TrustPage'
import { trustMetadata } from '@/src/content/trustMetadata'
import { safeJsonLd } from '@/src/lib/safeJsonLd'
import { storesJsonLd } from '@/src/lib/storesJsonLd'
import { requestLang } from '@/src/lib/requestLang'

// /about-us and /ar/about-us — a trust page (2026-10-02); text in src/content/trustPages.js.
export const generateMetadata = () => trustMetadata('about')

export default async function Page() {
  const { urlLang } = await requestLang()
  return (
    <>
      {/* the shop and its branches, as the contact page states them (Part 3 task 5, 2026-10-29) */}
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: safeJsonLd(storesJsonLd(urlLang)) }} />
      <TrustPage page="about" />
    </>
  )
}
