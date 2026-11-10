'use client'
import { useMemo, useState } from 'react'
import Link from '../LocaleLink'
import { useUIStore } from '../../Store/uiStore'
import { useCampaignProducts, useLiveCampaign, useHotDealsMode, useMenu, useEffectsAfterLoad } from '../../Hooks/queries/usePromo'
import { arrangeDeals, categoryChips, discountSteps, txt } from '../../lib/campaign'
import Countdown from './Countdown'
import DealCard from './DealCard'
import { BoltIcon, FlameIcon, Chevron, FlameArt, BoltArt, Embers } from './icons'
import './promo.css'
import './promo-surfaces.css'

// The Hot Deals page and the Flash Sale page — /hot-deals and /flash-sale (Part 2.2, 2026-11-05; redesigned
// and made permanent in the design pass). A TWIN.
//
// PERMANENT PAGES: they are up, and indexable, all year; only their products change. So the page has two
// states and both are designed:
//
//   LIVE   the campaign's hero in its own identity (fire / storm) — what it is, how many products, the biggest
//          REAL discount among them, the countdown to its real end — then the filters (a chip per category the
//          campaign actually has, the discount steps at least one product reaches), the sort, and every product.
//   IDLE   no campaign of this kind is running — never started, switched off, or just ended while the shopper
//          was reading. The same hero, unlit: it says so in plain words, shows NO countdown and no product of a
//          finished campaign, and sends the shopper on into the shop by its real categories.
//
// The whole campaign is at most 300 products, so the page loads them once and filters in the browser: a chip
// answers at once, and "Biggest discount" is an honest sort over all of them, not over one page. Until that
// list arrives the page shows the campaign's first twelve cards, which came with the campaign itself (and are
// in the server's HTML), so the page never opens on grey boxes.

const STR = {
  en: {
    flash_sale: { title: 'Flash sale', sub: 'A short sale on a chosen set of products. It ends when the timer does.' },
    hot_deals: { title: 'Hot deals', sub: 'The products of the running deal, at their deal prices, until the timer runs out.' },
    home: 'Back to homepage',
    ends: 'Ends in',
    statCount: 'products in this sale',
    statMax: 'biggest discount',
    all: 'All deals',
    atLeast: (n) => `${n}% off or more`,
    sortLabel: 'Sort',
    sorts: { discount: 'Biggest discount', price_asc: 'Price: low to high', price_desc: 'Price: high to low', ending: 'Fewest left' },
    showing: (n, total) => (n === total ? `${total} products` : `${n} of ${total} products`),
    none: 'No product matches these filters.',
    clear: 'Show all deals',
    idle: { flash_sale: 'No flash sale is running right now.', hot_deals: 'No hot deals are running right now.' },
    idleSub: {
      flash_sale: 'A flash sale is a short sale on a chosen set of products. When the next one starts, its products and its timer appear on this page, on the home page and in the header.',
      hot_deals: 'Hot deals are the products of a running deal at their deal prices. When the next one starts, its products and its timer appear on this page, on the home page and in the header.',
    },
    browse: 'Browse the shop',
    byCategory: 'Shop by category',
    failed: 'The products could not be loaded.',
    retry: 'Try again',
    exitMode: 'Leave Hot Deals mode',
  },
  ar: {
    flash_sale: { title: 'تخفيضات خاطفة', sub: 'تخفيض قصير على منتجات مختارة، وينتهي بانتهاء العدّاد.' },
    hot_deals: { title: 'عروض ساخنة', sub: 'منتجات العرض الجاري بأسعاره الخاصة، حتى انتهاء العدّاد.' },
    home: 'العودة إلى الرئيسية',
    ends: 'ينتهي خلال',
    statCount: 'منتج في هذا العرض',
    statMax: 'أعلى خصم',
    all: 'كل العروض',
    atLeast: (n) => `خصم ${n}% أو أكثر`,
    sortLabel: 'الترتيب',
    sorts: { discount: 'الأعلى خصمًا', price_asc: 'السعر: من الأقل', price_desc: 'السعر: من الأعلى', ending: 'الأقل توفرًا' },
    showing: (n, total) => (n === total ? `${total} منتج` : `${n} من ${total} منتج`),
    none: 'لا يوجد منتج يطابق هذه الفلاتر.',
    clear: 'عرض كل العروض',
    idle: { flash_sale: 'لا توجد تخفيضات خاطفة الآن.', hot_deals: 'لا توجد عروض ساخنة الآن.' },
    idleSub: {
      flash_sale: 'التخفيضات الخاطفة تخفيض قصير على منتجات مختارة. عند بدء التخفيض القادم تظهر منتجاته وعدّاده في هذه الصفحة وفي الصفحة الرئيسية وأعلى الموقع.',
      hot_deals: 'العروض الساخنة هي منتجات العرض الجاري بأسعاره الخاصة. عند بدء العرض القادم تظهر منتجاته وعدّاده في هذه الصفحة وفي الصفحة الرئيسية وأعلى الموقع.',
    },
    browse: 'تصفّح المتجر',
    byCategory: 'تسوّق حسب القسم',
    failed: 'تعذّر تحميل المنتجات.',
    retry: 'إعادة المحاولة',
    exitMode: 'الخروج من وضع العروض الساخنة',
  },
}

