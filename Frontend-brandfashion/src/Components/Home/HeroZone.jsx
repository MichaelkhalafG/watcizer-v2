'use client'
import BannerHero, { BannerLink } from './BannerHero'
import { optimizedWide } from '@/src/utils/picture'
import './homeZone.css'

// The hero zone in BANNERS mode (the twelve updates, Part 3.1, 2026-11-05). A TWIN.
//
// The large hero on the leading side — the same BannerHero as before, untouched — and two banner slots
// stacked on the trailing side. The two slots are their own things on the dashboard's Banners screen
// (placements "side, top" and "side, bottom"): they are not slides of the hero and never rotate.
//
// BOTH SLOTS EMPTY → this returns the hero alone, at full width, exactly as the page drew it before the
// slots existed. One slot filled → that banner takes the whole side column.
//
// On a phone the side banners go UNDER the hero, side by side (or one, full width) — as 4 : 3 tiles there, not
// 1.92 : 1 slivers. A slot may carry a picture cropped for the phone (`mobile_image`, the dashboard's "Phone only"
// banner of that slot): it is what a phone downloads and shows; a slot with one picture shows it on every screen.
// A slot whose only banner is for one device is not drawn on the other (the same classes the hero's slides use).

const altOf = (banner, lang) => {
  const alt = banner?.alt
  if (!alt) return ''
  if (typeof alt === 'string') return alt
  return alt[lang] ?? alt.en ?? Object.values(alt)[0] ?? ''
}

const SLOT = { en: (n) => `Side banner ${n}`, ar: (n) => `بانر جانبي ${n}` }
const SLOT_SIZES = '(min-width: 1440px) 440px, (min-width: 1024px) 32vw, 50vw'

export default function HeroZone({ banners, side = [], lang = 'en', dir = 'ltr' }) {
  const slots = (Array.isArray(side) ? side : []).filter((b) => b && b.image?.src)
  if (slots.length === 0) return <BannerHero banners={banners} lang={lang} dir={dir} />

  return (
    <div className={`hz hz--${slots.length}`} dir={dir}>
      <div className="hz__main">
        {/* beside the side slots the large banner is about two thirds of the page's column (homeZone.css) */}
        <BannerHero banners={banners} lang={lang} dir={dir} sizes="(min-width: 1440px) 946px, (min-width: 1024px) 67vw, 100vw" />
      </div>
      <div className="hz__side">
        {slots.map((banner, i) => {
          // at the size a slot is drawn (the performance pass, 2026-11-05): a third of the column on a computer, half
          // the screen under it on a tablet or a phone — the optimiser's copies by width, the file itself the widest
          const set = optimizedWide(banner.image.src, { widths: [320, 420, 640, 768], full: 960 })
          const phoneSet = banner.mobile_image?.src ? optimizedWide(banner.mobile_image.src, { widths: [320, 420, 640], full: 960 }) : undefined
          return (
            <BannerLink
              className={`hz__slot${banner.device === 'desktop' || banner.device === 'mobile' ? ` hz__slot--${banner.device}` : ''}`}
              key={banner.id}
              href={banner.href}
              lang={lang}
              /* a slot with no words of its own still needs a name (Lighthouse: link-name) */
              aria-label={altOf(banner, lang) || SLOT[lang === 'ar' ? 'ar' : 'en'](i + 1)}
            >
              <picture>
                {banner.mobile_image?.src ? <source media="(max-width: 599px)" srcSet={phoneSet || banner.mobile_image.src} sizes={phoneSet ? SLOT_SIZES : undefined} /> : null}
                {/* the dashboard's banner, an absolute asset-host URL — a plain <img> inside its <picture> */}
                <img src={banner.image.src} srcSet={set} sizes={set ? SLOT_SIZES : undefined} alt={altOf(banner, lang)} loading="eager" decoding="async" width="960" height="500" />
              </picture>
            </BannerLink>
          )
        })}
      </div>
    </div>
  )
}
