// Pure helpers shared by the card (a client component) and the pages that render it on the server.
// Kept out of the component file on purpose: a server component that imports a 'use client' module
// receives client REFERENCES for every export, and calling one of them on the server throws.

/** A localised string field (`{en, ar}`) or a plain string → text for `lang`, English as fallback. */
export const txt = (v, lang = 'en') => (v && typeof v === 'object' ? v[lang] ?? v.en ?? Object.values(v)[0] : v) ?? ''

/** `EGP 3,900` in English, `3,900 ج.م` in Arabic — Western digits in both, after the number in Arabic (§5). */
export const money = (n, lang = 'en', currency = 'EGP') => {
  const num = Number(n).toLocaleString('en-EG', { maximumFractionDigits: 0 })
  return lang === 'ar' ? `${num} ج.م` : `${currency} ${num}`
}

/**
 * The DESCRIPTIVE colours a row carries (`catalog_product_color`: dial → band → main, each once, only those
 * with a hex). They say what the product looks like; they are not choices. A v2 card row carries `colors[]`;
 * a v2 detail carries `attributes.colors`. The card renders them as TEXT (spec v2.11 §8.2) — never as dots,
 * which read as "pick one" (the 2026-10-17 review: a two-tone dial drawn as two purchasable colours).
 */
export function cardColours(product) {
  if (Array.isArray(product?.colors) && product.colors.length) return product.colors.filter((c) => c?.hex)
  const c = product?.attributes?.colors
  if (!c) return []
  const all = [...(c.dial ?? []), ...(c.band ?? []), ...(c.main ?? [])]
  return all.filter((x, i, a) => x?.hex && a.findIndex((y) => y.id === x.id) === i)
}

/**
 * The SELECTABLE options a row carries (`catalog_product_variants`, active rows): `[{id, color, size,
 * in_stock}]`. Zero rows on every real product today (spec §11.5); the 33 demo products have them. A picker —
 * the card's or the PDP's — is built on these and on nothing else.
 */
export function cardVariants(product) {
  return Array.isArray(product?.variants) ? product.variants.filter((v) => v && (v.color || v.size)) : []
}

/** Watchizer's "New" rule (Frontend-next ProductCard.jsx:13): created within the last 30 days. Inherited, not redesigned. */
export const NEW_WINDOW_MS = 30 * 24 * 60 * 60 * 1000
export function isNewProduct(createdAt, nowMs = Date.now()) {
  if (!createdAt) return false
  const t = createdAt instanceof Date ? createdAt.getTime() : new Date(createdAt).getTime()
  return Number.isFinite(t) && nowMs - t < NEW_WINDOW_MS
}

/**
 * A row as Watchizer's listing hands it to its card (the legacy shape after transformProductData, with every
 * v2 field the adapter spread onto it) → the v2 card shape BfProductCard reads. Used by the ProductCard
 * adapter so Watchizer's listing components render the Brand Fashion card without being edited.
 */
export function fromLegacyCard(row, lang = 'en') {
  if (!row) return null
  const selling = Number(row.selling_price || 0)
  const sale = Number(row.sale_price_after_discount || 0)
  const hasSale = sale > 0 && sale < selling
  const express = Number(row.stock || 0)
  const market = Number(row.market_stock || 0)
  const brandName = typeof row.brand === 'string' ? row.brand : txt(row.brand?.name, lang)
  const imageSrc = typeof row.image === 'string' ? row.image : row.image?.src || null
  return {
    ...row,
    title: row.title && typeof row.title === 'object' ? row.title : { [lang]: row.product_title || row.name || '' },
    brand: { ...(row.brand && typeof row.brand === 'object' ? row.brand : {}), name: { en: brandName, [lang]: brandName } },
    image: imageSrc ? { src: imageSrc } : null,
    hover_image: row.hover_image?.src ? row.hover_image : null,
    price: { amount: selling, sale_amount: hasSale ? sale : null, has_sale: hasSale, discount_pct: hasSale ? Math.round((1 - sale / selling) * 100) : 0, currency: 'EGP' },
    stock: { express, market, in_stock: typeof row.in_stock === 'boolean' ? row.in_stock : express > 0 || market > 0 },
    colors: Array.isArray(row.colors) ? row.colors : [],
    variants: Array.isArray(row.variants) ? row.variants : [],
    rating: { avg: row.ratings_avg ?? null, count: row.ratings_count ?? 0 },
    created_at: row.created_at,
  }
}
