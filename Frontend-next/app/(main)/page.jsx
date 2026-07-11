import HomeClient from './HomeClient'

// ISR: render on the server (with catalog data in the HTML for SEO), cache, and
// revalidate every 5 min — matching the products query staleTime.
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
        url: 'https://watchizereg.com/logo.svg',
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
  image: 'https://watchizereg.com/logo.svg',
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

// The catalog is prefetched + hydrated by the (main) layout (inside the
// HydrationBoundary), so this page just renders the client tree + the homepage
// Store JSON-LD.
export default function HomePage() {
  return (
    <>
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: JSON.stringify(storeJsonLd) }}
      />
      <HomeClient />
    </>
  )
}
