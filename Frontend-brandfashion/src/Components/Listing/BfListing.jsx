'use client'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { usePathname, useSearchParams } from 'next/navigation'
import { useQuery, keepPreviousData } from '@tanstack/react-query'
import { listingQueryFn } from '@/src/lib/listingRequest'
import { readListingState, toV2Query, toSearch, hasFilters, clearFilters, toggleValue, emptyFix, listingTitle, LIST_KEYS, PAGE_SIZE } from '@/src/lib/bfListing'
import { txt, money } from '@/src/lib/cardData'
import { aisleTint } from '@/src/lib/aisleTint'
import { localizeHref } from '@/src/utils/localePath'
import { useUIStore } from '@/src/Store/uiStore'
import { useToastStore } from '@/src/Store/toastStore'
import useCart from '@/src/Hooks/useCart'
import { refusedAddMessage } from '@/src/Store/cartStore'
import BfProductCard from '@/src/Components/Product/BfProductCard'
import BfRail, { dominantFamily } from './BfRail'
import './BfListing.css'

/*
 * B4 — the Brand Fashion listing (spec v2.9 §10.2; built 2026-10-17 on the v2 listing: ONE data path for the
 * cards, the counts, the bounds and the sort). The URL is the source of truth (src/lib/bfListing.js); the
 * server page prefetched the same key, so the first render hits that data.
 *
 * What is here, section by section of §10.2:
 *  • the ground takes the family tint of the category — read from the page's own cards (≥ 80% one family,
 *    §9's mixed rule); a search or a mixed page sits on white (§10.7).
 *  • title row h1 24/800 + count 12; sort a white pill select 36.
 *  • applied-filter chips: ink pills with ×, plus "Clear all", whenever ≥ 1 filter is on.
 *  • sidebar 240 sticky at 80 (desktop) / a 92% bottom sheet from a "Filters · N" chip (phone, tablet):
 *    Category (tree) · Brand (search + checkboxes + counts + "+N more") · Price (two inputs + the served
 *    range) · Gender · Colour (24px swatches) · Availability. Every row comes from the facet block of the
 *    CURRENT response — a value with no match is not served, so it is not drawn; while the next response is
 *    loading the previous rows stay but show no count and take no clicks, so a stale count is never shown.
 *  • grid 2 / 3 / 4; page size 24; numbered pagination, 36px pills; no "load more".
 *  • loading: skeleton in `tile`, no shimmer, past 300 ms only; fixed card heights.
 *  • empty result: says why, names the cheapest match when the price filter is the cause, offers the
 *    ONE-CHIP fix before "clear all", then a "Popular in …" rail.
 * Not drawn, by the spec's own list: Material and Grade sections (v2 serves them; §10.2 does not list them);
 * a Sale section (v2 serves no sale filter or count — recorded); the 2×2 promo square (no grid banner exists).
 */

