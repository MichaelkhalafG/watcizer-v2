// LANDING PAGES (SEO Phase 2, 2026-11-14). A TWIN. Fixed pages per brand × gender and brand × family, both languages:
// /brand/tommy-hilfiger/men, /brand/guess/bags, /brand/guess/bags/women. Core decides which exist (6+ LIVE products that
// narrow the brand page, App\Domain\Seo\LandingPages) and whether each may be indexed (its copy passed the quality gate
// in both languages) and sends both with the listing (`meta.landing`, and on a brand page `meta.landings`).

// the address segment → core's family / the gender's English name (what v2's listing filters take)
export const LANDING_FAMILIES = { watches: 'watch', bags: 'bag', wallets: 'wallet', perfumes: 'perfume' }
export const LANDING_GENDERS = { men: 'Men', women: 'Women', unisex: 'Unisex', kids: 'Kids' }

const FAMILY_SEGMENT = Object.fromEntries(Object.entries(LANDING_FAMILIES).map(([seg, fam]) => [fam, seg]))
const GENDER_SEGMENT = Object.fromEntries(Object.entries(LANDING_GENDERS).map(([seg, name]) => [name, seg]))

// The facet of a landing address: { brand, family?, gender? } — or null when the segments are not a landing page's
// (one family or one gender; a family then a gender). Lower-case segments only: the redirect to the clean path handles case.
export function landingFacet(brand, segments) {
  const segs = (segments || []).map((s) => String(s || '').toLowerCase())
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
  const fam = page.family ? FAMILY_WORDS[LANDING_FAMILIES[page.family]] : null
  const g = page.gender ? GENDER_WORDS[LANDING_GENDERS[page.gender]] : null
  if (fam && g) return ar ? `${fam[1]} ${g[1]}` : `${g[0]} ${fam[0].toLowerCase()}`
  if (fam) return ar ? fam[1] : fam[0]
  if (g) return ar ? g[1] : g[0].replace(/'s$|'$/, '')
  return ''
}
