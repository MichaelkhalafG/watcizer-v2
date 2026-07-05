import { useQuery } from '@tanstack/react-query'
import http from '../../Context/api'

// Single product by its readable slug/name, via the legacy by-name endpoint that
// ProductDetail already uses as its fallback. Available for a future ProductDetail
// migration; ProductDetail currently still resolves from the cached catalog first.
export const useProductDetail = (slug) =>
  useQuery({
    queryKey: ['product-detail', slug],
    enabled: !!slug,
    staleTime: 5 * 60 * 1000, // 5 min
    queryFn: async () => {
      const { data } = await http.get(
        `products/by-name/${encodeURIComponent(decodeURIComponent(slug))}`,
      )
      return data
    },
  })

export default useProductDetail
