import TrustPage from '@/src/Components/Trust/TrustPage'
import { trustMetadata } from '@/src/content/trustMetadata'
import { getServerV2Meta } from '@/src/lib/serverV2'
import { requestLang } from '@/src/lib/requestLang'
import { dealerBrands, dealerSentence } from '@/src/lib/dealers'
import { SHOP_NAME } from '@/src/lib/siteAddress'

// /warranty and /ar/warranty — a trust page (SEO Phase 1, 2026-11-14; Michael's trust-pages input). A TWIN: the text is each
// shop's own, in src/content/trustPages.js.
export const generateMetadata = () => trustMetadata('warranty')

// the brands the shop is an authorized dealer for (Michael, 2026-11-14): their watches carry the agent's warranty
export default async function Page() {
  const { urlLang } = await requestLang()
  const meta = await getServerV2Meta().catch(() => null)
  return <TrustPage page="warranty" dealers={dealerSentence(dealerBrands(meta?.data ?? meta), urlLang, SHOP_NAME)} />
}
