'use client'
import { useMemo, useState } from 'react'
import Link from '../../LocaleLink'
import { useUIStore } from '../../../Store/uiStore'
import { useLivePrices, useMenu } from '../../../Hooks/queries/usePromo'
import { livePrice, money, txt } from '../../../lib/campaign'
import { Chevron } from '../../Promo/icons'
import { STR, DEFAULT_HREFS, menuEntries } from './MegaMenu'
import './megaMenu.css'

// The mega menu ON A PHONE (the design pass, 2026-11-05). A TWIN. It lives inside each shop's menu drawer and
// replaces the drawer's text accordions with the same content the desktop mega menu shows — designed for a
// thumb, not the desktop panel squeezed:
//
//   level 1   the top level as ROWS: a picture, the name, the count, a chevron (a row is 56 px: a thumb target)
//   level 2   one entry, opened IN PLACE (the drawer does not grow a second column): a back row, "View all",
//             its sub-categories as PICTURE TILES three to a row, its brands as chips that scroll sideways,
//             and its featured product as one horizontal card
//
// Same data, same request (`/api/v2/{storefront}/menu`, asked for only once the drawer is opened), same links
// and the same real counts as the desktop menu ({@link menuEntries}). `omit` drops rail entries the shop's
// drawer already lists under its own name (its Sale link).

// The rows that are not a category have no picture to show: a tag for Brands, a spark for New arrivals, a
// percent for On sale.
const GLYPH = {
  brands: 'M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8zM7.5 7.5h.01',
  newest: 'M12 3l2.2 5.8L20 11l-5.8 2.2L12 19l-2.2-5.8L4 11l5.8-2.2z',
  sale: 'M19 5 5 19M7.5 7.5h.01M16.5 16.5h.01',
}

