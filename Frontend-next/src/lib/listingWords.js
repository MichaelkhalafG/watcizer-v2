// A listing page's title and fallback description, in words that are TRUE of its products (2026-11-14, SEO Phase 1,
// item 4). A TWIN; each shop's listingSeo.js passes its own name.
//
// Every brand, sub-type and grade title used to end in "Watches" (Arabic: began with «ساعات») whatever the page held —
// "Perfumes Watches", "Power Banks Watches", «ساعات أساور», "Pinko Watches in Egypt" for a shop of bags — and the
// fallback description read "Browse 1 perfume at … — luxury watches and accessories". The page's own products decide
// now, through core's `facets.category` for the SAME request (the families the page holds, with their counts).

// The families a listing holds: top-level slugs and second-level names with products, the largest first.
export function familiesOf(listing) {
  const rows = (listing?.v2?.facets?.category || []).filter((r) => Number(r?.count) > 0)
  const top = rows.filter((r) => Number(r.depth) === 1).map((r) => r.slug)
  const second = rows.filter((r) => Number(r.depth) === 2).sort((a, b) => Number(b.count) - Number(a.count))
  return { known: rows.length > 0, top, second }
}

// Only watches on the page (every top-level family with products is the Watches one).
export const watchesOnly = (families) => families.known && families.top.length > 0 && families.top.every((s) => s === 'watches')

// A name as a heading: an English name typed all in lower case gets capitals ("perfume" → "Perfume"); any other
// casing is the team's and is kept ("iPhone", "TAG Heuer", "G-Shock").
export const displayName = (name) => {
  const s = String(name || '').trim()
  if (!s || s !== s.toLowerCase() || /[^\x00-\x7F]/.test(s)) return s
  return s.replace(/\b([a-z])/g, (m) => m.toUpperCase())
}

const pick = (v, lang) => (v && typeof v === 'object' ? v[lang] ?? v.en ?? '' : v ?? '')

// "Bags & Wallets" / «حقائب ومحافظ» — the page's two largest second-level families.
function familyWords(families, lang) {
  const names = families.second.slice(0, 2).map((r) => displayName(pick(r.name, lang))).filter(Boolean)
  if (!names.length) return ''
  if (names.length === 1) return names[0]
  return lang === 'ar' ? `${names[0]} و${names[1]}` : `${names[0]} & ${names[1]}`
}

/**
 * The <title> of a facet page. `kind`: brand | subtype | grade | category. `name`: the entity's name in `lang`.
 *   brand, watches only      → "Rolex Watches in Egypt" / «ساعات رولكس في مصر»
 *   brand, other families    → "Coach Bags & Wallets in Egypt" / «حقائب ومحافظ كوتش في مصر»; none known → "Coach in Egypt"
 *   sub-type / grade, watches only → "Diver Watches" / «ساعات الغوص» (the name as the team wrote it, after «ساعات»)
 *   sub-type / grade, otherwise    → its own name: "Perfumes" / «عطور»
 */
export function listingTitle({ kind, name, families, lang, shop }) {
  const ar = lang === 'ar'
  const n = displayName(name)
  const watches = watchesOnly(families)
  let head
  if (kind === 'brand') {
    const fam = watches ? (ar ? 'ساعات' : 'Watches') : familyWords(families, lang)
    head = fam ? (ar ? `${fam} ${n} في مصر` : `${n} ${fam} in Egypt`) : ar ? `${n} في مصر` : `${n} in Egypt`
  } else if (kind === 'subtype' || kind === 'grade') {
    head = watches && !/watch|ساع/i.test(n) ? (ar ? `ساعات ${n}` : `${n} Watches`) : n
  } else {
    head = n
  }
  return `${head} | ${shop}`
}

// The fallback description (the dashboard's own text wins): facts true of every product in the shop — 24-hour
// delivery across Egypt and cash on delivery (BUSINESS FACTS 5). No count ("Browse 1 …"), no "luxury watches" on a
// page of power banks, and no claim about the products themselves.
export function listingDescription({ crumb, lang, shop }) {
  const c = displayName(crumb)
  return lang === 'ar'
    ? `تسوّق ${c} من ${shop} مع توصيل خلال 24 ساعة إلى كل محافظات مصر والدفع عند الاستلام.`
    : `Shop ${c} at ${shop}, with delivery across Egypt within 24 hours and cash on delivery.`
}

// A listing with fewer LIVE products than this is thin: noindex, follow, and out of the sitemap until it fills
// (the brief's item 5; LIVE = published and visible, in stock or not — the count v2 answers). Core's sitemap uses the
// same number (config compat.thin_listing_min).
export const THIN_LISTING_MIN = 3
