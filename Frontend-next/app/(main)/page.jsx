import { QueryClient, dehydrate, HydrationBoundary } from '@tanstack/react-query'
import HomeClient from './HomeClient'
import { getServerV2Menu } from '@/src/lib/serverV2'
import { slimMenu } from '@/src/lib/menuData'
import { slimCompatHome } from '@/src/lib/homeData'
import { STOREFRONT_CODE } from '@/src/lib/env'
import { safeJsonLd } from '@/src/lib/safeJsonLd'
import { getServerHome } from '@/src/lib/serverCatalog'
// the campaign banner's live price, stock and facts (2026-10-29, Watchizer only): one cached read of the product
import { getServerV2ProductForHome } from '@/src/lib/serverV2'
import { bannerModel } from '@/src/lib/experienceSpecs'
import { PRODUCT_SLUG } from '@/src/content/grandPrix1513477'
import { requestLang, alternatesFor, localePath, SITE } from '@/src/lib/requestLang'
// the shop's real accounts, from the one place the contact page reads them (2026-10-19)
import { FACEBOOK, INSTAGRAM, TIKTOK } from '@/src/content/trustPages'
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
      locale: t.locale,
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

// The two branches, worded as the CONTACT PAGE reads them (2026-10-19, the developer's instruction: this markup
// named one address and there are two). English, because this one object is emitted on both the English and the
// Arabic home and every other field of it is English — the street used to be Arabic ("اركديا مول . كورنيش النيل .
// امتداد ماسبيرو", a spelling and a punctuation the contact page does not use) on an otherwise English node.
const ARKADIA = {
  '@type': 'PostalAddress',
  streetAddress: 'Arkadia Mall, Corniche El Nil, Maspero extension',
  addressLocality: 'Cairo',
  addressRegion: 'Cairo Governorate',
  addressCountry: 'EG',
}
// Only what the contact page states: no region, and no opening hours — the Heliopolis branch's are not known.
const HELIOPOLIS = {
  '@type': 'PostalAddress',
  streetAddress: '90 El-Thawra Street',
  addressLocality: 'Heliopolis',
  addressCountry: 'EG',
}

// Organization/Store schema — emitted here (was App.jsx global) so it is in the
// homepage's initial HTML.
const storeJsonLd = {
  '@context': 'https://schema.org/',
  '@type': 'Store',
  name: 'Watchizer - Luxury Watches & Accessories',
  url: 'https://watchizereg.com',
  logo: 'https://watchizereg.com/logo.svg',
  image: 'https://watchizereg.com/og-image.jpg',
  description:
    'Discover a premium collection of luxury watches and fashion accessories at Watchizer. Shop exclusive timepieces with elegant designs and unbeatable prices in Egypt.',
  // `address` stays the Arkadia branch: it is the one this `geo` and these opening hours belong to, and a
  // LocalBusiness's primary address is what Google reads. `location` names both branches.
  address: ARKADIA,
  location: [
    { '@type': 'Place', name: 'Arkadia Mall branch', address: ARKADIA },
    { '@type': 'Place', name: 'Heliopolis branch', address: HELIOPOLIS },
  ],
  geo: { '@type': 'GeoCoordinates', latitude: 30.0444, longitude: 31.2357 },
  openingHoursSpecification: [
    {
      '@type': 'OpeningHoursSpecification',
      dayOfWeek: ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
      opens: '10:00',
      // the client's hours (Michael, 2026-10-29): every day 10:00 to midnight, written 23:59 (Google's form)
      closes: '23:59',
    },
  ],
  // The accounts the site actually links (2026-10-19, the developer). This used to name a different pair — a bare
  // `/watchizer` vanity name on each network — which matched neither the header, the footer nor the contact page.
  // Imported, never retyped, so the markup and the site stay the same two accounts.
  sameAs: [FACEBOOK, INSTAGRAM, TIKTOK],
  contactPoint: {
    '@type': 'ContactPoint',
    telephone: '+201551096234',
    contactType: 'customer service',
    areaServed: 'EG',
    availableLanguage: ['English', 'Arabic'],
  },
}

// The rails and their cards come from core's `catalog/home` (C-1 stage 4 slice C) — not the whole
// catalogue. null on failure: the client shows its retry instead of rails.
export default async function HomePage() {
  const [home, campaign, menu] = await Promise.all([
    getServerHome(),
    // never the home's failure: a product that cannot be read leaves the banner without price, stock and facts
    getServerV2ProductForHome(PRODUCT_SLUG).then((page) => (page ? bannerModel(page) : null), () => null),
    // nor is the menu: without it the category row draws each category's own picture and the browser fills the rest
    getServerV2Menu().then((body) => slimMenu(body?.data || null), () => null),
  ])
  const menuClient = new QueryClient()
  if (menu) menuClient.setQueryData(['menu', STOREFRONT_CODE], menu)
  return (
    <>
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: safeJsonLd(storeJsonLd) }}
      />
      {/* The home's category row draws each category's picture from the menu (the fallback rule, item 35), so THIS
          page carries the menu — slimmed — in its server render; every other page gets it from the browser's one
          request after load (usePromo.useMenu). */}
      <HydrationBoundary state={dehydrate(menuClient)}>
        {/* the sliders' data cut to what a card reads (src/lib/homeData.js): 788 KB of this page's HTML was the rest */}
        <HomeClient initialHome={slimCompatHome(home)} campaign={campaign} />
      </HydrationBoundary>
    </>
  )
}
