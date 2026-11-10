'use client'
import Link from '../LocaleLink'
import { pictureSources, optimized, optimizedSet, CARD_MAX } from '../../utils/picture'
import { barOf, dealLineOf, livePrice, lowStockText, money, payOf, savedOn, txt } from '../../lib/campaign'
import { useLivePrices } from '../../Hooks/queries/usePromo'

// One product of a campaign (Part 2, 2026-11-05). A TWIN: it draws core's v2 card and nothing else, so both
// shops use the same file and take their look from the --pm-* tokens in their own globals.css.
//
// REAL DATA ONLY. The rating shows when the product has ratings. The bar shows when core measured it (the
// units at the campaign's start and the units the ledger says were sold); a card without that measurement
// has no bar — it is not replaced by a made-up one. "Left" is the stock on the shelf right now.
//
// THE BAR AND THE LABEL NEVER CONTRADICT (the developer's review, 2026-11-05 — a card read "50% sold" and
// "Sold out" at once). One rule (lib/campaign.js `barOf`, core's `state`):
//   • a unit can still be bought           → "N sold" (units since the last restock; the bar is N of the
//                                            shelf after it, never full) beside "N left" (2026-11-10)
//   • every unit of the campaign was SOLD  → a full bar beside "Sold out"
//   • the stock reached zero another way   → "Sold out" and NO bar: the card claims no share the deal did not sell
//
// NO EMPTY BAR (Michael, 2026-11-10, lib/campaign.js `dealLineOf`): nothing sold since the restock → a "New deal"
// badge instead of a bar at 0; at most LOW_STOCK (5) units left → the stock line ("Only 3 left", Arabic counted),
// with the badge or as the bar's caption. Every state takes the bar's own height: nothing moves when one changes.

const STR = {
  en: {
    off: (n) => `${n}% off`,
    save: (x) => `Save ${x}`,
    sold: (n) => `${n} sold`,
    left: (n) => (n <= 10 ? `Only ${n} left` : `${n} left`),
    soldOut: 'Sold out',
    allSold: 'All sold',
    fresh: 'New deal',
    rated: (avg, n) => `Rated ${avg} out of 5 by ${n}`,
  },
  ar: {
    off: (n) => `خصم ${n}%`,
    save: (x) => `وفّر ${x}`,
    sold: (n) => `بيع ${n}`,
    left: (n) => (n <= 10 ? `باقي ${n} فقط` : `متبقي ${n}`),
    soldOut: 'نفدت الكمية',
    allSold: 'بيعت كلها',
    fresh: 'عرض جديد',
    rated: (avg, n) => `التقييم ${avg} من 5 (${n})`,
  },
}

