'use client'
import {
  memo,
  useState,
  useEffect,
  useMemo,
  useCallback,
  useRef,
  useContext,
} from 'react'
import Link from 'next/link'
import { useRouter, useSearchParams, usePathname } from 'next/navigation'
import {
  FiUser,
  FiPackage,
  FiMapPin,
  FiHeart,
  FiLock,
  FiLogOut,
  FiEye,
  FiEyeOff,
  FiTrash2,
  FiPlus,
  FiCheck,
  FiUpload,
  FiShoppingBag,
} from 'react-icons/fi'
import { FaWhatsapp } from 'react-icons/fa'
import { FcGoogle } from 'react-icons/fc'
import { MyContext } from '../../Context/Context'
import { useCatalog } from '../../Hooks/queries/useCatalog'
import { useOffers } from '../../Hooks/queries/useOffers'
import { useUIStore } from '../../Store/uiStore'
import { useAuthStore } from '../../Store/authStore'
import { useToastStore } from '../../Store/toastStore'
import http from '../../Context/api'
import { getImageUrl, handleImgError } from '../../utils/imageUrl'
import { productUrl } from '../../utils/productUrl'
import './Account.css'

// Store WhatsApp support line (same number used in the order-confirmation
// email) — used for the "Track Order" action since there is no tracking page.
const SUPPORT_WHATSAPP = '201274550956'

const TABS = ['profile', 'orders', 'addresses', 'wishlist', 'password']

// ── helpers ────────────────────────────────────────────────────────────────
const money = (v, isRTL) =>
  `${Math.round(Number(v) || 0).toLocaleString(isRTL ? 'ar-EG' : 'en-US')} ${
    isRTL ? 'ج.م' : 'EGP'
  }`

const initialsOf = (first, last) =>
  `${(first || '').charAt(0)}${(last || '').charAt(0)}`.toUpperCase() || '?'

// Client-side downscale + recompress before upload (max 500px, ~80% quality).
// Falls back to the original file if anything goes wrong.
const compressImage = (file) =>
  new Promise((resolve) => {
    try {
      const img = new Image()
      const url = URL.createObjectURL(file)
      img.onload = () => {
        URL.revokeObjectURL(url)
        const max = 500
        const scale = Math.min(1, max / Math.max(img.width, img.height))
        const w = Math.round(img.width * scale)
        const h = Math.round(img.height * scale)
        const canvas = document.createElement('canvas')
        canvas.width = w
        canvas.height = h
        const ctx = canvas.getContext('2d')
        ctx.drawImage(img, 0, 0, w, h)
        canvas.toBlob(
          (blob) => {
            if (!blob) return resolve(file)
            resolve(new File([blob], 'avatar.jpg', { type: 'image/jpeg' }))
          },
          'image/jpeg',
          0.8,
        )
      }
      img.onerror = () => {
        URL.revokeObjectURL(url)
        resolve(file)
      }
      img.src = url
    } catch {
      resolve(file)
    }
  })

// ── Small shared bits ────────────────────────────────────────────────────────
const SectionTitle = ({ children }) => (
  <div className="wz-acc-sectitle">
    <h2>{children}</h2>
    <span className="wz-acc-sectitle-rule" />
  </div>
)

// Module-scoped so it keeps a stable identity across renders (a nested
// definition would remount the input on every keystroke and drop focus).
const PasswordField = ({ label, value, onChange, visible, onToggle }) => (
  <div className="wz-acc-field wz-acc-field-full">
    <label>{label}</label>
    <div className="wz-acc-input-pw">
      <input
        type={visible ? 'text' : 'password'}
        value={value}
        onChange={(e) => onChange(e.target.value)}
      />
      <button type="button" onClick={onToggle} tabIndex={-1} aria-label="toggle">
        {visible ? <FiEyeOff size={15} /> : <FiEye size={15} />}
      </button>
    </div>
  </div>
)

const Avatar = ({ src, first, last, size }) => (
  <div className="wz-acc-avatar" style={{ width: size, height: size }}>
    {src ? (
      <img src={src} alt={[first, last].filter(Boolean).join(' ') || 'Profile photo'} loading="lazy" onError={handleImgError} />
    ) : (
      <span style={{ fontSize: size * 0.36 }}>{initialsOf(first, last)}</span>
    )}
  </div>
)

const StatusBadge = ({ status, isRTL }) => {
  const s = (status || 'pending').toLowerCase()
  const label = {
    pending: isRTL ? 'قيد الانتظار' : 'Pending',
    processing: isRTL ? 'قيد التنفيذ' : 'Processing',
    completed: isRTL ? 'مكتمل' : 'Completed',
    cancelled: isRTL ? 'ملغي' : 'Cancelled',
  }
  return <span className={`wz-acc-badge wz-acc-badge-${s}`}>{label[s] || status}</span>
}

