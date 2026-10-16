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
 * The ATTRIBUTE colours a row carries for the §8.2 swatch row — dial → band → main, each colour once,
 * only those with a hex. A v2 card row carries `colors[]`; a v2 detail carries `attributes.colors`.
 */
export function cardColours(product) {
  if (Array.isArray(product?.colors) && product.colors.length) return product.colors.filter((c) => c?.hex)
  const c = product?.attributes?.colors
  if (!c) return []
  const all = [...(c.dial ?? []), ...(c.band ?? []), ...(c.main ?? [])]
  return all.filter((x, i, a) => x?.hex && a.findIndex((y) => y.id === x.id) === i)
}
