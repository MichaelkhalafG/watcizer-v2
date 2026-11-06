// THE HOME'S PAYLOAD AS THE PAGE CARRIES IT (the performance pass, 2026-11-05). A TWIN.
//
// The home's server render hands its data to the browser inside the HTML, so the first paint needs no request. It
// handed over core's whole answer: measured, Watchizer's home was 1,463 KB of HTML of which 788 KB was this data —
// every product's long and short description in two languages, its search keywords, thirty specification fields,
// its whole gallery — for cards that draw a photo, a brand, a name, a price and a stock line. Brand Fashion's was
// 1,159 KB with 714 KB of data. The browser downloads it, parses it as script and walks it again to hydrate, before
// the page answers a tap.
//
// These functions keep what the home's cards read and nothing else. They change no card: the same rows go through
// the same transform into the same cards (src/lib/__tests__/homeData.test.js renders both and compares). The
// browser's own later refresh of the home still receives core's full answer — a superset, so nothing depends on
// which of the two a card came from.

// The widest a card's photo is ever drawn is 320 CSS px (picture.js, SIZES), 640 on a 2× screen: a wider rendition
// in a card's list is never chosen. A list with nothing at or under the cap is left as it is.
const CARD_MAX = 640
export const trimSrcset = (list, max = CARD_MAX) => {
  if (!Array.isArray(list)) return list
  const kept = list.filter((s) => !(Number(s?.width) > max))
  return kept.length ? kept : list
}

// ── compat (`catalog/home`, Watchizer): raw product rows, transformed in the browser by transformProductData ──
// What ProductCard, the sliders, the brand banner and productUrl read from a transformed card, as the raw fields
// they come from. `feature` and `gender` are emptied, not dropped: the transform maps over both.
const ROW_FIELDS = [
  'id', 'brand_id', 'category_type_id', 'grade_id', 'sub_type_id', 'image', 'selling_price', 'sale_price_after_discount',
  'percentage_discount', 'stock', 'market_stock', 'created_at', 'active', 'average_rating', 'ratings_avg', 'rating',
  'ratings_count', 'reviews_count', 'product_title', 'product_title_ar', 'name_en', 'name_ar', 'brand_name', 'slug',
]
const slimRow = (row) => {
  const out = {}
  for (const k of ROW_FIELDS) if (row[k] !== undefined) out[k] = row[k]
  out.translations = (row.translations || []).map((t) => ({ locale: t.locale, product_title: t.product_title }))
  out.image_srcset = trimSrcset(row.image_srcset)
  out.feature = []
  out.gender = []
  return out
}

// the same answer object comes back from the server's held copy for many requests: slim it once
const slimmed = new WeakMap()
const once = (value, make) => {
  if (!value || typeof value !== 'object') return value
  if (!slimmed.has(value)) slimmed.set(value, make(value))
  return slimmed.get(value)
}

/** core's `catalog/home` answer with each product row cut to what a home card reads; the galleries are not sent. */
export const slimCompatHome = (home) =>
  once(home, (h) => (Array.isArray(h.products) ? { ...h, products: h.products.map(slimRow), images: [] } : h))

// ── v2 (`v2/{storefront}/home`, Brand Fashion): cards, drawn as they are ──
// BfProductCard reads everything on a card except its short description; its two photos' lists are capped as above.
const slimImage = (image) => (image && Array.isArray(image.srcset) ? { ...image, srcset: trimSrcset(image.srcset) } : image)
const slimCard = (card) => {
  if (!card || typeof card !== 'object') return card
  const out = { ...card, image: slimImage(card.image), hover_image: slimImage(card.hover_image) }
  delete out.short_description
  return out
}

/** core's v2 home answer with each card (the sliders' and the deals band's) cut to what BfProductCard reads. */
export const slimV2Home = (home) =>
  once(home, (h) => ({
    ...h,
    rails: Array.isArray(h.rails) ? h.rails.map((r) => (Array.isArray(r.cards) ? { ...r, cards: r.cards.map(slimCard) } : r)) : h.rails,
    deals: Array.isArray(h.deals) ? h.deals.map(slimCard) : h.deals,
  }))
