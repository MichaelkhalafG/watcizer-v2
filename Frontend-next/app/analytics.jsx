'use client'
import Script from 'next/script'
import { useEffect } from 'react'
import { usePathname } from 'next/navigation'
import { META_PIXEL_IDS } from '@/src/lib/env'

/*
 * ── Meta (Facebook) + TikTok pixels ────────────────────────────────────────────────────────────
 *
 * The snippet Meta hands you is written for a page that reloads on every navigation. Pasted into
 * a Next.js layout it is wrong in three ways, and each is fixed here rather than worked around:
 *
 *  1. PAGEVIEW FIRES ONCE, ON THE FIRST LOAD ONLY. Every click after that is a client-side route
 *     change; the layout never re-runs, so Meta sees a shop where nobody browses. `PageViewOnRoute`
 *     below fires one PageView per pathname change.
 *
 *  2. IT CAN INITIALISE TWICE. The snippet's own `if (f.fbq) return` only guards the stub — it does
 *     not guard `fbq('init')`, and a second init doubles every event after it. `__wzMetaInit` is
 *     the guard that actually matters; `<Script id>` keeps Next from injecting the tag twice.
 *
 *  3. THE ID IS HARD-CODED. It is public either way, but pasted into markup it takes a code edit,
 *     a build and a deploy to change or switch off. The ids come from NEXT_PUBLIC_META_PIXEL_ID,
 *     and an empty list renders nothing at all — no script, no beacon, no events.
 *
 * ── ONE OR MORE PIXELS, ONE EVENT EACH TIME ──────────────────────────────────────
 *
 * NEXT_PUBLIC_META_PIXEL_ID is a comma-separated LIST, so two pixels can run side by side and either
 * can be dropped by editing one env line. Today it carries ONE (2026-10-10: the incumbent
 * 1611910119460872 was removed; .env.production says how to put it back).
 *
 * Every id is initialised; NOTHING ELSE in the codebase changes. `fbq('track', ...)` already
 * delivers to every initialised pixel — that is how fbevents.js works, and `trackSingle` is what
 * you would reach for to target just one. So there is exactly ONE track call per action, and no
 * loop anywhere over the ids: adding a pixel must never become adding a firing site.
 *
 * WHAT THE PIXEL HELPER SHOWS, so nobody reads it as a bug: each event appears ONCE PER PIXEL —
 * two "PageView" rows, two "AddToCart" rows — because there are two beacons, one per pixel id.
 * The rows carry DIFFERENT pixel ids. A genuine double-fire shows the SAME id twice; that is the
 * only reading that means something is wrong.
 *
 * The init guard stays SINGLE for the same reason — one flag covering all the inits, not one per
 * pixel — and so does the persisted Purchase dedupe in src/scripts/pixels.js, which is keyed on
 * the order number and knows nothing about how many pixels are listening.
 *
 * PageView is fired for the FIRST page by the bootstrap script and for every page after it by the
 * effect, which is why `lastPath` starts unset: the first pathname it sees is the one the snippet
 * already counted. Doing it the other way round — no PageView in the snippet, all of them from the
 * effect — loses the landing view whenever the effect runs before `afterInteractive` executes.
 *
 * Keyed on PATHNAME, not on the query string: `/listing?brand=x` is the same page with a different
 * filter, and counting each filter tick as a page view inflates every landing-page report. What
 * happens *within* a page is carried by the e-commerce events in src/scripts/pixels.js.
 */

// Module scope, not a ref: this survives StrictMode's double-mount in development and a Fast
// Refresh remount, both of which would otherwise fire a PageView for a page nobody navigated to.
let lastPath = null

function PageViewOnRoute() {
  const pathname = usePathname()

  useEffect(() => {
    if (lastPath === null) {
      lastPath = pathname // the bootstrap script already counted this one
      return
    }
    if (lastPath === pathname) return
    lastPath = pathname
    // Both stubs queue calls made before their library finishes loading, so an early
    // call is delayed, never dropped.
    window.fbq?.('track', 'PageView')
    window.ttq?.page?.()
  }, [pathname])

  return null
}

export default function Analytics() {
  return (
    <>
      {META_PIXEL_IDS.length > 0 ? (
        <>
          <Script id="fb-pixel" strategy="afterInteractive">
            {`!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');if(!window.__wzMetaInit){window.__wzMetaInit=1;${JSON.stringify(META_PIXEL_IDS)}.forEach(function(id){fbq('init',id)});fbq('track','PageView');}`}
          </Script>
          {/* Fallback for browsers with JavaScript off — one beacon per pixel, because a beacon
              URL carries exactly one id. Same 1x1 <img> Meta's own snippet uses, deliberately NOT
              next/image: it is a tracking request, not a picture, and routing it through the
              optimiser would change the URL Meta reads. */}
          <noscript>
            {META_PIXEL_IDS.map((id) => (
              // eslint-disable-next-line @next/next/no-img-element
              <img
                key={id}
                height="1"
                width="1"
                style={{ display: 'none' }}
                alt=""
                src={`https://www.facebook.com/tr?id=${id}&ev=PageView&noscript=1`}
              />
            ))}
          </noscript>
        </>
      ) : null}

      <Script id="tiktok-pixel" strategy="lazyOnload">
        {`!function(w,d,t){w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=['page','track','identify','instances','debug','on','off','once','ready','alias','group','enableCookie','disableCookie','holdConsent','revokeConsent','grantConsent'];ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e};ttq.load=function(e,n){var r='https://analytics.tiktok.com/i18n/pixel/events.js';ttq._i=ttq._i||{};ttq._i[e]=[];ttq._i[e]._u=r;ttq._t=ttq._t||{};ttq._t[e]=+new Date;ttq._o=ttq._o||{};ttq._o[e]=n||{};n=document.createElement('script');n.type='text/javascript';n.async=!0;n.src=r+'?sdkid='+e+'&lib='+t;e=document.getElementsByTagName('script')[0];e.parentNode.insertBefore(n,e)};ttq.page();ttq.load('CVC92PJC77U61UGLN4H0')}(window,document,'ttq');`}
      </Script>

      <PageViewOnRoute />
    </>
  )
}
