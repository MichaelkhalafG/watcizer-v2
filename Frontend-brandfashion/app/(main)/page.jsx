import BfHome from '@/src/Components/Home/BfHome'
import { safeJsonLd } from '@/src/lib/safeJsonLd'
import { getServerV2Home, getServerV2Meta, getServerShippingFees } from '@/src/lib/serverV2'
import { shopGraph } from '@/src/lib/shopSchema'
import { slimV2Home } from '@/src/lib/homeData'
import { requestLang, alternatesFor, localePath, SITE } from '@/src/lib/requestLang'

// ISR: render on the server (with the rails' cards in the HTML for SEO), cache, and
// revalidate every 5 min — also how soon a change on the dashboard's Home rails screen shows.
export const revalidate = 300

// Home metadata (overrides the layout defaults) — ported from Home.jsx's <Helmet>, in the URL's
// language (S-AR stage 1): English on /, Arabic on /ar, each self-canonical with hreflang to the
// other. The Arabic copy is the site's own (the root layout's Arabic defaults), not new wording.
const HOME_TEXT = {
  // The English title and description are the old site's Rank Math text (D-14, 2026-11-11), the old site's
  // "BrandFashion" written "Brand Fashion", as the shop writes its name; the Arabic stays this site's own (the old site had no Arabic SEO).
  en: {
    title: 'Original Watches in Egypt | Bags | Sunglasses | Brand Fashion',
    description:
      'Shop 100% original watches in Egypt, bags, sunglasses, and accessories for men and women at Brand Fashion. Enjoy exclusive discounts and fast delivery on all products.',
    alt: 'Brand Fashion — Luxury Watches',
    locale: 'en_US',
  },
  ar: {
    title: 'Brand Fashion - أفخم الساعات والإكسسوارات | تسوق الآن بأسعار مميزة',
    description:
      'اكتشف أفخم الساعات والإكسسوارات في Brand Fashion. تسوق الآن أرقى الساعات الفاخرة بتصاميم أنيقة وجودة عالمية بأسعار تنافسية.',
    alt: 'Brand Fashion — ساعات فاخرة',
    locale: 'ar_EG',
  },
}

export async function generateMetadata() {
  const { urlLang } = await requestLang()
  const t = HOME_TEXT[urlLang]
  // English keeps its exact canonical, trailing slash included.
  const url = urlLang === 'ar' ? `${SITE}${localePath('/', 'ar')}` : `${SITE}/`
  return {
    title: t.title,
    description: t.description,
    alternates: { ...alternatesFor('/', urlLang), canonical: url },
    openGraph: {
      title: t.title,
      description: t.description,
      url,
      siteName: 'Brand Fashion',
      type: 'website',
      images: [
        {
          url: `${SITE}/og-image.jpg`,
          width: 1200,
          height: 630,
          alt: t.alt,
        },
      ],
    },
    twitter: {
      card: 'summary_large_image',
      title: t.title,
      description: t.description,
      images: [`${SITE}/og-image.jpg`],
    },
  }
}

// The rails and their cards come from core's `catalog/home` (C-1 stage 4 slice C) — not the whole
// catalogue. null on failure: the client shows its retry instead of rails.
// B6 (2026-10-17, DIVERGED from Frontend-next — V2-READ-HALF / B6): the home is spec v2.9 §10.1 on v2 `home` and
// `meta` (BfHome). Either read failing leaves that part empty on the server render; the page is never a 404.
export default async function HomePage() {
  let home = null
  let meta = null
  try {
    home = await getServerV2Home()
  } catch {
    // core unreachable → no rails on the server render
  }
  try {
    meta = await getServerV2Meta()
  } catch {
    // no brand strip
  }
  const { urlLang } = await requestLang()
  // the dashboard's delivery fees, for the shipping policy in the structured data — never the home's failure
  const fees = await getServerShippingFees().catch(() => null)
  return (
    <>
      {/* THE SHOP (2026-11-14, SEO Phase 1): Organization + OnlineStore with its return and shipping policies, the two
          stores as its physical places, and the WebSite (src/lib/shopSchema.js). It replaces a single Store node with
          a description about watches only. */}
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: safeJsonLd(shopGraph(urlLang, fees)) }} />
      {/* the sliders' cards cut to what a card reads (src/lib/homeData.js) */}
      <BfHome home={slimV2Home(home)} meta={meta} />
    </>
  )
}
