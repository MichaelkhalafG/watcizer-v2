import './globals.css'
import ReactDOM from 'react-dom'
import { ASSET_BASE } from '@/src/lib/env'
import { requestLang } from '@/src/lib/requestLang'
import { elMessiriClass } from './fonts/elMessiri'
import Providers from './providers'
import Analytics from './analytics'
import Toast from './toast'
import ScrollToTop from '@/src/Components/ScrollToTop/ScrollToTop'
import OfflineBanner from '@/src/Components/Offline/OfflineBanner'

// Watchizer's face (spec v2.11 §5, Michael's decision of 2026-10-17): English on system Georgia, Arabic on
// El Messiri — the same pair Frontend-next loads, so the two shops share one voice. El Messiri is self-hosted
// from app/fonts/ (2026-11-05: next/font/local, no request to Google at build time) and exposed as
// --font-el-messiri; globals.css puts it on everything under [dir='rtl']. Same weights, `swap` and variable name.

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
  // <meta name="color-scheme" content="only light">: the half of Chrome's Auto-Dark opt-out that lives in
  // <head> (the other half is html{color-scheme: only light} in globals.css). Light-only, declared; not a theme.
  colorScheme: 'only light',
}

// THE PHOTOS' HOST IS CONNECTED TO BEFORE THE FIRST PHOTO IS FOUND (the performance pass, 2026-11-05). Every product
// photo and banner is on the asset host, another origin than the page: the browser opened that connection (DNS, TCP,
// TLS — 100 to 300 ms on a phone) only when it reached the first <img> in the HTML, and the page's largest picture
// waited behind it. One <link rel="preconnect"> in <head> starts it with the page. Nothing is downloaded by it.
let ASSET_ORIGIN = ''
try {
  ASSET_ORIGIN = ASSET_BASE ? new URL(ASSET_BASE).origin : ''
} catch {
  // a malformed value: no hint, the page works as before
}

export default async function RootLayout({ children }) {
  if (ASSET_ORIGIN) ReactDOM.preconnect(ASSET_ORIGIN)
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
    <html lang={lang} dir={dir} className={elMessiriClass}>
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
