import { useQuery } from '@tanstack/react-query'
import http from '../../Context/api'

// Home/side/bottom banners. `allSettled` so one failing banner endpoint doesn't
// blank the others. Returns the same 4 buckets MyProvider exposed.
export const useBanners = () =>
  useQuery({
    queryKey: ['banners'],
    queryFn: async () => {
      const endpoints = ['all_banner_side', 'all_banner_bottom', 'all_banner_home']
      const responses = await Promise.allSettled(endpoints.map((e) => http.get(`/${e}`)))
      const [side, bottom, home] = responses.map((r) =>
        r.status === 'fulfilled' ? r.value.data : [],
      )
      return {
        sideBanners: side,
        bottomBanners: bottom,
        homeBannersPc: home.filter((b) => b.type_show === 'pc'),
        homeBannersMob: home.filter((b) => b.type_show === 'mob'),
      }
    },
    staleTime: 30 * 60 * 1000, // 30 min
  })

export default useBanners
