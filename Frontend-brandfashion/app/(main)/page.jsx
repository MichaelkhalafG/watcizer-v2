import BfHome from '@/src/Components/Home/BfHome'
import { safeJsonLd } from '@/src/lib/safeJsonLd'
import { getServerV2Home, getServerV2Meta } from '@/src/lib/serverV2'
import { requestLang, alternatesFor, localePath, SITE } from '@/src/lib/requestLang'

// ISR: render on the server (with the rails' cards in the HTML for SEO), cache, and
// revalidate every 5 min — also how soon a change on the dashboard's Home rails screen shows.
export const revalidate = 300

// Home metadata (overrides the layout defaults) — ported from Home.jsx's <Helmet>, in the URL's
// language (S-AR stage 1): English on /, Arabic on /ar, each self-canonical with hreflang to the
// other. The Arabic copy is the site's own (the root layout's Arabic defaults), not new wording.
const HOME_TEXT = {
  en: {
    title: 'Brand Fashion | Luxury Watches & Accessories in Egypt',
    description:
      'Shop luxury watches and accessories at Brand Fashion — premium timepieces, elegant designs and unbeatable prices across Egypt.',
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
      locale: t.locale,
      images: [
        {
          url: 'https://michaelkhalaf.com/og-image.jpg',
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
      images: ['https://michaelkhalaf.com/og-image.jpg'],
    },
  }
}

// Organization/Store schema — emitted here (was App.jsx global) so it is in the
// homepage's initial HTML.
const storeJsonLd = {
  '@context': 'https://schema.org/',
  '@type': 'Store',
  name: 'Brand Fashion',
  url: 'https://michaelkhalaf.com',
  logo: 'https://michaelkhalaf.com/logo.svg',
  image: 'https://michaelkhalaf.com/og-image.jpg',
  description:
    'Discover a premium collection of luxury watches and fashion accessories at Brand Fashion. Shop exclusive timepieces with elegant designs and unbeatable prices in Egypt.',
  contactPoint: {
    '@type': 'ContactPoint',
    telephone: '+201283888490',
    contactType: 'customer service',
    areaServed: 'EG',
    availableLanguage: ['English', 'Arabic'],
  },
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
  return (
    <>
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: safeJsonLd(storeJsonLd) }}
      />
      <BfHome home={home} meta={meta} />
    </>
  )
}