// =============================================================================
//  Sidebar
// =============================================================================
const Sidebar = memo(function Sidebar({ tab, onSelect, onSignOut, user, avatar, memberYear, t }) {
  const links = [
    { key: 'profile', icon: <FiUser />, label: t('Personal Info', 'المعلومات الشخصية') },
    { key: 'orders', icon: <FiPackage />, label: t('My Orders', 'طلباتي') },
    { key: 'addresses', icon: <FiMapPin />, label: t('Addresses', 'العناوين') },
    { key: 'wishlist', icon: <FiHeart />, label: t('Wishlist', 'المفضلة') },
    { key: 'password', icon: <FiLock />, label: t('Change Password', 'تغيير كلمة المرور') },
  ]

  return (
    <aside className="wz-account-sidebar">
      <div className="wz-acc-usercard">
        <Avatar src={avatar} first={user?.first_name} last={user?.last_name} size={72} />
        <div className="wz-acc-usercard-info">
          <p className="wz-acc-usercard-name">
            {`${user?.first_name || ''} ${user?.last_name || ''}`.trim() ||
              t('My Account', 'حسابي')}
          </p>
          {user?.email && <p className="wz-acc-usercard-email">{user.email}</p>}
          {memberYear && (
            <p className="wz-acc-usercard-since">
              {t('Member since', 'عضو منذ')} {memberYear}
            </p>
          )}
        </div>
      </div>

      <nav className="wz-acc-nav">
        {links.map((l) => (
          <button
            key={l.key}
            type="button"
            className={`wz-acc-navlink${tab === l.key ? ' is-active' : ''}`}
            onClick={() => onSelect(l.key)}
          >
            <span className="wz-acc-navlink-icon">{l.icon}</span>
            <span>{l.label}</span>
          </button>
        ))}
        <div className="wz-acc-nav-sep" />
        <button
          type="button"
          className="wz-acc-navlink wz-acc-navlink-danger"
          onClick={onSignOut}
        >
          <span className="wz-acc-navlink-icon">
            <FiLogOut />
          </span>
          <span>{t('Sign Out', 'تسجيل الخروج')}</span>
        </button>
      </nav>
    </aside>
  )
})

