'use client'
import { useEffect, useRef } from 'react'
import { usePathname } from 'next/navigation'

function ScrollToTop() {
  const pathname = usePathname()
  const firstRun = useRef(true)

  useEffect(() => {
    // The INITIAL load is left where the visitor is (2026-10-29): this ran on hydration too, so anyone who had
    // already scrolled before the page hydrated — a phone on 4G takes seconds — was thrown back to the top. Only a
    // route CHANGE scrolls to the top, and moves keyboard focus to the main region so screen readers announce the
    // new page and focus isn't stranded on a control that just unmounted (skipped on the first run so a fresh
    // keyboard user still reaches the skip link first).
    if (firstRun.current) {
      firstRun.current = false
      return
    }
    //
    // THE FOCUS MUST NOT SCROLL (measured 2026-11-05, both shops, a computer and a phone): `main.focus()` scrolls a
    // region taller than the window to ITS top — so every new page landed one header-height down (153 px on
    // Watchizer's computer header, 53 px on its phone one), and under a sticky header that hid the top of the page:
    // the Hot Deals and Flash Sale heroes started under the bar. `preventScroll` moves the focus and leaves the page
    // at the top. Back and Forward are untouched: the browser and the router restore the old position after this
    // runs (measured: the same scrollY as at the click).
    window.scrollTo(0, 0)
    const main = document.getElementById('main-content')
    if (main) main.focus({ preventScroll: true })
  }, [pathname])

  return null
}

export default ScrollToTop
