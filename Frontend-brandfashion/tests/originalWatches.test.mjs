import { describe, expect, it } from 'vitest'
import { ORIGINAL_WATCHES, ORIGINAL_WATCHES_PATH } from '../src/content/originalWatches.js'
import { INSPECT_SHORT } from '../src/content/inspection.js'
import { v2ListingRequest } from '../src/lib/v2Listing.js'
import { buildListingParams, parseListingParams } from '../src/utils/listingParams.js'
import { directoryOf, menuChildHref } from '../src/lib/menuLinks.js'

/*
 * THE ORIGINAL WATCHES PAGE (Michael, 2026-11-14). A twin. It lists only what core's `collection=original-watches`
 * answers (never a matched product), and says only what is true of every watch it lists.
 */
describe('the Original watches page', () => {
  it('asks core for the collection, and keeps it through the URL the listing builds', () => {
    const seed = buildListingParams({ collection: 'original-watches' }).toString()
    expect(seed).toBe('collection=original-watches')
    const parsed = parseListingParams(new URLSearchParams(seed))
    expect(parsed.filters.collection).toBe('original-watches')
    expect(v2ListingRequest({ filters: parsed.filters })).toContain('collection=original-watches')
    expect(v2ListingRequest({ filters: { ...parsed.filters, brands: [5] } })).toContain('collection=original-watches')
  })

  it('has its own title, H1, meta and intro in both languages, within the length a result shows, claiming nothing more', () => {
    for (const lang of ['en', 'ar']) {
      expect(ORIGINAL_WATCHES.heading[lang]).toBeTruthy()
      expect(ORIGINAL_WATCHES.meta[lang].length).toBeLessThanOrEqual(155)
      expect(ORIGINAL_WATCHES.intro[lang]).toContain(INSPECT_SHORT[lang])
      const all = JSON.stringify([ORIGINAL_WATCHES.title[lang], ORIGINAL_WATCHES.meta[lang], ORIGINAL_WATCHES.intro[lang]])
      expect(all).not.toMatch(/authori[sz]ed|dealer|agent|وكيل|معتمد|free shipping|شحن مجاني/i)
    }
    expect(ORIGINAL_WATCHES_PATH).toBe('/original-watches')
  })

  it('is linked by the menu tile core sends first under Watches, and by the directory', () => {
    const tile = { id: 'original-watches', slug: 'original-watches', href: '/original-watches', name: { en: 'Original watches' }, count: 9 }
    expect(menuChildHref(tile, { slug: 'watches' }, null)).toBe('/original-watches')
    const tables = { categoryTypes: [{ id: 1, category_type_name: 'Watches', translations: [{ locale: 'en', category_type_name: 'Watches' }] }], subTypes: [] }
    const { categories } = directoryOf({ categories: [{ slug: 'watches', name: { en: 'Watches' }, count: 20, children: [tile] }] }, tables)
    expect(categories[0]?.children?.[0]?.href).toBe('/original-watches')
  })
})
