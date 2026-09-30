import HomeClient from './HomeClient'
import { safeJsonLd } from '@/src/lib/safeJsonLd'
import { getServerHome } from '@/src/lib/serverCatalog'
import { requestLang, alternatesFor, localePath, SITE } from '@/src/lib/requestLang'

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
