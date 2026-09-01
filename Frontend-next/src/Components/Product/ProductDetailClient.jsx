'use client'
import { memo, useEffect, useMemo, useState, useCallback, useRef } from 'react'
import Link from 'next/link'
import Image from 'next/image'
import { useRouter } from 'next/navigation'
import { FiShare2 } from 'react-icons/fi'
import ImageZoom from '../UI/ImageZoom'
import DOMPurify from 'dompurify'
import { useWishlist } from '../../Hooks/useWishlist'
import { useCatalog } from '../../Hooks/queries/useCatalog'
import { useOffers } from '../../Hooks/queries/useOffers'
import { useUIStore } from '../../Store/uiStore'
import { useAuthStore } from '../../Store/authStore'
import { useToastStore } from '../../Store/toastStore'
import useCart, { getItemKey } from '../../Hooks/useCart'
import ProductSlider from './ProductSlider'
import TrustSignals from '../Merchandising/TrustSignals'
import BackToTop from '../BackToTop/BackToTop'
import { trackViewContent } from '../../scripts/pixels'
import { getImageUrl, PLACEHOLDER_IMG } from '../../utils/imageUrl'
import { toSlug } from '../../utils/slugs'
import { buildListingParams } from '../../utils/listingParams'
import http from '../../Context/api'
import './ProductDetail.css'

// Lightweight star rating (no MUI). Read-only by default; pass onSelect to make
// it an interactive picker for the review form.
function Stars({ value = 0, size = 14, onSelect }) {
  const rounded = Math.round(value)
  return (
    <span className="wz-pd-starset" style={{ fontSize: size }}>
      {[1, 2, 3, 4, 5].map((n) => (
        <span
          key={n}
          className={`wz-pd-star${n <= rounded ? ' is-on' : ''}${onSelect ? ' is-click' : ''}`}
          onClick={onSelect ? () => onSelect(n) : undefined}
          role={onSelect ? 'button' : undefined}
          aria-label={onSelect ? `${n} stars` : undefined}
        >
          ★
        </span>
      ))}
    </span>
  )
}

// Recursively flatten an attribute value (string/number/array/object) to a
// display string. Hoisted to module scope so its self-reference is a stable
// module binding (not a hook-local that the react-hooks/immutability rule flags).
function safeStr(val) {
  if (!val) return ''
  if (typeof val === 'string') return val
  if (typeof val === 'number') return String(val)
  if (Array.isArray(val)) return val.map((v) => safeStr(v)).filter(Boolean).join(', ')
  return val.name_en || val.name || val.color_name || val.material_name || val.brand_name || ''
}

// Colour arrays reach the PDP in more than one shape depending on the source:
//   • catalog transform (transformProduct.js) → { color_id, color_value, color_name_en, color_name_ar }
//   • ProductResource `dial_color`/`band_color` → { id, color_value, color_name_en, color_name_ar }
//   • ProductResource `dial_colors`/`band_colors` → { id, name_en, name_ar }   (NO color_value!)
// The PDP promotes the catalog card to the full ProductResource record, whose
// `dial_colors` omits color_value — so the swatches lost their background and the
// spec rows went blank once the full record arrived. Normalise to one shape and
// prefer whichever source actually carries a color_value (the swatch fill).
function normalizeColorList(arr) {
  return (arr || []).map((c) => ({
    id: c.id ?? c.color_id ?? null,
    color_value: c.color_value ?? null,
    color_name_en: c.color_name_en ?? c.name_en ?? c.name ?? '',
    color_name_ar: c.color_name_ar ?? c.name_ar ?? '',
  }))
}
function resolveColorList(...sources) {
  const lists = sources.map(normalizeColorList)
  return lists.find((l) => l.some((c) => c.color_value)) || lists.find((l) => l.length) || []
}