// =============================================================================
//  TAB: Personal Info
// =============================================================================
function ProfileTab({ t, isRTL }) {
  const user = useAuthStore((s) => s.user)
  const updateUser = useAuthStore((s) => s.updateUser)
  const avatarUrl = useAuthStore((s) => s.avatarUrl)
  const showToast = useToastStore((s) => s.showToast)

  const original = useRef({
    first_name: user?.first_name || '',
    last_name: user?.last_name || '',
    phone_number: user?.phone_number || '',
  })

  const [firstName, setFirstName] = useState(original.current.first_name)
  const [lastName, setLastName] = useState(original.current.last_name)
  const [phone, setPhone] = useState(original.current.phone_number)
  const [imageFile, setImageFile] = useState(null)
  const [preview, setPreview] = useState(user?.image || '')
  const [saving, setSaving] = useState(false)
  const [saved, setSaved] = useState(false)
  const fileRef = useRef(null)

  const hasPhoto = Boolean(preview)

  const isDirty =
    firstName !== original.current.first_name ||
    lastName !== original.current.last_name ||
    (phone || '') !== (original.current.phone_number || '') ||
    imageFile !== null

  const onPickFile = useCallback(async (e) => {
    const file = e.target.files?.[0]
    if (!file) return
    const compressed = await compressImage(file)
    setImageFile(compressed)
    setPreview(URL.createObjectURL(compressed))
  }, [])

  const onRemovePhoto = useCallback(async () => {
    // If it's only a not-yet-saved local pick, just discard it.
    if (imageFile) {
      setImageFile(null)
      setPreview(user?.image || '')
      return
    }
    try {
      await http.delete('/me/avatar')
      updateUser({ image: '' })
      setPreview('')
      showToast(t('Photo removed', 'تم حذف الصورة'), 'success')
    } catch {
      showToast(t('Failed to remove photo', 'فشل حذف الصورة'), 'error')
    }
  }, [imageFile, user?.image, updateUser, showToast, t])

  const onSave = useCallback(async () => {
    if (!firstName.trim() || !lastName.trim()) {
      showToast(t('First and last name are required', 'الاسم الأول والأخير مطلوبان'), 'warning')
      return
    }
    setSaving(true)
    try {
      let res
      if (imageFile) {
        const fd = new FormData()
        fd.append('first_name', firstName)
        fd.append('last_name', lastName)
        fd.append('phone_number', phone || '')
        fd.append('image', imageFile)
        res = await http.post('/updateProfile', fd)
      } else {
        res = await http.post('/updateProfile', {
          first_name: firstName,
          last_name: lastName,
          phone_number: phone || '',
        })
      }

      const updatedImage = res?.data?.user?.image
      updateUser({
        first_name: firstName,
        last_name: lastName,
        phone_number: phone || '',
        ...(updatedImage ? { image: avatarUrl(updatedImage) } : {}),
      })
      original.current = { first_name: firstName, last_name: lastName, phone_number: phone || '' }
      setImageFile(null)
      if (updatedImage) setPreview(avatarUrl(updatedImage))
      setSaved(true)
      setTimeout(() => setSaved(false), 1500)
    } catch {
      showToast(t('Failed to update profile', 'فشل تحديث الملف الشخصي'), 'error')
    } finally {
      setSaving(false)
    }
  }, [firstName, lastName, phone, imageFile, updateUser, avatarUrl, showToast, t])

  return (
    <div>
      <SectionTitle>{t('Personal Information', 'المعلومات الشخصية')}</SectionTitle>

      <div className="wz-acc-avatar-area">
        <Avatar src={preview} first={firstName} last={lastName} size={100} />
        <div className="wz-acc-avatar-actions">
          <button type="button" className="wz-acc-linkbtn" onClick={() => fileRef.current?.click()}>
            <FiUpload size={13} /> {t('Change photo', 'تغيير الصورة')}
          </button>
          {hasPhoto && (
            <button
              type="button"
              className="wz-acc-linkbtn wz-acc-linkbtn-danger"
              onClick={onRemovePhoto}
            >
              <FiTrash2 size={13} /> {t('Remove photo', 'حذف الصورة')}
            </button>
          )}
          <input
            ref={fileRef}
            type="file"
            accept="image/*"
            hidden
            onChange={onPickFile}
          />
        </div>
      </div>

      <div className="wz-acc-form">
        <div className="wz-acc-field">
          <label>{t('First Name', 'الاسم الأول')}</label>
          <input value={firstName} onChange={(e) => setFirstName(e.target.value)} />
        </div>
        <div className="wz-acc-field">
          <label>{t('Last Name', 'اسم العائلة')}</label>
          <input value={lastName} onChange={(e) => setLastName(e.target.value)} />
        </div>
        <div className="wz-acc-field">
          <label>{t('Email', 'البريد الإلكتروني')}</label>
          <div className="wz-acc-input-locked">
            <input value={user?.email || ''} readOnly disabled />
            <FiLock size={13} />
          </div>
          {user?.has_password === false && (
            <span className="wz-acc-social-hint">
              <FcGoogle size={14} /> {t('Signed in via Google', 'تم تسجيل الدخول عبر جوجل')}
            </span>
          )}
        </div>
        <div className="wz-acc-field">
          <label>{t('Phone', 'رقم الهاتف')}</label>
          <input
            value={phone || ''}
            onChange={(e) => setPhone(e.target.value)}
            inputMode="tel"
            dir="ltr"
          />
        </div>
      </div>

      <div className="wz-acc-form-foot">
        <button
          type="button"
          className={`wz-acc-btn${saved ? ' is-saved' : ''}`}
          onClick={onSave}
          disabled={!isDirty || saving}
        >
          {saving
            ? t('SAVING…', 'جارٍ الحفظ…')
            : saved
              ? `✓ ${t('SAVED', 'تم الحفظ')}`
              : t('SAVE CHANGES', 'حفظ التغييرات')}
        </button>
      </div>
    </div>
  )
}