const STR = {
  en: {
    home: 'Home', filters: 'Filters', sort: 'Sort', showing: (n, t) => `${n} of ${t} products`, products: (t) => `${t} ${t === 1 ? 'product' : 'products'}`,
    clearAll: 'Clear all', clear: 'Clear', category: 'Category', brand: 'Brand', price: 'Price', gender: 'Gender', colour: 'Colour', availability: 'Availability',
    inStock: 'In stock', searchBrands: 'Search brands', more: (n) => `+${n} more`, less: 'Show fewer', min: 'Min', max: 'Max', apply: 'Apply', priceErr: 'Min must be below max',
    range: (a, b) => `Range ${a} – ${b}`, show: (n) => `Show ${n} results`, close: 'Close',
    sorts: { default: 'Default', price_asc: 'Price: low to high', price_desc: 'Price: high to low', newest: 'Newest', rating: 'Top rated' },
    emptyTitle: 'No products match', emptySearch: (q) => `Nothing matches “${q}”.`, emptyWhy: 'Nothing matches all of these filters.',
    startAt: (what, p) => `${what} start at ${p}.`, removeChip: (label, n) => `Remove “${label}” · ${n}`, showFrom: (p) => `Show from ${p}`,
    popularIn: (what) => `Popular in ${what}`, popular: 'Popular right now', seeAll: 'See all', prev: 'Previous', next: 'Next', page: 'Page', pagination: 'Pagination',
    removeFilter: (l) => `Remove ${l}`, allProducts: 'All products', retry: 'Retry', loadFail: 'Couldn’t load products', checkConn: 'Check your connection and try again.',
    added: 'Added to cart',
    allOut: 'Everything in this section is out of stock right now. “Notify me” on any product tells you when it is back.',
  },
  ar: {
    home: 'الرئيسية', filters: 'الفلاتر', sort: 'ترتيب', showing: (n, t) => `${n} من ${t} منتج`, products: (t) => `${t} منتج`,
    clearAll: 'مسح الكل', clear: 'مسح', category: 'الفئة', brand: 'العلامة التجارية', price: 'السعر', gender: 'النوع', colour: 'اللون', availability: 'التوفر',
    inStock: 'متوفر', searchBrands: 'ابحث عن علامة', more: (n) => `+${n} المزيد`, less: 'عرض أقل', min: 'الأدنى', max: 'الأقصى', apply: 'تطبيق', priceErr: 'الأدنى يجب أن يكون أقل من الأقصى',
    range: (a, b) => `النطاق ${a} – ${b}`, show: (n) => `عرض ${n} نتيجة`, close: 'إغلاق',
    sorts: { default: 'الافتراضي', price_asc: 'السعر: من الأقل', price_desc: 'السعر: من الأعلى', newest: 'الأحدث', rating: 'الأعلى تقييماً' },
    emptyTitle: 'لا توجد منتجات مطابقة', emptySearch: (q) => `لا شيء يطابق «${q}».`, emptyWhy: 'لا شيء يطابق كل هذه الفلاتر معاً.',
    startAt: (what, p) => `${what} تبدأ من ${p}.`, removeChip: (label, n) => `إزالة «${label}» · ${n}`, showFrom: (p) => `عرض من ${p}`,
    popularIn: (what) => `الأكثر رواجاً في ${what}`, popular: 'الأكثر رواجاً الآن', seeAll: 'عرض الكل', prev: 'السابق', next: 'التالي', page: 'صفحة', pagination: 'الصفحات',
    removeFilter: (l) => `إزالة ${l}`, allProducts: 'كل المنتجات', retry: 'إعادة المحاولة', loadFail: 'تعذّر تحميل المنتجات', checkConn: 'تحقّق من اتصالك وحاول مرة أخرى.',
    added: 'أُضيف إلى السلة',
    allOut: 'كل منتجات هذا القسم نفدت حاليًا. اضغط «نبّهني» على أي منتج لنخبرك عند توفره.',
  },
}
const GENDER_AR = { Men: 'رجالي', Women: 'نسائي', Unisex: 'للجنسين', Kids: 'أطفال' }

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

