// LANDING PAGES (SEO Phase 2, 2026-11-14). A TWIN. Fixed pages per brand × gender and brand × family, both languages:
// /brand/tommy-hilfiger/men, /brand/guess/bags, /brand/guess/bags/women. Core decides which exist (6+ LIVE products that
// narrow the brand page, App\Domain\Seo\LandingPages) and whether each may be indexed (its copy passed the quality gate
// in both languages) and sends both with the listing (`meta.landing`, and on a brand page `meta.landings`).
//
// BY DIAL COLOUR AND BY STRAP (2026-11-17): a brand's watches by one dial colour or one strap material —
// /brand/tommy-hilfiger/watches/blue-dial, /brand/guess/watches/leather-strap — by the same rules (core's LandingPages).
// The segment is the colour's / material's own slug (src/utils/slugs.js) and `-dial` / `-strap`; a strap page only for a
// strap material (STRAP_MATERIALS, core's list), so the listing's material filter (band or case) is the band.
import { colorSlug, materialSlug } from '../utils/slugs'

export const STRAP_MATERIALS = ['leather', 'silicone', 'rubber', 'nylon', 'mesh-steel']

// the address segment → core's family / the gender's English name (what v2's listing filters take)
export const LANDING_FAMILIES = { watches: 'watch', bags: 'bag', wallets: 'wallet', perfumes: 'perfume' }
export const LANDING_GENDERS = { men: 'Men', women: 'Women', unisex: 'Unisex', kids: 'Kids' }

const FAMILY_SEGMENT = Object.fromEntries(Object.entries(LANDING_FAMILIES).map(([seg, fam]) => [fam, seg]))
const GENDER_SEGMENT = Object.fromEntries(Object.entries(LANDING_GENDERS).map(([seg, name]) => [name, seg]))

// The facet of a landing address: { brand, family?, gender? } — or null when the segments are not a landing page's
// (one family or one gender; a family then a gender). Lower-case segments only: the redirect to the clean path handles case.
export function landingFacet(brand, segments) {
  const segs = (segments || []).map((s) => String(s || '').toLowerCase())
  if (segs.length === 2 && segs[0] === 'watches') {
    const dial = /^([a-z0-9-]+)-dial$/.exec(segs[1])
    if (dial) return { brand, family: 'watch', dial: dial[1] }
    const strap = /^([a-z0-9-]+)-strap$/.exec(segs[1])
    if (strap && STRAP_MATERIALS.includes(strap[1])) return { brand, family: 'watch', strap: strap[1] }
  }
  if (segs.length === 1) {
    if (LANDING_FAMILIES[segs[0]]) return { brand, family: LANDING_FAMILIES[segs[0]] }
    if (LANDING_GENDERS[segs[0]]) return { brand, gender: LANDING_GENDERS[segs[0]] }
    return null
  }
  if (segs.length === 2 && LANDING_FAMILIES[segs[0]] && LANDING_GENDERS[segs[1]]) {
    return { brand, family: LANDING_FAMILIES[segs[0]], gender: LANDING_GENDERS[segs[1]] }
  }
  return null
}

// The landing segments a set of listing filters names ([] when it is not a landing page: no family and no gender).
export function landingSegments(filters = {}) {
  // a dial-colour or strap page (2026-11-17): set only by the landing route itself (watchLandingFilters)
  if (filters.landingDial) return ['watches', `${filters.landingDial}-dial`]
  if (filters.landingStrap) return ['watches', `${filters.landingStrap}-strap`]
  const genders = filters.genders || []
  const family = filters.family ? FAMILY_SEGMENT[filters.family] : null
  const gender = genders.length === 1 ? GENDER_SEGMENT[genders[0]] : null
  if ((filters.family && !family) || genders.length > 1 || (genders.length === 1 && !gender)) return []
  return [family, gender].filter(Boolean)
}

