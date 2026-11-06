'use client'
import { useSyncExternalStore } from 'react'
import { createPortal } from 'react-dom'
import { usePathname } from 'next/navigation'
import { useRouter } from '../../Hooks/useLocaleRouter'
import { useUIStore } from '../../Store/uiStore'
import { barePath } from '../../utils/localePath'
import { useHotDealsMode, useLiveCampaign, useEffectsAfterLoad, useCampaignStart } from '../../Hooks/queries/usePromo'
import { CAMPAIGN_PATH } from '../../lib/campaign'
import Countdown from './Countdown'
import { FlameIcon } from './icons'
import './promo.css'

// THE HOT DEALS SWITCH (Part 2.4, 2026-11-05; the developer's review of the same day). A TWIN.
//
// A real switch (role="switch"). On: the shop goes into Hot Deals mode (the fire theme, listings narrowed to
// the live campaign's products) and the shopper is taken to the Hot Deals page. Off: the shop is back to normal,
// and a shopper standing on the Hot Deals page goes home. OFF it is a coal with a burning rim; ON it is alight.
// It carries the campaign's countdown and exists only while a Hot Deals campaign is live — at the campaign's end
// it leaves without a reload, and with no live campaign this renders nothing at all.
//
// THE FLASH SALE HAS NO CONTROL IN THE HEADER any more (the review): a Flash Sale is reached from its home
// section, from the Sale panel of the menu, and from its page.
//
// IT NEVER CHANGES SIZE. The countdown is drawn one character to a fixed cell (Countdown, `inline`), so the
// switch is exactly as wide at 09:59:59 as at 10:00:00 and the row it sits in never moves — a layout shift every
// second is what the first cut produced.
//
// `variant` says where this copy sits:
//   "top"    the header's top row on a computer
//   "float"  PHONES: a pill fixed in the thumb's corner of the screen — reachable with one thumb on any phone,
//            always in sight, and outside the page's flow, so it can never shift a thing. (It replaced the bar
//            under the header, which the review rejected.) Each shop lifts it over its own bottom bar with
//            `--pm-float-lift`.

// "Are we in the browser, after hydration?" without a state and an effect: false on the server and during
// hydration, true afterwards.
const noop = () => () => {}
const useMounted = () => useSyncExternalStore(noop, () => true, () => false)

const STR = {
  en: { hot: 'Hot deals', on: 'Hot Deals mode is on', off: 'Turn on Hot Deals mode' },
  ar: { hot: 'عروض ساخنة', on: 'وضع العروض الساخنة مفعّل', off: 'تفعيل وضع العروض الساخنة' },
}

export default function CampaignNav({ variant = 'top' }) {
  const language = useUIStore((s) => s.language)
  const lang = language === 'ar' ? 'ar' : 'en'
  const t = STR[lang]
  const router = useRouter()
  const pathname = usePathname()
  const mode = useHotDealsMode()
  const { offset } = useLiveCampaign('hot_deals')
  // a sale that starts while this page is open is asked for at its first second (one timer a page: the top copy)
  useCampaignStart(variant === 'top')
  const mounted = useMounted()
  useEffectsAfterLoad()

  if (!mode.available) return null
  // THE FLOATING PILL IS DRAWN ON <body>, not inside the header that mounts it: a header with a backdrop filter is
  // the containing block of everything `position: fixed` inside it, and the pill sat under the header instead of in
  // the thumb's corner (measured on Watchizer, 2026-11-05). A portal needs the browser, so the pill arrives after
  // hydration — it is outside the page's flow, so its arrival shifts nothing.
  if (variant === 'float' && !mounted) return null

  const toggle = () => {
    const next = !mode.on
    mode.set(next)
    if (next) router.push(CAMPAIGN_PATH.hot_deals)
    else if (barePath(pathname || '/') === CAMPAIGN_PATH.hot_deals) router.push('/')
  }

  const control = (
    <div className={`pm-nav pm-nav--${variant}`} dir={lang === 'ar' ? 'rtl' : 'ltr'} lang={lang}>
      <button type="button" role="switch" aria-checked={mode.on} aria-label={mode.on ? t.on : t.off} className="pm-switch" onClick={toggle}>
        <span className="pm-switch__orb" aria-hidden="true">
          <FlameIcon size={variant === 'float' ? 19 : 15} />
        </span>
        <span className="pm-switch__text">
          <span className="pm-switch__label">{t.hot}</span>
          <Countdown endsAt={mode.campaign.ends_at} offset={offset} lang={lang} variant="inline" />
        </span>
        <span className="pm-switch__track" aria-hidden="true">
          <span className="pm-switch__thumb" />
        </span>
      </button>
    </div>
  )
  return variant === 'float' ? createPortal(control, document.body) : control
}
