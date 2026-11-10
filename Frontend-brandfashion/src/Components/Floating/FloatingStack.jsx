'use client'
import { useSyncExternalStore } from 'react'
import { createPortal } from 'react-dom'
import { usePathname } from 'next/navigation'
import { isFloatHidden } from '../../lib/floating'
import './floating.css'

// THE ONE CORNER FOR EVERYTHING THAT FLOATS (the developer's instruction, 2026-11-07). A TWIN.
//
// <FloatingHost/>   mounted once in the (main) layout: an EMPTY fixed column in the thumb's corner (inline-end, bottom),
//                   lifted over a shop's own bottom bar by `--pm-float-lift`. It is in the server HTML — empty, so it
//                   shifts nothing and costs nothing — and it is the containing block of every floating element, so
//                   two of them STACK (a column with a gap) instead of landing on each other.
// <FloatingSlot/>   wraps a floating element: it renders NOTHING on the server and in the hydration render (a portal
//                   needs the browser, and the server's HTML must stay the hydration render's — React #418), and from
//                   the first client render after hydration it portals its child into the host — unless the page is
//                   one where no floating element is drawn (src/lib/floating.js: the cart, the checkout, every product
//                   page, and the element's own exceptions). `order` is its place in the column: lower = higher up.
//
// Without a host (a route outside the (main) layout) a slot renders nothing: a floating element is never loose.

const noop = () => () => {}
const useMounted = () => useSyncExternalStore(noop, () => true, () => false)

export const FLOATING_HOST_ID = 'wz-floating'

export function FloatingHost() {
  return <div id={FLOATING_HOST_ID} className="wz-floating" data-floating-host="" />
}

export function FloatingSlot({ name, order = 10, children }) {
  const pathname = usePathname()
  const mounted = useMounted()
  if (!mounted || isFloatHidden(name, pathname)) return null
  const host = document.getElementById(FLOATING_HOST_ID)
  if (!host) return null
  return createPortal(
    <div className="wz-floating__item" data-float={name} style={{ order }}>
      {children}
    </div>,
    host,
  )
}
