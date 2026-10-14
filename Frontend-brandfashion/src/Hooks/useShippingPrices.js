import { useMemo } from 'react'
import { useShipping } from './queries/useShipping'

// Maps the raw shipping-city rows (TanStack Query) into the shape consumers
// read: { id, GovernorateEn, GovernorateAr, Price }. This was MyProvider's
// `shippingPrices` useMemo, moved verbatim so Cart/Checkout/Account and the
// <AppStateBridge/> default-selection effect all derive it identically.
export const useShippingPrices = () => {
  const { data: shippingData = [] } = useShipping()
  return useMemo(
    () =>
      shippingData.map((city) => ({
        id: city.id.toString(),
        GovernorateEn:
          city.translations.find((t) => t.locale === 'en')?.city_name || city.city_name,
        GovernorateAr:
          city.translations.find((t) => t.locale === 'ar')?.city_name || city.city_name,
        Price: parseFloat(city.shipping_cost),
      })),
    [shippingData],
  )
}

export default useShippingPrices
