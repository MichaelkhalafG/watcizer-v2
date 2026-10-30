import { notFound } from 'next/navigation'
import { getServerV2Product } from '@/src/lib/serverV2'
import { getServerTables } from '@/src/lib/serverCatalog'
import { requestLang, alternatesFor, localePath, SITE } from '@/src/lib/requestLang'
import { safeJsonLd } from '@/src/lib/safeJsonLd'
import { experienceModel, productSeoFor } from '@/src/lib/experienceSpecs'
import { COPY, PATH, PRODUCT_SLUG, ASSETS } from '@/src/content/grandPrix1513477'
import GrandPrixExperience from '@/src/Components/Experience/GrandPrixExperience'
import { Cormorant_Garamond } from 'next/font/google'

// The page's Latin display face, self-hosted by next/font and loaded on this route only; Arabic display is the
// site's El Messiri (the root layout's --font-el-messiri).
const display = Cormorant_Garamond({ subsets: ['latin'], weight: ['300', '400', '500'], style: ['normal', 'italic'], variable: '--font-exp-display', display: 'swap' })

/*
 * /experience/hugo-boss-grand-prix-1513477 and /ar/experience/… — THE HERO PRODUCT PAGE (2026-10-27, Watchizer
 * only; no Brand Fashion twin, see Frontend-brandfashion/TWINS.txt).
 *
 * One page for one watch, reached from a home banner. The product — price, stock, photos, every spec — is
 * read from core's v2 answer for the product's slug at render, as the product page reads it (serverV2), and
 * re-read every five minutes (ISR, the product page's cadence). A v2 "no such product", or an answer that is
 * not product 26101, is a 404; a core that could not answer propagates to the error boundary (a 5xx, so a
 * crawler comes back rather than dropping the page).
 *
 * Metadata follows the URL's language (S-AR): English bare, Arabic under /ar, each self-canonical with
 * hreflang to the other and x-default English — alternatesFor, as every two-language page here. The Product
 * JSON-LD is the PRODUCT PAGE'S, built by the same pipeline over the same answer (productSeoFor), so the two
 * pages cannot disagree about the offer; its `offers.url` is the product page. This page's own address is in
 * its BreadcrumbList.
 *
 * Not in the sitemap today: core's CompatLocaleSitemap lists the shop's pages, and adding this one there is a
 * core change and a core deploy — the decision is the developer's (see the hero page record).
 */
export const revalidate = 300

const tablesOrEmpty = async () => {
  try {
    return await getServerTables()
  } catch {
    return {}
  }
}

export async function generateMetadata() {
  const page = await getServerV2Product(PRODUCT_SLUG)
  const model = page ? experienceModel(page) : null
  if (!model) notFound()
  const { urlLang } = await requestLang()
  const t = COPY[urlLang]
  // the social image is the opening poster rendered from the brand's model (public/experience/)
  const image = `${SITE}${ASSETS.poster}`
  const url = `${SITE}${localePath(PATH, urlLang)}`
  return {
    title: t.metaTitle,
    description: t.metaDescription,
    alternates: alternatesFor(PATH, urlLang),
    openGraph: {
      title: t.metaTitle,
      description: t.metaDescription,
      url,
      siteName: 'Watchizer',
      images: [{ url: image }],
    },
    other: { 'og:type': 'product' },
    twitter: { card: 'summary_large_image', title: t.metaTitle, description: t.metaDescription, images: [image] },
  }
}

export default async function ExperiencePage() {
  const page = await getServerV2Product(PRODUCT_SLUG)
  const model = page ? experienceModel(page) : null
  if (!model) notFound()
  const { urlLang } = await requestLang()
  const tables = await tablesOrEmpty()
  const seo = productSeoFor(page, urlLang, tables)
  const t = COPY[urlLang]
  const here = `${SITE}${localePath(PATH, urlLang)}`
  const breadcrumbLd = {
    '@context': 'https://schema.org/',
    '@type': 'BreadcrumbList',
    itemListElement: [
      { '@type': 'ListItem', position: 1, name: urlLang === 'ar' ? 'الرئيسية' : 'Home', item: `${SITE}${localePath('/', urlLang)}` },
      { '@type': 'ListItem', position: 2, name: model.title[urlLang] || model.title.en, item: `${SITE}${localePath(model.productPath, urlLang)}` },
      { '@type': 'ListItem', position: 3, name: t.metaTitle.replace(/ \| Watchizer$/, ''), item: here },
    ],
  }

  return (
    <>
      {seo ? <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: safeJsonLd(seo.productLd) }} /> : null}
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: safeJsonLd(breadcrumbLd) }} />
      {/* the first model and the studio map start downloading with the page's scripts, at low priority (after the
          poster): the stage's loader then finds them in flight instead of asking for them seconds later (2026-10-29) */}
      <link rel="preload" href={ASSETS.glbLite} as="fetch" crossOrigin="anonymous" fetchPriority="low" />
      <link rel="preload" href={ASSETS.env} as="fetch" crossOrigin="anonymous" fetchPriority="low" />
      <GrandPrixExperience model={model} fontClass={display.variable} />
    </>
  )
}