// =============================================================================
//  TAB: Orders
// =============================================================================
const OrderCard = memo(function OrderCard({ order, resolveItem, user, isRTL, t }) {
  const items = Array.isArray(order.order_item) ? order.order_item : []
  const date = order.created_at
    ? new Date(order.created_at).toLocaleDateString(isRTL ? 'ar-EG' : 'en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
      })
    : ''

  const paymentLabel =
    order.payment_method === 'cash'
      ? t('Cash on Delivery', 'الدفع عند الاستلام')
      : t('Paid Online', 'مدفوع أونلاين')

  // Build a detailed tracking request so support can act without asking for
  // the order details. Item names come from the catalog (the order payload
  // only carries ids); customer name/phone come from the signed-in user.
  const track = () => {
    const origin = window.location.origin
    const linkFor = (it) => {
      if (it.offer_id) return `${origin}/offer/${it.offer_id}`
      return it.product_id ? `${origin}/products/${it.product_id}` : ''
    }
    // "- name (xN)" with the product URL on the next indented line (skipped
    // when we can't build a link).
    const itemLines = items.flatMap((it) => {
      const line = `- ${resolveItem(it).name} (x${it.quantity})`
      const link = linkFor(it)
      return link ? [line, `  ${link}`] : [line]
    })

    const customer = `${user?.first_name || order.guest_name || ''} ${user?.last_name || ''}`.trim()
    const phone = order.address?.phone_number_one || user?.phone_number || order.guest_phone || ''
    const address = order.address?.address_line || ''
    const L = (en, ar) => (isRTL ? ar : en)

    // Plain-text labels only — no emojis or box-drawing separators (they render
    // as tofu boxes on some WhatsApp clients). Blank '' lines separate sections;
    // `false` entries (missing fields) are filtered out, real blanks are kept.
    const lines = [
      `*${L('Order Tracking Request', 'طلب متابعة الطلب')}*`,
      '',
      `${L('Order', 'رقم الطلب')}: #${order.order_number}`,
      `${L('Date', 'التاريخ')}: ${date}`,
      `${L('Total', 'الإجمالي')}: ${money(order.total_price_for_order, isRTL)}`,
      `${L('Payment', 'الدفع')}: ${paymentLabel}`,
      `${L('Status', 'الحالة')}: ${order.status || 'pending'}`,
      '',
      `*${L('Items', 'المنتجات')}:*`,
      ...itemLines,
      '',
      `*${L('Customer', 'العميل')}:*`,
      customer ? `${L('Name', 'الاسم')}: ${customer}` : false,
      phone ? `${L('Phone', 'الهاتف')}: ${phone}` : false,
      address ? `${L('Address', 'العنوان')}: ${address}` : false,
      '',
      L('I would like to know the status of my order.', 'أرغب في معرفة حالة طلبي.'),
      L('Thank you!', 'شكراً!'),
    ].filter((x) => x !== false)

    window.open(
      `https://wa.me/${SUPPORT_WHATSAPP}?text=${encodeURIComponent(lines.join('\n'))}`,
      '_blank',
      'noopener',
    )
  }

  // Shipping isn't a stored column — derive it as (order total − line subtotal).
  // The city comes from the eager-loaded address relation when present.
  const subtotal = items.reduce((s, it) => s + Number(it.total_price || 0), 0)
  const shippingCost = Math.max(0, Number(order.total_price_for_order || 0) - subtotal)
  const cityTr =
    order.address?.shipping_city?.translations || order.address?.shippingCity?.translations
  const cityName = cityTr?.length
    ? cityTr.find((x) => x.locale === (isRTL ? 'ar' : 'en'))?.city_name ||
      cityTr.find((x) => x.locale === 'en')?.city_name
    : null

  return (
    <div className="wz-acc-card wz-acc-order">
      <div className="wz-acc-order-head">
        <div className="wz-acc-order-head-main">
          <p className="wz-acc-order-num">#{order.order_number}</p>
          <div className="wz-acc-order-datebadge">
            <span className="wz-acc-order-date">{date}</span>
            <StatusBadge status={order.status} isRTL={isRTL} />
          </div>
        </div>
        <span className="wz-acc-order-count">
          {items.length} {items.length === 1 ? t('item', 'منتج') : t('items', 'منتجات')}
        </span>
      </div>

      <div className="wz-acc-order-items">
        {items.map((it) => {
          const info = resolveItem(it)
          return (
            <div className="wz-acc-order-item" key={it.id}>
              <div className="wz-acc-order-thumb">
                {info.image ? <img src={info.image} alt={info.name} width="48" height="48" loading="lazy" onError={handleImgError} /> : null}
              </div>
              <div className="wz-acc-order-item-info">
                <span className="wz-acc-order-name">{info.name}</span>
                <div className="wz-acc-order-item-meta">
                  <span className="wz-acc-order-qty">×{it.quantity}</span>
                  <span className="wz-acc-order-price">{money(it.total_price, isRTL)}</span>
                </div>
              </div>
            </div>
          )
        })}
      </div>

      <div className="wz-acc-order-foot">
        <div className="wz-acc-order-summary">
          <div className="wz-acc-order-total">
            <span>{t('Total', 'الإجمالي')}</span>
            <strong>{money(order.total_price_for_order, isRTL)}</strong>
          </div>
          {(cityName || shippingCost > 0) && (
            <p className="wz-acc-order-meta">
              {t('Shipping', 'الشحن')}: {cityName ? `${cityName} — ` : ''}
              {money(shippingCost, isRTL)}
            </p>
          )}
          <p className="wz-acc-order-meta">
            {t('Payment', 'الدفع')}: {paymentLabel}
          </p>
        </div>
        <button type="button" className="wz-acc-track" onClick={track}>
          <FaWhatsapp size={16} />
          {t('Track via WhatsApp', 'تتبع عبر واتساب')}
        </button>
      </div>
    </div>
  )
})

function OrdersTab({ t, isRTL }) {
  const { products } = useCatalog()
  const { data: offers = [] } = useOffers()
  const user = useAuthStore((s) => s.user)
  const [orders, setOrders] = useState(null) // null = loading
  const router = useRouter()

  useEffect(() => {
    let alive = true
    http
      .get('/me/orders')
      .then((res) => {
        if (alive) setOrders(Array.isArray(res.data) ? res.data : [])
      })
      .catch(() => alive && setOrders([]))
    return () => {
      alive = false
    }
  }, [])

  const resolveItem = useCallback(
    (item) => {
      if (item.product_id) {
        const p = products?.find((pp) => pp.id === item.product_id)
        return {
          name: p
            ? (isRTL ? p.product_title_ar || p.product_title : p.product_title) || p.name
            : `#${item.product_id}`,
          image: getImageUrl(p?.image, 'Product'),
        }
      }
      if (item.offer_id) {
        const o = offers?.find((oo) => oo.id === item.offer_id)
        return {
          name: o ? (isRTL ? o.offer_name_ar : o.offer_name_en) : `#${item.offer_id}`,
          image: o?.image || null,
        }
      }
      return { name: '-', image: null }
    },
    [products, offers, isRTL],
  )

  return (
    <div>
      <SectionTitle>{t('Order History', 'سجل الطلبات')}</SectionTitle>
      {orders === null ? (
        <div className="wz-acc-loading">
          <span className="wz-acc-spinner" />
        </div>
      ) : orders.length === 0 ? (
        <EmptyState
          icon={<FiPackage />}
          title={t('No orders yet', 'لا توجد طلبات بعد')}
          cta={t('Start shopping', 'ابدأ التسوق')}
          onCta={() => router.push('/listing')}
        />
      ) : (
        orders.map((o) => (
          <OrderCard key={o.id} order={o} resolveItem={resolveItem} user={user} isRTL={isRTL} t={t} />
        ))
      )}
    </div>
  )
}

