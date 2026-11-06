'use client'
import { useMemo } from 'react'
import dynamic from 'next/dynamic'
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
import WatchHero from '@/src/Components/Hero/WatchHero'
import HeroZone from '@/src/Components/Home/HeroZone'
import BrandBanner, { onColor } from '@/src/Components/Home/BrandBanner'
import CampaignRail from '@/src/Components/Promo/CampaignRail'
import { useHotDealsMode, useLiveCampaign } from '@/src/Hooks/queries/usePromo'
import { money as dealMoney } from '@/src/lib/campaign'
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
    home: 'Home', cart: 'Cart', account: 'Account', products: (n) => `${n} products`, railNames: { newest: 'New this week', offers: 'Offers', best_value: 'Best value' },
    under: (price) => `Under ${price}`,
    prev: 'Scroll back', next: 'Scroll forward',
  },
  ar: {
    heroLine: 'ساعات وأزياء الموسم، خصومات حتى 60%', heroCta: 'تسوّق التخفيضات',
    deals: 'تخفيضات', dealsSub: 'خصم 30% أو أكثر، متوفر الآن', seeAll: 'عرض الكل', categories: 'الفئات', brands: 'العلامات التجارية',
    home: 'الرئيسية', cart: 'السلة', account: 'حسابي', products: (n) => `${n} منتج`, railNames: { newest: 'جديد هذا الأسبوع', offers: 'العروض', best_value: 'أفضل قيمة' },
    under: (price) => `أقل من ${price}`,
    prev: 'السابق', next: 'التالي',
  },
}
// Hot Deals mode replaces the home with the campaign's page; loaded only when the switch is on.
const CampaignPage = dynamic(() => import('@/src/Components/Promo/CampaignPage'))