export default function BfListing({ route = {} }) {
  const { language } = useUIStore()
  const lang = language === 'ar' ? 'ar' : 'en'
  const t = STR[lang]
  const isRTL = lang === 'ar'
  const pathname = usePathname()
  const sp = useSearchParams()
  const state = useMemo(() => readListingState(sp, { category: route.category || null, brand: route.brand || null }), [sp, route.category, route.brand])
  const qs = toV2Query(state, lang)

  const { data, isError, isPlaceholderData, isFetching, refetch } = useQuery({
    queryKey: ['listing', qs],
    queryFn: listingQueryFn(qs),
    placeholderData: keepPreviousData,
    staleTime: 60 * 1000,
  })
  const page = data?.v2
  const cards = page?.data || []
  const facets = page?.facets || null
  const total = page?.meta?.total ?? 0
  const totalPages = Math.max(1, page?.meta?.last_page ?? Math.ceil(total / PAGE_SIZE))
  const busy = isPlaceholderData && isFetching
  const category = page?.meta?.category || null
  // F9 (2026-10-17, Michael): a page whose EVERY product is sold out says so in one line — a fact about inventory,
  // not an apology for data. This departs from §19-6's "no caption" by scale: that rule governs one facet row; this is
  // the whole result. Read from the served stock split, so it never contradicts the grid.
  const stockSplit = facets?.in_stock
  const allSoldOut = Boolean(data) && total > 0 && stockSplit != null && Number(stockSplit.in) === 0 && !state.in_stock

  // the loading skeleton: shown only past 300 ms (§10.2)
  const [slow, setSlow] = useState(false)
  useEffect(() => {
    if (data) return undefined
    const id = setTimeout(() => setSlow(true), 300)
    return () => clearTimeout(id)
  }, [data])

  // ── navigation: the URL is the state; pushState keeps it client-side (the App Router syncs useSearchParams)
  const go = useCallback(
    (next, { replace = false } = {}) => {
      const url = `${pathname}${toSearch(next)}`
      if (replace) window.history.replaceState(null, '', url)
      else window.history.pushState(null, '', url)
      if (next.page !== state.page) window.scrollTo({ top: 0, behavior: 'smooth' })
    },
    [pathname, state.page],
  )
  const toggle = (key, value) => go(toggleValue(state, key, value))
  const setPrice = (min, max) => go({ ...state, price_min: min, price_max: max, page: 1 })
  const setSort = (sort) => go({ ...state, sort, page: 1 })
  const setPage = (p) => go({ ...state, page: p })
  const clearAll = () => go(clearFilters(state))

  // ── names for chips and the title, from the facet block (brands) and the response (category)
  const brandName = (slug) => txt(facets?.brand?.find((b) => b.slug === slug)?.name, lang) || slug
  const colourName = (id) => txt(facets?.color?.find((c) => String(c.id) === String(id))?.name, lang) || `#${id}`
  const genderLabel = (g) => (isRTL ? GENDER_AR[g] || g : g)
  const fixedBrandName = route.brand ? brandName(route.brand) : null
  const title = listingTitle({ category, brandName: fixedBrandName, q: state.q, lang })

  // ── the ground (§10.2 "takes the family tint of the category"): a CATEGORY page is tinted when ≥ 80% of its
  //    cards share one family (§9's mixed rule — the fashion category, bag 66% / fashion / wallet, sits on white);
  //    /listing, a brand page and a search (§10.7) are mixed by nature and stay white.
  const family = route.category && !state.q ? dominantFamily(cards) : null
  const tinted = family != null
  const ground = tinted ? 'tint' : 'white'

  // ── chips
  const chips = []
  if (!state.fixed?.brand) state.brand.forEach((s) => chips.push({ label: brandName(s), remove: () => toggle('brand', s) }))
  state.gender.forEach((g) => chips.push({ label: genderLabel(g), remove: () => toggle('gender', g) }))
  state.color.forEach((c) => chips.push({ label: colourName(c), remove: () => toggle('color', c) }))
  state.material.forEach((m) => chips.push({ label: txt(facets?.material?.find((x) => String(x.id) === String(m))?.name, lang) || m, remove: () => toggle('material', m) }))
  state.grade.forEach((g) => chips.push({ label: txt(facets?.grade?.find((x) => String(x.id) === String(g))?.name, lang) || g, remove: () => toggle('grade', g) }))
  if (state.price_min != null || state.price_max != null) {
    const a = state.price_min != null ? money(state.price_min, lang) : '0'
    const b = state.price_max != null ? money(state.price_max, lang) : '∞'
    chips.push({ label: `${t.price}: ${a} – ${b}`, remove: () => setPrice(null, null) })
  }
  if (state.in_stock) chips.push({ label: t.inStock, remove: () => go({ ...state, in_stock: false, page: 1 }) })

  // ── add to cart from a card: today's cart store (compat); a refused add says why (as the old card did)
  const { addItem } = useCart()
  const showToast = useToastStore((s) => s.showToast)
  const onAdd = async (product) => {
    const price = product.price || {}
    const r = await addItem({
      product_id: product.id,
      quantity: 1,
      piece_price: price.has_sale ? price.sale_amount : price.amount,
      type_stock: Number(product.stock?.express) > 0 ? 'Express' : 'Market',
      name: txt(product.title, 'en'),
    })
    if (r && r.ok === false) {
      showToast?.(refusedAddMessage(r, isRTL), 'error')
      return false
    }
    return true
  }

  const [sheet, setSheet] = useState(false)
  useEffect(() => {
    document.body.style.overflow = sheet ? 'hidden' : ''
    return () => {
      document.body.style.overflow = ''
    }
  }, [sheet])

  const activeCount = chips.length
  const showSkeleton = !data && !isError && slow
  const empty = data && !busy && total === 0
  const tintStyle = tinted ? { '--bfl-tint': `var(--wz-${aisleTint(family)})` } : undefined

  const sidebar = (
    <Sidebar
      t={t}
      lang={lang}
      state={state}
      facets={facets}
      busy={busy}
      category={category}
      onToggle={toggle}
      onPrice={setPrice}
      onStock={(v) => go({ ...state, in_stock: v, page: 1 })}
      onCategory={(path) => {
        window.location.href = localizeHref(`/category/${path}${toSearch({ ...state, page: 1, category: null })}`, lang)
      }}
      onClear={clearAll}
      activeCount={activeCount}
    />
  )

  return (
    <div className={`bf-listing ${tinted ? 'is-tinted' : ''}`} style={tintStyle} dir={isRTL ? 'rtl' : 'ltr'} lang={lang}>
      <div className="bf-listing__inner">
        <nav className="bf-listing__crumb" aria-label="Breadcrumb">
          <a href={localizeHref('/', lang)}>{t.home}</a>
          <span aria-hidden="true">/</span>
          {category && category.depth > 1 && route.parent ? (
            <>
              <a href={localizeHref(`/category/${route.parent.path}`, lang)}>{txt(route.parent.name, lang)}</a>
              <span aria-hidden="true">/</span>
            </>
          ) : null}
          <span aria-current="page">{title}</span>
        </nav>

        <div className="bf-listing__titlerow">
          <h1 className="bf-listing__title">
            {title}
            {data && !busy ? <span className="bf-listing__count">{t.products(total)}</span> : null}
          </h1>
          <div className="bf-listing__sort">
            <label htmlFor="bf-sort">{t.sort}</label>
            <select id="bf-sort" className="bf-listing__select" value={state.sort} onChange={(e) => setSort(e.target.value)}>
              {Object.entries(t.sorts).map(([k, v]) => (
                <option key={k} value={k}>{v}</option>
              ))}
            </select>
          </div>
        </div>

        {allSoldOut && !busy ? <p className="bf-listing__note" role="status">{t.allOut}</p> : null}

        {activeCount > 0 ? (
          <div className="bf-chips" aria-label={t.filters}>
            {chips.map((c, i) => (
              <button key={`${c.label}-${i}`} type="button" className="bf-chip" onClick={c.remove} aria-label={t.removeFilter(c.label)}>
                {c.label} <span className="bf-chip__x" aria-hidden="true">×</span>
              </button>
            ))}
            <button type="button" className="bf-chips__clear" onClick={clearAll}>{t.clearAll}</button>
          </div>
        ) : null}

        <div className="bf-listing__toolbar">
          <button type="button" className="bf-listing__toolchip" onClick={() => setSheet(true)} aria-haspopup="dialog" aria-expanded={sheet}>
            {t.filters}{activeCount ? ` · ${activeCount}` : ''}
          </button>
        </div>

        <div className="bf-listing__body">
          <aside className="bf-listing__aside">{sidebar}</aside>

          <div className="bf-listing__main">
            {showSkeleton ? (
              <div className="bf-grid" aria-busy="true">
                {Array.from({ length: 8 }).map((_, i) => (
                  <div className="bf-skel" key={i}>
                    <div className="bf-skel__tile" />
                    <div className="bf-skel__line bf-skel__line--short" />
                    <div className="bf-skel__line" />
                    <div className="bf-skel__line bf-skel__line--short" />
                  </div>
                ))}
              </div>
            ) : isError && !data ? (
              <div className="bf-empty" role="alert">
                <h2 className="bf-empty__title">{t.loadFail}</h2>
                <p className="bf-empty__why">{t.checkConn}</p>
                <div className="bf-empty__actions">
                  <button type="button" className="bf-btn" onClick={() => refetch()}>{t.retry}</button>
                </div>
              </div>
            ) : empty ? (
              <EmptyState t={t} lang={lang} state={state} facets={facets} title={title} category={category} brandName={brandName} genderLabel={genderLabel} colourName={colourName} onGo={go} onAdd={onAdd} />
            ) : (
              <>
                <div className={`bf-grid ${busy ? 'is-busy' : ''}`} aria-busy={busy || undefined}>
                  {cards.map((c) => (
                    <BfProductCard key={c.id} product={c} lang={lang} ground={ground} href={localizeHref(`/product/${c.slug}`, lang)} onAdd={onAdd} />
                  ))}
                </div>
                {totalPages > 1 ? <Pager t={t} lang={lang} current={state.page} total={totalPages} pathname={pathname} state={state} onPage={setPage} /> : null}
              </>
            )}
          </div>
        </div>
      </div>

      {sheet ? (
        <>
          <div className="bf-sheet__overlay" onClick={() => setSheet(false)} />
          <div className="bf-sheet" role="dialog" aria-modal="true" aria-label={t.filters} dir={isRTL ? 'rtl' : 'ltr'}>
            <div className="bf-sheet__head">
              <b>{t.filters}{activeCount ? ` · ${activeCount}` : ''}</b>
              <button type="button" className="bf-sheet__close" onClick={() => setSheet(false)} aria-label={t.close}>×</button>
            </div>
            <div className="bf-sheet__body">{sidebar}</div>
            <div className="bf-sheet__foot">
              <button type="button" className="bf-btn" onClick={() => setSheet(false)}>{t.show(total)}</button>
            </div>
          </div>
        </>
      ) : null}
    </div>
  )
}

