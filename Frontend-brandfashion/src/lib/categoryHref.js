// A category's page on Brand Fashion (2026-11-14, SEO Phase 1). A category's `path` is its slug path
// ("fashion/bags/shoulder-bag"). The shop has a page for a TOP-LEVEL category (/category/{slug}) and a SECOND-LEVEL one
// (/subtypes/{slug}) — the facet routes resolve those two levels only. A deeper node has no page of its own, so it is
// never linked: the product page's breadcrumb linked /subtypes/shoulder-bag (404) and its BreadcrumbList named
// /category/fashion/bags (404, a one-segment route) — 104 of 244 middle crumbs in the 2026-11-13 crawl.

const segmentsOf = (path) => String(path || '').split('/').filter(Boolean)

// The node's own page, or null when it has none (no path, or deeper than the second level).
export function categoryPage(path) {
  const s = segmentsOf(path)
  if (s.length === 1) return `/category/${s[0]}`
  if (s.length === 2) return `/subtypes/${s[1]}`
  return null
}

// The deepest page on the way to the node — for a "See all" link: a third-level node sends the shopper to its
// second-level parent. No path at all: the whole listing.
export function nearestCategoryPage(path) {
  const s = segmentsOf(path)
  if (s.length >= 2) return `/subtypes/${s[1]}`
  if (s.length === 1) return `/category/${s[0]}`
  return '/listing'
}
