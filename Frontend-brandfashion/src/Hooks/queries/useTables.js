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

// BOTH storefronts' listings read v2 (Brand Fashion 2026-10-17, Watchizer 2026-10-22), and a v2 category is
// addressed by the storefront tree's own slug — which is not always the slug of the node's English name:
// Chargers & Cables is `chargers`, Earphones & Headphones is `audio` (measured 2026-10-18). Sending the name's
// slug asked v2 for a path it does not know, and those menu entries opened EMPTY pages. Each category type and
// sub-type is tagged `v2_slug` (the tree node with the same English name) where the two differ; everything else
// is untouched, so every other request is the same string as before.
//
// THE LOOKUP TABLES THEMSELVES STAY ON COMPAT. `catalog/meta` is still the source: slice 2 of the v2 move — the
// home page, the menu and these tables — is deliberately NOT in this wave, because v2's meta does not carry the
// legacy shapes these consumers read. This is one extra v2 READ for a slug mapping, not a move. (BF-MENU-V2
// replaces it.) The tree is an enrichment: if it cannot be read, the names' slugs are sent, as before.
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
