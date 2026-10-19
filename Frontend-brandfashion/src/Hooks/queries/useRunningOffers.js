import { useQuery } from '@tanstack/react-query'
import publicHttp from '../../Context/publicApi'
import { STOREFRONT_CODE } from '../../lib/env'

// The offers this storefront runs NOW — core's v2 `offers` (the dashboard's promotions; private, never cached).
// Brand Fashion's navigation shows its Offers entry only while there is at least one (Michael, 2026-10-19), and the
// entry comes back by itself the moment the dashboard starts an offer: a fresh read on every page load and every
// return to the tab (staleTime 0), nothing cached on the way (core's OffersTest proves the read is immediate).
export const useRunningOffers = () =>
  useQuery({
    queryKey: ['v2-running-offers', STOREFRONT_CODE],
    queryFn: async () => {
      const { data } = await publicHttp.get(`v2/${STOREFRONT_CODE}/offers`)
      return Array.isArray(data?.data) ? data.data : []
    },
    staleTime: 0,
    retry: 1,
  })

/** True while at least one offer runs; false while loading, on an error and when none runs. */
export const useHasOffers = () => (useRunningOffers().data?.length ?? 0) > 0
