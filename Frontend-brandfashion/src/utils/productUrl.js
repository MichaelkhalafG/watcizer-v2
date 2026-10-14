import { toSlug } from './slugs'

// Build a readable, language-stable product URL from the ENGLISH title.
//
// The transform exposes `product.name` = English product_title (ASCII) and
// `product.product_title` = localized title (may be Arabic). We slug the English
// title so URLs are stable across languages and never collapse to empty on
// Arabic input. Falls back to the id when no usable English name exists.
export const productUrl = (product) => {
  if (!product) return '/listing'
  const englishName = product.name || product.product_title || ''
  const slug = toSlug(englishName)
  return slug ? `/product/${slug}` : `/product/${product.id ?? ''}`
}

// Readable, language-stable offer URL. Offers carry a flat English name
// (`offer_name_en`) rather than a translations[] array, so we slug that; the id
// is the fallback when no usable English name exists.
export const offerUrl = (offer) => {
  if (!offer) return '/offers'
  const englishName = offer.offer_name_en || offer.name_en || offer.name || ''
  const slug = toSlug(englishName)
  return slug ? `/offer/${slug}` : `/offer/${offer.id ?? ''}`
}

export default productUrl