/* ── the sidebar: every row from the CURRENT facet block; a value with no match is not served, so never drawn ── */
function Sidebar({ t, lang, state, facets, busy, category, onToggle, onPrice, onStock, onCategory, onClear, activeCount }) {
  const isRTL = lang === 'ar'
  const [brandQuery, setBrandQuery] = useState('')
  const [brandsOpen, setBrandsOpen] = useState(false)
  const count = (n) => (busy ? '—' : n)

  // category tree from the facet rows (tree order by path): depth-1 parents with their depth-2 children
  const catRows = facets?.category || []
  const roots = catRows.filter((c) => c.depth === 1)
  const childrenOf = (root) => catRows.filter((c) => c.depth === 2 && c.path.startsWith(root.path + '/'))
  const currentPath = category?.path || state.category || null

  const brands = facets?.brand || []
  const brandRows = brands.filter((b) => !brandQuery || txt(b.name, lang).toLowerCase().includes(brandQuery.toLowerCase()) || b.slug.includes(brandQuery.toLowerCase()))
  const shownBrands = brandsOpen ? brandRows : brandRows.slice(0, 8)

  const genders = facets?.gender || []
  const colours = facets?.color || []
  const stock = facets?.in_stock
  const bounds = facets?.price

  // the inputs follow the URL: the form is keyed by the applied bounds, so a new URL remounts it with the new values
  const [pmin, setPmin] = useState(state.price_min ?? '')
  const [pmax, setPmax] = useState(state.price_max ?? '')
  const priceKey = `${state.price_min ?? ''}-${state.price_max ?? ''}`
  const priceErr = pmin !== '' && pmax !== '' && Number(pmin) > Number(pmax)

  return (
    <div className="bf-side" aria-busy={busy || undefined}>
      <div className="bf-side__head">
        <b>{t.filters}{activeCount ? ` (${activeCount})` : ''}</b>
        {activeCount ? <button type="button" className="bf-side__clear" onClick={onClear}>{t.clearAll}</button> : null}
      </div>

      {roots.length ? (
        <section className="bf-sec" aria-label={t.category}>
          <h3 className="bf-sec__title">{t.category}</h3>
          {roots.map((r) => (
            <div key={r.id}>
              <button type="button" className={`bf-row ${currentPath === r.path ? 'is-current' : ''}`} onClick={() => !busy && onCategory(r.path)} disabled={busy} style={{ width: '100%', background: 'none', border: 0, font: 'inherit', textAlign: 'start', padding: 0, color: 'inherit' }}>
                <span className="bf-row__label">{txt(r.name, lang)}</span>
                <span className="bf-row__count">{count(r.count)}</span>
              </button>
              {(currentPath === r.path || (currentPath && currentPath.startsWith(r.path + '/'))) &&
                childrenOf(r).map((c) => (
                  <button key={c.id} type="button" className={`bf-row bf-row--child ${currentPath === c.path ? 'is-current' : ''}`} onClick={() => !busy && onCategory(c.path)} disabled={busy} style={{ width: '100%', background: 'none', border: 0, font: 'inherit', textAlign: 'start', padding: 0, paddingInlineStart: 16, color: 'inherit' }}>
                    <span className="bf-row__label">{txt(c.name, lang)}</span>
                    <span className="bf-row__count">{count(c.count)}</span>
                  </button>
                ))}
            </div>
          ))}
        </section>
      ) : null}

      {!state.fixed?.brand && brands.length ? (
        <section className="bf-sec" aria-label={t.brand}>
          <h3 className="bf-sec__title">{t.brand}</h3>
          {brands.length > 8 ? <input className="bf-sec__search" type="search" placeholder={t.searchBrands} value={brandQuery} onChange={(e) => setBrandQuery(e.target.value)} aria-label={t.searchBrands} /> : null}
          {shownBrands.map((b) => (
            <label key={b.id} className="bf-row">
              <input type="checkbox" checked={state.brand.includes(b.slug)} onChange={() => onToggle('brand', b.slug)} disabled={busy} />
              <span className="bf-row__label">{txt(b.name, lang)}</span>
              <span className="bf-row__count">{count(b.count)}</span>
            </label>
          ))}
          {brandRows.length > 8 ? (
            <button type="button" className="bf-sec__more" onClick={() => setBrandsOpen((o) => !o)}>{brandsOpen ? t.less : t.more(brandRows.length - 8)}</button>
          ) : null}
        </section>
      ) : null}

      <section className="bf-sec" aria-label={t.price}>
        <h3 className="bf-sec__title">{t.price}</h3>
        <form
          key={priceKey}
          className="bf-price"
          onSubmit={(e) => {
            e.preventDefault()
            if (priceErr) return
            onPrice(pmin === '' ? null : Number(pmin), pmax === '' ? null : Number(pmax))
          }}
        >
          <input type="number" inputMode="numeric" min="0" placeholder={bounds?.min != null ? String(Math.floor(bounds.min)) : t.min} value={pmin} onChange={(e) => setPmin(e.target.value)} aria-label={t.min} className={priceErr ? 'is-error' : ''} disabled={busy} />
          <input type="number" inputMode="numeric" min="0" placeholder={bounds?.max != null ? String(Math.ceil(bounds.max)) : t.max} value={pmax} onChange={(e) => setPmax(e.target.value)} aria-label={t.max} className={priceErr ? 'is-error' : ''} disabled={busy} />
          {bounds?.min != null && bounds?.max != null ? <div className="bf-price__hint">{t.range(money(bounds.min, lang), money(bounds.max, lang))}</div> : null}
          {priceErr ? <div className="bf-price__err">{t.priceErr}</div> : null}
          <button type="submit" className="bf-price__apply" disabled={busy || priceErr}>{t.apply}</button>
        </form>
      </section>

      {genders.length ? (
        <section className="bf-sec" aria-label={t.gender}>
          <h3 className="bf-sec__title">{t.gender}</h3>
          {genders.map((g) => {
            const key = g.name?.en || String(g.id)
            return (
              <label key={g.id} className="bf-row">
                <input type="checkbox" checked={state.gender.includes(key)} onChange={() => onToggle('gender', key)} disabled={busy} />
                <span className="bf-row__label">{txt(g.name, lang)}</span>
                <span className="bf-row__count">{count(g.count)}</span>
              </label>
            )
          })}
        </section>
      ) : null}

      {colours.length ? (
        <section className="bf-sec" aria-label={t.colour}>
          <h3 className="bf-sec__title">{t.colour}</h3>
          <div className="bf-swatches" role="group" aria-label={t.colour}>
            {colours.map((c) => {
              const on = state.color.includes(String(c.id))
              return (
                <button key={c.id} type="button" role="checkbox" aria-checked={on} className="bf-swatch" style={{ background: c.hex }} title={`${txt(c.name, lang)} (${c.count})`} aria-label={`${txt(c.name, lang)} (${c.count})`} onClick={() => onToggle('color', c.id)} disabled={busy}>
                  <span className="bf-swatch__hit" aria-hidden="true" />
                </button>
              )
            })}
          </div>
          {state.color.length ? <div className="bf-swatch-name">{state.color.map((id) => txt(colours.find((c) => String(c.id) === String(id))?.name, lang) || id).join(isRTL ? '، ' : ', ')}</div> : null}
        </section>
      ) : null}

      {stock && (Number(stock.in) > 0 || state.in_stock) ? (
        <section className="bf-sec" aria-label={t.availability}>
          <h3 className="bf-sec__title">{t.availability}</h3>
          <label className="bf-row">
            <input type="checkbox" checked={state.in_stock} onChange={(e) => onStock(e.target.checked)} disabled={busy} />
            <span className="bf-dot bf-dot--ok" aria-hidden="true" />
            <span className="bf-row__label">{t.inStock}</span>
            <span className="bf-row__count">{count(stock.in)}</span>
          </label>
        </section>
      ) : null}
    </div>
  )
}

