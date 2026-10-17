'use client'
import { useSyncExternalStore } from 'react'
import { useUIStore } from '@/src/Store/uiStore'
import { txt } from '@/src/lib/cardData'
import { aisleTint } from '@/src/lib/aisleTint'
import { localizeHref } from '@/src/utils/localePath'
import BfProductCard from '@/src/Components/Product/BfProductCard'
import BfRail, { dominantFamily } from '@/src/Components/Listing/BfRail'
import './BfHome.css'

/*
 * B6 — the Brand Fashion home (spec v2.9 §10.1; built 2026-10-17 on v2 `home` + `meta`). The gate is §3's
 * density rule read off a real 390×844 browser: a card at least every 0.8 vh, no non-product band over
 * 0.35 vh, no two non-product bands adjacent.
 *
 * Bands, top to bottom (each a direct child of .bf-home — what src/lib/density.js measures):
 *  1. the OPENING band: the promo line (32, tint-leather, dismissable, remembered) + the hero (a client banner
 *     when the dashboard has one, else the system hero of §11.2: ink panel, the season's line, a brand-gold CTA,
 *     a 3-up / 6-up strip of real deal photos) + the category chips (36, tinted) — §10.1's items 2–4, grouped
 *     into ONE band because §3 forbids two adjacent non-product bands and §10.1 lists three in a row.
 *  2. the deals band: an ink header card with the brand-gold CTA, then the deals as cards (≥ 30% off and in
 *     stock, §19-12) — the first cards on the page.
 *  3. category squares (3 phone / 5 desktop; here as many depth-1 categories as the menu has): a real product
 *     photo on the family tint with the live count.
 *  4+ the dashboard's rails (BfRail: §9's title/count/see-all, mixed-rail tint, short-rail browse card),
 *     with the brand-gold promo block between rail 2 and rail 3 and the brand strip before the last rail.
 *  The last rail is the curated one when the dashboard has it (§10.1 item 10).
 *  Bottom nav on phone (item 11): Home · Categories · Cart · Account.
 */

const STR = {
  en: {
    promo: 'Delivery across Egypt · pay on delivery or by card', dismiss: 'Dismiss', heroLine: 'The season’s watches and fashion, up to −60%', heroCta: 'Shop the deals',
    deals: 'Deals', dealsSub: '30% off or more, in stock now', seeAll: 'See all', categories: 'Categories', promoBlock: 'Returns made easy — the customer pays return shipping only', promoCta: 'How it works',
    brands: 'Brands', home: 'Home', cart: 'Cart', account: 'Account', products: (n) => `${n} products`, railNames: { newest: 'New this week', offers: 'Offers' },
  },
  ar: {
    promo: 'التوصيل لجميع المحافظات · الدفع عند الاستلام أو بالبطاقة', dismiss: 'إغلاق', heroLine: 'ساعات وأزياء الموسم، خصومات حتى 60%', heroCta: 'تسوّق العروض',
    deals: 'العروض', dealsSub: 'خصم 30% أو أكثر، متوفر الآن', seeAll: 'عرض الكل', categories: 'الفئات', promoBlock: 'الإرجاع بسهولة — العميل يدفع شحن الإرجاع فقط', promoCta: 'كيف يعمل',
    brands: 'العلامات التجارية', home: 'الرئيسية', cart: 'السلة', account: 'حسابي', products: (n) => `${n} منتج`, railNames: { newest: 'جديد هذا الأسبوع', offers: 'العروض' },
  },
}
const PROMO_KEY = 'bf-promo-dismissed'
const readPromo = () => {
  try {
    return localStorage.getItem(PROMO_KEY) === '1'
  } catch {
    return false
  }
}
const subscribePromo = (cb) => {
  window.addEventListener('bf-promo', cb)
  window.addEventListener('storage', cb)
  return () => {
    window.removeEventListener('bf-promo', cb)
    window.removeEventListener('storage', cb)
  }
}

