import TrustPage from '@/src/Components/Trust/TrustPage'
import { trustMetadata } from '@/src/content/trustMetadata'
import { safeJsonLd } from '@/src/lib/safeJsonLd'
import { storesJsonLd } from '@/src/lib/storesJsonLd'
import { requestLang } from '@/src/lib/requestLang'
import { getServerV2Meta } from '@/src/lib/serverV2'
import { dealerBrands, dealerSentence } from '@/src/lib/dealers'
import { SHOP_NAME } from '@/src/lib/siteAddress'

// /about-us and /ar/about-us — a trust page (2026-10-02); text in src/content/trustPages.js.
export const generateMetadata = () => trustMetadata('about')

export default async function Page() {
  const { urlLang } = await requestLang()
  // the shop's followers, a dashboard setting (2026-11-14); the page renders without it if core cannot answer
  const meta = await getServerV2Meta().catch(() => null)
  const followers = meta?.storefront?.settings?.followers ?? null
  return (
    <>
      {/* the shop and its branches, as the contact page states them (Part 3 task 5, 2026-10-29) */}
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: safeJsonLd(storesJsonLd(urlLang)) }} />
      <TrustPage page="about" followers={followers} dealers={dealerSentence(dealerBrands(meta?.data ?? meta), urlLang, SHOP_NAME)} />
    </>
  )
}
