import './globals.css'
import { El_Messiri } from 'next/font/google'
import Providers from './providers'
import Analytics from './analytics'
import Toast from './toast'
import ScrollToTop from '@/src/Components/ScrollToTop/ScrollToTop'

// El Messiri (Arabic serif) — self-hosted by next/font, exposed as the CSS var
// --font-el-messiri that globals.css references (English stays on system Georgia).
const elMessiri = El_Messiri({
  subsets: ['arabic', 'latin'],
  weight: ['400', '500', '600', '700'],
  variable: '--font-el-messiri',
  display: 'swap',
})

// Static <head> parity with Frontend/index.html + the global SEO defaults from
// App.jsx (each page overrides title/description/canonical in Phase 3).
export const metadata = {
  metadataBase: new URL('https://watchizereg.com'),
  title: 'Watchizer - أفخم الساعات والإكسسوارات | تسوق الآن بأسعار مميزة',
  description:
    'اكتشف أفخم الساعات والإكسسوارات في Watchizer. تسوق الآن أرقى الساعات الفاخرة بتصاميم أنيقة وجودة عالمية بأسعار تنافسية.',
  keywords:
    "luxury watches, men's watches, women's watches, branded watches, best watch store Egypt, online watch shop, Rolex, Omega, TAG Heuer, Swiss watches, ساعات فاخرة, ساعات رجالي, ساعات نسائية, متجر ساعات في مصر",
  authors: [{ name: 'Watchizer - خبراء الساعات الفاخرة' }],
  robots: 'index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1',
  alternates: { canonical: 'https://watchizereg.com' },
  manifest: '/manifest.json',
  icons: {
    icon: [
      { url: '/logo.png?v=2', type: 'image/png' },
      { url: '/logo.svg?v=2', type: 'image/svg+xml' },
    ],
    apple: '/logo.png?v=2',
  },
  verification: { google: 'ySFGJkGj9eU9lzj8qAvuoqI9xt4Wcaswa_Q0Ke4Uoqg' },
  openGraph: {
    type: 'website',
    title: 'Watchizer - أفخم الساعات والإكسسوارات الفاخرة',
    description:
      'اكتشف مجموعة رائعة من الساعات الفاخرة والإكسسوارات العصرية في Watchizer. جودة استثنائية، تصاميم راقية، وعروض لا تُقاوم.',
    images: ['https://watchizereg.com/logo.svg'],
    url: 'https://watchizereg.com',
    siteName: 'Watchizer',
    locale: 'ar_EG',
  },
  twitter: {
    card: 'summary_large_image',
    title: 'Watchizer - أفخم الساعات والإكسسوارات الفاخرة',
    description:
      'تسوق أحدث موديلات الساعات الفاخرة والإكسسوارات الراقية بأفضل الأسعار فقط على Watchizer.',
    images: ['https://watchizereg.com/logo.svg'],
    site: '@Watchizer',
    creator: '@Watchizer',
  },
}

export const viewport = {
  width: 'device-width',
  initialScale: 1,
  themeColor: '#000000',
}

export default function RootLayout({ children }) {
  // Default lang/dir = English/LTR; <HtmlDirSync/> (in Providers) flips to Arabic
  // RTL on the client from uiStore.language — same behaviour as App.jsx:164-165.
  return (
    <html lang="en" dir="ltr" className={elMessiri.variable}>
      <body>
        <Providers>
          {children}
          {/* Global chrome (all routes incl. auth) that does NOT read the catalog.
              The cart drawer moved into the (main) layout so it sits below the
              catalog HydrationBoundary. */}
          <Toast />
          <ScrollToTop />
        </Providers>
        <Analytics />
      </body>
    </html>
  )
}