export default function BfHome({ home, meta }) {
  const { language } = useUIStore()
  const lang = language === 'ar' ? 'ar' : 'en'
  const t = STR[lang]
  const isRTL = lang === 'ar'
  const L = (href) => localizeHref(href, lang)

  // the promo line's dismissal lives in localStorage (§10.1 item 2): read as an external store so the server
  // render (never dismissed) and the first client render agree, then the stored value takes over
  const promoHidden = useSyncExternalStore(subscribePromo, readPromo, () => false)
  const dismiss = () => {
    try {
      localStorage.setItem(PROMO_KEY, '1')
    } catch {
      // storage unavailable: the line stays for this visit
    }
    window.dispatchEvent(new Event('bf-promo'))
  }

  const deals = home?.deals || []
  const rails = (home?.rails || []).filter((r) => r.cards?.length >= 2)
  const categories = (home?.categories || []).filter((c) => c.count > 0)
  const banner = home?.banner || null
  const brands = (meta?.brands || []).filter((b) => b.is_active !== false && b.logo?.src).slice(0, 12)
  const curated = rails.find((r) => r.kind === 'custom') || null
  const ordinary = rails.filter((r) => r !== curated && r.kind !== 'offers')   // the offers rail is the deals band's ancestor; the deals band replaces it
  const railTitle = (r) => txt(r.title, lang) || t.railNames[r.kind] || (r.cards[0] ? (r.kind === 'brand' ? txt(r.cards[0].brand?.name, lang) : txt(r.cards[0].primary_category?.name, lang)) : '')
  const railHref = (r) => {
    if (r.kind === 'brand' && r.cards[0]?.brand?.slug) return `/brand/${r.cards[0].brand.slug}`
    if (r.kind === 'category_type' && r.cards[0]?.primary_category?.path) return `/category/${r.cards[0].primary_category.path.split('/')[0]}`
    if (r.kind === 'newest') return '/listing?sort=newest'
    return '/listing'
  }

  const bands = []
  ordinary.forEach((r, i) => {
    bands.push(<BfRail key={`rail-${r.id}`} title={railTitle(r)} cards={r.cards} seeAllHref={railHref(r)} lang={lang} />)
    if (i === 1) {
      bands.push(
        <section className="bf-promo-block" key="promo-block" aria-label={t.promoBlock}>
          <div className="bf-promo-block__card">
            <p className="bf-promo-block__text">{t.promoBlock}</p>
            <a className="bf-promo-block__cta" href={L('/terms-and-conditions')}>{t.promoCta}</a>
          </div>
        </section>,
      )
    }
  })

  return (
    <div className="bf-home" dir={isRTL ? 'rtl' : 'ltr'} lang={lang}>
      {/* band 1: promo line + hero + category chips */}
      <section className="bf-open" aria-label={t.home}>
        {!promoHidden ? (
          <div className="bf-promo-line" role="status">
            <span>{t.promo}</span>
            <button type="button" onClick={dismiss} aria-label={t.dismiss}>×</button>
          </div>
        ) : null}
        {banner?.image?.src ? (
          <a className="bf-hero bf-hero--banner" href={L(banner.link_url || (banner.category?.path ? `/category/${banner.category.path}` : '/listing'))}>
            {/* eslint-disable-next-line @next/next/no-img-element -- the dashboard's banner, absolute asset-host URL */}
            <img src={banner.image.src} alt={txt(banner.category?.name, lang) || ''} fetchPriority="high" />
          </a>
        ) : (
          <div className="bf-hero bf-hero--system">
            <div>
              <p className="bf-hero__line">{t.heroLine}</p>
              <a className="bf-hero__cta" href={L('/listing')}>{t.heroCta}</a>
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
        {categories.length ? (
          <nav className="bf-chips-row" aria-label={t.categories}>
            {categories.map((c) => (
              <a key={c.id} className="bf-cat-chip" href={L(`/category/${c.path}`)} style={{ background: `var(--wz-${aisleTint(c.family)})` }}>
                {txt(c.name, lang)} <small>{c.count}</small>
              </a>
            ))}
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
              <a className="bf-hero__cta" href={L('/listing?in_stock=1')}>{t.seeAll}</a>
            </div>
            {deals.slice(0, 6).map((c, i) => (
              <div key={c.id} className={i >= 4 ? 'bf-deals__desktop-only' : undefined}>
                <BfProductCard product={c} lang={lang} ground="white" href={L(`/product/${c.slug}`)} />
              </div>
            ))}
          </div>
        </section>
      ) : null}

      {/* band 3: category squares */}
      {categories.length ? (
        <section className="bf-squares" aria-label={t.categories}>
          <div className="bf-squares__row">
            {categories.slice(0, 5).map((c) => (
              <a key={c.id} className="bf-square" href={L(`/category/${c.path}`)}>
                <div className="bf-square__tile" style={{ background: `var(--wz-${aisleTint(c.family)})` }}>
                  {c.image?.src ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img src={c.image.src} alt="" loading="lazy" />
                  ) : null}
                </div>
                <div className="bf-square__name">
                  <span>{txt(c.name, lang)}</span>
                  <small>{c.count}</small>
                </div>
              </a>
            ))}
          </div>
        </section>
      ) : null}

      {bands}

      {brands.length >= 3 ? (
        <section className="bf-brands" aria-label={t.brands}>
          <div className="bf-brands__row">
            {brands.map((b) => (
              <a key={b.id} className="bf-brand" href={L(`/brand/${b.slug}`)} aria-label={txt(b.name, lang)}>
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img src={b.logo.src} alt={txt(b.name, lang)} loading="lazy" />
              </a>
            ))}
          </div>
        </section>
      ) : null}

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

export { dominantFamily }
