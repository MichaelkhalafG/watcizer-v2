import { getServerV2Menu } from '@/src/lib/serverV2'
import { getServerTables } from '@/src/lib/serverCatalog'
import { requestLang } from '@/src/lib/requestLang'
import { localizeHref } from '@/src/utils/localePath'
import { txt } from '@/src/lib/campaign'
import { calmCase, footerDirectoryOf } from '@/src/lib/menuLinks'
import { displayName } from '@/src/lib/listingWords'
import DirectoryToggle from './DirectoryToggle'
import './SiteDirectory.css'

// THE SHOP'S DIRECTORY, IN THE SERVER HTML (2026-11-14, SEO Phase 1 + Search Console). A TWIN, a SERVER component.
//
// Google's render of a product page could not load the menu: /api/v2/{shop}/menu is closed to crawlers on the API
// host (robots.txt, on purpose), and the mega menu draws its links only on a hover or a tap (the INP pass) — so no
// crawled page linked 24 of Watchizer's 29 brand pages, 17 of its 21 sub-categories (2026-11-13 crawl). This block
// renders every category, its sub-categories that have a page and products, and the brands core lists for it, as
// plain <a href> links in the page's language, from core's menu (cached five minutes here, cached in core) — nothing
// when core cannot answer.
//
// PART OF THE FOOTER (Michael, 2026-11-17): it sits on the footer's own ground (the shop's Footer.css gives the
// colours), smaller and calmer than the footer's columns; on a phone it is one row that opens on a tap
// (DirectoryToggle — the links stay in the HTML while it is closed). Category names take one casing (calmCase:
// "SOCKS" → "Socks"); the brands are core's `directory_brands` only: indexable brand pages the search-exposure rules
// leave alone — no Generic, no Rolex, no brand whose products are all kept from search. The main menu is not this.
export default async function SiteDirectory() {
  const [menu, tables] = await Promise.all([
    getServerV2Menu().then((body) => body?.data || null, () => null),
    getServerTables().catch(() => null),
  ])
  const { categories, brands } = footerDirectoryOf(menu, tables)
  if (!categories.length && !brands.length) return null
  const { urlLang: lang } = await requestLang()
  const t = lang === 'ar' ? { label: 'تسوّق حسب الفئة والماركة', brands: 'الماركات' } : { label: 'Shop by category and brand', brands: 'Brands' }
  const link = (href) => localizeHref(href, lang)
  const name = (n) => calmCase(txt(n, lang))

  return (
    <nav className="wz-dir" aria-label={t.label} dir={lang === 'ar' ? 'rtl' : 'ltr'} lang={lang}>
      <DirectoryToggle label={t.label} bodyId="wz-dir-body">
        <div className="wz-dir__inner">
          {categories.map((c) => (
            <div className="wz-dir__col" key={c.href}>
              <a className="wz-dir__head" href={link(c.href)}>{name(c.name)}</a>
              {c.children.length ? (
                <ul className="wz-dir__list">
                  {c.children.map((s) => (
                    <li key={s.href}><a href={link(s.href)}>{name(s.name)}</a></li>
                  ))}
                </ul>
              ) : null}
            </div>
          ))}
          {brands.length ? (
            <div className="wz-dir__col wz-dir__col--brands">
              <p className="wz-dir__head">{t.brands}</p>
              <ul className="wz-dir__list wz-dir__list--brands">
                {brands.map((b) => (
                  <li key={b.href}><a href={link(b.href)} lang="en">{displayName(txt(b.name, 'en'))}</a></li>
                ))}
              </ul>
            </div>
          ) : null}
        </div>
      </DirectoryToggle>
    </nav>
  )
}
