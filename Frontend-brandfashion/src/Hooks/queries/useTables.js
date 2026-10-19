import { useQuery } from '@tanstack/react-query'
import http from '../../Context/api'
import publicHttp from '../../Context/publicApi'
import { STOREFRONT_CODE } from '../../lib/env'
import { toSlug, categorySlug, subTypeSlug } from '../../utils/slugs'

// All 11 lookup tables in ONE cached call (catalog/meta) instead of 11 separate
// all_* requests. The `tables` payload is the raw Eloquent shape — each row
// carries a translations[] array — exactly what transformProductData and the
// SPA filters/nav (SideBar, Nav) already consume. Rarely change →
// long staleTime.
// Shared query fn: client `http` by default; the SERVER passes serverFetch so the
// SSR prefetch reuses the exact same fetch + return shape (queryKey ['tables']).
export const tablesQueryFn = (client = http) => async () => {
  const { data } = await client.get('catalog/meta')
  return withV2Slugs(data.tables, client === http ? publicHttp : client)
}

// Brand Fashion (DIVERGED 2026-10-18, round 4 Part 6.2): the listing reads v2, whose category tree is storefront 2's
// own, and a node's slug is not always the slug of its English name — Chargers & Cables is `chargers`, Earphones &
// Headphones is `audio` — so the v2 request builder sent a path v2 does not know and those two menu entries opened
// EMPTY pages. Each category type and sub-type is tagged `v2_slug` (the tree node with the same English name) where
// the two differ; everything else is untouched, so every other request is the same string as before. The tree is
// an enrichment: if it cannot be read, the names' slugs are sent, as before. (BF-MENU-V2 replaces this.)
async function withV2Slugs(tables, client) {
  if (!tables) return tables
  let tree = []
  try {
    const { data } = await client.get(`v2/${STOREFRONT_CODE}/categories`)
    tree = Array.isArray(data?.tree) ? data.tree : []
  } catch {
    return tables
  }
  const byName = (nodes) => {
    const m = new Map()
    for (const n of nodes) {
      const k = toSlug(n?.name?.en ?? '')
      if (k && !m.has(k)) m.set(k, n)
    }
    return m
  }
  const tag = (list, slugOf, nodes, pick) =>
    (list || []).map((item) => {
      const node = nodes.get(slugOf(item))
      const v2 = node ? pick(node) : null
      return v2 && v2 !== slugOf(item) ? { ...item, v2_slug: v2 } : item
    })
  return {
    ...tables,
    categoryTypes: tag(tables.categoryTypes, categorySlug, byName(tree), (n) => n.path),
    subTypes: tag(tables.subTypes, subTypeSlug, byName(tree.flatMap((n) => n.children || [])), (n) => n.slug),
  }
}

export const useTables = () =>
  useQuery({
    queryKey: ['tables'],
    queryFn: tablesQueryFn(),
    staleTime: 30 * 60 * 1000, // 30 min — lookup tables rarely change
  })

export default useTables
