import { describe, expect, it } from 'vitest'
import { pictureSources, hasRenditions, SIZES } from '../src/utils/picture'
import { fromV2Product } from '../src/lib/v2Product'
import { legacyRow } from '../src/lib/v2Listing'
import { transformProductData } from '../src/utils/transformProduct'

/*
 * THE <picture> IS BUILT FROM WHAT THE API RECORDS, AND FROM NOTHING ELSE (2026-10-22).
 *
 * `srcset` entries are the renditions the pipeline wrote and checked; the helper groups them by format,
 * smallest first, and drops anything that is not a recorded file. The adapters carry the list from a v2
 * card or detail onto the compat-shaped rows the pages read, so a card and a gallery can offer it.
 */

const host = 'https://api.example.test/Uploads_Images/Product'
const recorded = [
  { src: `${host}/m-320.webp`, width: 320, format: 'webp' },
  { src: `${host}/m-160.avif`, width: 160, format: 'avif' },
  { src: `${host}/m-320.avif`, width: 320, format: 'avif' },
  { src: `${host}/m-160.webp`, width: 160, format: 'webp' },
]

describe('pictureSources', () => {
  it('groups the recorded renditions by format, AVIF and WebP, smallest first', () => {
    const s = pictureSources(recorded)
    expect(s.avif).toBe(`${host}/m-160.avif 160w, ${host}/m-320.avif 320w`)
    expect(s.webp).toBe(`${host}/m-160.webp 160w, ${host}/m-320.webp 320w`)
    expect(s.widths).toEqual([160, 320])
  })

  it('offers nothing for a row that records nothing, and drops entries that are not renditions', () => {
    expect(pictureSources([])).toEqual({ avif: null, webp: null, widths: [] })
    expect(pictureSources(null)).toEqual({ avif: null, webp: null, widths: [] })
    const junk = [{ src: '', width: 320, format: 'webp' }, { src: 'x', width: 0, format: 'webp' }, { src: 'y', width: 320, format: 'png' }, null]
    expect(pictureSources(junk)).toEqual({ avif: null, webp: null, widths: [] })
    expect(hasRenditions(junk.slice(0, 3))).toBe(false)
    expect(hasRenditions(recorded)).toBe(true)
  })

  it('never invents a width: only the recorded rungs appear', () => {
    const s = pictureSources([{ src: `${host}/m-480.webp`, width: 480, format: 'webp' }])
    expect(s.webp).toBe(`${host}/m-480.webp 480w`)
    expect(s.avif).toBeNull()
    expect(s.widths).toEqual([480])
  })

  it('keeps every sizes value a valid, measured-from media list', () => {
    for (const v of Object.values(SIZES)) expect(v).toMatch(/^(\(max-width: \d+px\) \d+(vw|px), )*\d+px$/)
  })
})

describe('the adapters carry the recorded renditions onto the rows the pages read', () => {
  const card = { id: 7, slug: 's', title: { en: 'T' }, brand: { id: 1, name: { en: 'B' } }, image: { src: `${host}/m.webp`, srcset: recorded }, price: { amount: 10 }, stock: {}, rating: {} }

  it('a v2 card row carries image_srcset, and the transform keeps it', () => {
    const row = legacyRow(card)
    expect(row.image).toBe(`${host}/m.webp`)
    expect(row.image_srcset).toEqual(recorded)
    const [t] = transformProductData([{ ...row, feature: [], gender: [], translations: [] }], {}, [], [], 'en')
    expect(t.image_srcset).toEqual(recorded)
  })

  it('a v2 detail carries each gallery image\'s srcset, keyed by url after the transform', () => {
    const page = {
      product: { ...card, images: [{ id: 1, is_cover: true, src: `${host}/m.webp`, srcset: recorded }, { id: 2, is_cover: false, src: `${host}/g.webp`, srcset: [] }], attributes: {}, specs: [] },
      related: [],
    }
    const adapted = fromV2Product(page)
    expect(adapted.payload.images.map((i) => i.srcset.length)).toEqual([4, 0])
    const [t] = transformProductData(adapted.payload.products, {}, adapted.payload.ratings, adapted.payload.images, 'en')
    expect(t.images).toEqual([`${host}/m.webp`, `${host}/g.webp`])
    expect(t.images_srcset).toEqual({ [`${host}/m.webp`]: recorded })
    expect(t.image_srcset).toEqual(recorded)
  })

  it('a row without renditions carries an empty list, so the card stays the plain image', () => {
    const row = legacyRow({ ...card, image: { src: `${host}/m.webp`, srcset: [] } })
    expect(row.image_srcset).toEqual([])
    expect(hasRenditions(row.image_srcset)).toBe(false)
  })
})