/* ── numbered pagination: real links (crawlable), 36px pills, current one ink; RTL by direction ── */
function Pager({ t, lang, current, total, pathname, state, onPage }) {
  const href = (p) => localizeHref(`${pathname}${toSearch({ ...state, page: p })}`, lang)
  const click = (p) => (e) => {
    e.preventDefault()
    onPage(p)
  }
  return (
    <nav className="bf-pager" aria-label={t.pagination}>
      <a className="bf-pager__btn" href={href(Math.max(1, current - 1))} onClick={click(Math.max(1, current - 1))} aria-disabled={current <= 1} aria-label={t.prev}>
        {lang === 'ar' ? '›' : '‹'}
      </a>
      {pageList(current, total).map((p, i) =>
        p === '…' ? (
          <span className="bf-pager__dots" key={`d${i}`}>…</span>
        ) : (
          <a key={p} className="bf-pager__btn" href={href(p)} onClick={click(p)} aria-current={p === current ? 'page' : undefined} aria-label={`${t.page} ${p}`}>
            {p}
          </a>
        ),
      )}
      <a className="bf-pager__btn" href={href(Math.min(total, current + 1))} onClick={click(Math.min(total, current + 1))} aria-disabled={current >= total} aria-label={t.next}>
        {lang === 'ar' ? '‹' : '›'}
      </a>
    </nav>
  )
}

