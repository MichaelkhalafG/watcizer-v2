import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import { landingFacet, landingLabel, landingName, landingSegments, watchLandingFilters } from '../src/lib/landing.js'
import { listingMetadata, resolveFacetFilters } from '../src/lib/listingSeo'

/*
 * LANDING PAGES (SEO Phase 2, 2026-11-14). A twin. /brand/<brand>/<family>, /<gender>, /<family>/<gender>: the facet they
 * are, their own address, their name in both languages, and noindex until core says their copy passed the gate.
 */
const read = (p) => readFileSync(new URL('../' + p, import.meta.url), 'utf8')
const tables = { brands: [{ id: 9, brand_name: 'Guess', slug: 'guess', translations: [{ locale: 'en', brand_name: 'Guess' }, { locale: 'ar', brand_name: 'جيس' }] }] }

describe('landing pages', () => {
  it('read their address as a facet, and nothing else', () => {
    expect(landingFacet('guess', ['bags'])).toEqual({ brand: 'guess', family: 'bag' })
    expect(landingFacet('guess', ['women'])).toEqual({ brand: 'guess', gender: 'Women' })
    expect(landingFacet('guess', ['bags', 'women'])).toEqual({ brand: 'guess', family: 'bag', gender: 'Women' })
    expect(landingFacet('guess', ['women', 'bags'])).toBeNull()
    expect(landingFacet('guess', ['shoes'])).toBeNull()
    expect(landingFacet('guess', ['bags', 'women', 'x'])).toBeNull()
  })

  it('name themselves in both languages, and label their link on the brand page', () => {
    expect(landingName('Guess', { family: 'bag', genders: ['Women'] }, 'en')).toBe("Guess Women's Bags")
    expect(landingName('جيس', { family: 'bag', genders: ['Women'] }, 'ar')).toBe('حقائب جيس للنساء')
    expect(landingName('Tommy Hilfiger', { genders: ['Men'] }, 'en')).toBe('Tommy Hilfiger for Men')
    expect(landingName('Guess', { family: 'bag' }, 'en')).toBe('Guess Bags')
    expect(landingLabel({ family: 'bags', gender: 'women' }, 'en')).toBe("Women's bags")
    expect(landingLabel({ gender: 'men' }, 'en')).toBe('Men')
    expect(landingLabel({ family: 'watches' }, 'ar')).toBe('ساعات')
    expect(landingSegments({ family: 'bag', genders: ['Women'] })).toEqual(['bags', 'women'])
    expect(landingSegments({ genders: ['Men', 'Women'] })).toEqual([])
  })

  it('are their own canonical page, noindex until core says the copy passed, then indexable', () => {
    const { filters } = resolveFacetFilters(tables, { brand: 'guess', family: 'bag', gender: 'Women' })
    expect(filters).toMatchObject({ brands: [9], family: 'bag', genders: ['Women'] })
    const meta = (landing) => listingMetadata({ tables, total: 40, answered: true, filters, pathname: '/brand/guess/bags/women', landing, lang: 'en' })
    const held = meta({ key: 'guess/bags/women', exists: true, indexable: false })
    expect(held.alternates.canonical).toMatch(/\/brand\/guess\/bags\/women$/)
    expect(held.robots).toEqual({ index: false, follow: true })
    expect(held.title).toMatch(/^Guess Women's Bags in Egypt \| /)
    expect(meta(null).robots).toEqual({ index: false, follow: true })
    expect(meta({ key: 'guess/bags/women', exists: true, indexable: true }).robots).toBeUndefined()
  })

  it('are routed, and the brand page carries the lead with its landing links', () => {
    expect(read('app/(main)/brand/[brand]/[a]/page.jsx')).toMatch(/landingFacet\(brand, \[a\]\)/)
    expect(read('app/(main)/brand/[brand]/[a]/[b]/page.jsx')).toMatch(/landingFacet\(brand, \[a, b\]\)/)
    expect(read('src/lib/facetListing.jsx')).toMatch(/lead=\{<FacetLead context=\{summary\.context\} landings=\{summary\.landings\}/)
    expect(read('app/(main)/listing/ListingClient.jsx')).toMatch(/<h1 className="wz-listing-h1">\{crumb\}<\/h1>\s*\{lead\}/)
  })
})

// BY DIAL COLOUR AND BY STRAP (2026-11-17)
const watchTables = {
  ...tables,
  colors: [{ id: 6, color_name: 'Blue', translations: [{ locale: 'en', color_name: 'Blue' }, { locale: 'ar', color_name: 'أزرق' }] }],
  materials: [
    { id: 2, material_name: 'Leather', translations: [{ locale: 'en', material_name: 'Leather' }, { locale: 'ar', material_name: 'جلد' }] },
    { id: 1, material_name: 'Stainless Steel', translations: [{ locale: 'en', material_name: 'Stainless Steel' }] },
  ],
}
describe('dial-colour and strap pages', () => {
  it('read /watches/<colour>-dial and /watches/<strap material>-strap, never a case material', () => {
    expect(landingFacet('guess', ['watches', 'blue-dial'])).toEqual({ brand: 'guess', family: 'watch', dial: 'blue' })
    expect(landingFacet('guess', ['watches', 'leather-strap'])).toEqual({ brand: 'guess', family: 'watch', strap: 'leather' })
    expect(landingFacet('guess', ['watches', 'stainless-steel-strap'])).toBeNull()
    expect(landingFacet('guess', ['bags', 'blue-dial'])).toBeNull()
  })

  it('resolve to the listing filters, their own address and their names', () => {
    const f = { family: 'watch' }
    expect(watchLandingFilters(f, watchTables, { dial: 'blue' })).toBe(true)
    expect(f.dialColors).toEqual([6])
    expect(landingSegments(f)).toEqual(['watches', 'blue-dial'])
    expect(landingName('Guess', f, 'en')).toBe('Guess Watches with a Blue Dial')
    expect(landingName('جيس', f, 'ar')).toBe('ساعات جيس بميناء أزرق')
    const g = { family: 'watch' }
    expect(watchLandingFilters(g, watchTables, { strap: 'leather' })).toBe(true)
    expect(g.materials).toEqual([2])
    expect(landingName('Guess', g, 'en')).toBe('Guess Watches with a Leather Strap')
    expect(watchLandingFilters({}, watchTables, { dial: 'purple' })).toBe(false)
    expect(watchLandingFilters({}, watchTables, { strap: 'stainless-steel' })).toBe(false)
    expect(landingLabel({ dial: 'blue', name: { en: 'Blue', ar: 'أزرق' } }, 'ar')).toBe('ميناء أزرق')
    expect(landingLabel({ strap: 'leather', name: { en: 'Leather', ar: 'جلد' } }, 'en')).toBe('Leather strap')
  })

  it('resolve through the facet route, and 404 an unknown colour', () => {
    expect(resolveFacetFilters(watchTables, { brand: 'guess', family: 'watch', dial: 'blue' }).ok).toBe(true)
    expect(resolveFacetFilters(watchTables, { brand: 'guess', family: 'watch', dial: 'teal' }).ok).toBe(false)
  })
})

