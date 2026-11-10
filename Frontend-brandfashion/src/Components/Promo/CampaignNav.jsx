'use client'
import { usePathname } from 'next/navigation'
import { useRouter } from '../../Hooks/useLocaleRouter'
import { useUIStore } from '../../Store/uiStore'
import { barePath } from '../../utils/localePath'
import { useHotDealsMode, useLiveCampaign, useEffectsAfterLoad, useCampaignStart } from '../../Hooks/queries/usePromo'
import { CAMPAIGN_PATH } from '../../lib/campaign'
import { FloatingSlot } from '../Floating/FloatingStack'
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
//   "float"  PHONES: a pill in the page's FLOATING CORNER (FloatingStack, 2026-11-07) — reachable with one thumb on
//            any phone, always in sight, outside the page's flow, and stacked with any other floating element instead
//            of over it. The corner itself is not drawn on the cart, the checkout and every product page (seen live:
//            the pill sat on the product page's sticky "Add to cart" bar and hid the button); the MODE is untouched
//            there — the header's switch stays. It replaced a bar under the header, which the review rejected.

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
  const { offset, serverNow } = useLiveCampaign('hot_deals')
  // a sale that starts while this page is open is asked for at its first second (one timer a page: the top copy)
  useCampaignStart(variant === 'top')
  useEffectsAfterLoad()

  if (!mode.available) return null

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
          <Countdown endsAt={mode.campaign.ends_at} offset={offset} now={serverNow} lang={lang} variant="inline" />
        </span>
        <span className="pm-switch__track" aria-hidden="true">
          <span className="pm-switch__thumb" />
        </span>
      </button>
    </div>
  )
  // the floating copy arrives after hydration, into the page's one corner (nothing in the server HTML, so nothing shifts)
  return variant === 'float' ? (
    <FloatingSlot name="hot-deals" order={20}>
      {control}
    </FloatingSlot>
  ) : (
    control
  )
}
