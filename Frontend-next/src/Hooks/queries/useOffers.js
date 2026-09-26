import { useQuery } from '@tanstack/react-query'

// Legacy OFFERS were deleted (Phase 0, G11 — promotions replace them), and `/all_offer` no longer
// exists: core forwards it to the retired legacy host, which answers nothing, so EVERY page view
// logged a failed request (batch 1, 2026-09-26). The hook stays — cart, checkout, product and
// account read `offers` — but it returns the empty list legacy was already returning, with no
// request at all. `client` is kept so the server-side prefetch's call still type-checks.
export const offersQueryFn = (client) => async () => []

export const useOffers = () =>
  useQuery({
    queryKey: ['offers'],
    queryFn: offersQueryFn(),
    staleTime: Infinity,
  })

export default useOffers
