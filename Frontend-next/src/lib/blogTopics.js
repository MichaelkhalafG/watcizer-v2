// THE ARTICLE ARCHIVES (SEO Phase 3, 2026-11-14) — a TWIN: /blogs/brand/<brand> and /blogs/topic/<topic>, each a
// paginated list of the shop's published articles about that brand or of that kind. An archive is INDEXABLE from
// MIN_INDEXABLE articles (fewer is a thin page: noindex, follow), the same threshold core's sitemap lists it from
// (CompatLocaleSitemap::ARCHIVE_MIN).

export const MIN_INDEXABLE = 3
export const PER_PAGE = 24

// the topics an article can have (its front matter's `topic`, core_blogs.topic); an unknown topic has no archive
export const TOPICS = {
  'buying-guide': { en: 'Buying guides', ar: 'أدلة الشراء' },
  comparison: { en: 'Comparisons', ar: 'مقارنات' },
  'how-to': { en: 'How-to guides', ar: 'إرشادات عملية' },
  care: { en: 'Care and maintenance', ar: 'العناية والصيانة' },
  style: { en: 'Style guides', ar: 'أدلة الأناقة' },
  gifts: { en: 'Gift guides', ar: 'أدلة الهدايا' },
  technology: { en: 'Movements and technology', ar: 'الماكينات والتقنية' },
  brand: { en: 'Brand guides', ar: 'أدلة العلامات التجارية' },
  explainer: { en: 'Explained', ar: 'شروحات' },
}

/** The articles of one archive, newest first (the list already is). */
export function archiveOf(blogs, kind, value) {
  const v = String(value || '').toLowerCase()
  return (blogs || []).filter((b) => (kind === 'brand' ? b?.brand?.slug === v : b?.topic === v))
}

/** The archive's name in a language: the brand's name, or the topic's. */
export function archiveName(kind, value, blogs, lang) {
  if (kind === 'topic') return TOPICS[value]?.[lang] || ''
  const brand = (blogs || []).find((b) => b?.brand?.slug === value)?.brand
  return brand?.name?.[lang] || brand?.name?.en || ''
}

/** One page of an archive; `page` past the end gives an empty list (the route answers 404). */
export function pageOf(list, page) {
  const p = Math.max(1, Number.parseInt(page, 10) || 1)
  return { items: list.slice((p - 1) * PER_PAGE, p * PER_PAGE), page: p, pages: Math.max(1, Math.ceil(list.length / PER_PAGE)) }
}
