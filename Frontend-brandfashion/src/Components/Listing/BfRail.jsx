'use client'
import { useEffect, useState } from 'react'
import BfProductCard from '@/src/Components/Product/BfProductCard'
import { aisleTint, FAMILIES } from '@/src/lib/aisleTint'
import { txt } from '@/src/lib/cardData'
import { localizeHref } from '@/src/utils/localePath'
import './BfListing.css'

/*
 * A product rail (spec v2.9 §9 — the repeated blocks). Used under the empty listing (B4), under the PDP (B5)
 * and on the home (B6).
 *  • header: title 18/800 + count, "See all" a 32px pill (never a text link).
 *  • mixed-rail rule: the rail takes a family tint only when ≥ 80% of its cards share one family; else white.
 *  • short-rail rule: fewer than 4 (phone) / 6 (desktop) cards → ONE dashed "Browse all …" card on the tint;
 *    never stretched, never centred. Below 2 cards the rail is not rendered at all.
 *  • cards on a tinted rail drop their own tint (§8.3) — `ground="tint"`.
 */

const STR = {
  en: { seeAll: 'See all', browse: (f) => `Browse all ${f} →` },
  ar: { seeAll: 'عرض الكل', browse: (f) => `تصفّح كل ${f} ←` },
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

export default function BfRail({ title, cards = [], seeAllHref = null, lang = 'en', onAdd, tint = 'auto', browseHref = null, className = '' }) {
  const desktop = useIsDesktop()
  if (!cards || cards.length < 2) return null
  const t = STR[lang] ?? STR.en
  const family = dominantFamily(cards)
  const tinted = tint === 'always' || (tint === 'auto' && family != null)
  const tintVar = tinted ? `var(--wz-${aisleTint(family)})` : null
  const min = desktop ? 6 : 4
  const short = cards.length < min
  const famWord = (FAMILY_WORD[lang] ?? FAMILY_WORD.en)[family || 'all']
  const browse = browseHref || seeAllHref || '/listing'
  return (
    <section className={`bf-rail ${tinted ? 'is-tinted' : ''} ${className}`} style={tintVar ? { background: tintVar } : undefined} dir={lang === 'ar' ? 'rtl' : 'ltr'}>
      <header className="bf-rail__head">
        <h2 className="bf-rail__title">
          {title}
          <span className="bf-rail__count">{cards.length}</span>
        </h2>
        {seeAllHref ? (
          <a className="bf-rail__all" href={localizeHref(seeAllHref, lang)}>
            {t.seeAll} <span aria-hidden="true">{lang === 'ar' ? '←' : '→'}</span>
          </a>
        ) : null}
      </header>
      <div className="bf-rail__track">
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

export { txt }
