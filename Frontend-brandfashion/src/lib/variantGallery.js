// Colour chosen by image (Part 1, 2026-11-04). A TWIN: both storefronts read it.
//
// Core's v2 product detail carries, per variant row, `image_ids` — the product images the dashboard marked as that
// row's — and, per image, `variant_id`. These helpers turn that into what a product page draws:
//
//   • galleryFor():   the images to show for the chosen colour — that colour's rows' images, in gallery order; the
//                     product's GENERAL images (no row) when the colour has none; every image when nothing is general.
//   • swatchFor():    the photo the colour's swatch shows — the first image of that colour's rows — or null, in which
//                     case the page falls back to the colour's hex circle.
//   • defaultColorId(): the colour the picker opens on — the product's own colour (core's `base_variant_id`), else the
//                     first colour with a row in stock, else the first colour. A picker must never open on nothing
//                     while the shopper is looking at one of its options (1.4).
//   • colourCount():  how many distinct colours the rows carry — above one, the product's own band/material colour
//                     spec describes one of them only and is hidden (1.5).
//
// Pure functions over the API's answer, so `tests/variantGallery.test.mjs` proves them without a browser.

const rowColourId = (v) => (v && v.color && v.color.id != null ? v.color.id : null)

/** The rows of one colour; every row when `colorId` is null. */
export const rowsOfColour = (variants, colorId) =>
  (Array.isArray(variants) ? variants : []).filter((v) => colorId == null || rowColourId(v) === colorId)

/** The image ids the rows of `colorId` own, in the order the rows list them, each once. */
export function imageIdsOfColour(variants, colorId) {
  const seen = new Set()
  const out = []
  for (const v of rowsOfColour(variants, colorId)) {
    for (const id of Array.isArray(v.image_ids) ? v.image_ids : []) {
      if (id != null && !seen.has(id)) {
        seen.add(id)
        out.push(id)
      }
    }
  }
  return out
}

/**
 * The gallery for a chosen colour. `images` is core's `images[]` (each with `id` and `variant_id`), in gallery order.
 * The order of the GALLERY wins over the order of the rows' lists: a cover stays first.
 */
export function galleryFor(images, variants, colorId) {
  const all = (Array.isArray(images) ? images : []).filter(Boolean)
  if (colorId == null) return generalOrAll(all)
  const wanted = new Set(imageIdsOfColour(variants, colorId))
  if (wanted.size === 0) return generalOrAll(all)
  const own = all.filter((img) => wanted.has(img.id))
  return own.length ? own : generalOrAll(all)
}

/** The product's general images (belonging to no row), or every image when none is general. */
export function generalOrAll(images) {
  const all = (Array.isArray(images) ? images : []).filter(Boolean)
  const general = all.filter((img) => img.variant_id == null)
  return general.length ? general : all
}

/** The photo a colour's swatch shows: the first image of that colour's rows, else null (the page draws the hex). */
export function swatchFor(images, variants, colorId) {
  const ids = imageIdsOfColour(variants, colorId)
  if (!ids.length) return null
  const byId = new Map((Array.isArray(images) ? images : []).filter(Boolean).map((img) => [img.id, img]))
  for (const id of ids) {
    const img = byId.get(id)
    if (img) return img
  }
  return null
}

/** Distinct colour ids across the rows, in row order. */
export function colourIds(variants) {
  const out = []
  for (const v of Array.isArray(variants) ? variants : []) {
    const id = rowColourId(v)
    if (id != null && !out.includes(id)) out.push(id)
  }
  return out
}

export const colourCount = (variants) => colourIds(variants).length

/**
 * The colour the picker opens on. `preferred` (from `?color=`) wins when a row carries it; then the product's own
 * colour (`baseVariantId` names its row); then the first colour with a row in stock; then the first colour; null
 * when the rows carry no colour at all.
 */
export function defaultColorId(variants, baseVariantId, preferred = null) {
  const rows = Array.isArray(variants) ? variants : []
  const ids = colourIds(rows)
  if (!ids.length) return null
  if (preferred != null && ids.includes(preferred)) return preferred
  const base = baseVariantId == null ? null : rows.find((v) => v && v.id === baseVariantId)
  const baseColour = rowColourId(base)
  if (baseColour != null) return baseColour
  const inStock = ids.find((id) => rowsOfColour(rows, id).some((v) => v && v.stock && v.stock.in_stock))
  return inStock != null ? inStock : ids[0]
}
