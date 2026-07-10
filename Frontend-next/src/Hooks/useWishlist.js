import { useCallback } from 'react'
import { useRouter } from 'next/navigation'
import { useUIStore } from '../Store/uiStore'
import { useAuthStore } from '../Store/authStore'
import { useToastStore } from '../Store/toastStore'
import { useCatalog } from './queries/useCatalog'
import { useOffers } from './queries/useOffers'
import http, { fetchWishList } from '../Context/api'

// Wishlist read + toggle, extracted from MyProvider. The wishList array lives in
// uiStore (shared, so every heart stays in sync); this hook exposes it plus the
// single add/remove toggle used by every heart button. Behaviour is byte-for-byte
// the same as MyProvider's handleAddTowishlist — only the state source changed
// (context → Zustand). The background fetch/refresh on login lives in
// <AppStateBridge/>; this hook re-fetches after an add so the new entry carries
// full product data.
export const useWishlist = () => {
  const router = useRouter()
  const wishList = useUIStore((s) => s.wishList)
  const setwishList = useUIStore((s) => s.setWishList)
  const user_id = useAuthStore((s) => s.userId)
  const language = useUIStore((s) => s.language)
  const showToast = useToastStore((s) => s.showToast)
  const { products } = useCatalog()
  const { data: offers = [] } = useOffers()

  const handleAddTowishlist = useCallback(
    async (id, type) => {
      if (!user_id) {
        showToast(
          language === 'ar' ? 'يجب تسجيل الدخول أولاً!' : 'You must login first!',
          'warning',
        )
        router.push('/login')
        return
      }

      const existing = wishList.find((w) =>
        type === 'p'
          ? Number(w.product_id) === Number(id)
          : Number(w.offer_id) === Number(id),
      )

      try {
        if (existing) {
          // Remove — delete the wishlist_item row, then drop it from state.
          await http.delete(`/delete_wishlist/${existing.id}`)
          setwishList((prev) => prev.filter((w) => w.id !== existing.id))
          showToast(
            language === 'ar' ? 'تمت الإزالة من المفضلة' : 'Removed from the Wish List!',
            'success',
          )
        } else {
          // Add — then re-fetch so the new entry carries full product data.
          const payload = {
            user_id: user_id,
            ...(type === 'p' ? { product_id: id } : { offer_id: id }),
          }
          await http.post('/add_wishlist', payload)
          await fetchWishList(user_id, products, offers, language, setwishList)
          showToast(
            language === 'ar' ? 'تمت الإضافة إلى المفضلة' : 'Added to the Wish List!',
            'success',
          )
        }
      } catch {
        showToast(
          language === 'ar'
            ? 'حدث خطأ، حاول مرة أخرى.'
            : 'Something went wrong, please try again.',
          'error',
        )
      }
    },
    [language, router, user_id, offers, products, showToast, wishList, setwishList],
  )

  return { wishList, setwishList, handleAddTowishlist }
}

export default useWishlist
