// The shop's menu as LINKS that answer (2026-11-14, SEO Phase 1 + Search Console). A TWIN.
//
// A menu node's page is the slug of its ENGLISH NAME — the rule the facet routes resolve and core's sitemap lists
// (`/subtypes/` + the slug of the sub-type's English name). The node's own `slug` is NOT that address everywhere:
// measured live, `audio` (Earphones & Headphones) answered 404 on both shops, `chargers` and Brand Fashion's
// `accessories-2` answered with a redirect, and Brand Fashion's "Shop by material" (295 products) has no page at all.
// With the lookup tables at hand a node that is not a real sub-type gets NO page (null) — never a link to a 404.
import { toSlug, subTypeSlug, categorySlug, brandSlug } from '../utils/slugs'

export const nodeSlug = (node) => toSlug(node?.name?.en || '') || node?.slug || ''

// `/subtypes/{slug}` for a second-level node; null when the tables say there is no such sub-type. Without tables
// (not loaded yet) the English-name slug is trusted.
export function subTypePage(node, tables) {
  const slug = nodeSlug(node)
  if (!slug) return null
  const list = tables?.subTypes
  if (Array.isArray(list) && list.length && !list.some((s) => subTypeSlug(s) === slug)) return null
  return `/subtypes/${slug}`
}

// `/category/{slug}` for a top-level node; null when the tables say there is no such category.
export function categoryTypePage(node, tables) {
  const slug = nodeSlug(node)
  if (!slug) return null
  const list = tables?.categoryTypes
  if (Array.isArray(list) && list.length && !list.some((c) => categorySlug(c) === slug)) return null
  return `/category/${slug}`
}

// A menu tile's link: its own page, else its parent category's, else the whole listing — a tile is always clickable.
// A tile that names its own page goes there (2026-11-14: core's "Original watches" collection, first under Watches).
export const menuChildHref = (child, parent, tables) =>
  (typeof child?.href === 'string' && child.href.startsWith('/') ? child.href : null) || subTypePage(child, tables) || categoryTypePage(parent, tables) || '/listing'

// The directory the server renders under every page: each category with the sub-categories that have a page and
// products, and every brand with products. Only links that answer; nothing else.
export function directoryOf(menu, tables) {
  const categories = []
  for (const n of menu?.categories || []) {
    const href = categoryTypePage(n, tables)
    if (!href) continue
    const children = []
    for (const c of n.children || []) {
      if (Number(c?.count ?? 1) <= 0) continue
      const page = (typeof c?.href === 'string' && c.href.startsWith('/') ? c.href : null) || subTypePage(c, tables)
      if (page && !children.some((x) => x.href === page)) children.push({ href: page, name: c.name })
    }
    categories.push({ href, name: n.name, children })
  }
  const brands = []
  for (const b of menu?.brands || []) {
    if (!b?.slug || Number(b.count ?? 0) <= 0) continue
    const href = `/brand/${b.slug}`
    // the brand's id too (2026-11-14): /llms.txt leaves out a brand whose products are all kept from search
    if (!brands.some((x) => x.href === href)) brands.push({ href, name: b.name, id: b.id ?? null })
  }
  return { categories, brands }
}

// A brand's / a top-level category's own page from a lookup-table id (2026-11-14): every internal link names the page
// (/brand/{slug}, /category/{slug}), never a filtered listing (/listing?brand=…) — the brief's item 6, and the crawl
// found 24 of 29 brand pages linked from nowhere because the site linked the filter instead. Null when the id is unknown.
export function brandPageOf(id, tables) {
  const b = (tables?.brands || []).find((x) => x.id === id)
  const slug = b ? brandSlug(b) : ''
  return slug ? `/brand/${slug}` : null
}

export function categoryPageOf(id, tables) {
  const c = (tables?.categoryTypes || []).find((x) => x.id === id)
  const slug = c ? categorySlug(c) : ''
  return slug ? `/category/${slug}` : null
}