export default function DealCard({ card, lang = 'en', blend = false, sizes = '(max-width: 599px) 44vw, 220px', eager = false }) {
  const t = STR[lang === 'ar' ? 'ar' : 'en']
  const title = txt(card.title, lang)
  // the price as it holds now: a campaign price follows its campaign to the second (2026-11-10)
  const livePriceMap = useLivePrices()
  const price = livePrice(card.price || {}, card.id, livePriceMap) || {}
  const pay = payOf(price)
  const saved = savedOn(price)
  const bar = barOf(card.campaign)
  const inStock = card.stock?.in_stock !== false
  const rating = Number(card.rating?.count) > 0 ? card.rating : null
  const sources = pictureSources(card.image?.srcset, CARD_MAX)
  // no recorded rendition: the optimiser's copy at the card's size, never the 1200 px master
  const plain = !sources.avif && !sources.webp
  // one answer for "can it be bought" and what the line under the price says: the card's own in-stock flag AND the
  // bar's state (units that exist but cannot be sold on this storefront are not "left")
  const line = dealLineOf(bar, inStock)
  const allSold = line.kind === 'all_sold'
  const lowText = line.low ? lowStockText(line.low, lang) : null
  const soldText = line.kind === 'bar' ? t.sold(line.sold) : t.allSold
  const leftText = line.kind === 'bar' ? lowText || t.left(line.left) : t.soldOut

  return (
    <article className={`pm-card${inStock && bar?.state !== 'unavailable' && !allSold ? '' : ' pm-card--out'}`}>
      <Link className="pm-card__link" href={`/product/${card.slug}`} prefetch={false}>
        <span className={`pm-card__media${blend ? ' pm-card__media--blend' : ''}`}>
          <picture>
            {sources.avif ? <source type="image/avif" srcSet={sources.avif} sizes={sizes} /> : null}
            {sources.webp ? <source type="image/webp" srcSet={sources.webp} sizes={sizes} /> : null}
            {/* the API's own renditions, on the asset host: a plain <img> inside the <picture> */}
            <img
              src={plain ? optimized(card.image?.src) : card.image?.src}
              srcSet={plain ? optimizedSet(card.image?.src) : undefined}
              alt={title}
              width={card.image?.width || 800}
              height={card.image?.height || 800}
              loading={eager ? 'eager' : 'lazy'}
              decoding="async"
            />
          </picture>
          {price.has_sale && price.discount_pct > 0 ? <span className="pm-card__off">{t.off(price.discount_pct)}</span> : null}
        </span>
        <span className="pm-card__body">
          {card.brand?.name ? (
            <span className="pm-card__brand" lang="en">
              {txt(card.brand.name, 'en')}
            </span>
          ) : null}
          <span className="pm-card__title">{title}</span>
          {rating ? (
            <span className="pm-card__rating" role="img" aria-label={t.rated(Number(rating.avg).toFixed(1), rating.count)}>
              <svg viewBox="0 0 20 20" width="13" height="13" aria-hidden="true">
                <path fill="currentColor" d="m10 1.6 2.6 5.3 5.8.8-4.2 4.1 1 5.8L10 14.9l-5.2 2.7 1-5.8L1.6 7.7l5.8-.8z" />
              </svg>
              <span aria-hidden="true">
                {Number(rating.avg).toFixed(1)} <span className="pm-card__rating-n">({rating.count})</span>
              </span>
            </span>
          ) : null}
          <span className="pm-card__price">
            <span className="pm-card__now">{money(pay, lang)}</span>
            {price.has_sale ? <s className="pm-card__was">{money(price.amount, lang)}</s> : null}
          </span>
          {saved > 0 ? <span className="pm-card__save">{t.save(money(saved, lang))}</span> : null}
          {line.kind === 'new' ? (
            // the bar's own frame: its track's room kept empty, the badge in the caption's place
            <span className="pm-bar pm-bar--new" role="img" aria-label={lowText ? `${t.fresh}, ${lowText}` : t.fresh}>
              <span className="pm-bar__track pm-bar__track--room" aria-hidden="true" />
              <span className="pm-bar__text" aria-hidden="true">
                <span className="pm-bar__new">{t.fresh}</span>
                <span className={`pm-bar__left${lowText ? ' pm-bar__left--low' : ''}`}>{lowText || '\u00a0'}</span>
              </span>
            </span>
          ) : line.kind === 'bar' || allSold ? (
            <span className="pm-bar" role="img" aria-label={`${soldText}, ${leftText}`}>
              <span className="pm-bar__track" aria-hidden="true">
                <span className="pm-bar__fill" style={{ inlineSize: `${line.kind === 'bar' ? line.pct : 100}%` }} />
              </span>
              <span className="pm-bar__text" aria-hidden="true">
                <span>{soldText}</span>
                <span className={`pm-bar__left${lowText ? ' pm-bar__left--low' : ''}`}>{leftText}</span>
              </span>
            </span>
          ) : line.kind === 'gone' ? (
            <span className="pm-card__gone">{t.soldOut}</span>
          ) : null}
        </span>
      </Link>
    </article>
  )
}
