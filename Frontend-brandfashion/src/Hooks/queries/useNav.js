import { useQuery } from '@tanstack/react-query'
import http from '../../Context/api'

// The header menu's catalogue facts (C-1 stage 2, 2026-09-27) — core's `catalog/nav`: the brand
// ids that have products, the sub-type and brand ids per category type, and the genders with both
// names. The menu used to derive these in the browser from the whole catalogue, on every render.
// Shared query fn: the SERVER passes serverFetch so the layout prefetches the same shape.
export const navQueryFn = (client = http) => async () => {
  const { data } = await client.get('catalog/nav')
  return {
    brand_ids: data?.brand_ids || [],
    sub_types_by_category: data?.sub_types_by_category || {},
    brands_by_category: data?.brands_by_category || {},
    genders: data?.genders || [],
  }
}

export const useNav = () =>
  useQuery({
    queryKey: ['nav'],
    queryFn: navQueryFn(),
    staleTime: 5 * 60 * 1000, // parity with the catalogue: core derives it from all_product
  })

export default useNav
