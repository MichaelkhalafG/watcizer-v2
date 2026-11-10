import CampaignPage from '@/src/Components/Promo/CampaignPage'
import { CAMPAIGN_META } from '@/src/content/campaignPages'
import { requestLang, alternatesFor } from '@/src/lib/requestLang'
import { getServerV2Menu } from '@/src/lib/serverV2'
import { idleMenu } from '@/src/lib/menuData'

// /flash-sale and /ar/flash-sale — the Flash Sale page (Part 2.2, 2026-11-05). A TWIN. See hot-deals/page.jsx: the
// same page in the storm identity, permanent and indexable, with its idle state's categories from this server render.

export async function generateMetadata() {
  const { urlLang } = await requestLang()
  const meta = CAMPAIGN_META.flash_sale
  const t = meta[urlLang] ?? meta.en
  return {
    title: t.title,
    description: t.description,
    alternates: alternatesFor(meta.path, urlLang),
    openGraph: { title: t.title, description: t.description, type: 'website' },
  }
}

export default async function Page() {
  const menu = await getServerV2Menu().then((body) => idleMenu(body?.data || null), () => null)
  return <CampaignPage presentation="flash_sale" menu={menu} />
}
