import SpinPage from '@/src/Components/Wheel/SpinPage'
import { getServerV2Wheel } from '@/src/lib/serverV2'
import { requestLang, alternatesFor, SITE } from '@/src/lib/requestLang'
import { WHEEL_BRAND } from '@/src/content/wheelBrand'

// /spin and /ar/spin — "Spin the wheel" (2026-11-07; its own shell since 2026-11-08; both shops, a twin since 2026-11-14,
// the words and the picture each shop's own in src/content/wheelBrand.js): the landing page
// of the prize game, for ads.
//
// A clean shareable address with NO shop chrome around it (app/(spin)/layout.jsx): the brand's mark, one "Home" pill
// and the wheel. Its own title, description and link preview (public/og-spin.jpg, 1200×630, each shop's own) so the link looks right on
// WhatsApp and Facebook — honest ones: not every spin wins (2026-11-09). NOINDEX, FOLLOW — an ad landing page, not a
// page for search — with hreflang between the two languages. The segments (with the colours the team chose on the
// dashboard), the switch and the terms come with the server render; the page never needs the menu and never fetches
// on the way in. Not in the main menu, by the developer's decision; its link is for the ads and the floating button.

const TEXT = WHEEL_BRAND.page

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
      siteName: WHEEL_BRAND.name,
      type: 'website',
      images: [{ url: `${SITE}${WHEEL_BRAND.ogImage}`, width: 1200, height: 630, alt: t.alt }],
    },
    twitter: { card: 'summary_large_image', title: t.title, description: t.description, images: [`${SITE}${WHEEL_BRAND.ogImage}`] },
  }
}

export default async function Page() {
  // never the page's failure: without an answer the page opens as "ended" (the button is not shown then either)
  const wheel = await getServerV2Wheel().then((body) => body?.data || null, () => null)
  return <SpinPage wheel={wheel} />
}