export default function MegaDrawer({ active = false, hrefs = {}, omit = [], onNavigate }) {
  const livePriceMap = useLivePrices()
  const language = useUIStore((s) => s.language)
  const lang = language === 'ar' ? 'ar' : 'en'
  const dir = lang === 'ar' ? 'rtl' : 'ltr'
  const t = STR[lang]
  const to = useMemo(() => ({ ...DEFAULT_HREFS, ...hrefs }), [hrefs])
  const [openKey, setOpenKey] = useState(null)
  const [asked, setAsked] = useState(false)
  if (active && !asked) setAsked(true)
  // The menu's data is in the page (item 36), so the drawer has it the moment it opens; its rows — and their
  // pictures — are built only then (the performance pass: a CLOSED drawer rendered every row on every phone load,
  // and its "lazy" pictures loaded anyway because the closed drawer sits just off the screen).
  const { data: fetched, isError, refetch } = useMenu(asked)
  const menu = asked ? fetched : null

  const entries = useMemo(() => menuEntries(menu, lang, t, to).filter((e) => !omit.includes(e.kind)), [menu, lang, t, to, omit])
  const current = openKey ? entries.find((e) => e.key === openKey) || null : null
  const go = () => {
    setOpenKey(null)
    onNavigate?.()
  }

  if (!menu) {
    return (
      <div className="md" dir={dir} lang={lang}>
        <p className="md__heading">{t.trigger}</p>
        {isError ? (
          <div className="md__state" role="status">
            <span>{t.failed}</span>
            <button type="button" className="mm__retry" onClick={() => refetch()}>
              {t.retry}
            </button>
          </div>
        ) : (
          <ul className="md__list" aria-hidden="true">
            {[0, 1, 2, 3, 4].map((i) => (
              <li key={i} className="md__row md__row--skel" />
            ))}
          </ul>
        )}
      </div>
    )
  }

  // the picture a top-level row shows: its own, else its first pictured sub-category's — never a stock image
  const picOf = (entry) => entry.node?.image?.src || (entry.node?.children || []).find((c) => c.image?.src)?.image?.src || null

  if (current && current.pane) {
    const node = current.node
    const children = node?.children || []
    const brands = current.kind === 'brands' ? menu.brands || [] : node?.brands || []
    const featured = node?.featured?.product ? node.featured : null
    const childHref = (child) => (current.kind === 'gender' ? to.genderChild(node, child) : to.child(child, node))
    return (
      <div className="md md--pane" dir={dir} lang={lang}>
        <button type="button" className="md__back" onClick={() => setOpenKey(null)}>
          <Chevron flip={dir === 'ltr'} size={18} />
          <span>{t.trigger}</span>
        </button>
        <div className="md__pane-head">
          <p className="md__pane-title">{current.label}</p>
          {current.href ? (
            <Link className="md__pane-all" href={current.href} onClick={go} prefetch={false}>
              {current.count != null ? t.products(current.count) : t.viewAll(current.label)}
              <Chevron flip={dir === 'rtl'} size={14} />
            </Link>
          ) : null}
        </div>

        {current.kind === 'brands' ? (
          <ul className="md__brands">
            {brands.map((b) => (
              <li key={b.id}>
                <Link className="md__brand" href={to.brand(b)} onClick={go} prefetch={false}>
                  <span className="md__brand-logo" aria-hidden="true">
                    {b.logo?.src ? (
                      // eslint-disable-next-line @next/next/no-img-element -- brand logo, asset-host URL
                      <img src={b.logo.src} alt="" loading="lazy" />
                    ) : (
                      <span>{txt(b.name, 'en').slice(0, 2)}</span>
                    )}
                  </span>
                  <span className="md__brand-name" lang="en">
                    {txt(b.name, lang)}
                  </span>
                  <span className="md__count">{b.count}</span>
                </Link>
              </li>
            ))}
          </ul>
        ) : (
          <>
            {children.length ? (
              <ul className="md__tiles">
                {children.map((child) => (
                  <li key={child.id}>
                    <Link className="md__tile" href={childHref(child)} onClick={go} prefetch={false}>
                      <span className="md__tile-pic" aria-hidden="true">
                        {child.image?.src ? (
                          // eslint-disable-next-line @next/next/no-img-element -- category picture, asset-host URL
                          <img src={child.image.src} alt="" loading="lazy" />
                        ) : (
                          <span>{txt(child.name, lang).trim().charAt(0)}</span>
                        )}
                      </span>
                      <span className="md__tile-name">{txt(child.name, lang)}</span>
                      <span className="md__count">{child.count}</span>
                    </Link>
                  </li>
                ))}
              </ul>
            ) : null}
            {brands.length ? (
              <div className="md__chips">
                <p className="md__chips-title">{t.brandsIn}</p>
                <ul>
                  {brands.map((b) => (
                    <li key={b.id}>
                      <Link className="md__chip" href={to.brand(b)} onClick={go} prefetch={false} lang="en">
                        {txt(b.name, lang)}
                      </Link>
                    </li>
                  ))}
                </ul>
              </div>
            ) : null}
            {featured ? (
              <Link className="md__feature" href={to.product(featured.product)} onClick={go} prefetch={false}>
                <span className="md__feature-pic" aria-hidden="true">
                  {/* eslint-disable-next-line @next/next/no-img-element -- product photo, asset-host URL */}
                  <img src={featured.product.image?.src} alt="" loading="lazy" />
                </span>
                <span className="md__feature-text">
                  <span className="md__feature-brand" lang="en">
                    {txt(featured.product.brand?.name, 'en')}
                  </span>
                  <span className="md__feature-title">{txt(featured.product.title, lang)}</span>
                  <span className="md__feature-price">{t.from(money(featured.price_from, lang))}</span>
                </span>
                {livePrice(featured.product.price, featured.product.id, livePriceMap)?.has_sale && livePrice(featured.product.price, featured.product.id, livePriceMap).discount_pct > 0 ? (
                  <span className="md__feature-off">{t.off(livePrice(featured.product.price, featured.product.id, livePriceMap).discount_pct)}</span>
                ) : null}
              </Link>
            ) : null}
          </>
        )}
      </div>
    )
  }

  return (
    <div className="md" dir={dir} lang={lang}>
      <p className="md__heading">{t.trigger}</p>
      <ul className="md__list">
        {entries.map((entry) => {
          const pic = picOf(entry)
          const inner = (
            <>
              <span className={`md__pic md__pic--${entry.kind}`} aria-hidden="true">
                {pic ? (
                  // eslint-disable-next-line @next/next/no-img-element -- category picture, asset-host URL
                  <img src={pic} alt="" loading="lazy" />
                ) : GLYPH[entry.kind] ? (
                  <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round">
                    <path d={GLYPH[entry.kind]} />
                  </svg>
                ) : (
                  <span>{entry.label.trim().charAt(0)}</span>
                )}
              </span>
              <span className="md__name">{entry.label}</span>
              {entry.tag ? <span className="md__tag">{entry.tag}</span> : null}
              {entry.count != null ? <span className="md__count">{entry.count}</span> : null}
              <span className="md__caret" aria-hidden="true">
                <Chevron flip={dir === 'rtl'} size={16} />
              </span>
            </>
          )
          return (
            <li key={entry.key}>
              {entry.pane ? (
                <button type="button" className="md__row" onClick={() => setOpenKey(entry.key)}>
                  {inner}
                </button>
              ) : (
                <Link className="md__row" href={entry.href} onClick={go} prefetch={false}>
                  {inner}
                </Link>
              )}
            </li>
          )
        })}
      </ul>
    </div>
  )
}
