// Analytics events for the Meta (fbq) + TikTok (ttq) pixels.
//
// Both globals are bootstrapped in index.html — FB on DOMContentLoaded, TikTok
// on requestIdleCallback — and each stub queues calls made before its script
// finishes loading, then flushes them on load. So calling these helpers early is
// safe; nothing is dropped. Every helper fires the equivalent NATIVE event on
// BOTH platforms. Currency is always EGP.
//
// Event-name note: ViewContent and AddToCart are identical on both platforms.
// For a completed order, Meta's event is `Purchase`; TikTok's canonical purchase
// conversion is `CompletePayment` (TikTok has no `Purchase` standard event), so
// we send the correct name to each.

const CURRENCY = 'EGP'

const fb = (event, params) => {
  if (typeof window !== 'undefined' && typeof window.fbq === 'function') {
    window.fbq('track', event, params)
  }
}

const tt = (event, params) => {
  if (typeof window !== 'undefined' && window.ttq && typeof window.ttq.track === 'function') {
    window.ttq.track(event, params)
  }
}

// PDP viewed (product or offer).
export const trackViewContent = ({ id, name, value }) => {
  const v = Number(value) || 0
  const cid = id != null ? [String(id)] : []
  fb('ViewContent', {
    content_type: 'product',
    content_ids: cid,
    content_name: name,
    value: v,
    currency: CURRENCY,
  })
  tt('ViewContent', {
    contents: id != null ? [{ content_id: String(id), content_name: name }] : [],
    value: v,
    currency: CURRENCY,
  })
}

// A line added to the cart, from ANY surface (card, PDP, offer) — fired from the
// single cartStore.addItem action so every entry point is covered once.
export const trackAddToCart = ({ id, name, value, quantity = 1 }) => {
  const q = Number(quantity) || 1
  const v = Number(value) || 0
  fb('AddToCart', {
    content_type: 'product',
    content_ids: id != null ? [String(id)] : [],
    content_name: name,
    contents: id != null ? [{ id: String(id), quantity: q }] : [],
    value: v,
    currency: CURRENCY,
  })
  tt('AddToCart', {
    contents:
      id != null
        ? [{ content_id: String(id), content_name: name, quantity: q, price: q ? v / q : v }]
        : [],
    value: v,
    currency: CURRENCY,
  })
}

// Order placed (confirmation page). Deduped per orderNumber so a remount or a
// back/forward within the SPA session never double-counts a sale.
const purchased = new Set()
export const trackPurchase = ({ orderNumber, value, contents = [] }) => {
  const key = orderNumber != null ? String(orderNumber) : ''
  if (key && purchased.has(key)) return
  if (key) purchased.add(key)

  const v = Number(value) || 0
  const numItems = contents.reduce((s, c) => s + (Number(c.quantity) || 0), 0)

  fb('Purchase', {
    content_type: 'product',
    contents: contents
      .filter((c) => c.id != null)
      .map((c) => ({ id: String(c.id), quantity: Number(c.quantity) || 1 })),
    content_ids: contents.filter((c) => c.id != null).map((c) => String(c.id)),
    ...(numItems ? { num_items: numItems } : {}),
    value: v,
    currency: CURRENCY,
  })
  tt('CompletePayment', {
    contents: contents.map((c) => ({
      content_id: c.id != null ? String(c.id) : undefined,
      content_name: c.name,
      quantity: Number(c.quantity) || 1,
      price: Number(c.price) || 0,
    })),
    value: v,
    currency: CURRENCY,
  })
}
