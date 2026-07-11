import './globals.css'
import { cookies } from 'next/headers'
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

export default async function RootLayout({ children }) {
  // Read the wz-lang cookie (written by uiStore.setLanguage) so the FIRST server
  // paint already matches the user's chosen language — no English flash on hard
  // reload, and the client store is initialised from the same cookie in Providers
  // so there is no hydration mismatch. <HtmlDirSync/> still keeps <html> in sync
  // on subsequent client-side language toggles.
  //
  // TRADEOFF: reading cookies() in the ROOT layout opts the whole app OUT of
  // static rendering — home/blog routes become dynamic (ƒ) instead of static (○).
  const lang = (await cookies()).get('wz-lang')?.value === 'ar' ? 'ar' : 'en'
  const dir = lang === 'ar' ? 'rtl' : 'ltr'
  return (
    <html lang={lang} dir={dir} className={elMessiri.variable}>
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
