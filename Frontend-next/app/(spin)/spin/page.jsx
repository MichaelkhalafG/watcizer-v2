import SpinPage from '@/src/Components/Wheel/SpinPage'
import { getServerV2Wheel } from '@/src/lib/serverV2'
import { requestLang, alternatesFor, SITE } from '@/src/lib/requestLang'

// /spin and /ar/spin — "Spin the wheel" (2026-11-07; its own shell since 2026-11-08, WATCHIZER ONLY): the landing page
// of the prize game, for ads.
//
// A clean shareable address with NO shop chrome around it (app/(spin)/layout.jsx): the brand's mark, one "Home" pill
// and the wheel. Its own title, description and link preview (public/og-spin.jpg, 1200×630) so the link looks right on
// WhatsApp and Facebook — honest ones: not every spin wins (2026-11-09). NOINDEX, FOLLOW — an ad landing page, not a
// page for search — with hreflang between the two languages. The segments (with the colours the team chose on the
// dashboard), the switch and the terms come with the server render; the page never needs the menu and never fetches
// on the way in. Not in the main menu, by the developer's decision; its link is for the ads and the floating button.

const TEXT = {
  en: {
    title: 'Spin the wheel, try your luck | Watchizer',
    description: 'Spin the Watchizer wheel once with your mobile number: free gifts, free shipping and discount codes are on it — not every spin wins.',
    alt: 'Watchizer — Spin the wheel',
    locale: 'en_US',
  },
  ar: {
    title: 'أدِر العجلة وجرّب حظك | Watchizer',
    description: 'أدِر عجلة Watchizer مرة واحدة برقم موبايلك: عليها هدايا مجانية وشحن مجاني وأكواد خصم — وليست كل لفّة تربح.',
    alt: 'Watchizer — أدِر العجلة',
    locale: 'ar_EG',
  },
}

export async function generateMetadata() {
  const { urlLang } = await requestLang()
  const t = TEXT[urlLang] ?? TEXT.en
  const alternates = alternatesFor('/spin', urlLang)
  return {
    title: t.title,
    description: t.description,
    alternates,
    robots: { index: false, follow: true },
    openGraph: {
      title: t.title,
      description: t.description,
      url: alternates.canonical,
      siteName: 'Watchizer',
      type: 'website',
      locale: t.locale,
      images: [{ url: `${SITE}/og-spin.jpg`, width: 1200, height: 630, alt: t.alt }],
    },
    twitter: { card: 'summary_large_image', title: t.title, description: t.description, images: [`${SITE}/og-spin.jpg`] },
  }
}

export default async function Page() {
  // never the page's failure: without an answer the page opens as "ended" (the button is not shown then either)
  const wheel = await getServerV2Wheel().then((body) => body?.data || null, () => null)
  return <SpinPage wheel={wheel} />
}
