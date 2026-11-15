/*
 * "Reserve online, pick up in store" (click & collect, 2026-11-14) — the storefront's side of core's
 * GET /api/v2/{code}/pickup: whether this shop offers it right now, its stores, how long an order is held, and the two
 * ready windows (our stock / sourced first). Core decides everything; this file only reads its answer. A TWIN
 * (Frontend-next and Frontend-brandfashion, identical).
 */

/** An answer that says "not offered", for a failed or missing read. */
export const PICKUP_OFF = Object.freeze({ enabled: false, stores: [], hold_hours: 48, windows: null })

/** Core's answer, read defensively: anything malformed reads as "not offered". */
export function pickupFrom(body) {
  const d = body && typeof body === 'object' && 'data' in body ? body.data : body
  if (!d || typeof d !== 'object' || !d.enabled || !Array.isArray(d.stores) || d.stores.length === 0) return PICKUP_OFF
  return {
    enabled: true,
    stores: d.stores.filter((s) => s && Number.isInteger(s.id)),
    hold_hours: Number(d.hold_hours) || 48,
    windows: d.windows || null,
  }
}

/** The text in the page's language: `{ en, ar }` → one string. */
export const inLang = (value, isRTL) =>
  value && typeof value === 'object' ? (isRTL ? value.ar || value.en : value.en || value.ar) || '' : value || ''

/**
 * The ready window the cart promises: the slowest line decides — a line sourced first (Market) makes the whole order
 * wait for it, exactly as core records it on the reservation.
 */
export function readyWindowFor(items, windows) {
  if (!windows) return null
  const sourced = (items || []).some((i) => String(i?.type_stock ?? i?.bucket ?? '').toLowerCase() === 'market')
  return (sourced ? windows.market : windows.express) || null
}

/** "Arkadia Mall · Heliopolis" — the stores' names in the page's language. */
export const storeNames = (pickup, isRTL) => (pickup?.stores || []).map((s) => inLang(s.name, isRTL)).join(' · ')
