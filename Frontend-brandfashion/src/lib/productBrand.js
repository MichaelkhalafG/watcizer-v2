import { txt } from './cardData'

/**
 * The Brand of a product's structured data (2026-11-24, Merchant listings asked for the brand). Named for every product
 * whose brand names a maker; never for a product the search rules match (`p.search`: hidden, or a restricted Rolex - the
 * 2026-11-14 rule), and never "Generic" (no maker named; Google asks not to send it as a brand - D12).
 */
export const GENERIC_BRAND = /^(generic|غير محدد)$/i

export function brandLd(p) {
  const name = String(txt(p?.brand?.name, 'en') || '').trim()
  if (!name || p?.search || GENERIC_BRAND.test(name)) return undefined
  return { '@type': 'Brand', name }
}
