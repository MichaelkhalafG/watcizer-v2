import './globals.css'
import { requestLang } from '@/src/lib/requestLang'
import { El_Messiri } from 'next/font/google'
import Providers from './providers'
import Analytics from './analytics'
import Toast from './toast'
import ScrollToTop from '@/src/Components/ScrollToTop/ScrollToTop'
import OfflineBanner from '@/src/Components/Offline/OfflineBanner'

// Watchizer's face (spec v2.11 §5, Michael's decision of 2026-10-17): English on system Georgia, Arabic on
// El Messiri — the same pair Frontend-next loads, so the two shops share one voice. El Messiri is self-hosted
// by next/font and exposed as --font-el-messiri; globals.css puts it on everything under [dir='rtl'].
const elMessiri = El_Messiri({
  subsets: ['arabic', 'latin'],
  weight: ['400', '500', '600', '700'],
  variable: '--font-el-messiri',
  display: 'swap',
})

// Static <head> parity with Frontend/index.html + the global SEO defaults from
// App.jsx (each page overrides title/description/canonical in Phase 3).
export const metadata = {
  metadataBase: new URL('https://michaelkhalaf.com'),
  title: 'Brand Fashion',
  description:
    'اكتشف أفخم الساعات والإكسسوارات في Brand Fashion. تسوق الآن أرقى الساعات الفاخرة بتصاميم أنيقة وجودة عالمية بأسعار تنافسية.',
  keywords:
    "luxury watches, men's watches, women's watches, branded watches, best watch store Egypt, online watch shop, Rolex, Omega, TAG Heuer, Swiss watches, ساعات فاخرة, ساعات رجالي, ساعات نسائية, متجر ساعات في مصر",
  authors: [{ name: 'Brand Fashion' }],
  robots: 'index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1',
  alternates: { canonical: 'https://michaelkhalaf.com' },
  manifest: '/manifest.json',
  // NOTE: no `icons` key here on purpose. The icons come from the Next.js file
  // conventions app/favicon.ico + app/icon.png + app/apple-icon.png, which Next
  // hashes and injects itself. Declaring them here too used to point the browser
  // at /logo.png — the full LANDSCAPE lockup, which Google rejects/letterboxes as
  // a favicon — while app/favicon.ico was still the create-next-app default, so
  // Google showed the Next.js mark. The file-convention assets are the square
  // Brand Fashion "BF" monogram, copied from new branding/design/brand-fashion-logo/ (spec §22.4).
  // The link-preview image (2026-09-27): a 1200×630 JPG. It was logo.svg, and Facebook,
  // WhatsApp, X and LinkedIn refuse SVG, so a shared link showed no picture at all. Replace
  // public/og-image.jpg with new artwork under the same name and nothing here changes.
  openGraph: {
    type: 'website',
    title: 'Brand Fashion',
    description:
      'اكتشف مجموعة رائعة من الساعات الفاخرة والإكسسوارات العصرية في Brand Fashion. جودة استثنائية، تصاميم راقية، وعروض لا تُقاوم.',
    images: [
      {
        url: 'https://michaelkhalaf.com/og-image.jpg',
        width: 1200,
        height: 630,
        alt: 'Brand Fashion',
      },
    ],
    url: 'https://michaelkhalaf.com',
    siteName: 'Brand Fashion',
    locale: 'ar_EG',
  },
  twitter: {
    card: 'summary_large_image',
    title: 'Brand Fashion',
    description:
      'تسوق أحدث موديلات الساعات الفاخرة والإكسسوارات الراقية بأفضل الأسعار فقط على Brand Fashion.',
    images: ['https://michaelkhalaf.com/og-image.jpg'],
  },
}

export const viewport = {
  width: 'device-width',
  initialScale: 1,
  themeColor: '#111111',
}

export default async function RootLayout({ children }) {
  // The request's language (S-AR stage 1): Arabic under /ar/, else the wz-lang cookie
  // (middleware.js). It sets <html lang/dir> AND starts the per-request UI store in that language,
  // so the page's server HTML is already in it — Arabic pages exist for Google, and there is no
  // English flash or hydration mismatch. <HtmlDirSync/> keeps <html> in sync on later toggles.
  //
  // TRADEOFF: reading the request in the ROOT layout opts the whole app OUT of
  // static rendering — home/blog routes become dynamic (ƒ) instead of static (○).
  const { lang } = await requestLang()
  const dir = lang === 'ar' ? 'rtl' : 'ltr'
  return (
    <html lang={lang} dir={dir} className={elMessiri.variable}>
      <body>
        <Providers language={lang}>
          {children}
          {/* Global chrome (all routes incl. auth) that does NOT read the catalog.
              The cart drawer moved into the (main) layout so it sits below the
              catalog HydrationBoundary. */}
          <Toast />
          <OfflineBanner />
          <ScrollToTop />
        </Providers>
        <Analytics />
      </body>
    </html>
  )
}
