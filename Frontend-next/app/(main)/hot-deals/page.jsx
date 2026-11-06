import CampaignPage from '@/src/Components/Promo/CampaignPage'
import { CAMPAIGN_META } from '@/src/content/campaignPages'
import { requestLang, alternatesFor } from '@/src/lib/requestLang'

// /hot-deals and /ar/hot-deals — the Hot Deals page (Part 2.2, 2026-11-05). A TWIN.
//
// A PERMANENT page (the developer's decision, 2026-11-05): it is up all year and only its products change, so it
// is INDEXABLE — self-canonical in each language with hreflang, like every other page — and when no campaign of
// its kind is live it shows a designed "nothing running" state, never a made-up countdown. Its title and
// description are each shop's own words (src/content/campaignPages.js) and describe the page, not one campaign.
//
// Which campaign is live is seeded into the page by the (main) layout, so the HTML a crawler reads carries the
// page's real state; the products and the countdown are then kept current in the browser.

export async function generateMetadata() {
  const { urlLang } = await requestLang()
  const meta = CAMPAIGN_META.hot_deals
  const t = meta[urlLang] ?? meta.en
  return {
    title: t.title,
    description: t.description,
    alternates: alternatesFor(meta.path, urlLang),
    openGraph: { title: t.title, description: t.description, type: 'website' },
  }
}

export default function Page() {
  return <CampaignPage presentation="hot_deals" />
}
