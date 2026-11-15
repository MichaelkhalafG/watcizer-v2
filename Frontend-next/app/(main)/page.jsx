import HomeClient from './HomeClient'
import { getServerV2Menu } from '@/src/lib/serverV2'
import { slimMenu } from '@/src/lib/menuData'
import { slimCompatHome } from '@/src/lib/homeData'
import { safeJsonLd } from '@/src/lib/safeJsonLd'
import { getServerHome } from '@/src/lib/serverCatalog'
// the campaign banner's live price, stock and facts (2026-10-29, Watchizer only): one cached read of the product
import { getServerV2ProductForHome } from '@/src/lib/serverV2'
import { bannerModel } from '@/src/lib/experienceSpecs'
import { PRODUCT_SLUG } from '@/src/content/grandPrix1513477'
import { requestLang, alternatesFor, localePath, SITE } from '@/src/lib/requestLang'
import { shopGraph } from '@/src/lib/shopSchema'
import { getServerShippingFees } from '@/src/lib/serverV2'
// the proposed title and meta behind the home headline switch (off: null, and HOME_TEXT stands)
import { PROPOSED_HOME_META } from '@/src/content/homeHeadline'

// ISR: render on the server (with the rails' cards in the HTML for SEO), cache, and
// revalidate every 5 min — also how soon a change on the dashboard's Home rails screen shows.
export const revalidate = 300

// Home metadata (overrides the layout defaults) — ported from Home.jsx's <Helmet>, in the URL's
// language (S-AR stage 1): English on /, Arabic on /ar, each self-canonical with hreflang to the
// other. The Arabic copy is the site's own (the root layout's Arabic defaults), not new wording.
const HOME_TEXT = {
  en: {
    title: 'Watchizer | Luxury Watches & Accessories in Egypt',
    description:
      'Shop luxury watches and accessories at Watchizer — premium timepieces, elegant designs and unbeatable prices across Egypt.',
    alt: 'Watchizer — Luxury Watches',
    locale: 'en_US',
  },
  ar: {
    title: 'Watchizer - أفخم الساعات والإكسسوارات | تسوق الآن بأسعار مميزة',
    description:
      'اكتشف أفخم الساعات والإكسسوارات في Watchizer. تسوق الآن أرقى الساعات الفاخرة بتصاميم أنيقة وجودة عالمية بأسعار تنافسية.',
    alt: 'Watchizer — ساعات فاخرة',
    locale: 'ar_EG',
  },
}

export async function generateMetadata() {
  const { urlLang } = await requestLang()
  const t = { ...HOME_TEXT[urlLang], ...(PROPOSED_HOME_META?.[urlLang] || {}) }
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
      siteName: 'Watchizer',
      type: 'website',
      images: [
        {
          url: 'https://watchizereg.com/og-image.jpg',
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
      images: ['https://watchizereg.com/og-image.jpg'],
    },
  }
}

// The rails and their cards come from core's `catalog/home` (C-1 stage 4 slice C) — not the whole
// catalogue. null on failure: the client shows its retry instead of rails.
export default async function HomePage() {
  const { urlLang } = await requestLang()
  const [home, campaign, menu, fees] = await Promise.all([
    getServerHome(),
    // never the home's failure: a product that cannot be read leaves the banner without price, stock and facts
    getServerV2ProductForHome(PRODUCT_SLUG).then((page) => (page ? bannerModel(page) : null), () => null),
    // nor is the menu: without it the category row draws each category's own picture and the browser fills the rest
    getServerV2Menu().then((body) => slimMenu(body?.data || null), () => null),
    // the dashboard's delivery fees, for the shipping policy in the structured data — never the home's failure
    getServerShippingFees().catch(() => null),
  ])
  return (
    <>
      {/* THE SHOP (2026-11-14, SEO Phase 1): Organization + OnlineStore with its return and shipping policies, the two
          stores as its physical places, and the WebSite — src/lib/shopGraph via shopSchema.js; the fees from the
          dashboard. It replaces a single Store node whose geo was never verified. */}
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: safeJsonLd(shopGraph(urlLang, fees)) }} />
      {/* The home's category row draws each category's picture from the menu (the fallback rule, item 35), so THIS
          page carries the menu — slimmed — in its server render, AS A PROP (2026-11-07): a HydrationBoundary here
          never reached the row's server render, because the header's menu component had created the query (empty)
          first, and React Query fills an existing query in an effect — which a server has none of. The browser's
          first render then had the menu from sessionStorage and the server's HTML did not: React #418 on the live
          home. HomeClient hands the prop to the row and seeds the query cache from it after mount, so the menu is
          still ready before the first hover. Every other page gets it from the browser's one request after load. */}
      {/* the sliders' data cut to what a card reads (src/lib/homeData.js): 788 KB of this page's HTML was the rest */}
      <HomeClient initialHome={slimCompatHome(home)} campaign={campaign} menu={menu} />
    </>
  )
}
