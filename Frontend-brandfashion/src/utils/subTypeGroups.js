// Grouping sub-types under the two category types (Watches / Fashion).
//
// The `sub_types` table has NO `category_type_id` FK, so the nav normally infers
// the grouping from the live product set (a sub-type shows under a category type
// when a product ties the two together). When there are no products yet — a fresh
// catalog, or a category type with nothing listed — that inference yields nothing
// and the dropdowns render empty. This module provides a deterministic fallback:
// classify each sub-type under Watches vs Fashion by its stable English name.
//
// Keys are lowercase English `sub_type_name` values (matching MasterDataSeeder).

export const WATCH_SUBTYPE_KEYS = new Set([
  'diver',
  'chronograph',
  'dress',
  'sport',
  'pilot',
  'gmt',
  'field',
  'racing',
  'skeleton',
  'tourbillon',
  'military',
  'casual',
  'smart watch',
  'digital watch',
])

export const FASHION_SUBTYPE_KEYS = new Set([
  'caps',
  'belts',
  'wallets',
  'bags',
  'perfumes',
  'sunglasses',
  'jewelry',
  'bracelets',
  'scarves',
  'keychains',
  'ties',
  'cufflinks',
  'pen',
])

// English value of a translatable lookup row (…translations[locale='en'][attr]),
// falling back to a flat field if translations aren't present.
const englishName = (item, attr) =>
  item?.translations?.find((t) => t.locale === 'en')?.[attr] ?? item?.[attr] ?? ''

// 'watch' | 'fashion' | null — derived from a category type's English name.
export const categoryTypeKind = (categoryType) => {
  const en = englishName(categoryType, 'category_type_name').toLowerCase()
  if (en.includes('watch')) return 'watch'
  if (en.includes('fashion')) return 'fashion'
  return null
}

// Sub-types belonging under a category type, classified by English name. Returns
// [] for any category type that isn't Watches or Fashion (unknown → no guess).
export const subTypesByName = (categoryType, allSubTypes = []) => {
  const kind = categoryTypeKind(categoryType)
  if (!kind) return []
  const keys = kind === 'watch' ? WATCH_SUBTYPE_KEYS : FASHION_SUBTYPE_KEYS
  return allSubTypes.filter((st) => keys.has(englishName(st, 'sub_type_name').toLowerCase()))
}
