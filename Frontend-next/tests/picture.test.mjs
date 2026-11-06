import { describe, expect, it } from 'vitest'
import { pictureSources, hasRenditions, SIZES, CARD_MAX, optimized, optimizedWide } from '../src/utils/picture'
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

// THE PERFORMANCE PASS (2026-11-05): a card's list stops at the widest file a card can use, and a banner with no
// recorded rendition is offered by width through the shop's own optimiser, the file itself last.
describe("a card's cap and a banner's widths", () => {
  const ladder = [160, 320, 480, 640, 960].flatMap((w) => [
    { src: `${host}/m-${w}.avif`, width: w, format: 'avif' },
    { src: `${host}/m-${w}.webp`, width: w, format: 'webp' },
  ])

  it('leaves the 960 px rendition out of a card, and nothing else', () => {
    const s = pictureSources(ladder, CARD_MAX)
    expect(s.widths).toEqual([160, 320, 480, 640])
    expect(s.avif.split(', ')).toHaveLength(4)
    expect(s.webp).not.toContain('960w')
    // without a cap the list is whole, as the gallery reads it
    expect(pictureSources(ladder).widths).toEqual([160, 320, 480, 640, 960])
  })

  it('keeps a list whole when every rendition is wider than the cap', () => {
    const wide = [{ src: `${host}/m-960.webp`, width: 960, format: 'webp' }]
    expect(pictureSources(wide, CARD_MAX).webp).toBe(`${host}/m-960.webp 960w`)
  })

  it('offers a banner by width through the optimiser, the file itself the widest', () => {
    const src = 'https://api.example.test/Uploads_Images/Banner_home/b.webp'
    const set = optimizedWide(src).split(', ')
    expect(set).toHaveLength(5)
    expect(set[0]).toBe(`/_next/image?url=${encodeURIComponent(src)}&w=640&q=80 640w`)
    expect(set[4]).toBe(`${src} 1600w`)
    // only next.config's sizes and qualities are ever asked for
    for (const part of set.slice(0, 4)) {
      const q = new URLSearchParams(part.split(' ')[0].split('?')[1])
      expect([320, 420, 640, 768, 1024, 1200]).toContain(Number(q.get('w')))
      expect([70, 75, 80, 90]).toContain(Number(q.get('q')))
    }
  })

  it('leaves a picture the optimiser does not take exactly as it is', () => {
    expect(optimizedWide('http://localhost:8000/Uploads_Images/Banner_home/b.webp')).toBeUndefined()
    expect(optimizedWide('/local.webp')).toBeUndefined()
    expect(optimized('/local.webp')).toBe('/local.webp')
    expect(optimized(undefined)).toBeUndefined()
  })
})