/* ── the empty result: why, the cheapest match, the one-chip fix, clear all, then a rail (§10.2) ── */
function EmptyState({ t, lang, state, facets, title, category, brandName, genderLabel, colourName, onGo, onAdd }) {
  const fix = emptyFix(state, facets)
  const what = title
  let why = state.q ? t.emptySearch(state.q) : t.emptyWhy
  let action = null
  if (fix?.key === 'price' && fix.startsAt != null) {
    why = t.startAt(what, money(fix.startsAt, lang))
    action = { label: t.showFrom(money(fix.startsAt, lang)), go: () => onGo({ ...state, price_min: null, price_max: null, page: 1 }) }
  } else if (fix && fix.key !== 'price') {
    const labelOf = {
      brand: () => (state.brand.length === 1 ? brandName(state.brand[0]) : t.brand),
      gender: () => (state.gender.length === 1 ? genderLabel(state.gender[0]) : t.gender),
      color: () => (state.color.length === 1 ? colourName(state.color[0]) : t.colour),
      material: () => 'Material',
      grade: () => 'Grade',
      in_stock: () => t.inStock,
    }
    const label = labelOf[fix.key]?.() || fix.key
    action = { label: t.removeChip(label, fix.count), go: () => onGo(fix.key === 'in_stock' ? { ...state, in_stock: false, page: 1 } : { ...state, [fix.key]: [], page: 1 }) }
  }

  // "Popular in {category}" — the category's available products; the whole shop when there is no category
  const railQs = toV2Query({ ...state, brand: state.fixed?.brand ? state.brand : [], gender: [], color: [], material: [], grade: [], price_min: null, price_max: null, in_stock: false, q: '', sort: 'default', page: 1 }, lang) + '&per_page=6'
  const rail = useQuery({ queryKey: ['listing', railQs], queryFn: listingQueryFn(railQs), staleTime: 5 * 60 * 1000 })
  const railCards = rail.data?.v2?.data || []
  const railTitle = category ? t.popularIn(txt(category.name, lang)) : t.popular
  const seeAll = category ? `/category/${category.path}` : '/listing'

  return (
    <div>
      <div className="bf-empty">
        <h2 className="bf-empty__title">{t.emptyTitle}</h2>
        <p className="bf-empty__why">{why}</p>
        <div className="bf-empty__actions">
          {action ? <button type="button" className="bf-btn" onClick={action.go}>{action.label}</button> : null}
          {hasFilters(state) ? <button type="button" className="bf-btn bf-btn--secondary" onClick={() => onGo(clearFilters(state))}>{t.clearAll}</button> : null}
          {state.q ? <a className="bf-btn bf-btn--secondary" href={localizeHref('/listing', lang)}>{t.allProducts}</a> : null}
        </div>
      </div>
      <BfRail title={railTitle} cards={railCards} seeAllHref={seeAll} lang={lang} onAdd={onAdd} />
    </div>
  )
}

export { LIST_KEYS }