// The kinds that are a SECTION of the page rather than a slider of products (core: HomeRails::SECTIONS).
const SECTION_KINDS = ['banners', 'hot_deals', 'flash_sale', 'categories', 'brands']

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
  // the live campaigns (Part 2, 2026-11-05): the header switch's mode, and whether a Flash Sale is running
  const hotDeals = useHotDealsMode()
  const flashSale = useLiveCampaign('flash_sale')

  const deals = home?.deals || []
  // a brand banner (Part 3.3) is a banner with three products by design, not a row of cards: it needs its brand,
  // not the two-card minimum
  // …and the Banners block's row (the design pass): core sends it at its place among the sliders only while a
  // hero leads the page; it is drawn there when the shop has a banner to show.
  // EVERY HOME SECTION IS A ROW OF THE DASHBOARD (the review of 2026-11-05): `managed_sections` names the sections
  // the Home sliders screen has a row for, on or off. Such a section is drawn ONLY where its row is (it arrives as a
  // rail of its kind) and not at all when the row is off; a section with no row keeps its old fixed place below.
  // A `featured` row is never drawn: the rotating featured banner is gone.
  const managed = new Set(home?.managed_sections || [])
  const rails = (home?.rails || []).filter((r) =>
    r.kind === 'featured' ? false : SECTION_KINDS.includes(r.kind) ? true : r.kind === 'brand_banner' ? !!r.brand?.slug : r.cards?.length >= 2,
  )
  // the two side slots of the hero zone (Part 3.1): [top, bottom], each a banner or null; both null = the hero alone
  const sideBanners = Array.isArray(home?.side_banners) ? home.side_banners : []
  const categories = (home?.categories || []).filter((c) => c.count > 0)
  // D1 (2026-10-20): EVERY active banner, so the hero can rotate. `banners` is core's list since that day;
  // `banner` was the one object the payload carried before it, and is read here as a one-banner list so this
  // page still works against a core that has not been deployed yet — core goes out before the storefronts.
  const banners = home?.banners?.length ? home.banners : home?.banner ? [home.banner] : []
  // WHICH HERO THIS SHOP DRAWS (the addendum, 2026-10-20): `home.hero` is the storefront's own setting,
  // flipped on the dashboard's Banners screen — `banner` (this shop today) or `three_d`. The banner hero
  // is the TWIN component Watchizer renders for the same setting, not a copy of it. With `banner` set and
  // no banners, §11.2's system hero still fills the band, as it has since B6.
  const threeD = home?.hero === 'three_d'
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

  const railTitle = (r) => txt(r.title, lang) || (r.kind === 'price' ? t.under(dealMoney(Number(r.target) || 0, lang)) : '') || t.railNames[r.kind] || (r.cards[0] ? (r.kind === 'brand' ? txt(r.cards[0].brand?.name, lang) : txt(r.cards[0].primary_category?.name, lang)) : '')
  const railHref = (r) => {
    if (r.kind === 'brand' && r.cards[0]?.brand?.slug) return `/brand/${r.cards[0].brand.slug}`
    if (r.kind === 'category_type' && r.cards[0]?.primary_category?.path) return `/category/${r.cards[0].primary_category.path.split('/')[0]}`
    if (r.kind === 'newest') return '/listing?sort=newest'
    if (r.kind === 'best_value') return '/listing?sale=1'
    return '/listing'
  }
  const railCount = (r) => {
    if (r.kind === 'brand') return brands.find((b) => b.id === r.cards[0]?.brand?.id)?.count ?? null
    if (r.kind === 'category_type') return categories.find((c) => c.path === r.cards[0]?.primary_category?.path?.split('/')[0])?.count ?? null
    return null
  }

  // the category row: a tile per family + its busiest sub-types — a constant, so it can be drawn in the opening band
  // (its old fixed place) or where its dashboard row is
  const catsNav = chipRow.length ? (
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
  ) : null

  // the brand strip, a constant for the same reason
  const brandsBand = (
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
        </section>
  )

  const bands = []
  ordinary.forEach((r, i) => {
    if (r.kind === 'banners') {
      // THE BANNERS BLOCK AS A SECTION: the large banner and its two side slots, where the dashboard placed them
      if (banners.length) {
        bands.push(
          <section className="bf-band" key={`rail-${r.id}`}>
            <HeroZone banners={banners} side={sideBanners} lang={lang} dir={dir} />
          </section>,
        )
      }
    } else if (r.kind === 'hot_deals' || r.kind === 'flash_sale') {
      // a campaign's section, where its row is — and only while its campaign is live
      if (r.kind === 'hot_deals' ? hotDeals.campaign : flashSale.campaign) {
        bands.push(
          <section className="bf-band bf-band--bleed" key={`rail-${r.id}`}>
            <CampaignRail presentation={r.kind} lang={lang} bleed />
          </section>,
        )
      }
    } else if (r.kind === 'categories') {
      if (catsNav) bands.push(<section className="bf-band" key={`rail-${r.id}`}>{catsNav}</section>)
    } else if (r.kind === 'brands') {
      if (brands.length >= 3) bands.push(brandsBand)
    } else if (r.kind === 'brand_banner') {
      // THE BRAND BANNER (Part 3.3): the brand's logo and page, the team's title, description and three colours,
      // and up to three of the brand's products — as the plain model the twin BrandBanner draws.
      bands.push(
        <section className="bf-band bf-band--bleed" key={`rail-${r.id}`}>
          <BrandBanner
            bleed
            lang={lang}
            dir={dir}
            model={{
              href: `/brand/${r.brand.slug}`,
              brand: txt(r.brand.name, 'en'),
              logo: r.brand.logo?.src || null,
              title: txt(r.title, lang),
              description: txt(r.description, lang),
              colors: r.colors || [],
              products: (r.cards || []).slice(0, 3).map((c) => ({
                id: c.id,
                // each card of the banner opens ITS OWN product
                href: `/product/${c.slug}`,
                image: c.image?.src,
                title: txt(c.title, lang),
                price: dealMoney(c.price?.has_sale ? c.price.sale_amount : c.price?.amount, lang),
                was: c.price?.has_sale ? dealMoney(c.price.amount, lang) : null,
                // the product's real discount, as its card prints it
                off: c.price?.has_sale && c.price.discount_pct > 0 ? c.price.discount_pct : null,
              })),
            }}
          />
        </section>,
      )
    } else if (r.background) {
      /*
       * A SLIDER'S OWN LOOK (Part 3.2): a background colour for its section and, separately, the blend that
       * dissolves the white studio background of its product photos into that colour. A slider with neither is
       * the plain BfRail below, exactly as before.
       */
      bands.push(
        <section className="bf-band bf-band--colour" key={`rail-${r.id}`} style={{ '--hl-ground': r.background }}>
          <div className={`hl${r.blend ? ' hl--blend' : ''}`} style={{ '--hl-ground': r.background, '--hl-ink': onColor(r.background) }}>
            {/* with the blend on, each card goes out with the mask of its photo's studio background (core: r.masks) */}
            <BfRail
              title={railTitle(r)}
              count={railCount(r)}
              cards={r.blend && r.masks ? r.cards.map((c) => (r.masks[c.id] ? { ...c, cut_mask: r.masks[c.id] } : c)) : r.cards}
              seeAllHref={railHref(r)}
              lang={lang}
              tint="never"
            />
          </div>
        </section>,
      )
    } else {
      bands.push(<BfRail key={`rail-${r.id}`} title={railTitle(r)} count={railCount(r)} cards={r.cards} seeAllHref={railHref(r)} lang={lang} />)
    }
    if (!managed.has('brands') && i === BRAND_STRIP_AFTER - 1 && brands.length >= 3) {
      bands.push(brandsBand)
    }
  })

  // HOT DEALS MODE (Part 2.4): the switch in the header is on, so the shop shows only the live Hot Deals
  // campaign — and its home IS that campaign's page. Off (or the campaign over), the home below returns.
  if (hotDeals.on) return <CampaignPage presentation="hot_deals" />

  return (
    <div className="bf-home" dir={dir} lang={lang}>
      {/* band 1: hero + the category row */}
      <section className="bf-open" aria-label={t.home}>
        {threeD ? (
          <WatchHero />
        ) : banners.length ? (
          <HeroZone banners={banners} side={sideBanners} lang={lang} dir={dir} />
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
        {managed.has('categories') ? null : catsNav}
      </section>

      {/*
       * THE LIVE CAMPAIGNS (Part 2.2, 2026-11-05): a Flash Sale and a Hot Deals slider, each in its own design,
       * each drawn only while a campaign of its kind is live and gone at its end — band and all.
       */}
      {!managed.has('flash_sale') && flashSale.campaign ? (
        <section className="bf-band bf-band--bleed">
          <CampaignRail presentation="flash_sale" lang={lang} bleed />
        </section>
      ) : null}
      {!managed.has('hot_deals') && hotDeals.campaign ? (
        <section className="bf-band bf-band--bleed">
          <CampaignRail presentation="hot_deals" lang={lang} bleed />
        </section>
      ) : null}

      {/* band 2: the deals — the first cards */}
      {/* …unless the dashboard has a "best value" slider: that IS the deals band, placed where the team wants it */}
      {deals.length >= 2 && !ordinary.some((r) => r.kind === 'best_value') ? (
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
