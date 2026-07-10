'use client'
import { useState, useEffect, useMemo, useRef, useCallback } from 'react'
import { useRouter, usePathname, useSearchParams } from 'next/navigation'
import { FiSliders, FiX, FiChevronDown, FiSearch, FiChevronLeft, FiChevronRight } from 'react-icons/fi'
import { useCatalog } from '@/src/Hooks/queries/useCatalog'
import { useUIStore } from '@/src/Store/uiStore'
import { buildListingParams, parseListingParams, paramsKey } from '@/src/utils/listingParams'
import { passesFilters } from '@/src/utils/filterPredicate'
import ProductCard from '@/src/Components/Product/ProductCard'
import SideBar from '@/src/Components/SideBar/SideBar'
import SmartSuggestions from '@/src/Components/Listing/SmartSuggestions'
import BackToTop from '@/src/Components/BackToTop/BackToTop'
import './Listing.css'

const PAGE_SIZE = 24
const PRICE_MAX = 99999999

// English gender name → Arabic label (mirrors SmartSuggestions).
const GENDER_AR = { Men: 'رجالي', Women: 'نسائي', Unisex: 'للجنسين', Kids: 'أطفال' }

// localized table name: current language → English fallback
const trName = (item, key, language) =>
  item?.translations?.find((t) => t.locale === language)?.[key] ??
  item?.translations?.find((t) => t.locale === 'en')?.[key] ??
  ''

// page list with ellipsis, max 5 numbers + first/last
function pageList(current, total) {
  if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1)
  const pages = [1]
  const start = Math.max(2, current - 1)
  const end = Math.min(total - 1, current + 1)
  if (start > 2) pages.push('…')
  for (let i = start; i <= end; i++) pages.push(i)
  if (end < total - 1) pages.push('…')
  pages.push(total)
  return pages
}

