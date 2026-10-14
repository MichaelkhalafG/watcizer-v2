import { useQuery } from '@tanstack/react-query'
import http from '../../Context/api'

// Shipping cities — almost never change → long staleTime. Returns the raw city
// rows; MyProvider maps them into { id, GovernorateEn/Ar, Price } for consumers.
export const useShipping = () =>
  useQuery({
    queryKey: ['shipping'],
    queryFn: async () => {
      const { data } = await http.get('/show_shipping_city')
      return Array.isArray(data) ? data : []
    },
    staleTime: 60 * 60 * 1000, // 60 min
  })

export default useShipping
