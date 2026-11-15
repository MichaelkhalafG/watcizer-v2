import { getServerV2Menu, getServerShippingFees, getServerV2Meta } from '@/src/lib/serverV2'
import { getServerTables } from '@/src/lib/serverCatalog'
import { SITE } from '@/src/lib/requestLang'
import { STORES_LD } from '@/src/content/trustPages'
import { directoryOf } from '@/src/lib/menuLinks'
import { displayName } from '@/src/lib/listingWords'
import { INSPECT_FULL } from '@/src/content/inspection'
import { dealerBrands } from '@/src/lib/dealers'

// /llms.txt (2026-11-14, SEO Phase 1 item 12) — a short, factual page about the shop for AI tools that read it (Google
// says it does not use it; others may). A TWIN: the facts are each shop's own (STORES_LD in its trustPages.js, its
// menu and its dashboard's delivery fees). Only what the shop's pages state: founded 2013 (BUSINESS FACTS 1), delivery
// within 24 hours (fact 5, Michael 2026-11-14), the dashboard's fee range, cash on delivery, the returns of fact 6. No
// free-shipping threshold. Refreshed hourly; core failing leaves the lists out, never the page.
export const revalidate = 3600

const txt = (v) => displayName((v && typeof v === 'object' ? v.en || Object.values(v)[0] : v) || '')

export async function GET() {
  const [menu, tables, fees, meta] = await Promise.all([
    getServerV2Menu().then((body) => body?.data || null, () => null),
    getServerTables().catch(() => null),
    getServerShippingFees().catch(() => null),
    getServerV2Meta().then((body) => body?.data ?? body ?? null, () => null),
  ])
  const { categories, brands: allBrands } = directoryOf(menu, tables)
  // never a brand whose products are all kept from search engines, nor one whose only products are the restricted
  // Rolex (2026-11-14, core's SearchExposure, `meta.search`); when core cannot say, no brand is named at all
  const exposure = meta?.search
  const left = new Set([...(exposure?.hidden_brand_ids || []), ...(exposure?.restricted_brand_ids || [])].map(String))
  const brands = exposure ? allBrands.filter((b) => !left.has(String(b.id))) : []
  const L = []
  const p = (s = '') => L.push(s)
  p(`# ${STORES_LD.name}`)
  p()
  p(`> ${STORES_LD.summary} Founded in 2013. Delivery across Egypt within 24 hours, cash on delivery, and free returns within 14 days.`)
  p()
  p('## About')
  p('- Founded: 2013')
  p(`- Website: ${SITE} (English) and ${SITE}/ar (Arabic)`)
  p(`- Customer service: ${STORES_LD.phone} (phone and WhatsApp), ${STORES_LD.email}`)
  for (const s of STORES_LD.stores) p(`- Store: ${[s.address.streetAddress, s.address.addressLocality].filter(Boolean).join(', ')}, Egypt`)
  if (STORES_LD.sameAs?.length) p(`- Social: ${STORES_LD.sameAs.join(', ')}`)
  p()
  p('## Ordering')
  p(`- Delivery: to every governorate of Egypt within 24 hours${fees ? `; the fee depends on the governorate (EGP ${fees.min}–${fees.max}) and is shown at checkout` : '; the fee depends on the governorate and is shown at checkout'}.`)
  p('- Payment: cash on delivery, and the other methods shown at checkout.')
  p(`- Inspection: ${INSPECT_FULL.en}`)
  p('- Returns and exchanges: within 14 days of delivery; the shop pays the return shipping; the refund is made by the original payment method within 24 hours of the item reaching the shop.')
  p(`- Warranty: up to 3 years depending on the watch; most watches carry the brand agent's warranty, a few the store's own, as each product page states; how it works: ${SITE}/warranty`)
  // the brands this shop is an authorized dealer for (a dashboard switch per brand, 2026-11-14); none listed = no claim
  const dealers = dealerBrands(meta)
  if (dealers.length) p(`- Authorized dealer for: ${dealers.map((b) => b.name?.en || b.slug).join(', ')}`)
  p(`- Shipping and delivery: ${SITE}/shipping`)
  p(`- Returns and exchanges: ${SITE}/returns`)
  p(`- Terms: ${SITE}/terms-and-conditions`)
  p(`- Questions and answers: ${SITE}/faq`)
  p(`- Contact: ${SITE}/contact-us`)
  p(`- About the shop: ${SITE}/about-us`)
  if (categories.length) {
    p()
    p('## Categories')
    for (const c of categories) {
      p(`- [${txt(c.name)}](${SITE}${c.href})${c.children.length ? `: ${c.children.map((s) => `[${txt(s.name)}](${SITE}${s.href})`).join(', ')}` : ''}`)
    }
  }
  if (brands.length) {
    p()
    p('## Brands')
    p(brands.map((b) => `[${txt(b.name)}](${SITE}${b.href})`).join(', '))
  }
  p()
  p('## Articles')
  p(`- ${SITE}/blogs`)
  return new Response(L.join('\n') + '\n', {
    headers: { 'Content-Type': 'text/plain; charset=utf-8', 'Cache-Control': 'public, max-age=3600' },
  })
}
