'use client'
import { useMemo } from 'react'
import { useUIStore } from '@/src/Store/uiStore'
import { useTables } from '@/src/Hooks/queries/useTables'
import { useNav } from '@/src/Hooks/queries/useNav'
import { buildListingParams } from '@/src/utils/listingParams'
import { categorySlug } from '@/src/utils/slugs'
import { txt } from '@/src/lib/cardData'
import { aisleTint } from '@/src/lib/aisleTint'
import { localizeHref } from '@/src/utils/localePath'
import BfProductCard from '@/src/Components/Product/BfProductCard'
import BfRail, { dominantFamily, useRailScroll } from '@/src/Components/Listing/BfRail'
import './BfHome.css'

/*
 * B6 — the Brand Fashion home (spec v2.11 §10.1; built 2026-10-17 on v2 `home` + `meta`, re-cut 2026-10-18 in the
 * visual reset). The gate is §3's density rule read off a real 390×844 browser: a card at least every 0.8 vh, no
 * non-product band over 0.35 vh, no two non-product bands adjacent.
 *
 * Bands, top to bottom (each a direct child of .bf-home — what src/lib/density.js measures):
 *  1. the OPENING band: the hero (a client banner when the dashboard has one, else the system hero of §11.2) and
 *     the category row — a photo tile per family with its live count, followed by that family's busiest
 *     sub-types as chips with their counts (§10.1 items 3–5, one band). The chips are the menu's own links:
 *     Watchizer's `/listing?categories=&subTypes=` (the twin Nav builds the same), so a chip behaves exactly as
 *     the menu entry does; the counts come from the v2 tree.
 *  2. the deals band: an ink header card with the brand-gold CTA, then the deals as cards (≥ 30% off and in
 *     stock, §19-12) — the first cards on the page.
 *  3+ the dashboard's rails (BfRail: §9's header, arrows, mixed-rail tint, short-rail browse card) — fourteen on
 *     the dev copy (BrandFashionHomeRailsSeeder, A8) — with the brand strip (brands that HAVE products here,
 *     from `home.brands`) after the sixth rail, and the curated rail last.
 *  Bottom nav on phone (item 11): Home · Categories · Cart · Account.
 *
 * Gone since 2026-10-18 (Michael): the promo line above the banner and the returns promo block. One warm accent
 * remains — the deals header's CTA.
 */

const STR = {
  en: {
    heroLine: 'The season’s watches and fashion, up to 60% off', heroCta: 'Shop the deals',
    deals: 'Deals', dealsSub: '30% off or more, in stock now', seeAll: 'See all', categories: 'Categories', brands: 'Brands',
    home: 'Home', cart: 'Cart', account: 'Account', products: (n) => `${n} products`, railNames: { newest: 'New this week', offers: 'Offers' },
    prev: 'Scroll back', next: 'Scroll forward',
  },
  ar: {
    heroLine: 'ساعات وأزياء الموسم، خصومات حتى 60%', heroCta: 'تسوّق التخفيضات',
    deals: 'تخفيضات', dealsSub: 'خصم 30% أو أكثر، متوفر الآن', seeAll: 'عرض الكل', categories: 'الفئات', brands: 'العلامات التجارية',
    home: 'الرئيسية', cart: 'السلة', account: 'حسابي', products: (n) => `${n} منتج`, railNames: { newest: 'جديد هذا الأسبوع', offers: 'العروض' },
    prev: 'السابق', next: 'التالي',
  },
}
const CHIPS_PER_FAMILY = 5
const BRAND_STRIP_AFTER = 6

// localized legacy table name: current language → English fallback (the twin Nav's rule)
const label = (item, key, language) =>
  item?.translations?.find((x) => x.locale === language)?.[key] ?? item?.translations?.find((x) => x.locale === 'en')?.[key] ?? item?.[key] ?? ''

