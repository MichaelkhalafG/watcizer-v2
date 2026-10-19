'use client'
import { memo } from 'react'
import { useUIStore } from '../../Store/uiStore'
import { useToastStore } from '../../Store/toastStore'
import useCart from '../../Hooks/useCart'
import { refusedAddMessage } from '../../Store/cartStore'
import { localizeHref } from '../../utils/localePath'
import { productUrl } from '../../utils/productUrl'
import { fromLegacyCard } from '../../lib/cardData'
import BfProductCard from './BfProductCard'

/*
 * Brand Fashion — DIVERGED from Frontend-next on 2026-10-18 (the visual reset, Part 1b). Watchizer's listing
 * components (ListingClient, SideBar, SmartSuggestions, the sliders) are the twins and render `<ProductCard>`;
 * here that name is an ADAPTER: the legacy row Watchizer's pipeline hands it becomes the v2 card shape and is
 * drawn by BfProductCard (spec §17.3: the card is rewritten; §8). The BEHAVIOUR is Watchizer's card's, line for
 * line: the same `addItem` payload (product_id, quantity 1, the effective piece price, Express when express stock
 * exists else Market, the English name for analytics), the same refusal toast, the same product URL. Only the look
 * and the card's own additions (hover photo, descriptive colours as text, the picker) are Brand Fashion's.
 */
const ProductCard = ({ product, showRating = true }) => {
  const { language } = useUIStore()
  const { addItem } = useCart()
  const showToast = useToastStore((s) => s.showToast)
  if (!product) return null
  const lang = language === 'ar' ? 'ar' : 'en'
  const card = fromLegacyCard(product, lang)
  const href = localizeHref(card.slug ? `/product/${card.slug}` : productUrl(product), lang)

  const onAdd = async () => {
    const price = Number(product.selling_price || 0)
    const salePrice = Number(product.sale_price_after_discount || 0)
    const hasSale = salePrice > 0 && salePrice < price
    const isExpress = Number(product.stock || 0) > 0
    // The English title for analytics only — as Watchizer's card: one product, one name in every report.
    const en = product.translations?.find((x) => x.locale === 'en')
    const analyticsName = en?.product_title || product.product_title || product.name_en || product.name || ''
    const result = await addItem({
      product_id: product.id,
      quantity: 1,
      piece_price: hasSale ? salePrice : price,
      type_stock: isExpress ? 'Express' : 'Market',
      name: analyticsName,
    })
    // A refused add was rolled back by the cart store; say why (Watchizer's rule of 2026-09-29).
    if (result && result.ok === false) {
      showToast(refusedAddMessage(result, lang === 'ar'), 'error')
      return false
    }
    return true
  }

  return <BfProductCard product={card} lang={lang} showRating={showRating} href={href} onAdd={onAdd} />
}

// Watchizer's own comparison: skip a re-render when the card's meaningful data is unchanged.
const areEqual = (prev, next) => {
  const a = prev.product
  const b = next.product
  return (
    a?.id === b?.id &&
    a?.product_title === b?.product_title &&
    a?.brand === b?.brand &&
    a?.selling_price === b?.selling_price &&
    a?.sale_price_after_discount === b?.sale_price_after_discount &&
    a?.stock === b?.stock &&
    a?.market_stock === b?.market_stock &&
    prev.showRating === next.showRating
  )
}

export default memo(ProductCard, areEqual)