// ListingClient — ported from Frontend Listing.jsx. react-router hooks are
// swapped for next/navigation; the URL is updated via window.history (which the
// App Router patches to sync usePathname/useSearchParams) so filter/sort/
// pagination changes stay client-side with no server round-trip.
//
// Two modes:
//   • canonical /listing (seedParams == null): the URL query string is the
//     shareable source of truth — full URL <-> store bidirectional sync.
//   • facet alias routes (seedParams = "brand=<id>" etc.): the server-resolved
//     facet seeds the store once per navigation; URL <-> store sync is off.
//
// Pre-hydration (SSR + first client paint) the pipeline reads filters/page from
// the URL/seed directly, so the server HTML already reflects ?brands=… . After
// mount the Zustand store (written by SideBar/SmartSuggestions) takes over — the
// URL→store effect seeds the store to match, so the two agree with no flash.
export default function ListingClient({ seedParams = null }) {
  const { tables, products, isFetching, isError, refetch } = useCatalog()
  const {
    language,
    currentPage: storeCurrentPage,
    setCurrentPage,
    filters: storeFilters,
    setFilters,
  } = useUIStore()
  const isRTL = language === 'ar'
  const router = useRouter()
  const pathname = usePathname()
  const urlSearchParams = useSearchParams()

  const syncUrl = seedParams == null

  const [drawerOpen, setDrawerOpen] = useState(false)

  // Category splits — derived locally (identical to MyProvider's old helper):
  // filter the localized catalog by the English category_type literal.
  const watches = useMemo(
    () => (products || []).filter((p) => p.category_type === 'Watches'),
    [products],
  )
  const fashion = useMemo(
    () => (products || []).filter((p) => p.category_type === 'Fashion'),
    [products],
  )

  // Hydration flag: false during SSR + the first client render (so both agree),
  // then true — handing the source of truth to the client store.
  const [hydrated, setHydrated] = useState(false)
  useEffect(() => {
    // One-shot SSR hydration flag flip — intentional.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setHydrated(true)
  }, [])

  const tablesReady = !!(tables && Object.keys(tables).length)

  // Live URL params drive q/sort (and, on /listing, the filter seed too).
  const parsed = useMemo(() => parseListingParams(urlSearchParams, tables), [urlSearchParams, tables])
  // Facet seed (alias routes) — a query string resolved on the server.
  const seed = useMemo(
    () => (seedParams != null ? parseListingParams(new URLSearchParams(seedParams), tables) : parsed),
    [seedParams, parsed, tables],
  )

  // Pre-hydration read straight from URL/seed; post-hydration from the store.
  const filters = hydrated ? storeFilters : seed.filters
  const currentPage = hydrated ? storeCurrentPage : seed.page

  // ── Facet alias → store: seed once per navigation (i.e. seedParams change) ──
  const seededRef = useRef(null)
  useEffect(() => {
    if (syncUrl || !tablesReady) return
    if (seededRef.current === seedParams) return
    seededRef.current = seedParams
    setFilters(seed.filters)
    setCurrentPage(1)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [seedParams, tablesReady, syncUrl])

  // Update the URL without a server round-trip; useSearchParams() re-renders.
  // Accepts a URLSearchParams or a (prev) => URLSearchParams updater.
  const setSearchParams = useCallback(
    (next, opts = {}) => {
      const current = new URLSearchParams(urlSearchParams.toString())
      const params = typeof next === 'function' ? next(current) : next
      const qs = params.toString()
      const url = qs ? `${pathname}?${qs}` : pathname
      if (opts.replace) window.history.replaceState(null, '', url)
      else window.history.pushState(null, '', url)
    },
    [urlSearchParams, pathname],
  )

  // Guards the store→URL effect from echoing a URL→store change.
  const skipStoreToUrl = useRef(false)

  // ── URL → store (canonical /listing only) ──
  useEffect(() => {
    if (!syncUrl || !tablesReady) return
    skipStoreToUrl.current = true
    setFilters(parsed.filters)
    setCurrentPage(parsed.page)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [urlSearchParams, syncUrl, tablesReady])

  // ── store → URL (canonical /listing only) ──
  useEffect(() => {
    if (!syncUrl || !tablesReady) return
    if (skipStoreToUrl.current) {
      skipStoreToUrl.current = false
      return
    }
    const target = buildListingParams(
      storeFilters,
      { q: parsed.q, sort: parsed.sort, page: storeCurrentPage },
      tables,
    )
    if (paramsKey(target) !== paramsKey(urlSearchParams)) {
      setSearchParams(target, { replace: false })
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [storeFilters, storeCurrentPage, syncUrl, tablesReady])

  // ── Filter + search + sort pipeline ──
  // Derived synchronously (useMemo, not an effect) so the SERVER render already
  // reflects the filtered count + first page — an effect wouldn't run during SSR.
  const filteredProducts = useMemo(() => {
    if (!products?.length && !watches?.length && !fashion?.length) return []

    let base = products

    if (filters.categories.length > 0) {
      const names = filters.categories
        .map((cat) => {
          const c = tables.categoryTypes?.find((item) => item.id === cat)
          return c?.translations?.find((t) => t.locale === language)?.category_type_name
        })
        .filter(Boolean)
      if (names.length === 1) {
        if (names[0] === 'Watches') base = watches
        else if (names[0] === 'Fashion') base = fashion
      }
    }

    let filtered = base.filter((product) => passesFilters(product, filters))

    const q = parsed.q.trim().toLowerCase()
    if (q) {
      filtered = filtered.filter(
        (p) =>
          p.product_title?.toLowerCase().includes(q) ||
          p.short_description?.toLowerCase().includes(q) ||
          p.brand?.toLowerCase().includes(q) ||
          p.search_keywords?.toLowerCase().includes(q),
      )
    }

    if (parsed.sort === 'price_asc') {
      filtered = [...filtered].sort((a, b) => a.sale_price_after_discount - b.sale_price_after_discount)
    } else if (parsed.sort === 'price_desc') {
      filtered = [...filtered].sort((a, b) => b.sale_price_after_discount - a.sale_price_after_discount)
    } else if (parsed.sort === 'newest') {
      filtered = [...filtered].sort((a, b) => b.id - a.id)
    } else if (parsed.sort === 'rating') {
      filtered = [...filtered].sort((a, b) => (b.rating || 0) - (a.rating || 0))
    }

    return filtered
  }, [filters, products, watches, fashion, tables, language, parsed])

  const totalPages = Math.max(1, Math.ceil(filteredProducts.length / PAGE_SIZE))

  // Derived slice — no separate state/effect needed.
  const displayedProducts = useMemo(
    () => filteredProducts.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE),
    [filteredProducts, currentPage],
  )

  // ── Sort dropdown (writes to URL directly) ──
  const handleSort = useCallback(
    (e) => {
      const sort = e.target.value
      setSearchParams((prev) => {
        const p = new URLSearchParams(prev)
        if (!sort || sort === 'default') p.delete('sort')
        else p.set('sort', sort)
        p.delete('page')
        return p
      })
    },
    [setSearchParams],
  )

  const goToPage = useCallback(
    (value) => {
      if (value < 1 || value > totalPages || value === currentPage) return
      setCurrentPage(value)
      window.scrollTo({ top: 0, behavior: 'smooth' })
    },
    [totalPages, currentPage, setCurrentPage],
  )

  // ── Active filter tags ──
  const nameMap = useMemo(() => {
    const m = (arr, key) =>
      Object.fromEntries((arr || []).map((it) => [it.id, trName(it, key, language)]))
    return {
      brands: m(tables?.brands, 'brand_name'),
      categories: m(tables?.categoryTypes, 'category_type_name'),
      subTypes: m(tables?.subTypes, 'sub_type_name'),
      colors: m(tables?.colors, 'color_name'),
      materials: m(tables?.materials, 'material_name'),
      movements: m(tables?.movementTypes, 'movement_type_name'),
      shapes: m(tables?.shapes, 'shape_name'),
      displayTypes: m(tables?.displayTypes, 'display_type_name'),
    }
  }, [tables, language])

  const removeArr = useCallback(
    (key, id) => {
      setCurrentPage(1)
      setFilters((prev) => ({ ...prev, [key]: (prev[key] || []).filter((x) => x !== id) }))
    },
    [setCurrentPage, setFilters],
  )

  const activeTags = useMemo(() => {
    const tags = []
    const push = (label, onRemove) => label && tags.push({ label, onRemove })
    ;(filters.brands || []).forEach((id) =>
      push(nameMap.brands[id], () => removeArr('brands', id)),
    )
    ;(filters.categories || []).forEach((id) =>
      push(nameMap.categories[id], () => removeArr('categories', id)),
    )
    ;(filters.subTypes || []).forEach((id) =>
      push(nameMap.subTypes[id], () => removeArr('subTypes', id)),
    )
    ;(filters.genders || []).forEach((g) =>
      push(isRTL ? GENDER_AR[g] || g : g, () => removeArr('genders', g)),
    )
    ;(filters.dialColors || []).forEach((id) =>
      push(nameMap.colors[id], () => removeArr('dialColors', id)),
    )
    ;(filters.bandColors || []).forEach((id) =>
      push(nameMap.colors[id], () => removeArr('bandColors', id)),
    )
    ;(filters.materials || []).forEach((id) =>
      push(nameMap.materials[id], () => removeArr('materials', id)),
    )
    ;(filters.movements || []).forEach((id) =>
      push(nameMap.movements[id], () => removeArr('movements', id)),
    )
    ;(filters.shapes || []).forEach((id) =>
      push(nameMap.shapes[id], () => removeArr('shapes', id)),
    )
    ;(filters.displayTypes || []).forEach((id) =>
      push(nameMap.displayTypes[id], () => removeArr('displayTypes', id)),
    )
    if (filters.offers)
      push(isRTL ? 'عروض' : 'On Offer', () =>
        setFilters((prev) => ({ ...prev, offers: false })),
      )
    const [pmin, pmax] = filters.price || [0, PRICE_MAX]
    if (pmin > 0 || pmax < PRICE_MAX) {
      const label = `${isRTL ? 'السعر' : 'Price'}: ${pmin.toLocaleString()} - ${
        pmax >= PRICE_MAX ? '∞' : pmax.toLocaleString()
      }`
      push(label, () => setFilters((prev) => ({ ...prev, price: [0, PRICE_MAX] })))
    }
    return tags
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filters, nameMap, isRTL])

  const clearAll = useCallback(() => {
    setCurrentPage(1)
    setFilters({
      categories: [],
      brands: [],
      subTypes: [],
      genders: [],
      offers: false,
      price: [0, PRICE_MAX],
      dialColors: [],
      bandColors: [],
      materials: [],
      movements: [],
      shapes: [],
      displayTypes: [],
      grades: [],
    })
  }, [setCurrentPage, setFilters])

  // body scroll lock while the mobile drawer is open
  useEffect(() => {
    document.body.style.overflow = drawerOpen ? 'hidden' : ''
    return () => {
      document.body.style.overflow = ''
    }
  }, [drawerOpen])

  // ── Breadcrumb label ──
  const crumb = useMemo(() => {
    if ((filters.categories || []).length === 1) return nameMap.categories[filters.categories[0]]
    if ((filters.subTypes || []).length === 1) return nameMap.subTypes[filters.subTypes[0]]
    if ((filters.brands || []).length === 1) return nameMap.brands[filters.brands[0]]
    return isRTL ? 'كل المنتجات' : 'All Products'
  }, [filters, nameMap, isRTL])

  const resultCount = filteredProducts.length
  const showSkeleton = isFetching && resultCount === 0

  return (
    <div className="wz-listing" dir={isRTL ? 'rtl' : 'ltr'}>
      <div className="wz-listing-inner">
        {/* Breadcrumb */}
        <nav className="wz-bc" aria-label="Breadcrumb">
          <button className="wz-bc-link" onClick={() => router.push('/')}>
            {isRTL ? 'الرئيسية' : 'Home'}
          </button>
          <span className="wz-bc-sep">/</span>
          <span className="wz-bc-current">{crumb}</span>
        </nav>

        {/* Page heading (SEO H1) — reflects the active category/brand/subtype context */}
        <h1 className="wz-listing-h1">{crumb}</h1>

        {/* Smart suggestions */}
        <SmartSuggestions />

        {/* Body: sidebar + content */}
        <div className="wz-listing-body">
          <aside className="wz-listing-aside">
            <SideBar />
          </aside>

          <div className="wz-listing-main">
            {/* Toolbar */}
            <div className="wz-toolbar">
              <button
                className="wz-toolbar-filter"
                onClick={() => setDrawerOpen(true)}
                aria-label={isRTL ? 'الفلاتر' : 'Filters'}
              >
                <FiSliders />
                <span>{isRTL ? 'الفلاتر' : 'Filters'}</span>
                {activeTags.length > 0 && <span className="wz-toolbar-badge">{activeTags.length}</span>}
              </button>

              <span className="wz-toolbar-count">
                {isRTL
                  ? `عرض ${displayedProducts.length} من ${resultCount} منتج`
                  : `Showing ${displayedProducts.length} of ${resultCount} ${
                      resultCount === 1 ? 'product' : 'products'
                    }`}
              </span>

              <div className="wz-toolbar-sort">
                <span className="wz-toolbar-sort-label">{isRTL ? 'ترتيب' : 'Sort'}</span>
                <div className="wz-select">
                  <select value={parsed.sort} onChange={handleSort} aria-label={isRTL ? 'ترتيب' : 'Sort by'}>
                    <option value="default">{isRTL ? 'الافتراضي' : 'Default'}</option>
                    <option value="price_asc">{isRTL ? 'السعر: من الأقل' : 'Price: Low to High'}</option>
                    <option value="price_desc">{isRTL ? 'السعر: من الأعلى' : 'Price: High to Low'}</option>
                    <option value="newest">{isRTL ? 'الأحدث' : 'Newest'}</option>
                    <option value="rating">{isRTL ? 'الأعلى تقييماً' : 'Top Rated'}</option>
                  </select>
                  <FiChevronDown className="wz-select-icon" />
                </div>
              </div>
            </div>

            {/* Active filter tags */}
            {activeTags.length > 0 && (
              <div className="wz-tags">
                {activeTags.map((t, i) => (
                  <button key={`${t.label}-${i}`} className="wz-tag" onClick={t.onRemove}>
                    <span>{t.label}</span>
                    <FiX />
                  </button>
                ))}
                <button className="wz-tags-clear" onClick={clearAll}>
                  {isRTL ? 'مسح الكل' : 'Clear all'}
                </button>
              </div>
            )}

            {/* Grid / skeleton / fetch-error / empty */}
            {showSkeleton ? (
              <div className="wz-listing-grid">
                {Array.from({ length: 8 }).map((_, i) => (
                  <div className="wz-skel-card" key={i}>
                    <div className="wz-skel-img" />
                    <div className="wz-skel-line wz-skel-brand" />
                    <div className="wz-skel-line wz-skel-name" />
                    <div className="wz-skel-line wz-skel-price" />
                  </div>
                ))}
              </div>
            ) : isError && resultCount === 0 ? (
              /* Fetch failure ≠ "no products match filters" — show the real
                 reason + a retry, not the empty-filter state. */
              <div className="wz-empty" role="alert">
                <FiX className="wz-empty-icon" />
                <h3 className="wz-empty-title">
                  {isRTL ? 'تعذّر تحميل المنتجات' : "Couldn't load products"}
                </h3>
                <p className="wz-empty-sub">
                  {isRTL ? 'تحقّق من اتصالك وحاول مرة أخرى' : 'Check your connection and try again'}
                </p>
                <button className="wz-empty-btn" onClick={refetch}>
                  {isRTL ? 'إعادة المحاولة' : 'Retry'}
                </button>
              </div>
            ) : resultCount === 0 ? (
              <div className="wz-empty">
                <FiSearch className="wz-empty-icon" />
                <h3 className="wz-empty-title">
                  {isRTL ? 'لم يتم العثور على منتجات' : 'No products found'}
                </h3>
                <p className="wz-empty-sub">
                  {isRTL ? 'حاول تعديل الفلاتر' : 'Try adjusting your filters'}
                </p>
                <button className="wz-empty-btn" onClick={clearAll}>
                  {isRTL ? 'مسح كل الفلاتر' : 'Clear all filters'}
                </button>
              </div>
            ) : (
              <>
                <div
                  className="wz-listing-grid wz-fade"
                  key={`${currentPage}-${parsed.sort}-${resultCount}`}
                >
                  {displayedProducts.map((product) => (
                    <ProductCard key={product.id} product={product} />
                  ))}
                </div>

                {/* Pagination */}
                {totalPages > 1 && (
                  <div className="wz-pager">
                    <button
                      className="wz-pager-btn wz-pager-edge"
                      disabled={currentPage <= 1}
                      onClick={() => goToPage(currentPage - 1)}
                      aria-label={isRTL ? 'السابق' : 'Previous'}
                    >
                      {isRTL ? <FiChevronRight /> : <FiChevronLeft />}
                      <span>{isRTL ? 'السابق' : 'Prev'}</span>
                    </button>

                    {pageList(currentPage, totalPages).map((p, i) =>
                      p === '…' ? (
                        <span className="wz-pager-dots" key={`e${i}`}>
                          …
                        </span>
                      ) : (
                        <button
                          key={p}
                          className={`wz-pager-num${p === currentPage ? ' is-active' : ''}`}
                          onClick={() => goToPage(p)}
                        >
                          {p}
                        </button>
                      ),
                    )}

                    <button
                      className="wz-pager-btn wz-pager-edge"
                      disabled={currentPage >= totalPages}
                      onClick={() => goToPage(currentPage + 1)}
                      aria-label={isRTL ? 'التالي' : 'Next'}
                    >
                      <span>{isRTL ? 'التالي' : 'Next'}</span>
                      {isRTL ? <FiChevronLeft /> : <FiChevronRight />}
                    </button>
                  </div>
                )}
              </>
            )}
          </div>
        </div>
      </div>

      {/* Mobile filter drawer */}
      <div
        className={`wz-fdrawer-overlay${drawerOpen ? ' is-open' : ''}`}
        onClick={() => setDrawerOpen(false)}
      />
      <aside
        className={`wz-fdrawer${drawerOpen ? ' is-open' : ''}`}
        dir={isRTL ? 'rtl' : 'ltr'}
        inert={!drawerOpen ? true : undefined}
      >
        <div className="wz-fdrawer-head">
          <span className="wz-fdrawer-title">{isRTL ? 'الفلاتر' : 'Filters'}</span>
          <button className="wz-fdrawer-clear" onClick={clearAll}>
            {isRTL ? 'مسح الكل' : 'Clear all'}
          </button>
          <button
            className="wz-fdrawer-close"
            onClick={() => setDrawerOpen(false)}
            aria-label={isRTL ? 'إغلاق' : 'Close'}
          >
            <FiX />
          </button>
        </div>
        <div className="wz-fdrawer-body">
          <SideBar />
        </div>
        <div className="wz-fdrawer-foot">
          <button className="wz-fdrawer-apply" onClick={() => setDrawerOpen(false)}>
            {isRTL ? `عرض ${resultCount} منتج` : `Show ${resultCount} results`}
          </button>
        </div>
      </aside>

      <BackToTop />
    </div>
  )
}
