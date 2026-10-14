import { useQuery } from '@tanstack/react-query'
import http from '../../Context/api'
import { STOREFRONT_CODE } from '../../lib/env'

// The online payment methods the checkout may offer — core's v2 list (batch 1, 2026-09-26):
// only rows a shopper can actually pay with, each with both labels and optional order limits
// (`min_total` / `max_total`, EGP, null = none). Cash on delivery is NOT in this list's hands:
// the checkout always offers it.
//
// Short-lived on purpose: switching a method off in the dashboard (Apple Pay is switched exactly
// this way) must reach the checkout quickly, and core caches it for 60 s to match.
export const usePaymentMethods = () =>
  useQuery({
    queryKey: ['payment-methods', STOREFRONT_CODE],
    queryFn: async () => {
      const { data } = await http.get(`/v2/${STOREFRONT_CODE}/payment-methods`)
      return Array.isArray(data?.data) ? data.data : []
    },
    staleTime: 60 * 1000,
    retry: 1,
  })

export default usePaymentMethods