const FAMILY_WORDS = {
  watch: ['Watches', 'ساعات'],
  bag: ['Bags', 'حقائب'],
  wallet: ['Wallets', 'محافظ'],
  perfume: ['Perfumes', 'عطور'],
}
const GENDER_WORDS = {
  Men: ["Men's", 'للرجال', 'for Men'],
  Women: ["Women's", 'للنساء', 'for Women'],
  Unisex: ['Unisex', 'للجنسين', 'Unisex'],
  Kids: ["Kids'", 'للأطفال', 'for Kids'],
}

// The page's name in a language, from the brand's name: "Guess Women's Bags" / «حقائب Guess للنساء»,
// "Tommy Hilfiger for Men" / «Tommy Hilfiger للرجال», "Guess Bags" / «حقائب Guess».
export function landingName(brandName, filters = {}, lang = 'en') {
  const ar = lang === 'ar'
  // "Tommy Hilfiger Watches with a Blue Dial" / «ساعات Tommy Hilfiger بميناء أزرق»; "… with a Leather Strap" / «… بسوار جلد»
  if (filters.landingDialName) return ar ? `ساعات ${brandName} بميناء ${filters.landingDialName.ar}` : `${brandName} Watches with a ${filters.landingDialName.en} Dial`
  if (filters.landingStrapName) return ar ? `ساعات ${brandName} بسوار ${filters.landingStrapName.ar}` : `${brandName} Watches with a ${filters.landingStrapName.en} Strap`
  const fam = filters.family ? FAMILY_WORDS[filters.family] : null
  const g = (filters.genders || []).length === 1 ? GENDER_WORDS[filters.genders[0]] : null
  if (fam && g) return ar ? `${fam[1]} ${brandName} ${g[1]}` : `${brandName} ${g[0]} ${fam[0]}`
  if (fam) return ar ? `${fam[1]} ${brandName}` : `${brandName} ${fam[0]}`
  if (g) return ar ? `${brandName} ${g[1]}` : `${brandName} ${g[2]}`
  return brandName
}

// A landing page's link label on its brand page ("Men", "Women's bags" / «للرجال», «حقائب للنساء»).
export function landingLabel(page, lang = 'en') {
  const ar = lang === 'ar'
  // a dial-colour or strap page: core sends the colour's / material's names (2026-11-17)
  if (page.dial && page.name) return ar ? `ميناء ${page.name.ar || page.name.en}` : `${page.name.en} dial`
  if (page.strap && page.name) return ar ? `سوار ${page.name.ar || page.name.en}` : `${page.name.en} strap`
  const fam = page.family ? FAMILY_WORDS[LANDING_FAMILIES[page.family]] : null
  const g = page.gender ? GENDER_WORDS[LANDING_GENDERS[page.gender]] : null
  if (fam && g) return ar ? `${fam[1]} ${g[1]}` : `${g[0]} ${fam[0].toLowerCase()}`
  if (fam) return ar ? fam[1] : fam[0]
  if (g) return ar ? g[1] : g[0].replace(/'s$|'$/, '')
  return ''
}

// The filters of a dial-colour or strap page (2026-11-17): the colour's / material's id for the listing, and its slug and
// names for the address, the H1 and the title. False when the segment is no colour / strap material of the tables.
const nameOf = (item, key, lang) => item?.translations?.find((t) => t.locale === lang)?.[key] || item?.[key] || ''
export function watchLandingFilters(filters, tables = {}, facet = {}) {
  if (facet.dial !== undefined) {
    const c = (tables.colors || []).find((x) => colorSlug(x) === facet.dial)
    if (!c) return false
    filters.dialColors = [c.id]
    filters.landingDial = facet.dial
    filters.landingDialName = { en: nameOf(c, 'color_name', 'en'), ar: nameOf(c, 'color_name', 'ar') || nameOf(c, 'color_name', 'en') }
  }
  if (facet.strap !== undefined) {
    const m = (tables.materials || []).find((x) => materialSlug(x) === facet.strap)
    if (!m || !STRAP_MATERIALS.includes(facet.strap)) return false
    filters.materials = [m.id]
    filters.landingStrap = facet.strap
    filters.landingStrapName = { en: nameOf(m, 'material_name', 'en'), ar: nameOf(m, 'material_name', 'ar') || nameOf(m, 'material_name', 'en') }
  }
  return true
}
