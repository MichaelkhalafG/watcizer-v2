import { useQuery } from '@tanstack/react-query'
import http from '../../Context/api'
import { transformProductData } from '../../utils/transformProduct'

// The full catalog. The backend serves everything from /all_product (no server
// filtering), so — per the migration's discovery rule — we keep the bulk fetch
// but run it through TanStack Query for caching / dedupe / stale-while-revalidate.
//
// Needs the lookup `tables` to localize each product, so it's `enabled` only once
// tables are present and the transform runs ONCE per fetch (inside queryFn, not
// on every render). Both EN + AR variants are produced up-front so a language
// switch is instant (no refetch) — matching the previous behaviour exactly.
// Shared query fn: client `http` by default; the SERVER passes serverFetch so the
// same fetch + transform + return shape is reused for SSR prefetch (queryKey
// ['products'] and the data shape stay identical → client cache-hits, no refetch).
export const productsQueryFn = (tables, client = http) => async () => {
  const [p, r, i] = await Promise.all([
    client.get('/all_product'),
    client.get('/all_product_rating'),
    client.get('/all_product_image'),
  ])
  return {
    ratings: r.data,
    productsEn: transformProductData(p.data, tables, r.data, i.data, 'en'),
    productsAr: transformProductData(p.data, tables, r.data, i.data, 'ar'),
  }
}

export const useProducts = (tables) => {
  const hasTables = !!tables && Object.keys(tables).length > 0

  return useQuery({
    queryKey: ['products'],
    enabled: hasTables,
    staleTime: 5 * 60 * 1000, // 5 min
    queryFn: productsQueryFn(tables),
  })
}

export default useProducts
