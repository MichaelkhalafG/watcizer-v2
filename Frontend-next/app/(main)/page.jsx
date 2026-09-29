import HomeClient from './HomeClient'
import { safeJsonLd } from '@/src/lib/safeJsonLd'
import { getServerHome } from '@/src/lib/serverCatalog'

// ISR: render on the server (with the rails' cards in the HTML for SEO), cache, and
// revalidate every 5 min — also how soon a change on the dashboard's Home rails screen shows.
export const revalidate = 300

// Home metadata (overrides the layout defaults) — ported from Home.jsx's <Helmet>.
// The layout's OpenGraph/Twitter defaults are Arabic (ar_EG); override them here so
// the social cards match this page's English title/description (en_US).
const HOME_TITLE = 'Watchizer | Luxury Watches & Accessories in Egypt'
const HOME_DESC =
  'Shop luxury watches and accessories at Watchizer — premium timepieces, elegant designs and unbeatable prices across Egypt.'

export const metadata = {
  title: HOME_TITLE,
  description: HOME_DESC,
  alternates: { canonical: 'https://watchizereg.com/' },
  openGraph: {
    title: HOME_TITLE,
    description: HOME_DESC,
    url: 'https://watchizereg.com/',
    siteName: 'Watchizer',
    type: 'website',
    locale: 'en_US',
    images: [
      {
        url: 'https://watchizereg.com/og-image.jpg',
        width: 1200,
        height: 630,
        alt: 'Watchizer — Luxury Watches',
      },
    ],
  },
  twitter: {
    card: 'summary_large_image',
    title: HOME_TITLE,
    description: HOME_DESC,
    images: ['https://watchizereg.com/og-image.jpg'],
  },
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
  address: {
    '@type': 'PostalAddress',
    streetAddress: 'اركديا مول . كورنيش النيل . امتداد ماسبيرو',
    addressLocality: 'Cairo',
    addressRegion: 'Cairo Governorate',
    addressCountry: 'EG',
  },
  geo: { '@type': 'GeoCoordinates', latitude: 30.0444, longitude: 31.2357 },
  openingHoursSpecification: [
    {
      '@type': 'OpeningHoursSpecification',
      dayOfWeek: ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
      opens: '10:00',
      closes: '22:00',
    },
  ],
  sameAs: ['https://www.facebook.com/watchizer', 'https://www.instagram.com/watchizer'],
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
  const home = await getServerHome()
  return (
    <>
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: safeJsonLd(storeJsonLd) }}
      />
      <HomeClient initialHome={home} />
    </>
  )
}
