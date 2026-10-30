/*
 * Free shipping over the storefront's threshold (2026-10-29) — core decides it (App\Domain\Shipping\FreeShipping)
 * and says so in every cart answer (`totals.shipping.free_over`); this is the same rule for the page to SHOW it and
 * to send the total the server will compute: the goods at the prices charged reach the threshold → delivery is free.
 * Compared in cents, as core compares them. A TWIN (Frontend-next and Frontend-brandfashion, identical).
 */

/**
 * @param {number|string|null|undefined} over  the threshold from the cart answer; null / 0 / missing = off
 * @param {number} subtotal                      the goods at the prices charged
 * @returns {{ over: number|null, free: boolean, remaining: number|null }}
 */
export function freeShippingOf(over, subtotal) {
  const threshold = Number(over)
  if (!Number.isFinite(threshold) || threshold <= 0) return { over: null, free: false, remaining: null }
  const free = Math.round((Number(subtotal) || 0) * 100) >= Math.round(threshold * 100)
  return { over: threshold, free, remaining: free ? 0 : Math.round((threshold - (Number(subtotal) || 0)) * 100) / 100 }
}

/** What delivery costs: 0 when free, the governorate's price otherwise (0 while no governorate is chosen). */
export function shippingCharged(cityPrice, freeShipping) {
  if (freeShipping && freeShipping.free) return 0
  return Number(cityPrice) || 0
}
