import { useQuery } from '@tanstack/react-query'
import http from '../../Context/api'

// The 11 lookup tables, fetched in parallel and keyed into the shape the catalog
// transform expects. Rarely change → long staleTime.
const TABLE_ENDPOINTS = [
  'all_category_type',
  'all_brand',
  'all_grade',
  'all_sub_type',
  'all_color',
  'all_material',
  'all_shape',
  'all_size_type',
  'all_display_type',
  'all_closure_type',
  'all_movement_type',
]

export const useTables = () =>
  useQuery({
    queryKey: ['tables'],
    queryFn: async () => {
      const res = await Promise.all(TABLE_ENDPOINTS.map((e) => http.get(`/${e}`)))
      return {
        categoryTypes: res[0].data,
        brands: res[1].data,
        grades: res[2].data,
        subTypes: res[3].data,
        colors: res[4].data,
        materials: res[5].data,
        shapes: res[6].data,
        sizeTypes: res[7].data,
        displayTypes: res[8].data,
        closureTypes: res[9].data,
        movementTypes: res[10].data,
      }
    },
    staleTime: 30 * 60 * 1000, // 30 min — lookup tables rarely change
  })

export default useTables
