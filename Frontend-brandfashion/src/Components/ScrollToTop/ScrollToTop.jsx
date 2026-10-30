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
    window.scrollTo(0, 0)
    const main = document.getElementById('main-content')
    if (main) main.focus()
  }, [pathname])

  return null
}

export default ScrollToTop
