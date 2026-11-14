import { useQuery } from '@tanstack/react-query'
import http from '../../Context/api'
import { STOREFRONT_CODE } from '../../lib/env'
import { PICKUP_OFF, pickupFrom } from '../../lib/pickup'

// Whether this shop offers "pick up in store" right now, and its stores (click & collect, 2026-11-14). The page's
// server render hands over its own read (`initial`, from getServerV2Pickup) so the choice is in the first paint and
// nothing moves when this answer lands; the browser asks again after a minute, so the dashboard's switch reaches an
// open page. A failed read is "not offered" — the shop's delivery checkout is unchanged then. A TWIN.
export const usePickup = (initial) => {
  const { data } = useQuery({
    queryKey: ['pickup', STOREFRONT_CODE],
    queryFn: async () => pickupFrom((await http.get(`/v2/${STOREFRONT_CODE}/pickup`)).data),
    initialData: initial ? pickupFrom(initial) : undefined,
    staleTime: 60 * 1000,
    retry: 1,
  })
  return data || PICKUP_OFF
}

export default usePickup
