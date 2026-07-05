// Readable URL slugs for filter entities.
//
// Slugs are built from the ENGLISH name (via the item's translations[]) so a URL
// generated in one language still resolves in another — i.e. slugs are
// language-stable. Names in this app live in `item.translations[{locale,<key>}]`,
// not as flat properties, so nameOf() reads from there (English → flat → Arabic).

export const toSlug = (name) => {
  if (!name) return ''
  return name
    .toString()
    .toLowerCase()
    .trim()
    .replace(/['’`]/g, '')
    .replace(/[&]/g, '')
    .replace(/[^a-z0-9\s-]/g, '')
    .replace(/\s+/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-+|-+$/g, '')
}

const nameOf = (item, key) =>
  item?.translations?.find((t) => t.locale === 'en')?.[key] ??
  item?.[key] ??
  item?.translations?.find((t) => t.locale === 'ar')?.[key] ??
  ''

export const brandSlug = (b) => toSlug(nameOf(b, 'brand_name')) || String(b?.id ?? '')
export const subTypeSlug = (s) => toSlug(nameOf(s, 'sub_type_name')) || String(s?.id ?? '')
export const categorySlug = (c) => toSlug(nameOf(c, 'category_type_name')) || String(c?.id ?? '')
export const colorSlug = (c) => toSlug(nameOf(c, 'color_name')) || String(c?.id ?? '')
export const materialSlug = (m) => toSlug(nameOf(m, 'material_name')) || String(m?.id ?? '')
export const movementSlug = (m) => toSlug(nameOf(m, 'movement_type_name')) || String(m?.id ?? '')
export const shapeSlug = (s) => toSlug(nameOf(s, 'shape_name')) || String(s?.id ?? '')
export const displayTypeSlug = (d) => toSlug(nameOf(d, 'display_type_name')) || String(d?.id ?? '')

// Resolve a slug back to its table item. Exact slug match first, then partial.
export const fromSlug = (slug, items, slugFn) => {
  if (!slug || !items?.length) return null
  const lower = slug.toLowerCase()
  const exact = items.find((i) => slugFn(i) === lower)
  if (exact) return exact
  return (
    items.find((i) => {
      const s = slugFn(i)
      return s && (s.includes(lower) || lower.includes(s))
    }) || null
  )
}