// Unified product / offer detail page. Mode is derived from the path:
//   /product/:slug  /products/:id  → product mode
//   /offer/:slug                   → offer mode
// Products resolve from the context catalog (every product is loaded there),
// with an API by-name fallback for legacy raw-title URLs. Offers resolve from
// the context `offers` array (no single-offer endpoint exists), and a loading
// grace period waits for context so a refresh never flashes a false 404.
// Client island for the product/offer detail routes. `param` (slug or id) and
// `isOffer` are passed by the server page (which resolves the route + owns the
// SEO metadata / JSON-LD / canonical redirect — none of that lives here anymore).
function ProductDetailClient({ param, isOffer = false }) {
  const router = useRouter()

  // Server data from the shared TanStack Query cache; wishlist from Zustand.
  const { handleAddTowishlist, wishList } = useWishlist()
  const { products, tables, isError: catalogIsError, refetch: refetchCatalog } = useCatalog()
  const { data: offers = [] } = useOffers()
  const { language } = useUIStore()
  const isRTL = language === 'ar'
  const { userId } = useAuthStore()
  const showToast = useToastStore((s) => s.showToast)
  const { cart, addItem, updateQuantity } = useCart()

  // ── Resolvers ───────────────────────────────────────────────────────────
  const getName = useCallback((it, lang) => {
    if (!it) return ''
    if (it.offer_name_en || it.offer_name_ar)
      return lang === 'ar'
        ? it.offer_name_ar || it.offer_name_en
        : it.offer_name_en || it.offer_name_ar
    if (it.translations?.length) {
      const t =
        it.translations.find((x) => x.locale === lang) ||
        it.translations.find((x) => x.locale === 'en')
      if (t?.product_title) return t.product_title
    }
    return lang === 'ar'
      ? it.product_title_ar || it.name_ar || it.product_title || it.name || it.name_en || ''
      : it.name_en || it.name || it.product_title || it.product_title_ar || it.name_ar || ''
  }, [])

  const getBrand = useCallback((it, lang) => {
    if (!it) return ''
    if (it.brand?.translations?.length) {
      const t =
        it.brand.translations.find((x) => x.locale === lang) ||
        it.brand.translations.find((x) => x.locale === 'en')
      if (t?.brand_name) return t.brand_name
    }
    if (typeof it.brand === 'string') return it.brand
    return it.brand?.brand_name || it.brand?.name_en || it.brand_name || ''
  }, [])

  // ── Data loading ────────────────────────────────────────────────────────
  const contextProduct = useMemo(() => {
    if (isOffer || !param) return null
    const all = products || []
    if (!all.length) return null
    if (/^\d+$/.test(param)) return all.find((p) => p.id === Number(param)) || null
    return (
      all.find((p) => toSlug(p.name || '') === param) ||
      all.find((p) => toSlug(p.product_title || '') === param) ||
      null
    )
  }, [isOffer, param, products])

  const [apiProduct, setApiProduct] = useState(null)
  const [fetchState, setFetchState] = useState('idle') // idle | loading | done | notfound | error

  // Full product record (specs, gallery images, colour names, descriptions) lives
  // in ProductResource — NOT in the lightweight catalog card. Fetch it by id when the
  // catalog resolved the product (fast path), else by english name for legacy raw-title
  // URLs / a catalog miss. Both endpoints return { product: ProductResource, related }.
  // The catalog card (contextProduct) still drives the instant SSR/above-the-fold view;
  // this promotes to the full record once it arrives.
  useEffect(() => {
    if (isOffer || !param) return
    const byId = contextProduct?.id ?? (/^\d+$/.test(param) ? param : null)
    // No id yet AND the catalog hasn't loaded → wait (avoids a premature by-name
    // call / false 404 before the catalog can resolve the slug).
    if (!byId && (!products || !products.length)) return
    let alive = true
    // Fetch-status sync for an external API call — intentional effect setState.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setFetchState('loading')
    const req = byId
      ? http.get(`products/${byId}`)
      : http.get(`products/by-name/${encodeURIComponent(decodeURIComponent(param))}`)
    req
      .then(({ data }) => {
        if (!alive) return
        setApiProduct(data.product)
        setFetchState('done')
      })
      .catch((err) => {
        if (!alive) return
        setFetchState(err.response?.status === 404 ? 'notfound' : 'error')
      })
    return () => {
      alive = false
    }
  }, [isOffer, param, contextProduct, products])

  const offer = useMemo(() => {
    if (!isOffer || !param) return null
    const all = offers || []
    if (!all.length) return null
    if (/^\d+$/.test(param)) return all.find((o) => o.id === Number(param)) || null
    return (
      all.find((o) => toSlug(o.offer_name_en || '') === param) ||
      all.find((o) => toSlug(o.offer_name_ar || '') === param) ||
      null
    )
  }, [isOffer, param, offers])

  const offerProduct = useMemo(
    () => (isOffer && offer ? (products || []).find((p) => p.id === offer.main_product_id) : null),
    [isOffer, offer, products],
  )

  // Prefer the full API record, but ONLY when it matches the currently-resolved
  // product — otherwise (mid-navigation, before the new record arrives) the catalog
  // card carries the view, so there's no stale-product flash. A catalog miss (legacy
  // raw-title URL) still resolves via the by-name apiProduct.
  const product = isOffer
    ? null
    : apiProduct &&
        (apiProduct.id === contextProduct?.id || String(apiProduct.id) === String(param))
      ? apiProduct
      : contextProduct || apiProduct
  const item = isOffer ? offer : product

  // Loading grace: wait for context before declaring a 404 (fixes refresh bug).
  const [grace, setGrace] = useState(false)
  useEffect(() => {
    // Reset the 404 grace window whenever the route changes — intentional.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setGrace(false)
    const t = setTimeout(() => setGrace(true), 4000)
    return () => clearTimeout(t)
  }, [param, isOffer])

  const isLoading = !item && fetchState !== 'notfound' && fetchState !== 'error' && !grace
  const notFound = !item && (fetchState === 'notfound' || fetchState === 'error' || grace)

  // ── Derived display data ────────────────────────────────────────────────
  const name = getName(item, language)
  const brandLabel = isOffer ? '' : getBrand(product, language)

  const price = Number(item?.selling_price || 0)
  const salePrice = Number(item?.sale_price_after_discount || 0)
  const hasSale = salePrice > 0 && salePrice < price
  const finalPrice = hasSale ? salePrice : price
  const discount = hasSale ? Math.round((1 - salePrice / price) * 100) : 0
  const fmt = useCallback(
    (v) => Math.round(Number(v) || 0).toLocaleString(isRTL ? 'ar-EG' : 'en-US'),
    [isRTL],
  )
  const currency = isRTL ? 'ج.م' : 'EGP'

  const expressStock = Number(product?.stock || 0)
  const marketStock = Number(product?.market_stock || 0)
  const offerStock = Number(offer?.stock || 0)
  const inStock = isOffer ? offerStock > 0 : expressStock > 0 || marketStock > 0
  const maxStock = isOffer ? offerStock : expressStock > 0 ? expressStock : marketStock

  const ratingValue = isOffer
    ? Number(offer?.average_rate || 0)
    : Number(product?.rating || product?.average_rating || 0)

  // Prefer the source that carries color_value (see resolveColorList) so the
  // full ProductResource record doesn't drop the swatches.
  const dialColors = resolveColorList(product?.dial_color, product?.dial_colors)
  const bandColors = resolveColorList(product?.band_color, product?.band_colors)
  const [selectedDial, setSelectedDial] = useState(null)
  const [selectedBand, setSelectedBand] = useState(null)
  useEffect(() => {
    // Seed the default dial/band selection when the item changes — intentional.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setSelectedDial(dialColors?.[0]?.color_value || null)
    setSelectedBand(bandColors?.[0]?.color_value || null)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [item])

  // ── Images ──────────────────────────────────────────────────────────────
  // Catalog MAIN image (same source the product cards use — resolved from the
  // 'Product' folder, which is populated in seed data). The gallery images live
  // under a different folder that can be missing, so this is the reliable
  // fallback for every broken gallery entry (and the whole gallery if empty).
  const catalogImg = useMemo(
    () => getImageUrl((isOffer ? offerProduct : product)?.image, 'Product'),
    [isOffer, offerProduct, product],
  )

  const images = useMemo(() => {
    const list = []
    const push = (v) => {
      const u = getImageUrl(typeof v === 'string' ? v : v?.image)
      if (u) list.push(u)
    }
    if (item?.images?.length) item.images.forEach(push)
    if (item?.image) push(item.image)
    if (isOffer && offerProduct?.image) push(offerProduct.image)
    const uniq = [...new Set(list.filter(Boolean))]
    // Empty gallery → show the reliable catalog image, then the placeholder.
    return uniq.length ? uniq : [catalogImg || PLACEHOLDER_IMG]
  }, [item, offerProduct, isOffer, catalogImg])

  // Graceful image fallback for the gallery (main image + thumbnails). A broken
  // gallery URL first swaps to the catalog image, then to the inline placeholder.
  // The `fbStage` dataset flag prevents an infinite onError loop: each element
  // advances at most one stage per failure, and the placeholder detaches onerror.
  const handleGalleryImgError = useCallback(
    (e) => {
      const img = e.currentTarget
      if (img.dataset.fbStage !== 'catalog' && catalogImg && img.src !== catalogImg) {
        img.dataset.fbStage = 'catalog'
        img.src = catalogImg
        return
      }
      img.onerror = null // stop: placeholder is a data URI that cannot 404
      img.src = PLACEHOLDER_IMG
    },
    [catalogImg],
  )

  const [activeImg, setActiveImg] = useState(0)
  // Reset gallery to the first image on route change — intentional.
  // eslint-disable-next-line react-hooks/set-state-in-effect
  useEffect(() => setActiveImg(0), [param, isOffer])
  // Horizontal-swipe gallery on mouse/touch. (Zoom itself is handled in-place by
  // the custom ImageZoom component below — hover on desktop, touch-drag on mobile.)
  const touchX = useRef(null)

  const onTouchStart = (e) => {
    touchX.current = e.touches[0].clientX
  }
  const onTouchEnd = (e) => {
    if (touchX.current == null) return
    const dx = e.changedTouches[0].clientX - touchX.current
    if (Math.abs(dx) > 40) {
      const n = images.length
      setActiveImg((i) => (dx < 0 ? (i + 1) % n : (i - 1 + n) % n))
    }
    touchX.current = null
  }

  // ── Mobile gallery (< md): native scroll-snap carousel, no zoom ──────────
  // The dot indicator is driven by an IntersectionObserver watching the slides.
  // The desktop zoom gallery is untouched; both render and CSS shows one.
  const carouselRef = useRef(null)
  const slideRefs = useRef([])
  useEffect(() => {
    const root = carouselRef.current
    if (!root) return
    const slides = slideRefs.current.filter(Boolean)
    if (slides.length < 2) return
    const obs = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting && entry.intersectionRatio >= 0.5) {
            const idx = Number(entry.target.dataset.idx)
            if (!Number.isNaN(idx)) setActiveImg(idx)
          }
        })
      },
      { root, threshold: [0.5, 0.75] },
    )
    slides.forEach((s) => obs.observe(s))
    return () => obs.disconnect()
  }, [images.length])
  const scrollToSlide = useCallback((i) => {
    const root = carouselRef.current
    if (root) root.scrollTo({ left: i * root.clientWidth, behavior: 'smooth' })
  }, [])

  // ── Cart ────────────────────────────────────────────────────────────────
  const [quantity, setQuantity] = useState(1)
  // Reset quantity to 1 on route change — intentional.
  // eslint-disable-next-line react-hooks/set-state-in-effect
  useEffect(() => setQuantity(1), [param, isOffer])
  const changeQty = (d) => setQuantity((q) => Math.max(1, Math.min(q + d, maxStock || 1)))

  const [isAdding, setIsAdding] = useState(false)
  const handleAdd = useCallback(async () => {
    if (isAdding || !inStock) return
    setIsAdding(true)
    try {
      if (isOffer) {
        const key = `offer_${offer.id}`
        const existing = cart?.cart_item?.find((i) => getItemKey(i) === key)
        if (existing) await updateQuantity(key, existing.quantity + quantity)
        else
          await addItem({ offer_id: offer.id, quantity, piece_price: finalPrice, type_stock: 'Express' })
      } else {
        const key = `product_${product.id}`
        const existing = cart?.cart_item?.find((i) => getItemKey(i) === key)
        if (existing) await updateQuantity(key, existing.quantity + quantity)
        else
          await addItem({
            product_id: product.id,
            quantity,
            piece_price: finalPrice,
            type_stock: expressStock > 0 ? 'Express' : 'Market',
            color_band: selectedBand || null,
            color_dial: selectedDial || null,
          })
      }
      showToast(isRTL ? 'تمت الإضافة إلى السلة!' : 'Added to cart!', 'success')
    } finally {
      setIsAdding(false)
    }
  }, [
    isAdding,
    inStock,
    isOffer,
    offer,
    product,
    cart,
    quantity,
    finalPrice,
    expressStock,
    selectedBand,
    selectedDial,
    updateQuantity,
    addItem,
    showToast,
    isRTL,
  ])

  // ── Wishlist ────────────────────────────────────────────────────────────
  // Reflect the ACTUAL wishlist membership (was a local flag that only ever
  // flipped on, so it never showed removal or the real state on load).
  const isWishlisted = useMemo(() => {
    const targetId = isOffer ? offer?.id : product?.id
    if (!targetId || !wishList?.length) return false
    return wishList.some((w) =>
      isOffer
        ? Number(w.offer_id) === Number(targetId)
        : Number(w.product_id) === Number(targetId),
    )
  }, [wishList, isOffer, offer, product])

  const [wishlistPending, setWishlistPending] = useState(false)
  const handleWishlist = useCallback(async () => {
    if (!userId) {
      showToast(isRTL ? 'يجب تسجيل الدخول أولاً' : 'Please login first', 'warning')
      router.push('/login')
      return
    }
    if (wishlistPending) return
    setWishlistPending(true)
    try {
      await handleAddTowishlist(isOffer ? offer.id : product.id, isOffer ? 'o' : 'p')
    } finally {
      setWishlistPending(false)
    }
  }, [userId, isOffer, offer, product, handleAddTowishlist, router, showToast, isRTL, wishlistPending])

  // (SEO title/meta now live in the route's generateMetadata — server-rendered.)

  // ── Analytics: ViewContent (fires once per resolved product/offer) ────────
  const viewedId = isOffer ? offer?.id : product?.id
  useEffect(() => {
    if (viewedId == null) return
    trackViewContent({ id: viewedId, name: getName(item, 'en'), value: finalPrice })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [viewedId])

  // ── Brand breadcrumb link ───────────────────────────────────────────────
  const brandHref = useMemo(() => {
    if (isOffer) return '/offers'
    if (!product?.brand_id) return null
    return `/listing?${buildListingParams({ brands: [product.brand_id] }, {}, tables).toString()}`
  }, [isOffer, product, tables])

  const brandLogo = useMemo(() => {
    if (isOffer || !product?.brand_id) return null
    const b = (tables?.brands || []).find((x) => x.id === product.brand_id)
    return b ? getImageUrl(b.image, 'Brand') : null
  }, [isOffer, product, tables])

  // ── Specifications (built from the watch — the offer's main product in offer mode) ──
  const specSource = isOffer ? offerProduct : product
  // Fashion vs watch. category_type is the top-level "Watches"/"Fashion" label:
  // ProductResource exposes it (EN) as category_type / category_type_name. Fashion
  // items reuse the band_* columns for their single colour/material, so we relabel
  // the colour + drop watch-only spec rows for them.
  const isFashion = useMemo(() => {
    const ct = safeStr(specSource?.category_type || specSource?.category_type_name)
    return /fashion/i.test(ct)
  }, [specSource])

  const specs = useMemo(() => {
    const it = specSource
    if (!it) return []
    const rows = []
    // Locale-aware value picker: the full record ships an `_ar` sibling for FK
    // names; the catalog transform instead ships one already-localized value.
    // Prefer the `_ar` sibling in Arabic, else fall back to the base key.
    const pick = (key) => (isRTL ? (it[key + '_ar'] ?? it[key]) : it[key])
    const add = (en, ar, val) => {
      const v = safeStr(val)
      if (v && !['null', 'undefined', 'N/A'].includes(v))
        rows.push({ label: isRTL ? ar : en, value: v })
    }
    const sized = (val, unit) =>
      val || val === 0 ? `${val} ${safeStr(unit) || ''}`.trim() : ''
    const boolLabel = (v) => {
      if (v === null || v === undefined || v === '') return ''
      const yes = v === 1 || v === '1' || v === true
      return yes ? (isRTL ? 'نعم' : 'Yes') : isRTL ? 'لا' : 'No'
    }
    const colorNames = (arr) =>
      (arr || [])
        .map((c) => (isRTL ? c.color_name_ar || c.name_ar : c.color_name_en || c.name_en))
        .filter(Boolean)
        .join(isRTL ? '، ' : ', ')
    // Locale-aware name for a related model. The full record ships nested objects
    // ({name_en, name_ar}); the catalog transform ships one already-localized
    // string. Objects → pick the current locale (never blindly name_en, which is
    // what safeStr did — that showed English brand/type/grade/gender in Arabic).
    const locName = (v) =>
      v && typeof v === 'object' ? (isRTL ? v.name_ar || v.name_en : v.name_en || v.name_ar) : v
    // Join a list of related models (or a single one) as localized names, with an
    // optional pre-joined string fallback (the resource's *_string aliases).
    const listNames = (v, fallbackStr) =>
      Array.isArray(v)
        ? v.map(locName).filter(Boolean).join(isRTL ? '، ' : ', ')
        : locName(v) || fallbackStr || ''

    // ── Shared (watch + fashion) ──
    add('Brand', 'الماركة', locName(it.brand))
    add('Category', 'الفئة', pick('category_type'))
    add('Type', 'النوع', locName(it.sub_type))
    add('Gender', 'الجنس', listNames(it.gender, it.gender_string))
    add('Grade', 'التصنيف', locName(it.grade) || it.grade_string)
    add('Model Name', 'اسم الموديل', pick('model_name'))
    add('Model Number', 'رقم الموديل', it.model_number)
    // Features render for both watch + fashion. Prefer `features` (objects carry
    // name_en/name_ar); the catalog ships localized strings under the same key.
    add('Features', 'المميزات', listNames(it.features || it.feature, it.feature_string))

    if (isFashion) {
      // Fashion: single colour + material, no watch jargon.
      add('Color', 'اللون', colorNames(it.band_colors || it.band_color))
      add('Material', 'الخامة', pick('band_material'))
      add('Closure', 'الإغلاق', pick('band_closure'))
      add('Country', 'بلد المنشأ', pick('country'))
      add('Stone', 'الحجر', pick('stone'))
      add(
        'Warranty',
        'الضمان',
        it.warranty_years ? `${it.warranty_years} ${isRTL ? 'سنة' : 'yrs'}` : '',
      )
      // Structured extra specs (bag/wallet/perfume/electronics + dimensions) →
      // readable rows. Known keys get friendly bilingual labels; anything else
      // falls back to a humanized key.
      const EXTRA_LABELS = {
        width_cm:         { en: 'Width', ar: 'العرض', unit: 'cm' },
        height_cm:        { en: 'Height', ar: 'الارتفاع', unit: 'cm' },
        depth_cm:         { en: 'Depth', ar: 'العمق', unit: 'cm' },
        strap_length_cm:  { en: 'Strap Length', ar: 'طول الحزام', unit: 'cm' },
        bag_strap_type:   { en: 'Strap Type', ar: 'نوع الحزام' },
        bag_compartments: { en: 'Compartments', ar: 'الأقسام' },
        laptop_compartment: { en: 'Laptop Compartment', ar: 'جيب لابتوب' },
        waterproof:       { en: 'Waterproof', ar: 'مقاوم للماء' },
        wallet_card_slots:{ en: 'Card Slots', ar: 'جيوب البطاقات' },
        rfid_protection:  { en: 'RFID Protection', ar: 'حماية RFID' },
        coin_pocket:      { en: 'Coin Pocket', ar: 'جيب نقود' },
        perfume_volume:   { en: 'Volume', ar: 'الحجم', unit: 'ml' },
        perfume_type:     { en: 'Type', ar: 'النوع' },
        perfume_scent:    { en: 'Scent', ar: 'الرائحة' },
        elec_battery_capacity: { en: 'Battery', ar: 'البطارية', unit: 'mAh' },
        elec_connectivity:{ en: 'Connectivity', ar: 'الاتصال' },
      }
      const extra = it.extra_attributes
      if (extra && typeof extra === 'object') {
        Object.entries(extra).forEach(([k, v]) => {
          const map = EXTRA_LABELS[k]
          const en = map ? map.en : k.replace(/_/g, ' ').replace(/\b\w/g, (m) => m.toUpperCase())
          const ar = map ? map.ar : en
          const val = typeof v === 'boolean' ? boolLabel(v) : map?.unit ? `${v} ${map.unit}` : v
          add(en, ar, val)
        })
      }
      return rows
    }

    // ── Watch specs ──
    add('Case Material', 'خامة العلبة', pick('dial_case_material') || pick('case_material'))
    add('Dial Color', 'لون الميناء', colorNames(it.dial_colors || it.dial_color))
    add('Band Material', 'خامة السوار', pick('band_material'))
    add('Band Color', 'لون السوار', colorNames(it.band_colors || it.band_color))
    add('Movement', 'الحركة', pick('watch_movement'))
    add('Display Type', 'نوع العرض', pick('dial_display_type'))
    add('Shape', 'الشكل', pick('case_shape'))
    add('Closure Type', 'نوع القفل', pick('band_closure'))
    add('Case Size', 'حجم العلبة', sized(it.case_size, it.case_size_type))
    add('Case Thickness', 'سماكة العلبة', sized(it.case_thickness, it.case_thickness_size_type))
    add('Band Width', 'عرض السوار', sized(it.band_width, it.band_width_size_type))
    add('Band Length', 'طول السوار', sized(it.band_length, it.band_size_type))
    add('Watch Height', 'ارتفاع الساعة', sized(it.watch_height, it.watch_height_size_type))
    add('Watch Width', 'عرض الساعة', sized(it.watch_width, it.watch_width_size_type))
    add('Watch Length', 'طول الساعة', sized(it.watch_length, it.watch_length_size_type))
    add('Water Resistance', 'مقاومة المياه', sized(it.water_resistance, it.water_resistance_size_type))
    add('Glass Material', 'مادة الزجاج', pick('dial_glass_material') || pick('glass_material'))
    add('Interchangeable Dial', 'ميناء قابل للتبديل', boolLabel(it.interchangeable_dial))
    add('Interchangeable Strap', 'سوار قابل للتبديل', boolLabel(it.interchangeable_strap))
    add('Watch Box', 'علبة الساعة', boolLabel(it.watch_box))
    add(
      'Warranty',
      'الضمان',
      it.warranty_years ? `${it.warranty_years} ${isRTL ? 'سنة' : 'yrs'}` : '',
    )
    add('Country', 'بلد المنشأ', pick('country'))
    add('Stone', 'الحجر', pick('stone'))
    return rows
  }, [specSource, isFashion, isRTL])

  // ── Description ──
  const description = useMemo(() => {
    const it = isOffer ? offer : product
    if (!it) return ''
    if (isOffer)
      return (
        (isRTL ? it.long_description_ar : it.long_description_en) || it.long_description_en || ''
      )
    if (it.translations?.length) {
      const t =
        it.translations.find((x) => x.locale === language) ||
        it.translations.find((x) => x.locale === 'en')
      if (t?.long_description) return t.long_description
      if (t?.short_description) return t.short_description
    }
    return it.long_description || it.short_description || it.description || ''
  }, [product, offer, isOffer, language, isRTL])

  // DOMPurify needs a DOM, so it can't run during SSR. Sanitize on the client
  // after mount — server + first client render both emit an empty content div
  // (no hydration mismatch); the sanitized HTML fills in post-hydration. (The
  // SEO-critical description already ships in the server meta + JSON-LD.)
  const [descHtml, setDescHtml] = useState('')
  useEffect(() => {
    // Client-only DOMPurify sanitize after mount (no SSR DOM) — intentional.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setDescHtml(description ? DOMPurify.sanitize(description) : '')
  }, [description])

  // ── Ratings / reviews (graceful — empty list if the endpoint errors) ──
  const [ratings, setRatings] = useState([])
  const [newReview, setNewReview] = useState({ value: 0, comment: '' })
  const fetchRatings = useCallback(() => {
    const itemId = isOffer ? offer?.id : product?.id
    if (!itemId) return
    const endpoint = isOffer ? '/all_offer_rating' : '/all_product_rating'
    const key = isOffer ? 'offer_id' : 'product_id'
    http
      .get(endpoint)
      .then((res) => setRatings((res.data || []).filter((r) => r[key] === itemId)))
      .catch(() => setRatings([]))
  }, [isOffer, offer?.id, product?.id])
  useEffect(() => {
    fetchRatings()
  }, [fetchRatings])

  const avgRating = ratings.length
    ? ratings.reduce((a, r) => a + Number(r.rating || 0), 0) / ratings.length
    : ratingValue
  const reviewCount = ratings.length

  const handleSubmitReview = useCallback(async () => {
    if (!userId) {
      showToast(isRTL ? 'يجب تسجيل الدخول أولاً' : 'Please login first', 'warning')
      router.push('/login')
      return
    }
    const comment = DOMPurify.sanitize(newReview.comment || '')
    if (!newReview.value || !comment.trim()) {
      showToast(isRTL ? 'يرجى إدخال تقييم وتعليق' : 'Please enter a rating and comment', 'warning')
      return
    }
    try {
      const endpoint = isOffer ? '/add_offer_rating' : '/add_product_rating'
      await http.post(endpoint, null, {
        params: {
          [isOffer ? 'offer_id' : 'product_id']: isOffer ? offer.id : product.id,
          rating: newReview.value,
          comment,
          user_id: userId,
        },
      })
      setNewReview({ value: 0, comment: '' })
      fetchRatings()
      showToast(isRTL ? 'تم إرسال التقييم!' : 'Review submitted!', 'success')
    } catch {
      showToast(isRTL ? 'تعذّر إرسال التقييم' : 'Could not submit review', 'error')
    }
  }, [userId, newReview, setNewReview, isOffer, offer, product, fetchRatings, showToast, isRTL, router])

  // ── Related products: relevance scoring (lazy-rendered when scrolled near) ──
  // For offers, scoring runs against the offer's linked product so we recommend
  // genuinely similar watches. A cheap pre-filter (share ≥1 core attribute)
  // keeps the loop fast even on large catalogs.
  const related = useMemo(() => {
    const base = isOffer ? offerProduct : product
    if (!base || !products?.length) return []
    const exclude = base.id
    const currentPrice = Number(base.sale_price_after_discount || base.selling_price || 0)
    const baseGenders = Array.isArray(base.genders_en)
      ? base.genders_en
      : base.genders_en
        ? [base.genders_en]
        : []
    const baseDialIds = new Set(
      (base.dial_colors || base.dial_color || []).map((c) => c.color_id).filter(Boolean),
    )

    const scored = products
      .filter((p) => p.id !== exclude)
      // pre-filter: must share at least one core attribute to be worth scoring
      .filter(
        (p) =>
          p.brand_id === base.brand_id ||
          p.sub_type_id === base.sub_type_id ||
          p.category_type_id === base.category_type_id,
      )
      .map((p) => {
        let score = 0
        if (p.brand_id && p.brand_id === base.brand_id) score += 3
        if (p.sub_type_id && p.sub_type_id === base.sub_type_id) score += 3
        if (p.category_type_id && p.category_type_id === base.category_type_id) score += 1
        const pGenders = Array.isArray(p.genders_en)
          ? p.genders_en
          : p.genders_en
            ? [p.genders_en]
            : []
        if (baseGenders.some((g) => pGenders.includes(g))) score += 2
        if (p.watch_movement_id && p.watch_movement_id === base.watch_movement_id) score += 2
        if (p.case_shape_id && p.case_shape_id === base.case_shape_id) score += 1
        if (p.band_material_id && p.band_material_id === base.band_material_id) score += 1
        if (
          baseDialIds.size &&
          (p.dial_colors || p.dial_color || []).some((c) => baseDialIds.has(c.color_id))
        )
          score += 1
        const pPrice = Number(p.sale_price_after_discount || p.selling_price || 0)
        if (currentPrice > 0 && pPrice > 0) {
          const ratio = pPrice / currentPrice
          if (ratio >= 0.7 && ratio <= 1.3) score += 2
          else if (ratio >= 0.5 && ratio <= 1.5) score += 1
        }
        if (p.grade_id && p.grade_id === base.grade_id) score += 1
        return { product: p, score }
      })
      .filter((x) => x.score >= 2)
      .sort((a, b) => {
        if (b.score !== a.score) return b.score - a.score
        const aBrand = a.product.brand_id === base.brand_id ? 1 : 0
        const bBrand = b.product.brand_id === base.brand_id ? 1 : 0
        if (bBrand !== aBrand) return bBrand - aBrand
        // Random tie-break for equally-scored related products — intentional variety.
        // eslint-disable-next-line react-hooks/purity
        return Math.random() - 0.5
      })
      .map((x) => x.product)

    // Fallback for new/sparse products: top up from the same category.
    let result = scored.slice(0, 12)
    if (result.length < 4) {
      const have = new Set(result.map((p) => p.id))
      const fill = products.filter(
        (p) => p.id !== exclude && p.category_type_id === base.category_type_id && !have.has(p.id),
      )
      result = [...result, ...fill].slice(0, 12)
    }
    return result
  }, [product, offerProduct, isOffer, products])

  const [showRelated, setShowRelated] = useState(false)
  const relatedRef = useRef(null)
  useEffect(() => {
    const el = relatedRef.current
    if (!el) return
    const obs = new IntersectionObserver(
      ([entry]) => {
        if (entry.isIntersecting) {
          setShowRelated(true)
          obs.disconnect()
        }
      },
      { rootMargin: '200px' },
    )
    obs.observe(el)
    return () => obs.disconnect()
  }, [item?.id])

  // ── Mobile sticky buy bar ──
  const [showSticky, setShowSticky] = useState(false)
  const cartBtnRef = useRef(null)
  useEffect(() => {
    const el = cartBtnRef.current
    if (!el) return
    const obs = new IntersectionObserver(([entry]) => setShowSticky(!entry.isIntersecting), {
      threshold: 0,
    })
    obs.observe(el)
    return () => obs.disconnect()
  }, [item?.id])

  // ── Accordions (mobile) ──
  // Exclusive accordion (mobile): only one section open at a time — tapping a
  // header collapses the others. Desktop shows every section regardless (the
  // collapse styling is mobile-only), so this only changes the phone view.
  const [openSection, setOpenSection] = useState(null) // 'specs' | 'desc' | 'reviews' | null
  const toggleSection = useCallback(
    (id) => setOpenSection((cur) => (cur === id ? null : id)),
    [],
  )
  const specsOpen = openSection === 'specs'
  const descOpen = openSection === 'desc'
  const reviewsOpen = openSection === 'reviews'

  // ── Share ──
  const handleShare = useCallback(async () => {
    const url = window.location.href
    const title = getName(isOffer ? offer : product, language)
    if (navigator.share) {
      try {
        await navigator.share({ title, url })
      } catch {
        /* user cancelled */
      }
    } else {
      try {
        await navigator.clipboard.writeText(url)
        showToast(isRTL ? 'تم نسخ الرابط!' : 'Link copied!', 'success')
      } catch {
        /* clipboard blocked */
      }
    }
  }, [isOffer, offer, product, language, getName, showToast, isRTL])

  // ── Render: catalog fetch failed (the product resolves from the catalog, so a
  //    fetch failure means we can't load it) → inline error + retry, shown
  //    immediately instead of a skeleton that decays into a wrong "not found". ──
  if (catalogIsError && !item) {
    return (
      <div className="wz-pd">
        <div className="wz-pd-notfound" role="alert">
          <h2>{isRTL ? 'تعذّر تحميل المنتج' : "Couldn't load this product"}</h2>
          <p>
            {isRTL
              ? 'تحقّق من اتصالك وحاول مرة أخرى.'
              : 'Check your connection and try again.'}
          </p>
          <button className="wz-pd-notfound-btn" onClick={refetchCatalog}>
            {isRTL ? 'إعادة المحاولة' : 'Retry'}
          </button>
        </div>
      </div>
    )
  }

  // ── Render: loading ───────────────────────────────────────────────────
  if (isLoading) {
    return (
      <div className="wz-pd">
        <div className="wz-pd-inner">
          <div className="wz-pd-skel-bc" />
          <div className="wz-pd-grid">
            <div className="wz-pd-skel-gallery">
              <div className="wz-pd-skel-main" />
              <div className="wz-pd-skel-thumbs">
                {Array.from({ length: 4 }).map((_, i) => (
                  <div className="wz-pd-skel-thumb" key={i} />
                ))}
              </div>
            </div>
            <div className="wz-pd-skel-info">
              <div className="wz-pd-skel-line w40" />
              <div className="wz-pd-skel-line w80" />
              <div className="wz-pd-skel-line w60" />
              <div className="wz-pd-skel-line w30" />
              <div className="wz-pd-skel-block" />
              <div className="wz-pd-skel-btn" />
            </div>
          </div>
        </div>
      </div>
    )
  }

  // ── Render: 404 ─────────────────────────────────────────────────────────
  if (notFound) {
    return (
      <div className="wz-pd">
        <div className="wz-pd-notfound">
          <h2>
            {isRTL
              ? isOffer
                ? 'العرض غير موجود'
                : 'المنتج غير موجود'
              : isOffer
                ? 'Offer not found'
                : 'Product not found'}
          </h2>
          <p>
            {isRTL
              ? 'العنصر الذي تبحث عنه غير متوفر.'
              : "The item you're looking for doesn't exist."}
          </p>
          <button className="wz-pd-notfound-btn" onClick={() => router.push('/listing')}>
            {isRTL ? 'تصفح كل المنتجات' : 'Browse all products'}
          </button>
        </div>
      </div>
    )
  }

  // ── Render: detail ──────────────────────────────────────────────────────
  const cartLabel = !inStock
    ? isRTL
      ? 'غير متوفر'
      : 'Out of Stock'
    : isAdding
      ? isRTL
        ? 'جارٍ الإضافة…'
        : 'Adding…'
      : !isOffer && expressStock <= 0 && marketStock > 0
        ? isRTL
          ? 'اطلب مسبقاً'
          : 'Pre-Order'
        : isRTL
          ? 'أضف إلى السلة'
          : 'Add to Cart'

  const stockTone = isOffer
    ? offerStock > 0
      ? 'express'
      : 'out'
    : expressStock > 0
      ? 'express'
      : marketStock > 0
        ? 'market'
        : 'out'

  const stockLabel = isOffer
    ? offerStock > 0
      ? isRTL
        ? `متوفر · ${offerStock}`
        : `In stock · ${offerStock}`
      : isRTL
        ? 'غير متوفر'
        : 'Out of stock'
    : expressStock > 0
      ? isRTL
        ? `إكسبريس · ${expressStock} متاح`
        : `Express · ${expressStock} in stock`
      : marketStock > 0
        ? isRTL
          ? `ماركت · ${marketStock} متاح`
          : `Market · ${marketStock} available`
        : isRTL
          ? 'غير متوفر'
          : 'Out of stock'

  const lowStock = inStock && maxStock > 0 && maxStock <= 5

  // Related grouped by brand for the two-section presentation.
  const relatedBase = isOffer ? offerProduct : product
  const relatedBrandId = relatedBase?.brand_id
  const relatedBrandName = getBrand(relatedBase, language) || relatedBase?.brand || ''
  const sameBrandRelated = related.filter((p) => p.brand_id === relatedBrandId)
  const otherBrandRelated = related.filter((p) => p.brand_id !== relatedBrandId)
  const splitRelated = sameBrandRelated.length >= 3 && otherBrandRelated.length >= 3

  return (
    <div className="wz-pd" dir={isRTL ? 'rtl' : 'ltr'}>
      <div className="wz-pd-inner">
        {/* Breadcrumb */}
        <nav className="wz-pd-bc" aria-label="Breadcrumb">
          <Link href="/" className="wz-pd-bc-link">
            {isRTL ? 'الرئيسية' : 'Home'}
          </Link>
          <span className="wz-pd-bc-sep">/</span>
          {isOffer ? (
            <Link href="/offers" className="wz-pd-bc-link">
              {isRTL ? 'العروض' : 'Offers'}
            </Link>
          ) : brandLabel && brandHref ? (
            <Link href={brandHref} className="wz-pd-bc-link">
              {brandLabel}
            </Link>
          ) : (
            <Link href="/listing" className="wz-pd-bc-link">
              {isRTL ? 'كل المنتجات' : 'All Products'}
            </Link>
          )}
          <span className="wz-pd-bc-sep">/</span>
          <span className="wz-pd-bc-current">{name}</span>
        </nav>

        {/* Mobile-only summary: brand + name + price, fills the space above the
            image so the first viewport is informative (hidden on desktop). */}
        <div className="wz-pd-mobile-summary" dir={isRTL ? 'rtl' : 'ltr'}>
          {brandLabel && <p className="wz-pd-ms-brand">{brandLabel}</p>}
          <h1 className="wz-pd-ms-name">{name}</h1>
          <div className="wz-pd-ms-price">
            <span className={hasSale ? 'wz-pd-ms-sale' : 'wz-pd-ms-regular'}>
              {fmt(finalPrice)} {currency}
            </span>
            {hasSale && (
              <>
                <span className="wz-pd-ms-orig">
                  {fmt(price)} {currency}
                </span>
                <span className="wz-pd-ms-discount">−{discount}%</span>
              </>
            )}
          </div>
        </div>

        <div className="wz-pd-grid">
          {/* ── Gallery ── */}
          <div className="wz-pd-gallery">
            {/* DESKTOP (md+): in-place hover/zoom + thumbnails — unchanged. */}
            <div className="wz-pd-gallery-desktop">
              <div
                className="wz-pd-main"
                onTouchStart={onTouchStart}
                onTouchEnd={onTouchEnd}
              >
                {/* key forces a clean remount when the selected image changes so
                    the hover-zoom always targets the current main image. */}
                <ImageZoom
                  key={images[activeImg]}
                  src={images[activeImg]}
                  alt={name}
                  width={600}
                  height={600}
                  zoomScale={2.5}
                  className="wz-pd-main-img-zoom"
                  onError={handleGalleryImgError}
                />
                {hasSale && <span className="wz-pd-discount">−{discount}%</span>}
              </div>

              {images.length > 1 && (
                <div className="wz-pd-thumbs">
                  {images.map((img, i) => (
                    <button
                      key={img + i}
                      className={`wz-pd-thumb${i === activeImg ? ' is-active' : ''}`}
                      onClick={() => setActiveImg(i)}
                      aria-label={`Image ${i + 1}`}
                    >
                      <Image
                        src={img}
                        alt={`${name} - image ${i + 1}`}
                        width={48}
                        height={48}
                        quality={70}
                        onError={handleGalleryImgError}
                      />
                    </button>
                  ))}
                </div>
              )}
            </div>

            {/* MOBILE (< md): native scroll-snap carousel, no zoom/lightbox. */}
            <div className="wz-pd-gallery-mobile">
              <div className="wz-pd-carousel" ref={carouselRef}>
                {images.map((img, i) => (
                  <div
                    className="wz-pd-slide"
                    key={img + i}
                    data-idx={i}
                    ref={(el) => {
                      slideRefs.current[i] = el
                    }}
                  >
                    <Image
                      src={img}
                      alt={`${name} - image ${i + 1}`}
                      width={600}
                      height={600}
                      quality={80}
                      className="wz-pd-slide-img"
                      onError={handleGalleryImgError}
                    />
                    {hasSale && i === 0 && <span className="wz-pd-discount">−{discount}%</span>}
                  </div>
                ))}
              </div>
              {images.length > 1 && (
                <div className="wz-pd-dots">
                  {images.map((_, i) => (
                    <span
                      key={i}
                      className={`wz-pd-dot${i === activeImg ? ' is-active' : ''}`}
                      onClick={() => scrollToSlide(i)}
                    />
                  ))}
                </div>
              )}
            </div>
          </div>

          {/* ── Info ── */}
          <div className="wz-pd-info">
            {brandLabel &&
              (brandHref ? (
                <Link href={brandHref} className="wz-pd-brand wz-pd-hide-mobile">
                  {brandLogo && (
                    <Image
                      src={brandLogo}
                      alt=""
                      className="wz-pd-brand-logo"
                      width={28}
                      height={28}
                      quality={80}
                    />
                  )}
                  <span>{brandLabel}</span>
                </Link>
              ) : (
                <span className="wz-pd-brand wz-pd-hide-mobile">
                  <span>{brandLabel}</span>
                </span>
              ))}
            <h2 className="wz-pd-name wz-pd-hide-mobile">{name}</h2>

            {avgRating > 0 && (
              <div className="wz-pd-rating" aria-label={`${avgRating.toFixed(1)} / 5`}>
                <span
                  className="wz-pd-stars"
                  style={{ '--rating': Math.max(0, Math.min(5, avgRating)) }}
                >
                  ★★★★★
                </span>
                <span className="wz-pd-rating-num">
                  {avgRating.toFixed(1)}
                  {reviewCount > 0 ? ` · ${reviewCount} ${isRTL ? 'تقييم' : 'reviews'}` : ''}
                </span>
              </div>
            )}

            <div className="wz-pd-divider wz-pd-hide-mobile" />

            {/* Price */}
            <div className="wz-pd-prices wz-pd-hide-mobile">
              <span className={`wz-pd-price${hasSale ? ' wz-pd-price--sale' : ''}`}>
                {fmt(finalPrice)} {currency}
              </span>
              {hasSale && (
                <span className="wz-pd-price-orig">
                  {fmt(price)} {currency}
                </span>
              )}
              {hasSale && <span className="wz-pd-save">−{discount}%</span>}
            </div>

            {/* Stock */}
            <div className={`wz-pd-stock wz-pd-stock--${stockTone}`}>
              <span className="wz-pd-stock-dot" />
              <span className="wz-pd-stock-text">{stockLabel}</span>
              {lowStock && (
                <span className="wz-pd-stock-low">
                  {isRTL ? `باقي ${maxStock} فقط!` : `Only ${maxStock} left!`}
                </span>
              )}
            </div>

            {/* Colors (products only). Fashion items reuse band_color as their single
                product colour — hide the watch-only "Dial Color" and relabel the band
                swatch as plain "Color" so bags/belts don't show watch terminology. */}
            {!isOffer && !isFashion && dialColors.length > 0 && (
              <div className="wz-pd-colors">
                <span className="wz-pd-colors-label">{isRTL ? 'لون الميناء' : 'Dial Color'}</span>
                <div className="wz-pd-swatches">
                  {dialColors.map((c, i) => (
                    <button
                      key={i}
                      className={`wz-pd-swatch${selectedDial === c.color_value ? ' is-active' : ''}`}
                      style={{ background: c.color_value || '#f0f0f0' }}
                      onClick={() => setSelectedDial(c.color_value)}
                      title={isRTL ? c.color_name_ar : c.color_name_en}
                      aria-label={isRTL ? c.color_name_ar : c.color_name_en}
                    />
                  ))}
                </div>
              </div>
            )}
            {!isOffer && bandColors.length > 0 && (
              <div className="wz-pd-colors">
                <span className="wz-pd-colors-label">
                  {isFashion ? (isRTL ? 'اللون' : 'Color') : isRTL ? 'لون السوار' : 'Band Color'}
                </span>
                <div className="wz-pd-swatches">
                  {bandColors.map((c, i) => (
                    <button
                      key={i}
                      className={`wz-pd-swatch${selectedBand === c.color_value ? ' is-active' : ''}`}
                      style={{ background: c.color_value || '#f0f0f0' }}
                      onClick={() => setSelectedBand(c.color_value)}
                      title={isRTL ? c.color_name_ar : c.color_name_en}
                      aria-label={isRTL ? c.color_name_ar : c.color_name_en}
                    />
                  ))}
                </div>
              </div>
            )}

            {/* Quantity + actions */}
            <div className="wz-pd-actions">
              <div className="wz-pd-qty">
                <button
                  onClick={() => changeQty(-1)}
                  disabled={quantity <= 1}
                  aria-label="Decrease"
                >
                  −
                </button>
                <span>{quantity}</span>
                <button
                  onClick={() => changeQty(1)}
                  disabled={quantity >= (maxStock || 1)}
                  aria-label="Increase"
                >
                  +
                </button>
              </div>

              <button
                ref={cartBtnRef}
                className={`wz-pd-add${isAdding ? ' is-added' : ''}`}
                onClick={handleAdd}
                disabled={!inStock || isAdding}
                aria-busy={isAdding}
              >
                {cartLabel}
              </button>

              <button
                className={`wz-pd-wish${isWishlisted ? ' is-on' : ''}`}
                onClick={handleWishlist}
                disabled={wishlistPending}
                aria-busy={wishlistPending}
                style={wishlistPending ? { opacity: 0.55, cursor: 'wait' } : undefined}
                aria-label={
                  isWishlisted
                    ? isRTL ? 'إزالة من قائمة الرغبات' : 'Remove from wishlist'
                    : isRTL ? 'أضف إلى قائمة الرغبات' : 'Add to wishlist'
                }
                title={
                  isWishlisted
                    ? isRTL ? 'إزالة من قائمة الرغبات' : 'Remove from wishlist'
                    : isRTL ? 'أضف إلى قائمة الرغبات' : 'Add to wishlist'
                }
              >
                {isWishlisted ? '♥' : '♡'}
              </button>

              <button
                className="wz-pd-share"
                onClick={handleShare}
                aria-label={isRTL ? 'مشاركة' : 'Share'}
                title={isRTL ? 'مشاركة' : 'Share'}
              >
                <FiShare2 />
              </button>
            </div>

            {/* Short description */}
            {(item?.short_description ||
              item?.short_description_en ||
              item?.short_description_ar) && (
              <p className="wz-pd-short">
                {safeStr(
                  isOffer
                    ? isRTL
                      ? item.short_description_ar
                      : item.short_description_en
                    : item.short_description,
                )}
              </p>
            )}

            {/* Trust badges + live social proof / low-stock urgency */}
            <TrustSignals
              variant="pdp"
              rating={avgRating}
              reviewCount={reviewCount}
              stockLeft={inStock ? maxStock : null}
              isRTL={isRTL}
              isFashion={isFashion}
            />
          </div>
        </div>

        {/* ── Specifications ── */}
        {specs.length > 0 && (
          <section className="wz-pd-section wz-pd-specs">
            <button
              className="wz-pd-section-head"
              onClick={() => toggleSection('specs')}
              aria-expanded={specsOpen}
            >
              <span>{isRTL ? 'المواصفات' : 'Specifications'}</span>
              <span className={`wz-pd-acc-icon${specsOpen ? ' is-open' : ''}`}>›</span>
            </button>
            <div className={`wz-pd-section-body${specsOpen ? ' is-open' : ''}`}>
              <div className="wz-pd-spec-grid">
                {specs.map((row, i) => (
                  <div className="wz-pd-spec-item" key={i}>
                    <span className="wz-pd-spec-label">{row.label}</span>
                    <span className="wz-pd-spec-value">{row.value}</span>
                  </div>
                ))}
              </div>
            </div>
          </section>
        )}

        {/* ── Description ── */}
        {description && (
          <section className="wz-pd-section wz-pd-desc">
            <button
              className="wz-pd-section-head"
              onClick={() => toggleSection('desc')}
              aria-expanded={descOpen}
            >
              <span>{isRTL ? 'الوصف' : 'Description'}</span>
              <span className={`wz-pd-acc-icon${descOpen ? ' is-open' : ''}`}>›</span>
            </button>
            <div className={`wz-pd-section-body${descOpen ? ' is-open' : ''}`}>
              <div
                className="wz-pd-desc-content"
                dangerouslySetInnerHTML={{ __html: descHtml }}
              />
            </div>
          </section>
        )}

        {/* ── Ratings & reviews ── */}
        <section className="wz-pd-section wz-pd-ratings">
          <button
            className="wz-pd-section-head"
            onClick={() => toggleSection('reviews')}
            aria-expanded={reviewsOpen}
          >
            <span>
              {isRTL ? 'التقييمات' : 'Reviews'}
              {reviewCount > 0 ? ` (${reviewCount})` : ''}
            </span>
            <span className={`wz-pd-acc-icon${reviewsOpen ? ' is-open' : ''}`}>›</span>
          </button>
          <div className={`wz-pd-section-body${reviewsOpen ? ' is-open' : ''}`}>
          <div className="wz-pd-acc-inner">
          <div className="wz-pd-ratings-summary">
            <div className="wz-pd-ratings-avg">{(avgRating || 0).toFixed(1)}</div>
            <div className="wz-pd-ratings-meta">
              <Stars value={avgRating} size={18} />
              <p className="wz-pd-ratings-count">
                {reviewCount > 0
                  ? `${reviewCount} ${isRTL ? 'تقييم' : reviewCount === 1 ? 'review' : 'reviews'}`
                  : isRTL
                    ? 'لا توجد تقييمات بعد'
                    : 'No reviews yet'}
              </p>
            </div>
          </div>

          {ratings.length > 0 && (
            <div className="wz-pd-reviews">
              {ratings.map((r) => (
                <div className="wz-pd-review" key={r.id}>
                  <div className="wz-pd-review-head">
                    <Stars value={Number(r.rating)} size={13} />
                    <span className="wz-pd-review-name">
                      {r.user_name || r.first_name || (isRTL ? 'عميل' : 'Customer')}
                    </span>
                    {r.created_at && (
                      <span className="wz-pd-review-date">
                        {new Date(r.created_at).toLocaleDateString(isRTL ? 'ar-EG' : 'en-US')}
                      </span>
                    )}
                  </div>
                  {r.comment && <p className="wz-pd-review-text">{r.comment}</p>}
                </div>
              ))}
            </div>
          )}

          {userId ? (
            <div className="wz-pd-review-form">
              <h3>{isRTL ? 'أضف تقييمك' : 'Write a review'}</h3>
              <Stars
                value={newReview.value}
                size={26}
                onSelect={(v) => setNewReview((p) => ({ ...p, value: v }))}
              />
              <textarea
                className="wz-pd-review-input"
                rows={3}
                placeholder={isRTL ? 'اكتب تعليقك...' : 'Write your comment...'}
                value={newReview.comment}
                onChange={(e) => setNewReview((p) => ({ ...p, comment: e.target.value }))}
              />
              <button className="wz-pd-review-submit" onClick={handleSubmitReview}>
                {isRTL ? 'إرسال التقييم' : 'Submit Review'}
              </button>
            </div>
          ) : (
            <p className="wz-pd-review-login">
              {isRTL ? 'سجّل الدخول لكتابة تقييم' : 'Login to write a review'}
            </p>
          )}
          </div>
          </div>
        </section>
      </div>

      {/* ── Related (full-width band) ── */}
      <div className="wz-pd-related-band" ref={relatedRef}>
        {showRelated && related.length > 0 && (
          <div className="wz-pd-related-inner">
            {splitRelated ? (
              <>
                <ProductSlider
                  gradeproducts={sameBrandRelated}
                  text={{
                    title: {
                      en: `More from ${relatedBrandName}`,
                      ar: `المزيد من ${relatedBrandName}`,
                    },
                    description: { en: '', ar: '' },
                  }}
                />
                <ProductSlider
                  gradeproducts={otherBrandRelated}
                  text={{
                    title: {
                      en: "Similar Products You'll Love",
                      ar: 'منتجات مشابهة ستعجبك',
                    },
                    description: { en: '', ar: '' },
                  }}
                />
              </>
            ) : (
              <ProductSlider
                gradeproducts={related}
                text={{
                  title: { en: 'You May Also Like', ar: 'قد يعجبك أيضاً' },
                  description: { en: '', ar: '' },
                }}
              />
            )}
          </div>
        )}
      </div>

      {/* ── Mobile sticky buy bar ── */}
      <div className={`wz-pd-sticky${showSticky ? ' is-visible' : ''}`}>
        <div className="wz-pd-sticky-price">
          <span className="wz-pd-sticky-amount">
            {fmt(finalPrice)} {currency}
          </span>
          {hasSale && (
            <span className="wz-pd-sticky-orig">
              {fmt(price)} {currency}
            </span>
          )}
        </div>
        <button
          className="wz-pd-sticky-add"
          onClick={handleAdd}
          disabled={!inStock || isAdding}
          aria-busy={isAdding}
        >
          {cartLabel}
        </button>
      </div>

      <BackToTop />
    </div>
  )
}

export default memo(ProductDetailClient)
