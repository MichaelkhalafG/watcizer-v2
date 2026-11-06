'use client'
import { useEffect, useRef, useState } from 'react'
import { usePathname, useSearchParams } from 'next/navigation'
import './navProgress.css'

// The line across the top of the page while a navigation is on its way (round 2, item 19, 2026-11-05). A TWIN.
//
// WHY: measured on a production build, a page of these shops answers a click in 0.1 to 1 s — but for that time
// NOTHING on the screen says the click was taken: the old page simply stands there, and that reads as "slow" or
// "did it work?". The pages are rendered on the server per request, so the wait itself is the server's; what is
// ours is the silence. This line starts the moment an internal link is clicked and finishes when the new page is
// in — an answer within one frame, whatever the server takes.
//
// It is outside the page's flow (position: fixed, 3 px), so it shifts nothing; it is decoration (aria-hidden) —
// the page's own title and focus tell assistive tech about the navigation; and under prefers-reduced-motion it
// does not animate, it is simply there until the page arrives.
//
// What counts as a navigation: a plain left click on a same-origin <a href> that goes to a DIFFERENT path or
// query (not a hash on this page, not a download, not a new tab, not a modified click). A navigation started any
// other way (router.push from a button) gets no line — a missing line is harmless, a stuck one is not, which is
// also why it gives up by itself after eight seconds.

export default function NavProgress() {
  const pathname = usePathname()
  const search = useSearchParams()
  const [busy, setBusy] = useState(false)
  const timer = useRef(null)
  const here = `${pathname}?${search?.toString() || ''}`

  useEffect(() => {
    const onClick = (e) => {
      if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return
      const a = e.target.closest?.('a[href]')
      if (!a || a.target === '_blank' || a.hasAttribute('download')) return
      let url
      try {
        url = new URL(a.href, window.location.href)
      } catch {
        return
      }
      if (url.origin !== window.location.origin) return
      if (url.pathname === window.location.pathname && url.search === window.location.search) return
      setBusy(true)
      if (timer.current) clearTimeout(timer.current)
      timer.current = setTimeout(() => setBusy(false), 8000)
    }
    document.addEventListener('click', onClick, true)
    return () => {
      document.removeEventListener('click', onClick, true)
      if (timer.current) clearTimeout(timer.current)
    }
  }, [])

  // the new page is in: the address the router reports has changed
  const last = useRef(here)
  useEffect(() => {
    if (last.current === here) return
    last.current = here
    if (timer.current) clearTimeout(timer.current)
    setBusy(false)
  }, [here])

  return <span className={`nav-progress${busy ? ' is-busy' : ''}`} aria-hidden="true" />
}
