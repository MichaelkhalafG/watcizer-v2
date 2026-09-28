import { useMemo } from 'react'
import { useQuery, keepPreviousData } from '@tanstack/react-query'
import publicHttp from '../../Context/publicApi'
import { listingRequest, listingQueryFn } from '../../lib/listingRequest'
import { useTables } from './useTables'
import { transformProductData } from '../../utils/transformProduct'

// The listing on the server (C-1 stage 3, 2026-09-27) — core's `catalog/listing`: one page of
// products plus every facet count, for the storefront's own filter state. The browser used to
// download the whole catalogue and do this itself on every filter change.
//
// ONE builder turns the filter state into the request, and the request string IS the query key
// (src/lib/listingRequest.js — pure, so the server pages prefetch with it too).

export const useListing = (args, options = {}) => {
  const qs = listingRequest(args)
  return useQuery({
    queryKey: ['listing', qs],
    queryFn: listingQueryFn(qs),
    // Keep showing the previous page's cards while the next filter state loads — no skeleton flash.
    placeholderData: keepPreviousData,
    staleTime: 60 * 1000,
    ...options,
  })
}

// Raw rows (+ their ratings and images) → the storefront's card shape, in the SERVER's order.
// transformProductData re-sorts (out of stock last, newest first); a page sorted by price must
// keep the order the server gave it, so the order is restored afterwards.
export const useCardsOf = (payload, language) => {
  const { data: tables } = useTables()
  return useMemo(() => {
    const rows = payload?.products
    if (!rows?.length || !tables) return []
    const order = new Map(rows.map((p, i) => [p.id, i]))
    return transformProductData(rows, tables, payload.ratings || [], payload.images || [], language).sort(
      (a, b) => order.get(a.id) - order.get(b.id),
    )
  }, [payload, tables, language])
}

// Cards by id — the cart drawer's lines. Ids are sorted so the same set is one cache entry.
export const useCards = (ids) => {
  const key = [...new Set((ids || []).filter(Boolean))].sort((a, b) => a - b).join(',')
  return useQuery({
    queryKey: ['cards', key],
    queryFn: async () => {
      // Header-less (no CORS preflight) — see Context/publicApi.js.
      const client = publicHttp
      const { data } = await client.get(`catalog/cards?ids=${key}`)
      return data
    },
    enabled: key !== '',
    staleTime: 5 * 60 * 1000,
  })
}

export { listingRequest, listingQueryFn }
export default useListing
