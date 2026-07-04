import { useQuery } from '@tanstack/react-query'
import http from '../../Context/api'
import { getImageUrl } from '../../utils/imageUrl'

// Offer transform — identical to the old api.jsx fetchOffers(), producing the flat
// bilingual shape (offer_name_en/ar, prices, image URL, offer_rating[]) consumers read.
const transformOffers = (data) =>
  (data || []).map((offer) => {
    const tr = (locale, key) =>
      offer.translations.find((t) => t.locale === locale)?.[key]
    return {
      id: offer.id,
      main_product_id: offer.main_product_id,
      category_type_id: offer.category_type_id,
      gift_product_ids: offer.gift_product_ids.map((id) => parseInt(id)),
      selling_price: parseFloat(offer.selling_price),
      sale_price_after_discount: parseFloat(offer.sale_price_after_discount),
      stock: offer.stock,
      image: getImageUrl(offer.image, 'Offer'),
      average_rate: offer.average_rate ? parseFloat(offer.average_rate) : null,
      created_at: offer.created_at,
      updated_at: offer.updated_at,
      short_description_en: tr('en', 'short_description') || 'No Description',
      short_description_ar: tr('ar', 'short_description') || 'No Description',
      long_description_en: tr('en', 'long_description') || 'No Description',
      in_season: offer.in_season,
      long_description_ar: tr('ar', 'long_description') || 'No Description',
      offer_name_en: tr('en', 'offer_name') || 'Unnamed Offer',
      offer_name_ar: tr('ar', 'offer_name') || 'Unnamed Offer',
      offer_rating: offer.offer_rating.map((rating) => ({
        id: rating.id,
        user_id: rating.user_id,
        offer_id: rating.offer_id,
        rating: parseInt(rating.rating),
        comment: rating.comment,
        created_at: rating.created_at,
        updated_at: rating.updated_at,
      })),
    }
  })

export const useOffers = () =>
  useQuery({
    queryKey: ['offers'],
    queryFn: async () => {
      const { data } = await http.get('/all_offer')
      return transformOffers(data)
    },
    staleTime: 5 * 60 * 1000, // 5 min
  })

export default useOffers
