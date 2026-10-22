import { describe, expect, it } from 'vitest'
import { facetRedirectPath, cleanPath } from '../src/lib/listingSeo'

/*
 * ONE ADDRESS PER BRAND OR GRADE PAGE (Search Console, 2026-10-22). /brand/TAG Heuer, /brand/Citizen and /brand/citizen,
 * /grade/Entry Level all resolved and answered 200 with a canonical to the clean slug — one page under several
 * addresses. The owner's decision: every name-form or case variant redirects, permanently, to the clean path; a URL
 * that is already clean is left alone; the two-segment route has no clean form and is never redirected.
 */

const tables = {
  brands: [
    { id: 8, brand_name: 'TAG Heuer', translations: [{ locale: 'en', brand_name: 'TAG Heuer' }] },
    { id: 58, brand_name: 'Citizen', translations: [{ locale: 'en', brand_name: 'Citizen' }] },
    { id: 60, brand_name: 'Q&Q', translations: [{ locale: 'en', brand_name: 'Q&Q' }] },
  ],
  grades: [{ id: 7, grade_name: 'Entry Level', translations: [{ locale: 'en', grade_name: 'Entry Level' }] }],
}

describe('facetRedirectPath', () => {
  it('sends a brand by its name, a case variant and a prefix to the clean slug', () => {
    expect(facetRedirectPath('/brand/TAG Heuer', cleanPath('brand', tables, { brands: [8] }, '/brand/TAG Heuer'))).toBe('/brand/tag-heuer')
    expect(facetRedirectPath('/brand/TAG%20Heuer', '/brand/tag-heuer')).toBe('/brand/tag-heuer')
    expect(facetRedirectPath('/brand/Citizen', cleanPath('brand', tables, { brands: [58] }, '/brand/Citizen'))).toBe('/brand/citizen')
    expect(facetRedirectPath('/brand/Q&Q', cleanPath('brand', tables, { brands: [60] }, '/brand/Q&Q'))).toBe('/brand/qq')
    expect(facetRedirectPath('/brand/cit', '/brand/citizen')).toBe('/brand/citizen')
  })

  it('leaves a clean URL alone, encoded or not', () => {
    expect(facetRedirectPath('/brand/citizen', cleanPath('brand', tables, { brands: [58] }, '/brand/citizen'))).toBeNull()
    expect(facetRedirectPath('/brand/tag-heuer', '/brand/tag-heuer')).toBeNull()
    expect(facetRedirectPath('/grade/entry-level', cleanPath('grade', tables, { grades: [7] }, '/grade/entry-level'))).toBeNull()
  })

  it('sends a grade by its name to its slug', () => {
    expect(facetRedirectPath('/grade/Entry Level', cleanPath('grade', tables, { grades: [7] }, '/grade/Entry Level'))).toBe('/grade/entry-level')
    expect(facetRedirectPath('/grade/Entry%20Level', '/grade/entry-level')).toBe('/grade/entry-level')
  })

  it('never redirects a route that has no clean form, or an unresolved facet', () => {
    // the two-segment route: cleanPath hands the pathname back unchanged
    expect(facetRedirectPath('/watches/rolex', cleanPath('watches', tables, { subTypes: [1], brands: [1] }, '/watches/rolex'))).toBeNull()
    expect(facetRedirectPath('/brand/x', null)).toBeNull()
    expect(facetRedirectPath('', '/brand/x')).toBeNull()
  })
})
