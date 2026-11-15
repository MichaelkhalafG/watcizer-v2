import TrustPage from '@/src/Components/Trust/TrustPage'
import { trustMetadata } from '@/src/content/trustMetadata'
import { safeJsonLd } from '@/src/lib/safeJsonLd'
import { returnPolicy } from '@/src/lib/shopSchema'

// /returns and /ar/returns — a trust page (SEO Phase 1, 2026-11-14; Michael's trust-pages input). A TWIN: the text is each
// shop's own, in src/content/trustPages.js.
export const generateMetadata = () => trustMetadata('returns')

export default function Page() {
  // the return policy as structured data, on the returns page (Google's MerchantReturnPolicy): the same policy the
  // home page's graph and every product offer carry — 14 days, free return by mail, full refund
  return (
    <>
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: safeJsonLd({ '@context': 'https://schema.org', ...returnPolicy() }) }} />
      <TrustPage page="returns" />
    </>
  )
}
