import { memo, useEffect, useRef } from 'react'
import { useNavigate } from 'react-router-dom'
import { FiX } from 'react-icons/fi'
import { useCatalog } from '../../Hooks/queries/useCatalog'
import { useOffers } from '../../Hooks/queries/useOffers'
import { useUIStore } from '../../Store/uiStore'
import { getImageUrl, handleImgError, PLACEHOLDER_IMG } from '../../utils/imageUrl'
import { productUrl, offerUrl } from '../../utils/productUrl'
import TrustSignals from '../../Components/Merchandising/TrustSignals'
import './Cart.css'

// Slide-in "added to cart" drawer. Same props as before ({ open, onClose, cart })
// so App.jsx is unchanged. Shows the just-added item highlighted, then the rest
// of the cart, with a subtotal + actions footer. Auto-closes after 6s unless the
// user interacts. No MUI.
function CartModal({ open, onClose, cart }) {
  const { products } = useCatalog()
  const { data: offers = [] } = useOffers()
  const { language } = useUIStore()
  const isRTL = language === 'ar'
  const t = (en, ar) => (isRTL ? ar : en)
  const navigate = useNavigate()
  const timer = useRef(null)
  const asideRef = useRef(null)
  const triggerRef = useRef(null)
  // Latest onClose without re-running the focus-trap effect on every App render.
  const onCloseRef = useRef(onClose)
  useEffect(() => {
    onCloseRef.current = onClose
  }, [onClose])

  const items = cart?.cart_item || []
  // The most-recently added/updated line sits last in the array.
  const justAdded = items[items.length - 1]
  const others = items.slice(0, -1)

  // Auto-close after 6s; cancel on any interaction with the drawer.
  useEffect(() => {
    if (!open) return
    timer.current = setTimeout(onClose, 6000)
    return () => clearTimeout(timer.current)
  }, [open, onClose])
  const cancelAuto = () => {
    if (timer.current) clearTimeout(timer.current)
  }

  // ── Focus management ──────────────────────────────────────────────────────
  // On open: remember the trigger, move focus into the drawer, trap Tab and
  // close on Escape. On close (effect cleanup): restore focus to the trigger.
  // (When closed the drawer is `inert`, so it's already out of the tab order.)
  useEffect(() => {
    if (!open) return
    triggerRef.current = document.activeElement
    const node = asideRef.current
    if (!node) return

    const focusablesIn = () =>
      Array.from(
        node.querySelectorAll(
          'a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])',
        ),
      ).filter((el) => el.offsetParent !== null)

    // Move focus into the drawer (first focusable = the close button).
    ;(focusablesIn()[0] || node).focus()

    const onKeyDown = (e) => {
      if (e.key === 'Escape') {
        onCloseRef.current?.()
        return
      }
      if (e.key !== 'Tab') return
      const f = focusablesIn()
      if (!f.length) return
      const first = f[0]
      const last = f[f.length - 1]
      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault()
        last.focus()
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault()
        first.focus()
      }
    }
    node.addEventListener('keydown', onKeyDown)

    return () => {
      node.removeEventListener('keydown', onKeyDown)
      const trigger = triggerRef.current
      if (trigger && typeof trigger.focus === 'function') trigger.focus()
    }
  }, [open])

  const currency = isRTL ? 'ج.م' : 'EGP'
  const fmt = (v) => Math.round(Number(v) || 0).toLocaleString(isRTL ? 'ar-EG' : 'en-US')

  const resolve = (item) => {
    if (item.product_id) {
      const p = (products || []).find((x) => x.id === item.product_id)
      return {
        name: p?.name || p?.product_title || '',
        image: getImageUrl(p?.image) || PLACEHOLDER_IMG,
        url: p ? productUrl(p) : '/listing',
      }
    }
    const o = (offers || []).find((x) => String(x.id) === String(item.offer_id))
    return {
      name: o ? (isRTL ? o.offer_name_ar : o.offer_name_en) : '',
      image: getImageUrl(o?.image) || PLACEHOLDER_IMG,
      url: o ? offerUrl(o) : '/offers',
    }
  }

  const subtotal = items.reduce((s, i) => s + Number(i.piece_price) * i.quantity, 0)
  const count = items.reduce((s, i) => s + i.quantity, 0)

  const goCart = () => {
    onClose()
    navigate('/cart')
  }

  return (
    <>
      <div className={`wz-cartmodal-overlay${open ? ' is-open' : ''}`} onClick={onClose} />
      <aside
        ref={asideRef}
        className={`wz-cartmodal${open ? ' is-open' : ''}`}
        dir={isRTL ? 'rtl' : 'ltr'}
        role="dialog"
        aria-modal="true"
        aria-label={t('Cart', 'السلة')}
        tabIndex={-1}
        inert={!open ? '' : undefined}
        onMouseEnter={cancelAuto}
        onTouchStart={cancelAuto}
      >
        {/* Header */}
        <div className="wz-cartmodal-head">
          <h3>
            {t('Cart', 'السلة')}
            {count > 0 && <span className="wz-cartmodal-count"> ({count})</span>}
          </h3>
          <button className="wz-cartmodal-close" onClick={onClose} aria-label={t('Close', 'إغلاق')}>
            <FiX />
          </button>
        </div>

        {/* Just added */}
        {justAdded &&
          (() => {
            const d = resolve(justAdded)
            return (
              <div className="wz-cartmodal-added">
                <span className="wz-cartmodal-added-label">✓ {t('Just added', 'تمت الإضافة')}</span>
                <div className="wz-cartmodal-added-item">
                  <img src={d.image} alt={d.name} width="56" height="56" loading="lazy" onError={handleImgError} />
                  <div className="wz-cartmodal-added-info">
                    <p className="wz-cartmodal-item-name">{d.name}</p>
                    <span className="wz-cartmodal-item-price">
                      {fmt(justAdded.piece_price)} {currency}
                    </span>
                  </div>
                </div>
              </div>
            )
          })()}

        {/* Other items (scrollable) */}
        <div className="wz-cartmodal-body">
          {others.length > 0 && (
            <ul className="wz-cartmodal-list">
              {others.map((item) => {
                const d = resolve(item)
                return (
                  <li className="wz-cartmodal-row" key={`${item.product_id || 'o'}-${item.offer_id || ''}-${item.id}`}>
                    <img src={d.image} alt={d.name} width="48" height="48" loading="lazy" onError={handleImgError} />
                    <div className="wz-cartmodal-row-info">
                      <p className="wz-cartmodal-row-name">{d.name}</p>
                      <span className="wz-cartmodal-row-meta">
                        {t('Qty', 'الكمية')} {item.quantity} · {fmt(Number(item.piece_price) * item.quantity)} {currency}
                      </span>
                    </div>
                  </li>
                )
              })}
            </ul>
          )}
        </div>

        {/* Footer */}
        <div className="wz-cartmodal-foot">
          <TrustSignals variant="cart" isRTL={isRTL} />
          <div className="wz-cartmodal-subtotal">
            <span>{t('Subtotal', 'المجموع الفرعي')}</span>
            <span className="wz-cartmodal-subtotal-val">
              {fmt(subtotal)} {currency}
            </span>
          </div>
          <button className="wz-cartmodal-view" onClick={goCart}>
            {t('VIEW CART', 'عرض السلة')}
          </button>
          <button className="wz-cartmodal-cont" onClick={onClose}>
            {t('Continue Shopping', 'متابعة التسوق')}
          </button>
        </div>
      </aside>
    </>
  )
}

export default memo(CartModal)
