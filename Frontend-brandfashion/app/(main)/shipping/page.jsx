import TrustPage from '@/src/Components/Trust/TrustPage'
import { trustMetadata } from '@/src/content/trustMetadata'
import { safeJsonLd } from '@/src/lib/safeJsonLd'
import { shippingService } from '@/src/lib/shopSchema'
import { getServerShippingFees } from '@/src/lib/serverV2'

// /shipping and /ar/shipping — a trust page (SEO Phase 1, 2026-11-14; Michael's trust-pages input). A TWIN: the text is each
// shop's own, in src/content/trustPages.js.
export const generateMetadata = () => trustMetadata('shipping')

export default async function Page() {
  // the shipping policy as structured data, on the shipping page (Google's organization-level shipping policy): the
  // same ShippingService the home page's graph carries, its rate from the dashboard's fees — none when core cannot say
  const fees = await getServerShippingFees().catch(() => null)
  const service = shippingService(fees)
  return (
    <>
      {service ? <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: safeJsonLd({ '@context': 'https://schema.org', ...service }) }} /> : null}
      <TrustPage page="shipping" />
    </>
  )
}
