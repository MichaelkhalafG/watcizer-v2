import { useQuery } from '@tanstack/react-query'
import http from '../../Context/api'

// All 11 lookup tables in ONE cached call (catalog/meta) instead of 11 separate
// all_* requests. The `tables` payload is the raw Eloquent shape — each row
// carries a translations[] array — exactly what transformProductData and the
// SPA filters/nav (SideBar, Nav) already consume. Rarely change →
// long staleTime.
export const useTables = () =>
  useQuery({
    queryKey: ['tables'],
    queryFn: async () => {
      const { data } = await http.get('catalog/meta')
      return data.tables
    },
    staleTime: 30 * 60 * 1000, // 30 min — lookup tables rarely change
  })

export default useTables