// =============================================================================
//  TAB: Addresses
// =============================================================================
const AddressCard = memo(function AddressCard({ address, cityName, onDelete, isRTL, t }) {
  return (
    <div className="wz-acc-card wz-acc-address">
      <div className="wz-acc-address-body">
        <p className="wz-acc-address-line">{address.address_line}</p>
        {cityName && <p className="wz-acc-address-city">{cityName}</p>}
        <p className="wz-acc-address-phone" dir="ltr">
          📞 {address.phone_number_one}
        </p>
      </div>
      <div className="wz-acc-address-actions">
        <button
          type="button"
          className="wz-acc-linkbtn wz-acc-linkbtn-danger"
          onClick={() => onDelete(address.id)}
        >
          <FiTrash2 size={13} /> {t('Delete', 'حذف')}
        </button>
      </div>
    </div>
  )
})

function AddressesTab({ t, isRTL }) {
  const { shippingPrices, shippingid, setShippingid, setShipping, setShippingName } =
    useContext(MyContext)
  const { language } = useUIStore()
  const showToast = useToastStore((s) => s.showToast)

  const [addresses, setAddresses] = useState(null)
  const [adding, setAdding] = useState(false)
  const [street, setStreet] = useState('')
  const [building, setBuilding] = useState('')
  const [floor, setFloor] = useState('')
  const [apartment, setApartment] = useState('')
  const [phone, setPhone] = useState('')
  const [busy, setBusy] = useState(false)

  const load = useCallback(() => {
    http
      .get('/me/addresses')
      .then((res) => setAddresses(Array.isArray(res.data) ? res.data : []))
      .catch(() => setAddresses([]))
  }, [])

  useEffect(() => {
    load()
  }, [load])

  const cityNameFor = useCallback(
    (addr) => {
      const tr = addr.shipping_city?.translations || addr.shippingCity?.translations
      if (tr?.length) {
        return (
          tr.find((x) => x.locale === language)?.city_name ||
          tr.find((x) => x.locale === 'en')?.city_name
        )
      }
      // Fallback: resolve from the shippingPrices context by id.
      const city = shippingPrices.find((c) => String(c.id) === String(addr.shipping_city_id))
      return city ? (isRTL ? city.GovernorateAr : city.GovernorateEn) : ''
    },
    [language, isRTL, shippingPrices],
  )

  const onCity = (e) => {
    const id = e.target.value
    setShippingid(id)
    const c = shippingPrices.find((city) => city.id === id)
    if (c) {
      setShipping(c.Price.toString())
      setShippingName(isRTL ? c.GovernorateAr : c.GovernorateEn)
    }
  }

  const resetForm = () => {
    setStreet('')
    setBuilding('')
    setFloor('')
    setApartment('')
    setPhone('')
    setAdding(false)
  }

  const onAdd = async () => {
    if (!shippingid) {
      showToast(t('Please select a city', 'الرجاء اختيار المدينة'), 'warning')
      return
    }
    if (!street.trim() || !building.trim() || !phone.trim()) {
      showToast(t('Street, building and phone are required', 'الشارع والمبنى والهاتف مطلوبة'), 'warning')
      return
    }
    let line = `${street}, Building ${building}`
    if (floor.trim()) line += `, Floor ${floor}`
    if (apartment.trim()) line += `, Apartment ${apartment}`

    setBusy(true)
    try {
      const res = await http.post('/add_address', {
        shipping_city_id: shippingid,
        address_line: line,
        phone_number_one: phone,
      })
      if (res.data?.success) {
        showToast(t('Address added', 'تمت إضافة العنوان'), 'success')
        resetForm()
        load()
      } else {
        showToast(t('Failed to add address', 'فشل إضافة العنوان'), 'error')
      }
    } catch {
      showToast(t('Failed to add address', 'فشل إضافة العنوان'), 'error')
    } finally {
      setBusy(false)
    }
  }

  const onDelete = useCallback(
    async (id) => {
      if (!window.confirm(t('Delete this address?', 'حذف هذا العنوان؟'))) return
      try {
        await http.delete(`/me/addresses/${id}`)
        setAddresses((prev) => (prev || []).filter((a) => a.id !== id))
        showToast(t('Address deleted', 'تم حذف العنوان'), 'success')
      } catch {
        showToast(t('Failed to delete address', 'فشل حذف العنوان'), 'error')
      }
    },
    [showToast, t],
  )

  return (
    <div>
      <SectionTitle>{t('Shipping Addresses', 'عناوين الشحن')}</SectionTitle>

      {addresses === null ? (
        <div className="wz-acc-loading">
          <span className="wz-acc-spinner" />
        </div>
      ) : (
        <>
          {addresses.map((a) => (
            <AddressCard
              key={a.id}
              address={a}
              cityName={cityNameFor(a)}
              onDelete={onDelete}
              isRTL={isRTL}
              t={t}
            />
          ))}

          {!adding ? (
            <button type="button" className="wz-acc-add-address" onClick={() => setAdding(true)}>
              <FiPlus /> {t('Add new address', 'إضافة عنوان جديد')}
            </button>
          ) : (
            <div className="wz-acc-card wz-acc-addr-form">
              <div className="wz-acc-form">
                <div className="wz-acc-field wz-acc-field-full">
                  <label>{t('Governorate', 'المحافظة')}</label>
                  <select value={shippingid || ''} onChange={onCity}>
                    <option value="" disabled>
                      {t('Select city', 'اختر المدينة')}
                    </option>
                    {shippingPrices.map((c) => (
                      <option key={c.id} value={c.id.toString()}>
                        {isRTL ? c.GovernorateAr : c.GovernorateEn}
                      </option>
                    ))}
                  </select>
                </div>
                <div className="wz-acc-field">
                  <label>{t('Street Name', 'اسم الشارع')}</label>
                  <input value={street} onChange={(e) => setStreet(e.target.value)} />
                </div>
                <div className="wz-acc-field">
                  <label>{t('Building Number', 'رقم المبنى')}</label>
                  <input value={building} onChange={(e) => setBuilding(e.target.value)} />
                </div>
                <div className="wz-acc-field">
                  <label>{t('Floor Number', 'رقم الطابق')}</label>
                  <input value={floor} onChange={(e) => setFloor(e.target.value)} />
                </div>
                <div className="wz-acc-field">
                  <label>{t('Apartment Number', 'رقم الشقة')}</label>
                  <input value={apartment} onChange={(e) => setApartment(e.target.value)} />
                </div>
                <div className="wz-acc-field wz-acc-field-full">
                  <label>{t('Phone', 'رقم الهاتف')}</label>
                  <input value={phone} onChange={(e) => setPhone(e.target.value)} dir="ltr" />
                </div>
              </div>
              <div className="wz-acc-form-foot wz-acc-form-foot-split">
                <button type="button" className="wz-acc-linkbtn" onClick={resetForm}>
                  {t('Cancel', 'إلغاء')}
                </button>
                <button type="button" className="wz-acc-btn" onClick={onAdd} disabled={busy}>
                  {busy ? t('SAVING…', 'جارٍ الحفظ…') : t('Save Address', 'حفظ العنوان')}
                </button>
              </div>
            </div>
          )}
        </>
      )}
    </div>
  )
}

