// AUTHORIZED DEALER, PER BRAND (Michael, 2026-11-14) — a TWIN. Core's list is v2 meta's
// `storefront.settings.authorized_brands` (brand slugs the team switched on, per shop; OFF by default). Every visible
// "authorized dealer" / «وكيل معتمد» on the shop comes from here or from a product's own `authorized_dealer` (core:
// false on any product the hide-from-search rule matches). No structured data carries the claim.

const pick = (name, lang) => (typeof name === 'string' ? name : name?.[lang] || name?.en || name?.ar || '')

/** The brands this shop is an authorized dealer for, with their names: [{ slug, name: {en, ar} }]. */
export function dealerBrands(meta) {
  const slugs = Array.isArray(meta?.storefront?.settings?.authorized_brands) ? meta.storefront.settings.authorized_brands : []
  const brands = Array.isArray(meta?.brands) ? meta.brands : []
  return slugs
    .map((slug) => {
      const b = brands.find((x) => x?.slug === slug)
      return b ? { slug, name: typeof b.name === 'object' && b.name ? b.name : { en: String(b.name || slug), ar: String(b.name || slug) } } : null
    })
    .filter(Boolean)
}

/** Is this brand slug on the list? */
export const isDealerFor = (meta, slug) => dealerBrands(meta).some((b) => b.slug === slug)

/** "Watchizer is an authorized dealer for Hugo Boss and Tommy Hilfiger." — '' when the list is empty. */
export function dealerSentence(brands, lang, shop) {
  if (!brands?.length) return ''
  const names = brands.map((b) => pick(b.name, lang))
  const ar = lang === 'ar'
  const list = names.length === 1 ? names[0] : ar ? `${names.slice(0, -1).join('، ')} و${names[names.length - 1]}` : `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`
  return ar ? `${shop} وكيل معتمد لـ${list}.` : `${shop} is an authorized dealer for ${list}.`
}

/** The product page's line beside the warranty. */
export const DEALER_LABEL = { en: 'Authorized dealer', ar: 'وكيل معتمد' }
