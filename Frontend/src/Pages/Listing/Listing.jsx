import { useState, useEffect, useMemo, useRef, useCallback } from 'react'
import { useParams, useSearchParams, useLocation, useNavigate } from 'react-router-dom'
import { FiSliders, FiX, FiChevronDown, FiSearch, FiChevronLeft, FiChevronRight } from 'react-icons/fi'
import { Helmet } from 'react-helmet-async'
import { useCatalog } from '../../Hooks/queries/useCatalog'
import { useUIStore } from '../../Store/uiStore'
import { buildListingParams, parseListingParams, paramsKey } from '../../utils/listingParams'
import { passesFilters } from '../../utils/filterPredicate'
import ProductCard from '../../Components/Product/ProductCard'
import SideBar from '../../Components/SideBar/SideBar'
import SmartSuggestions from '../../Components/Listing/SmartSuggestions'
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

function Listing() {
  const { tables, products, isFetching } = useCatalog()
  const { language, currentPage, setCurrentPage, filters, setFilters } = useUIStore()
  const navigate = useNavigate()
  const isRTL = language === 'ar'

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

  const [filteredProducts, setFilteredProducts] = useState([])
  const [drawerOpen, setDrawerOpen] = useState(false)

  const { suptype, brand, category, grade } = useParams()
  const [searchParams, setSearchParams] = useSearchParams()
  const location = useLocation()

  // URL is the shareable source of truth on /listing; parse it (resolving slugs
  // → ids via `tables`) on each searchParams/tables change.
  const parsed = useMemo(() => parseListingParams(searchParams, tables), [searchParams, tables])
  const onListing = location.pathname === '/listing'
  const tablesReady = !!(tables && Object.keys(tables).length)

  // ── Slug route → store: seed filters from /listing/:category etc. routes ──
  useEffect(() => {
    if (
      filters &&
      filters.categories.length === 0 &&
      filters.brands.length === 0 &&
      filters.subTypes.length === 0 &&
      (filters.grades?.length || 0) === 0
    ) {
      const brandObj = tables?.brands?.find((bran) => bran.brand_name === brand)
      const subTypeObj = tables?.subTypes?.find((subty) => subty.sub_type_name === suptype)
      const categoryObj = tables?.categoryTypes?.find((cat) => cat.category_type_name === category)
      // /grade/:grade routes here too (was the legacy ListingGrades page). The param
      // is the English grade_name, matched against the plain column or EN translation.
      const gradeObj = tables?.grades?.find(
        (g) =>
          g.grade_name === grade ||
          g.translations?.find((t) => t.locale === 'en')?.grade_name === grade,
      )

      let updatedFilters = { ...filters, price: [0, 99999999] }
      if (brandObj) updatedFilters.brands = [brandObj.id]
      if (subTypeObj) updatedFilters.subTypes = [subTypeObj.id]
      if (categoryObj) updatedFilters.categories = [categoryObj.id]
      if (gradeObj) updatedFilters.grades = [gradeObj.id]
      if (suptype && brand && subTypeObj && brandObj) {
        updatedFilters = {
          ...filters,
          brands: [brandObj.id],
          subTypes: [subTypeObj.id],
          price: [0, 99999999],
        }
      }
      if (brandObj || subTypeObj || categoryObj || gradeObj) setFilters(updatedFilters)
    }
  }, [filters, category, suptype, brand, grade, tables, setFilters])

  // Guards the store→URL effect from echoing a URL→store change.
  const skipStoreToUrl = useRef(false)

  // ── URL → store ──
  useEffect(() => {
    if (!onListing || !tablesReady) return
    skipStoreToUrl.current = true
    setFilters(parsed.filters)
    setCurrentPage(parsed.page)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchParams, onListing, tablesReady])

  // ── store → URL ──
  useEffect(() => {
    if (!onListing || !tablesReady) return
    if (skipStoreToUrl.current) {
      skipStoreToUrl.current = false
      return
    }
    const target = buildListingParams(
      filters,
      { q: parsed.q, sort: parsed.sort, page: currentPage },
      tables,
    )
    if (paramsKey(target) !== paramsKey(searchParams)) {
      setSearchParams(target, { replace: false })
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filters, currentPage, onListing, tablesReady])

  // ── Filter + search + sort pipeline ──
  useEffect(() => {
    if (!products?.length && !watches?.length && !fashion?.length) return

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

    setFilteredProducts(filtered)
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

  // ── Per-page SEO (title / description / self-canonical) ──
  // Title format depends on which single facet is active (brand / category /
  // sub-type); falls back to the global default via App.jsx otherwise.
  const seo = useMemo(() => {
    const brandId = (filters.brands || []).length === 1 ? filters.brands[0] : null
    const catId = (filters.categories || []).length === 1 ? filters.categories[0] : null
    const subId = (filters.subTypes || []).length === 1 ? filters.subTypes[0] : null
    const activeCount =
      (filters.brands?.length || 0) +
      (filters.categories?.length || 0) +
      (filters.subTypes?.length || 0)

    let title
    if (activeCount === 1 && brandId && nameMap.brands[brandId]) {
      title = `${nameMap.brands[brandId]} Watches in Egypt | Watchizer`
    } else if (activeCount === 1 && catId && nameMap.categories[catId]) {
      title = `${nameMap.categories[catId]} | Watchizer`
    } else if (activeCount === 1 && subId && nameMap.subTypes[subId]) {
      title = `${nameMap.subTypes[subId]} Watches | Watchizer`
    } else {
      title = 'Shop All Luxury Watches & Accessories | Watchizer'
    }

    const desc = `Browse ${resultCount} ${crumb} at Watchizer — luxury watches and accessories with premium designs and unbeatable prices in Egypt.`
    // Self-canonical to the clean path (query filters collapse to one URL).
    const canonical = `https://watchizereg.com${location.pathname}`
    return { title, desc, canonical }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filters, nameMap, crumb, resultCount, location.pathname])

  return (
    <div className="wz-listing" dir={isRTL ? 'rtl' : 'ltr'}>
      <Helmet>
        <title>{seo.title}</title>
        <meta name="description" content={seo.desc} />
        <link rel="canonical" href={seo.canonical} />
        {/* Social share — category/listing OG/Twitter (og:image falls back to the
            global brand logo; there's no reliable per-category image here). */}
        <meta property="og:type" content="website" />
        <meta property="og:title" content={seo.title} />
        <meta property="og:description" content={seo.desc} />
        <meta property="og:url" content={seo.canonical} />
        <meta name="twitter:card" content="summary_large_image" />
        <meta name="twitter:title" content={seo.title} />
        <meta name="twitter:description" content={seo.desc} />
        {/* BreadcrumbList: Home → {active category/brand} */}
        <script type="application/ld+json">
          {JSON.stringify({
            '@context': 'https://schema.org/',
            '@type': 'BreadcrumbList',
            itemListElement: [
              {
                '@type': 'ListItem',
                position: 1,
                name: isRTL ? 'الرئيسية' : 'Home',
                item: 'https://watchizereg.com/',
              },
              {
                '@type': 'ListItem',
                position: 2,
                name: crumb,
                item: seo.canonical,
              },
            ],
          })}
        </script>
      </Helmet>
      <div className="wz-listing-inner">
        {/* Breadcrumb */}
        <nav className="wz-bc" aria-label="Breadcrumb">
          <button className="wz-bc-link" onClick={() => navigate('/')}>
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

            {/* Grid / skeleton / empty */}
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
        aria-hidden={!drawerOpen}
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
    </div>
  )
}

export default Listing
