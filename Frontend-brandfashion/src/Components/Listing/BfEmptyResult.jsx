'use client'
import { useQuery } from '@tanstack/react-query'
import { FiSearch } from 'react-icons/fi'
import publicHttp from '@/src/Context/publicApi'
import { STOREFRONT_CODE } from '@/src/lib/env'
import { localizeHref } from '@/src/utils/localePath'
import BfRail from '@/src/Components/Listing/BfRail'
import './BfEmptyResult.css'

// Brand Fashion's empty result (B8, spec §21 "Empty search" / "Empty filter result", 2026-10-19). Watchizer's said
// "No products found — Try adjusting your filters" and offered only Clear all. Here: a search names its words and asks
// for the spelling to be checked (core has no spelling suggestions to offer), a filtered list offers the ONE-CHIP fix —
// remove the filter added last — then Clear all, the families and Sale as ways in, and a rail, so it is never a dead end.
const FAMILIES = [
  { href: '/category/watches', en: 'Watches', ar: 'ساعات' },
  { href: '/category/fashion', en: 'Fashion', ar: 'أزياء وإكسسوارات' },
  { href: '/category/electronics', en: 'Electronics', ar: 'إلكترونيات' },
  { href: '/listing?sale=1', en: 'Sale', ar: 'تخفيضات' },
]

export default function BfEmptyResult({ q = '', lang = 'en', lastTag = null, hasFilters = false, onClearAll }) {
  const ar = lang === 'ar'
  const { data: newIn = [] } = useQuery({
    queryKey: ['bf-empty-newin'],
    queryFn: async () => (await publicHttp.get(`v2/${STOREFRONT_CODE}/products?sort=newest&in_stock=1&per_page=12`)).data,
    staleTime: 5 * 60 * 1000,
    select: (d) => d?.data || [],
  })
  const term = (q || '').trim()
  return (
    <div className="wz-empty bf-empty">
      <FiSearch className="wz-empty-icon" aria-hidden="true" />
      <h2 className="wz-empty-title">
        {term ? (ar ? `لا نتائج لـ «${term}»` : `Nothing matches “${term}”`) : ar ? 'لا منتجات تطابق هذه الفلاتر' : 'No products match these filters'}
      </h2>
      <p className="wz-empty-sub">
        {term
          ? ar ? 'تحقّق من الكتابة، أو ابدأ من هنا:' : 'Check the spelling, or start from one of these:'
          : ar ? 'أزل آخر فلتر أضفته، أو امسحها كلها:' : 'Remove the last filter you added, or clear them all:'}
      </p>
      {!term && (lastTag || hasFilters) ? (
        <div className="bf-empty__fixes">
          {lastTag ? (
            <button type="button" className="wz-empty-btn" onClick={lastTag.onRemove}>
              {ar ? `إزالة «${lastTag.label}»` : `Remove “${lastTag.label}”`}
            </button>
          ) : null}
          {hasFilters ? (
            <button type="button" className="bf-empty__clear" onClick={onClearAll}>
              {ar ? 'مسح كل الفلاتر' : 'Clear all filters'}
            </button>
          ) : null}
        </div>
      ) : null}
      <ul className="bf-empty__chips">
        {FAMILIES.map((f) => (
          <li key={f.href}>
            <a className="bf-empty__chip" href={localizeHref(f.href, lang)}>{ar ? f.ar : f.en}</a>
          </li>
        ))}
      </ul>
      {newIn.length > 1 ? <BfRail title={ar ? 'وصل حديثاً' : 'New in'} cards={newIn} lang={lang} seeAllHref="/listing?sort=newest" tint="none" className="bf-empty__rail" /> : null}
    </div>
  )
}
