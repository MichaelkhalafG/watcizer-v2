import CampaignPage from '@/src/Components/Promo/CampaignPage'
import { CAMPAIGN_META } from '@/src/content/campaignPages'
import { requestLang, alternatesFor } from '@/src/lib/requestLang'
import { getServerV2Menu } from '@/src/lib/serverV2'
import { idleMenu } from '@/src/lib/menuData'

// /hot-deals and /ar/hot-deals — the Hot Deals page (Part 2.2, 2026-11-05). A TWIN.
//
// A PERMANENT page (the developer's decision, 2026-11-05): it is up all year and only its products change, so it
// is INDEXABLE — self-canonical in each language with hreflang, like every other page — and when no campaign of
// its kind is live it shows a designed "nothing running" state, never a made-up countdown. Its title and
// description are each shop's own words (src/content/campaignPages.js) and describe the page, not one campaign.
//
// Which campaign is live is seeded into the page by the (main) layout, so the HTML a crawler reads carries the
// page's real state; the products and the countdown are then kept current in the browser.
//
// The "nothing running" state lists the shop's categories; they come from THIS server render (the menu's top level,
// slimmed to a name and a slug — 2026-11-07), so the server's HTML and the browser's first render list the same
// ones. Before, the browser read them from a copy only it had (React #418 on the live site).

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

export default async function Page() {
  // never the page's failure: without the menu the idle state simply lists no categories
  const menu = await getServerV2Menu().then((body) => idleMenu(body?.data || null), () => null)
  return <CampaignPage presentation="hot_deals" menu={menu} />
}