// =============================================================================
//  TAB: Wishlist
// =============================================================================
function WishlistTab({ t, isRTL }) {
  // Wishlist state stays in context; products/offers from the shared query cache.
  const { wishList, setwishList } = useContext(MyContext)
  const { products } = useCatalog()
  const { data: offers = [] } = useOffers()
  const router = useRouter()

  const remove = useCallback(
    async (id) => {
      try {
        const res = await http.delete(`/delete_wishlist/${id}`)
        if (res.status === 200) {
          setwishList((prev) => prev.filter((item) => item.id !== id))
        }
      } catch {
        // ignore
      }
    },
    [setwishList],
  )

  // Resolve each wishlist entry against the live catalog so the title, image
  // (proper URL) and current price are always correct — the raw wishlist entry
  // may carry a bare filename or a stale/absent price. Falls back to whatever
  // the entry itself holds when the product/offer isn't in the loaded catalog.
  const items = useMemo(() => {
    if (!wishList?.length) return []
    return wishList
      .map((item) => {
        if (item.product_id) {
          const p = products?.find((pp) => pp.id === item.product_id)
          return {
            key: item.id,
            wishlistId: item.id,
            title:
              (isRTL ? p?.product_title_ar || p?.product_title : p?.product_title) ||
              item.product_title,
            image: p ? getImageUrl(p.image, 'Product') : item.product_image,
            price: p ? p.sale_price_after_discount || p.selling_price : item.product_price,
            to: productUrl(p || item),
          }
        }
        if (item.offer_id) {
          const o = offers?.find((oo) => oo.id === item.offer_id)
          return {
            key: item.id,
            wishlistId: item.id,
            title: o ? (isRTL ? o.offer_name_ar : o.offer_name_en) : item.offer_title,
            image: o?.image || item.offer_image,
            price: o ? o.sale_price_after_discount || o.selling_price : item.offer_price,
            to: `/offer/${item.offer_id}`,
          }
        }
        return null
      })
      .filter(Boolean)
  }, [wishList, products, offers, isRTL])

  if (!items.length) {
    return (
      <div>
        <SectionTitle>{t('My Wishlist', 'المفضلة')}</SectionTitle>
        <EmptyState
          icon={<FiHeart />}
          title={t('Your wishlist is empty', 'المفضلة فارغة')}
          cta={t('Discover our collection', 'اكتشف مجموعتنا')}
          onCta={() => router.push('/listing')}
        />
      </div>
    )
  }

  return (
    <div>
      <SectionTitle>{t('My Wishlist', 'المفضلة')}</SectionTitle>
      <div className="wz-acc-wishgrid">
        {items.map((it) => (
          <div className="wz-acc-card wz-acc-wishitem" key={it.key}>
            <Link href={it.to} className="wz-acc-wishitem-media">
              {it.image ? <img src={it.image} alt={it.title} width="72" height="72" loading="lazy" onError={handleImgError} /> : null}
            </Link>
            <div className="wz-acc-wishitem-body">
              <Link href={it.to} className="wz-acc-wishitem-title">
                {it.title}
              </Link>
              {it.price != null && it.price !== '' && (
                <p className="wz-acc-wishitem-price">{money(it.price, isRTL)}</p>
              )}
            </div>
            <button
              type="button"
              className="wz-acc-wishitem-remove"
              onClick={() => remove(it.wishlistId)}
              aria-label={t('Remove', 'حذف')}
            >
              <FiTrash2 size={15} />
            </button>
          </div>
        ))}
      </div>
    </div>
  )
}