export default function CampaignPage({ presentation, menu: menuFromPage = null }) {
  const language = useUIStore((s) => s.language)
  const lang = language === 'ar' ? 'ar' : 'en'
  const dir = lang === 'ar' ? 'rtl' : 'ltr'
  const t = STR[lang]
  const hot = presentation === 'hot_deals'
  const { campaign, offset, isLoading, serverNow } = useLiveCampaign(presentation)
  useEffectsAfterLoad()
  const { data: cards, isLoading: loadingCards, isError, refetch } = useCampaignProducts(presentation, campaign)
  const mode = useHotDealsMode()
  // The chips are named by the menu's top-level categories WHEN THE MENU IS ALREADY IN THE CACHE (the shopper opened
  // it) — a live campaign page never fetches the whole menu just to name two chips: measured 2026-11-05, that
  // request (1.1 s behind the products' own on a one-request-at-a-time server) was the slowest thing on the page.
  // Without it a chip is named by its cards' own category. Only the "nothing running" state asks for the menu: it
  // lists the shop's categories as the way on.
  // THE PAGE'S OWN SERVER RENDER MAY HAND THE MENU'S CATEGORIES IN (`menu`, 2026-11-07): read in the first paint — the
  // idle "shop by category" — they must be the same on the server and in the hydration render, and a sessionStorage
  // copy only the browser has made them differ (React #418 on the live site). With the prop, the hook is only a reader
  // of the cache (no request of its own).
  const { data: fetched } = useMenu(!menuFromPage && !campaign && !isLoading)
  const menu = fetched || menuFromPage || undefined
  const topName = (chip) => txt(menu?.categories?.find((c) => c.path === chip.key)?.name, lang) || txt(chip.name, lang) || chip.key.replace(/-/g, ' ')
  const [category, setCategory] = useState(null)
  const [minDiscount, setMinDiscount] = useState(0)
  const [sort, setSort] = useState('discount')

  // the whole campaign once it has arrived; until then its first twelve cards, which came with the campaign
  const all = useMemo(() => cards || campaign?.cards || [], [cards, campaign])
  const chips = useMemo(() => categoryChips(all), [all])
  const steps = useMemo(() => discountSteps(all), [all])
  const shown = useMemo(() => arrangeDeals(all, { category, minDiscount, sort }), [all, category, minDiscount, sort])
  const total = cards ? all.length : campaign?.count || all.length
  const waiting = loadingCards && all.length === 0

  const head = t[presentation]
  const kind = hot ? 'hot' : 'flash'
  const mark = <span className="pm-hero__mark" aria-hidden="true">{hot ? <FlameIcon size={22} /> : <BoltIcon size={22} />}</span>
  const art = hot ? <FlameArt className="pm-hero__art" /> : <BoltArt className="pm-hero__art" />

  if (!campaign) {
    const categories = (menu?.categories || []).filter((c) => c?.slug && txt(c.name, lang)).slice(0, 8)
    return (
      <div className={`pm-page pm-page--${kind} pm-page--idle`} dir={dir} lang={lang}>
        {isLoading ? (
          <span className="pm-skel pm-skel--hero" aria-hidden="true" />
        ) : (
          <section className="pm-hero pm-hero--idle" aria-labelledby="pm-hero-title">
            {art}
            <div className="pm-hero__text">
              <h1 className="pm-hero__title" id="pm-hero-title">
                {mark}
                {head.title}
              </h1>
              <p className="pm-hero__state" role="status">
                {t.idle[presentation]}
              </p>
              <p className="pm-hero__sub">{t.idleSub[presentation]}</p>
              <div className="pm-hero__actions">
                <Link className="pm-btn pm-btn--light" href="/listing">
                  {t.browse}
                  <Chevron flip={dir === 'rtl'} size={16} />
                </Link>
              </div>
            </div>
          </section>
        )}
        {!isLoading && categories.length > 0 ? (
          <nav className="pm-idle-nav" aria-label={t.byCategory}>
            <h2 className="pm-idle-nav__title">{t.byCategory}</h2>
            <ul className="pm-idle-nav__list">
              {categories.map((c) => (
                <li key={c.slug}>
                  <Link className="pm-chip pm-chip--link" href={`/category/${c.slug}`}>
                    {txt(c.name, lang)}
                  </Link>
                </li>
              ))}
            </ul>
          </nav>
        ) : null}
      </div>
    )
  }

  return (
    <div className={`pm-page pm-page--${kind}`} dir={dir} lang={lang}>
      <section className="pm-hero" aria-labelledby="pm-hero-title">
        {art}
        {hot ? <Embers /> : null}
        <div className="pm-hero__text">
          <Link className="pm-hero__back" href="/" onClick={hot && mode.on ? () => mode.set(false) : undefined}>
            <Chevron flip={dir === 'ltr'} size={16} />
            {hot && mode.on ? t.exitMode : t.home}
          </Link>
          <h1 className="pm-hero__title" id="pm-hero-title">
            {mark}
            {head.title}
          </h1>
          <p className="pm-hero__sub">{head.sub}</p>
          <dl className="pm-hero__stats">
            <div>
              <dd>{total}</dd>
              <dt>{t.statCount}</dt>
            </div>
            {campaign.max_discount > 0 ? (
              <div>
                <dd>{campaign.max_discount}%</dd>
                <dt>{t.statMax}</dt>
              </div>
            ) : null}
          </dl>
        </div>
        <div className="pm-hero__time">
          <span className="pm-hero__ends">{t.ends}</span>
          <Countdown endsAt={campaign.ends_at} offset={offset} now={serverNow} lang={lang} className="pm-count--lg" />
        </div>
      </section>

      <div className="pm-tools">
        <div className="pm-chips" role="group" aria-label={t.all}>
          <button type="button" className="pm-chip" aria-pressed={category === null && minDiscount === 0} onClick={() => { setCategory(null); setMinDiscount(0) }}>
            {t.all}
          </button>
          {chips.length > 1
            ? chips.map((chip) => (
                <button type="button" className="pm-chip" key={chip.key} aria-pressed={category === chip.key} onClick={() => setCategory(category === chip.key ? null : chip.key)}>
                  {topName(chip)} <span className="pm-chip__n">{chip.count}</span>
                </button>
              ))
            : null}
          {steps.map((step) => (
            <button type="button" className="pm-chip pm-chip--step" key={step} aria-pressed={minDiscount === step} onClick={() => setMinDiscount(minDiscount === step ? 0 : step)}>
              {t.atLeast(step)}
            </button>
          ))}
        </div>
        <label className="pm-sort">
          <span>{t.sortLabel}</span>
          <select value={sort} onChange={(e) => setSort(e.target.value)}>
            {Object.entries(t.sorts).map(([key, name]) => (
              <option key={key} value={key}>
                {name}
              </option>
            ))}
          </select>
        </label>
      </div>

      <p className="pm-showing" aria-live="polite">
        {waiting ? ' ' : t.showing(shown.length, cards ? all.length : total)}
      </p>

      {isError && all.length === 0 ? (
        <div className="pm-empty" role="alert">
          <p>{t.failed}</p>
          <button type="button" className="pm-btn" onClick={() => refetch()}>
            {t.retry}
          </button>
        </div>
      ) : waiting ? (
        <div className="pm-grid" aria-hidden="true">
          {Array.from({ length: 10 }, (_, i) => (
            <span className="pm-skel pm-skel--card" key={i} />
          ))}
        </div>
      ) : shown.length === 0 ? (
        <div className="pm-empty">
          <p>{t.none}</p>
          <button type="button" className="pm-btn" onClick={() => { setCategory(null); setMinDiscount(0) }}>
            {t.clear}
          </button>
        </div>
      ) : (
        <div className="pm-grid">
          {shown.map((card, i) => (
            <DealCard key={card.id} card={card} lang={lang} eager={i < 4} sizes="(max-width: 599px) 46vw, (max-width: 1023px) 30vw, 260px" />
          ))}
        </div>
      )}
    </div>
  )
}
