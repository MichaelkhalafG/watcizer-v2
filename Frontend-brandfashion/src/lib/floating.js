/*
 * FLOATING ELEMENTS — the one rule for every button, pill or chip fixed over the page (the developer's instruction,
 * 2026-11-07). A TWIN (both storefronts).
 *
 * Seen live on a phone: the Hot Deals switch sat on the product page's sticky "Add to cart" bar and hid the button.
 * So, for EVERY floating element — the Hot Deals switch, the back-to-top button, the wheel's button, whatever comes
 * next:
 *
 *   • it is NOT shown at all on the cart, the checkout and every product page (phone and desktop): those pages have
 *     bottom controls of their own (the sticky buy bar, the cart's bar, the checkout's button), and a floating thing
 *     has nothing to add there;
 *   • everywhere else the floating elements share ONE corner stack (FloatingStack.jsx) and never overlap each other
 *     or a page's own controls;
 *   • the wheel's page hides the wheel's own button (the page IS the wheel).
 *
 * The Hot Deals MODE itself is untouched: only its floating switch is hidden; the header's switch stays.
 */

import { barePath } from '../utils/localePath'

/** Path prefixes (English form, no /ar) where no floating element is drawn. */
export const FLOAT_HIDDEN_PREFIXES = [
  '/cart', // the cart and its recovery page
  '/checkout',
  '/product/', // a product page (slug)
  '/products/', // a product page (legacy id)
  '/offer/', // an offer's product page
  '/order-confirmation',
]

/** Paths hidden only for ONE floating element, by its name. */
export const FLOAT_HIDDEN_FOR = {
  // the wheel's page is the wheel: no button pointing at itself
  wheel: ['/spin'],
}

/** Is `pathname` (any language) a page where no floating element may be drawn? */
export function isFloatHiddenPath(pathname) {
  const path = barePath(String(pathname || '/'))
  return FLOAT_HIDDEN_PREFIXES.some((prefix) => (prefix.endsWith('/') ? path.startsWith(prefix) : path === prefix || path.startsWith(prefix + '/') || path.startsWith(prefix + '?')))
}

/** Is `pathname` a page where the floating element `name` is hidden — by the common rule, or by its own list? */
export function isFloatHidden(name, pathname) {
  if (isFloatHiddenPath(pathname)) return true
  const path = barePath(String(pathname || '/'))
  return (FLOAT_HIDDEN_FOR[name] || []).some((p) => path === p || path.startsWith(p + '/') || path.startsWith(p + '?'))
}
