'use client'
import Link from '../LocaleLink'
import { useRailScroll } from '../../Hooks/useRailScroll'
import { useLiveCampaign, useEffectsAfterLoad } from '../../Hooks/queries/usePromo'
import { CAMPAIGN_PATH } from '../../lib/campaign'
import Countdown from './Countdown'
import DealCard from './DealCard'
import { BoltIcon, FlameIcon, Chevron, FlameArt, BoltArt, Embers } from './icons'
import './promo.css'
import './promo-surfaces.css'

// A live campaign's slider on the home page (Part 2.2, 2026-11-05; redesigned in the design pass). A TWIN.
//
// One mechanism, two presentations, each with ITS OWN identity:
//
//   flash_sale  STORM — a night-blue panel, a yellow bolt, the countdown in the bolt's colour; the head is one
//               line above the cards;
//   hot_deals   FIRE — a burning head card on the leading side (what it is, how big, how long) with a flame
//               behind it and sparks rising, the cards scrolling beside it on a warm ground.
//
// It renders NOTHING when no campaign of its presentation is live, and it leaves the page at the campaign's
// end without a reload. The cards are core's first twelve; "View all" opens the campaign's own page.
//
// PHONE: the Hot Deals head becomes a banner above the cards and its button moves under them (the three are
// grid children, placed by promo.css) — a layout of its own, not the desktop one squeezed.
//
// `bleed` — the section runs the FULL WIDTH of the page (the developer's review, 2026-11-05): no rounded corners,
// its head and its first card kept on the page's own column by `--pm-container` / `--pm-gutter`, the cards
// scrolling out to the far edge.

const STR = {
  en: {
    flash_sale: 'Flash sale',
    hot_deals: 'Hot deals',
    ends: 'Ends in',
    allCount: (n) => `View all ${n}`,
    upTo: (n) => `Up to ${n}% off`,
    count: (n) => `${n} products, while the timer runs`,
    prev: 'Scroll back',
    next: 'Scroll forward',
  },
  ar: {
    flash_sale: 'تخفيضات خاطفة',
    hot_deals: 'عروض ساخنة',
    ends: 'ينتهي خلال',
    allCount: (n) => `عرض الكل (${n})`,
    upTo: (n) => `خصم حتى ${n}%`,
    count: (n) => `${n} منتج حتى انتهاء العدّاد`,
    prev: 'السابق',
    next: 'التالي',
  },
}

export default function CampaignRail({ presentation, lang = 'en', bleed = false }) {
  const { campaign, offset } = useLiveCampaign(presentation)
  useEffectsAfterLoad()
  const l = lang === 'ar' ? 'ar' : 'en'
  const dir = l === 'ar' ? 'rtl' : 'ltr'
  const [trackRef, rail] = useRailScroll(dir)
  if (!campaign || !campaign.cards?.length) return null

  const t = STR[l]
  const hot = presentation === 'hot_deals'
  const href = CAMPAIGN_PATH[presentation]
  const title = t[presentation]

  const arrows = (
    <span className="pm-rail__arrows">
      <button type="button" className="pm-arrow" onClick={() => rail.page(-1)} disabled={rail.atStart} aria-label={t.prev}>
        <Chevron flip={dir === 'ltr'} />
      </button>
      <button type="button" className="pm-arrow" onClick={() => rail.page(1)} disabled={rail.atEnd} aria-label={t.next}>
        <Chevron flip={dir === 'rtl'} />
      </button>
    </span>
  )

  const cards = (
    <div className="pm-rail__track" ref={trackRef}>
      {campaign.cards.map((card, i) => (
        <div className="pm-rail__cell" key={card.id}>
          <DealCard card={card} lang={l} eager={i < 2} />
        </div>
      ))}
    </div>
  )

  if (hot) {
    return (
      <section className={`pm-rail pm-rail--hot${bleed ? ' pm-rail--bleed' : ''}`} dir={dir} lang={l} aria-label={title}>
        <div className="pm-hot">
          <div className="pm-hot__head">
            <FlameArt className="pm-hot__art" />
            <Embers />
            <h2 className="pm-hot__title">
              <span className="pm-hot__mark" aria-hidden="true">
                <FlameIcon size={16} />
              </span>
              {title}
            </h2>
            {campaign.max_discount > 0 ? <p className="pm-hot__big">{t.upTo(campaign.max_discount)}</p> : null}
            <p className="pm-hot__sub">{t.count(campaign.count)}</p>
            <div className="pm-hot__time">
              <span className="pm-hot__ends">{t.ends}</span>
              <Countdown endsAt={campaign.ends_at} offset={offset} lang={l} />
            </div>
          </div>
          <div className="pm-hot__cards">{cards}</div>
          <div className="pm-hot__foot">
            <Link className="pm-btn pm-btn--on-hot" href={href}>
              {t.allCount(campaign.count)}
              <Chevron flip={dir === 'rtl'} size={16} />
            </Link>
            {arrows}
          </div>
        </div>
      </section>
    )
  }

  return (
    <section className={`pm-rail pm-rail--flash${bleed ? ' pm-rail--bleed' : ''}`} dir={dir} lang={l} aria-label={title}>
      <div className="pm-flash">
        <BoltArt className="pm-flash__art" />
        <header className="pm-flash__head">
          <span className="pm-flash__mark" aria-hidden="true">
            <BoltIcon size={20} />
          </span>
          <h2 className="pm-flash__title">{title}</h2>
          <span className="pm-flash__time">
            <span className="pm-flash__ends">{t.ends}</span>
            <Countdown endsAt={campaign.ends_at} offset={offset} lang={l} />
          </span>
          <span className="pm-flash__tools">
            <Link className="pm-btn pm-btn--glass" href={href}>
              {t.allCount(campaign.count)}
              <Chevron flip={dir === 'rtl'} size={16} />
            </Link>
            {arrows}
          </span>
        </header>
        {cards}
        {/* phones: "View all" is a bar under the cards (the head keeps one line: the mark, the name, the time) */}
        <Link className="pm-btn pm-btn--glass pm-flash__all" href={href}>
          {t.allCount(campaign.count)}
          <Chevron flip={dir === 'rtl'} size={16} />
        </Link>
      </div>
    </section>
  )
}