export default function BfHome({ home, meta }) {
  const { language } = useUIStore()
  const lang = language === 'ar' ? 'ar' : 'en'
  const t = STR[lang]
  const isRTL = lang === 'ar'
  const dir = isRTL ? 'rtl' : 'ltr'
  const L = (href) => localizeHref(href, lang)
  const { data: tables = {} } = useTables()
  const { data: nav } = useNav()
  const [brandsRef, brandsRail] = useRailScroll(dir)
  const [catsRef, catsRail] = useRailScroll(dir)

  const deals = home?.deals || []
  const rails = (home?.rails || []).filter((r) => r.cards?.length >= 2)
  const categories = (home?.categories || []).filter((c) => c.count > 0)
  const banner = home?.banner || null
  const brands = (home?.brands || []).filter((b) => b.count > 0 && b.logo?.src)
  const curated = rails.find((r) => r.kind === 'custom' && /our picks|اختياراتنا/i.test(`${r.title?.en || ''} ${r.title?.ar || ''}`)) || null
  const ordinary = rails.filter((r) => r !== curated && r.kind !== 'offers')   // the offers rail is the deals band's ancestor; the deals band replaces it

  // ── the category row: a tile per family + its busiest sub-types as chips. Links are the twin menu's own
  //    (Watchizer's listing URL with the legacy ids), counts are the v2 tree's, matched by English name.
  const chipRow = useMemo(() => {
    const tree = Array.isArray(meta?.tree) ? meta.tree : []
    const byPath = new Map(tree.map((n) => [n.path, n]))
    const cts = tables?.categoryTypes || []
    const subs = tables?.subTypes || []
    const byCat = nav?.sub_types_by_category || {}
    return categories.map((c) => {
      const ct = cts.find((x) => categorySlug(x) === c.slug || label(x, 'category_type_name', 'en').toLowerCase() === txt(c.name, 'en').toLowerCase())
      const node = byPath.get(c.path)
      const children = (node?.children || []).filter((n) => n.product_count > 0).sort((a, b) => b.product_count - a.product_count)
      const chips = []
      for (const n of children) {
        if (chips.length === CHIPS_PER_FAMILY) break
        const st = subs.find((s) => label(s, 'sub_type_name', 'en').toLowerCase() === txt(n.name, 'en').toLowerCase())
        if (!ct || !st) continue
        if (byCat[ct.id] && !byCat[ct.id].includes(st.id)) continue   // the menu's rule: only sub-types that have products
        const params = buildListingParams({ categories: [ct.id], brands: [], subTypes: [st.id], genders: [], offers: false, price: [0, 99999999] }, {}, tables)
        chips.push({ id: n.id, name: txt(n.name, lang), count: n.product_count, href: `/listing?${params.toString()}` })
      }
      return { ...c, href: `/category/${c.slug}`, chips }
    })
  }, [categories, meta, tables, nav, lang])

  const railTitle = (r) => txt(r.title, lang) || t.railNames[r.kind] || (r.cards[0] ? (r.kind === 'brand' ? txt(r.cards[0].brand?.name, lang) : txt(r.cards[0].primary_category?.name, lang)) : '')
  const railHref = (r) => {
    if (r.kind === 'brand' && r.cards[0]?.brand?.slug) return `/brand/${r.cards[0].brand.slug}`
    if (r.kind === 'category_type' && r.cards[0]?.primary_category?.path) return `/category/${r.cards[0].primary_category.path.split('/')[0]}`
    if (r.kind === 'newest') return '/listing?sort=newest'
    return '/listing'
  }
  const railCount = (r) => {
    if (r.kind === 'brand') return brands.find((b) => b.id === r.cards[0]?.brand?.id)?.count ?? null
    if (r.kind === 'category_type') return categories.find((c) => c.path === r.cards[0]?.primary_category?.path?.split('/')[0])?.count ?? null
    return null
  }

  const bands = []
  ordinary.forEach((r, i) => {
    bands.push(<BfRail key={`rail-${r.id}`} title={railTitle(r)} count={railCount(r)} cards={r.cards} seeAllHref={railHref(r)} lang={lang} />)
    if (i === BRAND_STRIP_AFTER - 1 && brands.length >= 3) {
      bands.push(
        <section className="bf-brands" key="brands" aria-label={t.brands} dir={dir}>
          <header className="bf-rail__head">
            <h2 className="bf-rail__title">{t.brands}<span className="bf-rail__count">{brands.length}</span></h2>
            <div className="bf-rail__tools">
              <button type="button" className="bf-rail__arrow" onClick={() => brandsRail.page(-1)} disabled={brandsRail.atStart} aria-label={t.prev}><Chevron flip={!isRTL} /></button>
              <button type="button" className="bf-rail__arrow" onClick={() => brandsRail.page(1)} disabled={brandsRail.atEnd} aria-label={t.next}><Chevron flip={isRTL} /></button>
            </div>
          </header>
          <div className="bf-brands__row" ref={brandsRef}>
            {brands.map((b) => (
              <a key={b.id} className="bf-brand" href={L(`/brand/${b.slug}`)}>
                {/* eslint-disable-next-line @next/next/no-img-element -- brand logo, asset-host URL */}
                <img src={b.logo.src} alt="" loading="lazy" />
                <span className="bf-brand__name" lang="en">{txt(b.name, lang)}</span>
                <span className="bf-brand__count">{b.count}</span>
              </a>
            ))}
          </div>
        </section>,
      )
    }
  })

  return (
    <div className="bf-home" dir={dir} lang={lang}>
      {/* band 1: hero + the category row */}
      <section className="bf-open" aria-label={t.home}>
        {banner?.image?.src ? (
          <a className="bf-hero bf-hero--banner" href={L(banner.link_url || (banner.category?.path ? `/category/${banner.category.path}` : '/listing'))}>
            {/* eslint-disable-next-line @next/next/no-img-element -- the dashboard's banner, absolute asset-host URL */}
            <img src={banner.image.src} alt={txt(banner.category?.name, lang) || ''} fetchPriority="high" />
          </a>
        ) : (
          <div className="bf-hero bf-hero--system">
            <div>
              <p className="bf-hero__line">{t.heroLine}</p>
              {/* the deals' own page (2026-10-19): `in_stock=1` is no listing key, so this opened the whole shop */}
              <a className="bf-cta-gold" href={L('/listing?sale=1&discount=30&inStock=1')}>{t.heroCta}</a>
            </div>
            <div className="bf-hero__strip" aria-hidden="true">
              {deals.slice(0, 6).map((c, i) => (
                <a className="bf-hero__photo" key={c.id} href={L(`/product/${c.slug}`)} tabIndex={-1} style={i >= 3 ? { display: 'var(--bf-hero-extra, none)' } : undefined}>
                  {/* eslint-disable-next-line @next/next/no-img-element */}
                  <img src={c.image?.src} alt="" loading={i < 3 ? 'eager' : 'lazy'} />
                </a>
              ))}
            </div>
          </div>
        )}
        {chipRow.length ? (
          <nav className="bf-cats" aria-label={t.categories}>
            <div className="bf-cats__row" ref={catsRef}>
              {chipRow.map((c) => (
                <div className="bf-cats__family" key={c.id}>
                  <a className="bf-cat" href={L(c.href)} style={{ '--bf-cat-tint': `var(--wz-${aisleTint(c.family)})` }}>
                    <span className="bf-cat__tile" aria-hidden="true">
                      {c.image?.src ? (
                        // eslint-disable-next-line @next/next/no-img-element
                        <img src={c.image.src} alt="" loading="lazy" />
                      ) : null}
                    </span>
                    <span className="bf-cat__text">
                      <span className="bf-cat__name">{txt(c.name, lang)}</span>
                      <span className="bf-cat__count">{t.products(c.count)}</span>
                    </span>
                  </a>
                  {c.chips.map((s) => (
                    <a key={s.id} className="bf-chip" href={L(s.href)}>
                      {s.name} <span className="bf-chip__count">{s.count}</span>
                    </a>
                  ))}
                </div>
              ))}
            </div>
            <div className="bf-cats__arrows">
              <button type="button" className="bf-rail__arrow" onClick={() => catsRail.page(-1)} disabled={catsRail.atStart} aria-label={t.prev}><Chevron flip={!isRTL} /></button>
              <button type="button" className="bf-rail__arrow" onClick={() => catsRail.page(1)} disabled={catsRail.atEnd} aria-label={t.next}><Chevron flip={isRTL} /></button>
            </div>
          </nav>
        ) : null}
      </section>

      {/* band 2: the deals — the first cards */}
      {deals.length >= 2 ? (
        <section className="bf-deals" aria-label={t.deals}>
          <div className="bf-deals__grid">
            <div className="bf-deals__head">
              <div>
                <h2 className="bf-deals__title">{t.deals}</h2>
                <div className="bf-deals__sub">{t.dealsSub}</div>
              </div>
              {/* the band's own rule on the Sale page (Michael, 2026-10-19): 30 %+ and in stock — it used to open
                  `/listing?in_stock=1`, a key the listing does not read, so "See all" showed the whole shop */}
              <a className="bf-cta-gold" href={L('/listing?sale=1&discount=30&inStock=1')}>{t.seeAll}</a>
            </div>
            {deals.slice(0, 6).map((c, i) => (
              <div key={c.id} className={i >= 4 ? 'bf-deals__desktop-only' : undefined}>
                <BfProductCard product={c} lang={lang} ground="white" href={L(`/product/${c.slug}`)} />
              </div>
            ))}
          </div>
        </section>
      ) : null}

      {bands}

      {curated ? <BfRail title={railTitle(curated)} cards={curated.cards} seeAllHref="/listing" lang={lang} tint="always" className="bf-rail--curated" /> : null}

      <nav className="bf-bottom-nav" aria-label={t.home}>
        <a href={L('/')} aria-current="page"><Icon d="M3 11 12 3l9 8v10h-6v-6H9v6H3z" />{t.home}</a>
        <a href={L('/listing')}><Icon d="M4 5h7v7H4zM13 5h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z" />{t.categories}</a>
        <a href={L('/cart')}><Icon d="M6 6h15l-2 9H8L5 3H2M9 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2zm9 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2z" />{t.cart}</a>
        <a href={L('/account')}><Icon d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm-8 9a8 8 0 0 1 16 0" />{t.account}</a>
      </nav>
    </div>
  )
}

function Icon({ d }) {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d={d} />
    </svg>
  )
}

function Chevron({ flip = false }) {
  return (
    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" style={flip ? { transform: 'scaleX(-1)' } : undefined}>
      <path d="m9 6 6 6-6 6" />
    </svg>
  )
}

export { dominantFamily }
