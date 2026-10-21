'use client'
import { useEffect, useState } from 'react'
import BfProductCard from '@/src/Components/Product/BfProductCard'
import { useRailScroll } from '@/src/Hooks/useRailScroll'
import { aisleTint, FAMILIES } from '@/src/lib/aisleTint'
import { txt } from '@/src/lib/cardData'
import { localizeHref } from '@/src/utils/localePath'
import './BfRail.css'

/*
 * A product rail (spec v2.11 §9 — the repeated blocks). Used on the home (B6) and under the PDP (B5).
 *  • header: title h2 + the target's live count when known, "See all" a 32px pill, and on desktop the two arrows
 *    that page the track (noon's affordance: the reader is TOLD there is more to the side); on phone and tablet
 *    the track shows a partial card at its edge (the peek) and scrolls by touch.
 *  • mixed-rail rule: the rail takes a family tint only when ≥ 80% of its cards share one family; else white.
 *  • short-rail rule: fewer than 4 (phone) / 6 (desktop) cards → ONE dashed "Browse all …" card on the tint;
 *    never stretched, never centred. Below 2 cards the rail is not rendered at all.
 *  • cards on a tinted rail drop their own tint (§8.3) — `ground="tint"`.
 */

const STR = {
  en: { seeAll: 'See all', browse: (f) => `Browse all ${f}`, prev: 'Scroll back', next: 'Scroll forward', products: (n) => `${n} products` },
  ar: { seeAll: 'عرض الكل', browse: (f) => `تصفّح كل ${f}`, prev: 'السابق', next: 'التالي', products: (n) => `${n} منتج` },
}
const FAMILY_WORD = {
  en: { watch: 'watches', bag: 'bags', wallet: 'wallets', fashion: 'fashion', electronics: 'electronics', perfume: 'perfumes', all: 'products' },
  ar: { watch: 'الساعات', bag: 'الحقائب', wallet: 'المحافظ', fashion: 'الأزياء', electronics: 'الإلكترونيات', perfume: 'العطور', all: 'المنتجات' },
}

/** The family ≥ 80% of the cards share, or null (a mixed rail). */
export function dominantFamily(cards) {
  if (!cards?.length) return null
  const counts = {}
  for (const c of cards) counts[c.family] = (counts[c.family] || 0) + 1
  const [fam, n] = Object.entries(counts).sort((a, b) => b[1] - a[1])[0]
  return n / cards.length >= 0.8 && FAMILIES.includes(fam) ? fam : null
}

export function useIsDesktop() {
  const [desktop, setDesktop] = useState(true)
  useEffect(() => {
    const mq = window.matchMedia('(min-width: 1024px)')
    const on = () => setDesktop(mq.matches)
    on()
    mq.addEventListener('change', on)
    return () => mq.removeEventListener('change', on)
  }, [])
  return desktop
}

/*
 * `useRailScroll` moved to `src/Hooks/useRailScroll.js` on 2026-10-20 and is RE-EXPORTED here, under
 * the name every caller in this app already imports. It had to move: the shared banner hero
 * (`src/Components/Home/BannerHero.jsx`) is a twin that runs in both storefront apps, and Watchizer
 * has no `BfRail` to import a hook from. The implementation is unchanged — see the hook's own file
 * for what the drag has to get right.
 */
export { useRailScroll }

export default function BfRail({ title, cards = [], seeAllHref = null, lang = 'en', onAdd, tint = 'auto', browseHref = null, className = '', count = null, heading = 'h2' }) {
  const desktop = useIsDesktop()
  const dir = lang === 'ar' ? 'rtl' : 'ltr'
  const [trackRef, { atStart, atEnd, page }] = useRailScroll(dir)
  if (!cards || cards.length < 2) return null
  const t = STR[lang] ?? STR.en
  const family = dominantFamily(cards)
  const tinted = tint === 'always' || (tint === 'auto' && family != null)
  const tintVar = tinted ? `var(--wz-${aisleTint(family)})` : null
  const min = desktop ? 6 : 4
  const short = cards.length < min
  const famWord = (FAMILY_WORD[lang] ?? FAMILY_WORD.en)[family || 'all']
  const browse = browseHref || seeAllHref || '/listing'
  const H = heading
  return (
    <section className={`bf-rail ${tinted ? 'is-tinted' : ''} ${className}`} style={tintVar ? { background: tintVar } : undefined} dir={dir} aria-label={title}>
      <header className="bf-rail__head">
        <H className="bf-rail__title">
          {title}
          {count != null && count > 0 ? <span className="bf-rail__count">{t.products(count)}</span> : null}
        </H>
        <div className="bf-rail__tools">
          {seeAllHref ? <a className="bf-rail__all" href={localizeHref(seeAllHref, lang)}>{t.seeAll}</a> : null}
          {cards.length > min ? (
            <>
              <button type="button" className="bf-rail__arrow" onClick={() => page(-1)} disabled={atStart} aria-label={t.prev}>
                <Chevron flip={dir === 'ltr'} />
              </button>
              <button type="button" className="bf-rail__arrow" onClick={() => page(1)} disabled={atEnd} aria-label={t.next}>
                <Chevron flip={dir === 'rtl'} />
              </button>
            </>
          ) : null}
        </div>
      </header>
      <div className="bf-rail__track" ref={trackRef}>
        {cards.map((c) => (
          <BfProductCard key={c.id} product={c} lang={lang} ground={tinted ? 'tint' : 'white'} href={localizeHref(`/product/${c.slug}`, lang)} onAdd={onAdd} />
        ))}
        {short ? (
          <a className="bf-rail__browse" href={localizeHref(browse, lang)}>
            {t.browse(famWord)}
          </a>
        ) : null}
      </div>
    </section>
  )
}

function Chevron({ flip = false }) {
  return (
    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" style={flip ? { transform: 'scaleX(-1)' } : undefined}>
      <path d="m9 6 6 6-6 6" />
    </svg>
  )
}

export { txt }
