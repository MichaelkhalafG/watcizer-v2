'use client'
import { aisleTint } from '@/src/lib/aisleTint'
import { txt } from '@/src/lib/cardData'
import { localizeHref } from '@/src/utils/localePath'
import BfRail from '@/src/Components/Listing/BfRail'
import './NotFoundView.css'

const STR = {
  en: { title: 'This page has moved or never existed', lead: 'Search for it, or start from one of these.', search: 'Search products', go: 'Search', sale: 'Sale', deals: 'Deals' },
  ar: { title: 'هذه الصفحة نُقلت أو لم تكن موجودة أصلاً', lead: 'ابحث عنها، أو ابدأ من هنا.', search: 'ابحث عن المنتجات', go: 'بحث', sale: 'تخفيضات', deals: 'تخفيضات' },
}

// The 404's body (spec §10.8): a search field that opens the listing's search, the families as chips on their own
// aisle tints plus Sale (four), and the deals as a rail — so the shopper always has a next step.
export default function NotFoundView({ lang = 'en', categories = [], deals = [] }) {
  const t = STR[lang] ?? STR.en
  const L = (href) => localizeHref(href, lang)
  return (
    <div className="bf-404" dir={lang === 'ar' ? 'rtl' : 'ltr'} lang={lang}>
      <div className="bf-404__inner">
        <h1 className="bf-404__title">{t.title}</h1>
        <p className="bf-404__lead">{t.lead}</p>
        <form className="bf-404__search" action={L('/listing')} method="get" role="search">
          <label className="bf-404__label" htmlFor="bf-404-q">{t.search}</label>
          <input id="bf-404-q" name="q" type="search" className="bf-404__input" placeholder={t.search} autoComplete="off" />
          <button type="submit" className="bf-404__go">{t.go}</button>
        </form>
        <ul className="bf-404__chips">
          {categories.map((c) => (
            <li key={c.id}>
              <a className="bf-404__chip" href={L(`/category/${c.slug}`)} style={{ background: `var(--wz-${aisleTint(c.family)})` }}>
                {txt(c.name, lang)}
              </a>
            </li>
          ))}
          <li>
            <a className="bf-404__chip" href={L('/listing?sale=1')} style={{ background: 'var(--wz-tint-leather)' }}>
              {t.sale}
            </a>
          </li>
        </ul>
      </div>
      {deals.length >= 2 ? <BfRail title={t.deals} cards={deals} lang={lang} seeAllHref="/listing?sale=1&discount=30&inStock=1" tint="none" className="bf-404__rail" /> : null}
    </div>
  )
}