// =============================================================================
//  TAB: Change Password
// =============================================================================
function PasswordTab({ t }) {
  const showToast = useToastStore((s) => s.showToast)
  const user = useAuthStore((s) => s.user)
  const updateUser = useAuthStore((s) => s.updateUser)
  // Default to true (safe fallback): if we don't yet know, require the current
  // password rather than letting anyone skip it.
  const hasPassword = user?.has_password ?? true

  const [current, setCurrent] = useState('')
  const [next, setNext] = useState('')
  const [confirm, setConfirm] = useState('')
  const [show, setShow] = useState({ current: false, next: false, confirm: false })
  const [busy, setBusy] = useState(false)

  const checks = useMemo(
    () => ({
      length: next.length >= 8,
      number: /\d/.test(next),
      upper: /[A-Z]/.test(next),
    }),
    [next],
  )
  const score = Object.values(checks).filter(Boolean).length
  const strength = score <= 1 ? 'weak' : score === 2 ? 'medium' : 'strong'
  const matches = confirm.length > 0 && next === confirm

  // Social-auth users have no current password to enter.
  const valid = (!hasPassword || current) && checks.length && checks.number && checks.upper && matches

  const toggle = (f) => setShow((p) => ({ ...p, [f]: !p[f] }))

  const onSubmit = async () => {
    if (!valid) return
    setBusy(true)
    try {
      const payload = { new_password: next, new_password_confirmation: confirm }
      if (hasPassword) payload.current_password = current
      await http.post('/updatePassword', payload)

      showToast(
        hasPassword
          ? t('Password updated', 'تم تحديث كلمة المرور')
          : t('Password set', 'تم تعيين كلمة المرور'),
        'success',
      )
      setCurrent('')
      setNext('')
      setConfirm('')
      // A social user now has a password → flip this tab to the normal form.
      if (!hasPassword) updateUser({ has_password: true })
    } catch (err) {
      const msg =
        err?.response?.status === 401
          ? t('Current password is incorrect', 'كلمة المرور الحالية غير صحيحة')
          : t('Failed to update password', 'فشل تحديث كلمة المرور')
      showToast(msg, 'error')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div>
      <SectionTitle>
        {hasPassword
          ? t('Change Password', 'تغيير كلمة المرور')
          : t('Set Your Password', 'تعيين كلمة المرور')}
      </SectionTitle>
      <div className="wz-acc-form">
        {!hasPassword && (
          <p className="wz-acc-social-note wz-acc-field-full">
            {t(
              'You signed in with Google. Set a password to also enable email login.',
              'سجّلت الدخول بحساب جوجل. عيّن كلمة مرور لتتمكن أيضاً من الدخول بالإيميل.',
            )}
          </p>
        )}
        {hasPassword && (
          <PasswordField
            label={t('Current Password', 'كلمة المرور الحالية')}
            value={current}
            onChange={setCurrent}
            visible={show.current}
            onToggle={() => toggle('current')}
          />
        )}
        <PasswordField
          label={t('New Password', 'كلمة المرور الجديدة')}
          value={next}
          onChange={setNext}
          visible={show.next}
          onToggle={() => toggle('next')}
        />

        {next.length > 0 && (
          <div className="wz-acc-field wz-acc-field-full">
            <div className={`wz-acc-strength wz-acc-strength-${strength}`}>
              <span />
            </div>
            <ul className="wz-acc-pwchecks">
              <li className={checks.length ? 'ok' : ''}>
                {checks.length ? '✓' : '○'} {t('8+ characters', '8 أحرف على الأقل')}
              </li>
              <li className={checks.number ? 'ok' : ''}>
                {checks.number ? '✓' : '○'} {t('One number', 'رقم واحد')}
              </li>
              <li className={checks.upper ? 'ok' : ''}>
                {checks.upper ? '✓' : '○'} {t('One uppercase', 'حرف كبير واحد')}
              </li>
            </ul>
          </div>
        )}

        <PasswordField
          label={t('Confirm New Password', 'تأكيد كلمة المرور')}
          value={confirm}
          onChange={setConfirm}
          visible={show.confirm}
          onToggle={() => toggle('confirm')}
        />
        {confirm.length > 0 && !matches && (
          <p className="wz-acc-field-error wz-acc-field-full">
            {t("Passwords don't match", 'كلمتا المرور غير متطابقتين')}
          </p>
        )}
      </div>

      <div className="wz-acc-form-foot">
        <button type="button" className="wz-acc-btn" onClick={onSubmit} disabled={!valid || busy}>
          {busy
            ? t('SAVING…', 'جارٍ الحفظ…')
            : hasPassword
              ? t('UPDATE PASSWORD', 'تحديث كلمة المرور')
              : t('SET PASSWORD', 'تعيين كلمة المرور')}
        </button>
      </div>
    </div>
  )
}

// ── Empty state ──────────────────────────────────────────────────────────────
const EmptyState = ({ icon, title, cta, onCta }) => (
  <div className="wz-acc-empty">
    <span className="wz-acc-empty-icon">{icon}</span>
    <p className="wz-acc-empty-title">{title}</p>
    <button type="button" className="wz-acc-btn" onClick={onCta}>
      <FiShoppingBag size={14} /> {cta}
    </button>
  </div>
)

// =============================================================================
//  Account (shell)
// =============================================================================
function Account() {
  const { language } = useUIStore()
  const isRTL = language === 'ar'
  const user = useAuthStore((s) => s.user)
  const logout = useAuthStore((s) => s.logout)
  const updateUser = useAuthStore((s) => s.updateUser)
  const showToast = useToastStore((s) => s.showToast)
  const router = useRouter()
  const params = useSearchParams()
  const pathname = usePathname()

  const t = useCallback((en, ar) => (isRTL ? ar : en), [isRTL])

  const tab = TABS.includes(params.get('tab')) ? params.get('tab') : 'profile'
  const setTab = useCallback(
    (key) => {
      // Next's useSearchParams is read-only — push a new query to switch tabs.
      router.push(`${pathname}?tab=${key}`)
    },
    [router, pathname],
  )

  // One lightweight call to enrich the sidebar with "member since" and to learn
  // whether this account has a local password (social-auth users don't) so the
  // Password / Profile tabs render the right variant.
  const [memberYear, setMemberYear] = useState('')
  useEffect(() => {
    let alive = true
    http
      .get('/auth/me')
      .then((res) => {
        if (!alive || !res.data) return
        if (res.data.created_at) {
          setMemberYear(String(new Date(res.data.created_at).getFullYear()))
        }
        if ('has_password' in res.data) {
          updateUser({ has_password: !!res.data.has_password })
        }
      })
      .catch(() => {})
    return () => {
      alive = false
    }
  }, [updateUser])

  const onSignOut = useCallback(() => {
    logout()
    showToast(t('You have been signed out', 'تم تسجيل خروجك'), 'success')
    router.push('/')
  }, [logout, showToast, router, t])

  const avatar = user?.image || ''

  return (
    <div className="wz-account-page" dir={isRTL ? 'rtl' : 'ltr'}>
      <div className="wz-account">
        <Sidebar
          tab={tab}
          onSelect={setTab}
          onSignOut={onSignOut}
          user={user}
          avatar={avatar}
          memberYear={memberYear}
          t={t}
        />
        <main className="wz-account-content">
          {tab === 'profile' && <ProfileTab t={t} isRTL={isRTL} />}
          {tab === 'orders' && <OrdersTab t={t} isRTL={isRTL} />}
          {tab === 'addresses' && <AddressesTab t={t} isRTL={isRTL} />}
          {tab === 'wishlist' && <WishlistTab t={t} isRTL={isRTL} />}
          {tab === 'password' && <PasswordTab t={t} />}
        </main>
        {/* Sign-out is hidden from the horizontal tab row on mobile — this
            dedicated button (mobile-only via CSS) replaces it. */}
        <button type="button" className="wz-acc-mobile-logout" onClick={onSignOut}>
          <FiLogOut size={15} /> {t('Sign Out', 'تسجيل الخروج')}
        </button>
      </div>
    </div>
  )
}

export default memo(Account)
