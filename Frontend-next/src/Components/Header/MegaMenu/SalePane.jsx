'use client'
import Link from '../../LocaleLink'
import { useLiveCampaign } from '../../../Hooks/queries/usePromo'
import { CAMPAIGN_PATH, txt } from '../../../lib/campaign'
import Countdown from '../../Promo/Countdown'
import { BoltIcon, FlameIcon, Chevron } from '../../Promo/icons'

// The SALE panel of the mega menu (the developer's review, 2026-11-05). A TWIN.
//
// Everything discounted, in one place and in this order:
//
//   1. the LIVE CAMPAIGNS — Hot Deals and Flash Sale, each a card in its own identity with its real product
//      count, biggest discount and countdown; a campaign that is not running is simply not there;
//   2. ON SALE BY CATEGORY — a picture tile per top-level category that has discounted products in stock,
//      with how many and the biggest discount among them;
//   3. ON SALE BY BRAND — the brands with the most discounted products, each with its count.
//
// Every number is core's (`menu.sale`, and `/campaigns` for the two campaigns). The links that differ between
// the shops (a category's or a brand's sale listing) come from each shop's Nav through `to`.

export default function SalePane({ sale, lang, dir, to, go, t }) {
  const hot = useLiveCampaign('hot_deals')
  const flash = useLiveCampaign('flash_sale')
  const deals = [
    hot.campaign ? { key: 'hot', campaign: hot.campaign, offset: hot.offset, now: hot.serverNow, name: t.hot, icon: <FlameIcon size={20} />, href: CAMPAIGN_PATH.hot_deals } : null,
    flash.campaign ? { key: 'flash', campaign: flash.campaign, offset: flash.offset, now: flash.serverNow, name: t.flash, icon: <BoltIcon size={20} />, href: CAMPAIGN_PATH.flash_sale } : null,
  ].filter(Boolean)

  return (
    <div className="mm__sale">
      {deals.length ? (
        <ul className={`mm__deals mm__deals--${deals.length}`}>
          {deals.map((d) => (
            <li key={d.key}>
              <Link className={`mm__deal mm__deal--${d.key}`} href={d.href} data-mm-tile="" onClick={go} prefetch={false}>
                <span className="mm__deal-mark" aria-hidden="true">
                  {d.icon}
                </span>
                <span className="mm__deal-text">
                  <span className="mm__deal-name">{d.name}</span>
                  <span className="mm__deal-sub">{t.dealSub(d.campaign.count, d.campaign.max_discount)}</span>
                </span>
                <span className="mm__deal-time">
                  <span className="mm__deal-ends">{t.endsIn}</span>
                  <Countdown endsAt={d.campaign.ends_at} offset={d.offset} now={d.now} lang={lang} variant="inline" />
                </span>
                <span className="mm__deal-go" aria-hidden="true">
                  <Chevron flip={dir === 'rtl'} size={16} />
                </span>
              </Link>
            </li>
          ))}
        </ul>
      ) : null}

      {(sale?.categories || []).length ? (
        <>
          <p className="mm__chips-title">{t.saleByCategory}</p>
          <ul className="mm__tiles mm__tiles--sale">
            {sale.categories.map((c) => (
              <li key={c.id}>
                <Link className="mm__tile" href={to.saleCategory(c)} data-mm-tile="" onClick={go} prefetch={false}>
                  <span className="mm__tile-pic" aria-hidden="true">
                    {c.image?.src ? (
                      // eslint-disable-next-line @next/next/no-img-element -- category picture, asset-host URL
                      <img src={c.image.src} alt="" loading="lazy" />
                    ) : (
                      <span>{txt(c.name, lang).trim().charAt(0)}</span>
                    )}
                    {c.max_discount > 0 ? <span className="mm__tile-off">{t.off(c.max_discount)}</span> : null}
                  </span>
                  <span className="mm__tile-name">{txt(c.name, lang)}</span>
                  <span className="mm__tile-count">{t.onSale(c.count)}</span>
                </Link>
              </li>
            ))}
          </ul>
        </>
      ) : null}

      {(sale?.brands || []).length ? (
        <div className="mm__chips">
          <p className="mm__chips-title">{t.saleByBrand}</p>
          <ul>
            {sale.brands.map((b) => (
              <li key={b.id}>
                <Link className="mm__chip" href={to.saleBrand(b)} data-mm-tile="" onClick={go} prefetch={false}>
                  <span lang="en">{txt(b.name, lang)}</span>
                  <span className="mm__chip-n">{b.count}</span>
                </Link>
              </li>
            ))}
          </ul>
        </div>
      ) : null}
    </div>
  )
}
