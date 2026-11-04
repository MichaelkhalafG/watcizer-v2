import { describe, expect, it } from 'vitest'
import { colourCount, defaultColorId, galleryFor, generalOrAll, imageIdsOfColour, swatchFor } from '../src/lib/variantGallery'

/*
 * COLOUR CHOSEN BY IMAGE (Part 1, 2026-11-04): what the product page draws for a chosen colour, held to core's
 * answer shape — `images[].variant_id` and `variants[].image_ids`. A brown wallet with a red row: the brown is
 * the product's own colour (its row is `base_variant_id`), the red row owns two of the five photos.
 */

const img = (id, variant_id = null) => ({ id, src: `https://api.example.test/Uploads_Images/Product/${id}.webp`, variant_id })
const images = [img(1), img(2, 20), img(3, 20), img(4), img(5, 10)]
const brown = { id: 8, hex: '#4A2C1A', name: { en: 'Brown', ar: 'بني' } }
const red = { id: 30, hex: '#C00', name: { en: 'Red', ar: 'أحمر' } }
const variants = [
  { id: 10, color: brown, size: null, image_ids: [5], stock: { in_stock: true } },
  { id: 20, color: red, size: null, image_ids: [3, 2], stock: { in_stock: true } },
]

describe('galleryFor', () => {
  it('shows the chosen colour’s own photos, in GALLERY order (the cover stays first)', () => {
    expect(galleryFor(images, variants, 30).map((i) => i.id)).toEqual([2, 3])
    expect(galleryFor(images, variants, 8).map((i) => i.id)).toEqual([5])
  })
  it('shows the general photos when nothing is chosen, and when the colour owns none', () => {
    expect(galleryFor(images, variants, null).map((i) => i.id)).toEqual([1, 4])
    const bare = [{ id: 40, color: { id: 99 }, image_ids: [], stock: { in_stock: true } }]
    expect(galleryFor(images, bare, 99).map((i) => i.id)).toEqual([1, 4])
  })
  it('shows every photo when every photo belongs to a row and the chosen colour has none', () => {
    const allOwned = images.map((i) => ({ ...i, variant_id: i.variant_id ?? 20 }))
    expect(generalOrAll(allOwned).map((i) => i.id)).toEqual([1, 2, 3, 4, 5])
    expect(galleryFor(allOwned, [{ id: 7, color: { id: 1 }, image_ids: [] }], 1).map((i) => i.id)).toEqual([1, 2, 3, 4, 5])
  })
  it('tolerates a missing or malformed answer', () => {
    expect(galleryFor(null, null, 1)).toEqual([])
    expect(galleryFor(images, undefined, 1).map((i) => i.id)).toEqual([1, 4])
    expect(imageIdsOfColour([{ id: 1, color: { id: 2 }, image_ids: 'no' }], 2)).toEqual([])
  })
})

describe('swatchFor', () => {
  it('is the first photo of the colour’s rows, else null so the page falls back to the hex', () => {
    expect(swatchFor(images, variants, 30)?.id).toBe(3)
    expect(swatchFor(images, variants, 8)?.id).toBe(5)
    expect(swatchFor(images, [{ id: 1, color: { id: 2 }, image_ids: [999] }], 2)).toBeNull()
    expect(swatchFor(images, variants, 404)).toBeNull()
  })
})

describe('defaultColorId', () => {
  it('opens on the product’s own colour — the one the shopper is looking at (1.4)', () => {
    expect(defaultColorId(variants, 10)).toBe(8)
  })
  it('lets ?color= win when a row carries it, and ignores a colour no row has', () => {
    expect(defaultColorId(variants, 10, 30)).toBe(30)
    expect(defaultColorId(variants, 10, 404)).toBe(8)
  })
  it('falls back to the first colour in stock, then the first colour, then nothing', () => {
    const rows = [
      { id: 1, color: { id: 5 }, image_ids: [], stock: { in_stock: false } },
      { id: 2, color: { id: 6 }, image_ids: [], stock: { in_stock: true } },
    ]
    expect(defaultColorId(rows, null)).toBe(6)
    expect(defaultColorId(rows.map((r) => ({ ...r, stock: { in_stock: false } })), null)).toBe(5)
    expect(defaultColorId([{ id: 3, color: null, size: { id: 1 } }], null)).toBeNull()
    expect(defaultColorId([], null)).toBeNull()
  })
})

describe('colourCount', () => {
  it('counts distinct colours across the rows — above one, the band colour spec is hidden (1.5)', () => {
    expect(colourCount(variants)).toBe(2)
    expect(colourCount([{ id: 1, color: brown }, { id: 2, color: brown, size: { id: 1 } }])).toBe(1)
    expect(colourCount([{ id: 1, color: null, size: { id: 1 } }])).toBe(0)
  })
})
