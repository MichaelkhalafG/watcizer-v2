// Analytics events for the Meta (fbq) + TikTok (ttq) pixels.
//
// Both globals are bootstrapped in app/analytics.jsx, which also fires PageView on
// every route change. Each stub queues calls made before its script finishes
// loading, then flushes them on load, so calling these helpers early is safe;
// nothing is dropped. Every helper fires the equivalent NATIVE event on BOTH
// platforms. Currency is always EGP.
//
// ── WHERE EACH EVENT IS FIRED FROM, AND WHY THERE ────────────────────────────
//
//   ViewContent       ProductDetailClient, once per resolved product/offer.
//   AddToCart         cartStore, AFTER the server confirms the line — not on click.
//                     A click the API then refuses is not a cart addition, and
//                     counting it teaches the ad auction to chase people who never
//                     got the item into their basket.
//   InitiateCheckout  Checkout, once per visit to the page with a non-empty cart.
//   Purchase          OrderConfirmation — CASH ON DELIVERY ONLY.
//
// ── THE CARD-PAYMENT GAP, STATED PLAINLY ─────────────────────────────────
//
// A card order never reaches the confirmation page: Checkout hands the shopper to
// Paymob with window.location.href, and the payment callback returns them to the
// site ROOT — with `?payment_error=1` on failure and no marker at all on success.
// The browser is therefore never told which order was paid, and there is
// deliberately NO Purchase event on the card path: firing one at the Paymob
// hand-off would count every abandoned and every declined payment as a sale, and
// one such event poisons every campaign report built on it.
//
// Closing it properly means the server saying so — either Meta's Conversions API
// called from the payment callback, which is the only place that knows the payment
// cleared, or a success marker on the return URL that the storefront verifies.
// Both are a separate piece of work; until one lands, card sales are UNDER-counted
// here, never over-counted.
//
// Event-name note: ViewContent and AddToCart are identical on both platforms.
// For a completed order, Meta's event is `Purchase`; TikTok's canonical purchase
// conversion is `CompletePayment` (TikTok has no `Purchase` standard event), so
// we send the correct name to each.

const CURRENCY = 'EGP'

// ONE call per action, however many pixels are listening. `fbq('track', ...)` delivers
// to every pixel app/analytics.jsx initialised — that is fbevents.js's own behaviour,
// and `trackSingle` is what you would reach for to target just one. Do NOT loop over
// META_PIXEL_IDS here: adding a pixel must never become adding a firing site.
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

// A line added to the cart, from ANY surface (card, PDP, offer, or the drawer's
// "+"). Fired from cartStore once the server has confirmed the line, so every
// entry point is covered exactly once and a failed sync counts as nothing.
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

// Checkout started — the shopper has reached the checkout page with something in
// the cart. `value` is the MERCHANDISE subtotal: shipping is not known until a
// governorate is chosen, and it is not merchandise value in any case.
export const trackInitiateCheckout = ({ value, contents = [] }) => {
  const v = Number(value) || 0
  const numItems = contents.reduce((sum, c) => sum + (Number(c.quantity) || 0), 0)

  fb('InitiateCheckout', {
    content_type: 'product',
    content_ids: contents.filter((c) => c.id != null).map((c) => String(c.id)),
    contents: contents
      .filter((c) => c.id != null)
      .map((c) => ({ id: String(c.id), quantity: Number(c.quantity) || 1 })),
    ...(numItems ? { num_items: numItems } : {}),
    value: v,
    currency: CURRENCY,
  })
  tt('InitiateCheckout', {
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

// Order placed. CASH ON DELIVERY ONLY — see the card-payment note at the top of
// this file; the caller is responsible for not calling this for a card order.
//
// Deduped per order number, and the record is PERSISTED rather than held only in a
// Set: an in-memory guard dies with the page, so a shopper who reloads the
// confirmation page, or opens it again in a new tab, would be counted as a second
// sale. The last few order numbers are enough — nobody reloads a confirmation page
// from twenty orders ago — and storage being unavailable degrades to the
// in-memory guard rather than to a double count within the session.
const PURCHASED_KEY = 'wz_fb_purchased'
const PURCHASED_KEEP = 20
const purchased = new Set()

const alreadyPurchased = (key) => {
  if (purchased.has(key)) return true
  try {
    const raw = JSON.parse(localStorage.getItem(PURCHASED_KEY) || '[]')
    return Array.isArray(raw) && raw.includes(key)
  } catch {
    return false
  }
}

const rememberPurchased = (key) => {
  purchased.add(key)
  try {
    const raw = JSON.parse(localStorage.getItem(PURCHASED_KEY) || '[]')
    const list = (Array.isArray(raw) ? raw : []).filter((k) => k !== key)
    list.push(key)
    localStorage.setItem(PURCHASED_KEY, JSON.stringify(list.slice(-PURCHASED_KEEP)))
  } catch {
    // Private mode / storage disabled. The in-memory Set still covers this page.
  }
}

export const trackPurchase = ({ orderNumber, value, contents = [] }) => {
  const key = orderNumber != null ? String(orderNumber) : ''
  if (key && alreadyPurchased(key)) return
  if (key) rememberPurchased(key)

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
