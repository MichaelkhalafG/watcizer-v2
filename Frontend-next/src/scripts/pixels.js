// Analytics events for GA4 (gtag), the Meta pixel (fbq) and the TikTok pixel (ttq). A TWIN.
//
// All three globals are bootstrapped in app/analytics.jsx, which also fires PageView on
// every route change, and each is rendered only when ITS id is configured (env.js) — a
// storefront with no ids loads no script and sends nothing, and the helpers below are
// then no-ops. Each stub queues calls made before its script finishes loading, then
// flushes them on load, so calling these helpers early is safe; nothing is dropped.
// Every helper fires the equivalent NATIVE event on every configured platform. Currency
// is always EGP.
//
// ── WHERE EACH EVENT IS FIRED FROM, AND WHY THERE ────────────────────────────
//
//   GA4 view_item · ViewContent            ProductDetailClient, once per resolved product/offer.
//   GA4 view_item_list                     ListingClient, once per result set (the page, the sort,
//                                          the filters), the first 50 cards.
//   GA4 add_to_cart · AddToCart            cartStore, AFTER the server confirms the line — not on
//                                          click. A click the API then refuses is not a cart
//                                          addition, and counting it teaches the ad auction to
//                                          chase people who never got the item into their basket.
//   GA4 remove_from_cart                   cartStore, when a line is removed or its quantity cut.
//                                          GA4 only: neither pixel has a standard event for it.
//   GA4 view_cart                          Cart, once per visit with a non-empty cart. GA4 only.
//   GA4 begin_checkout · InitiateCheckout  Checkout, once per visit to the page with a non-empty cart.
//   GA4 purchase · Purchase ·              OrderConfirmation — the cash path from the order the
//   CompletePayment                        store hands over, the card path from the snapshot
//                                          Checkout saves before the Paymob hand-off (below).
//
// ── BEFORE 2026-10-22 ───────────────────────────────────────────────────────
//
// GA4 received page views and nothing else: no component called gtag, so the property had
// 2.4K users and no add-to-carts and no purchases to show — measured on the live site, with
// every analytics request listed, before any of this was written. The pixels fired on cash
// orders only (see the next note). And a direct landing on a product page could lose its
// ViewContent: the bootstraps are injected after hydration, after the page's own effects, so
// `window.fbq` was not there yet when the page asked for it. `whenReady` below waits for it.
//
// ── THE CARD PAYMENT, AND WHY PURCHASE FIRES EXACTLY ONCE ────────────────────
//
// Checkout hands a card shopper to Paymob with window.location.href, and the payment callback
// returns them to /order-confirmation?order=…&status=…. The order's lines, its merchandise
// value and its shipping are saved to localStorage just before that hand-off
// (savePendingPurchase) and read back on the landing (takePendingPurchase); the landing fires
// Purchase for `status=paid` only, and only when the saved order number is the one in the
// query. `pending` (a kiosk order the shopper has yet to pay for, or a callback the policy
// could not settle) fires nothing: the money has not moved, and a Purchase that is later not
// paid for is a sale that never happened. `failed` fires nothing and drops the snapshot.
//
// The server already sends Meta a Purchase from the payment callback through the Conversions
// API, with event_id `purchase-<order number>` (core, MetaConversions). The browser Purchase
// here carries the SAME event id, so Meta keeps one of the two — that is how its deduplication
// works — and no card sale counts twice. TikTok gets the same id as `event_id` for the same
// reason, should a server event ever be added there. GA4 deduplicates on `transaction_id`.
//
// A reload of the confirmation page, or opening it again in a new tab, must not count a
// second sale: the order numbers already reported are PERSISTED (wz_fb_purchased), not held
// in a Set that dies with the page.
//
// Event-name note: ViewContent and AddToCart are identical on both pixels. For a completed
// order, Meta's event is `Purchase`; TikTok's canonical purchase conversion is `CompletePayment`
// (TikTok has no `Purchase` standard event), so we send the correct name to each.
import { GA4_MEASUREMENT_ID, META_PIXEL_IDS, TIKTOK_PIXEL_ID } from '../lib/env'

const CURRENCY = 'EGP'

// A vendor global that is not there YET (its bootstrap is injected after hydration, after the
// page's own effects; TikTok's only after the load event): try now, then every 250 ms for
// thirty seconds, then give up quietly. A storefront whose id is not configured never gets this
// far — see the three callers.
const whenReady = (ready, fn, tries = 120) => {
  if (typeof window === 'undefined') return
  if (ready()) {
    fn()
    return
  }
  if (tries <= 0) return
  setTimeout(() => whenReady(ready, fn, tries - 1), 250)
}

// ONE call per action, however many pixels are listening. `fbq('track', ...)` delivers
// to every pixel app/analytics.jsx initialised — that is fbevents.js's own behaviour,
// and `trackSingle` is what you would reach for to target just one. Do NOT loop over
// META_PIXEL_IDS here: adding a pixel must never become adding a firing site.
const fb = (event, params, eventId) => {
  if (!META_PIXEL_IDS.length) return
  whenReady(
    () => typeof window.fbq === 'function',
    () => (eventId ? window.fbq('track', event, params, { eventID: eventId }) : window.fbq('track', event, params)),
  )
}
const tt = (event, params, eventId) => {
  if (!TIKTOK_PIXEL_ID) return
  whenReady(
    () => !!(window.ttq && typeof window.ttq.track === 'function'),
    () => (eventId ? window.ttq.track(event, params, { event_id: eventId }) : window.ttq.track(event, params)),
  )
}
// gtag is the stub app/analytics.jsx defines (it pushes to dataLayer, after `config`), so an
// event queued before gtag.js loads is sent, in order, once it has.
const ga = (event, params) => {
  if (!GA4_MEASUREMENT_ID) return
  whenReady(
    () => typeof window.gtag === 'function',
    () => window.gtag('event', event, params),
  )
}

const money = (v) => Math.round((Number(v) || 0) * 100) / 100

// A GA4 item (the recommended-event shape): item_id or item_name is required, both are sent
// when known; price is the UNIT price. `contents` everywhere in this file is
// [{ id, name, quantity, price }].
export const gaItem = (c, index) => ({
  ...(c.id != null ? { item_id: String(c.id) } : {}),
  ...(c.name ? { item_name: String(c.name) } : {}),
  price: money(c.price),
  quantity: Number(c.quantity) || 1,
  ...(index != null ? { index } : {}),
})
const gaItems = (contents) => contents.filter((c) => c.id != null || c.name).map((c) => gaItem(c))

// PDP viewed (product or offer). `value` is the unit price the shopper sees.
export const trackViewContent = ({ id, name, value }) => {
  const v = money(value)
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
  ga('view_item', {
    currency: CURRENCY,
    value: v,
    items: gaItems([{ id, name, price: v, quantity: 1 }]),
  })
}

// A result set shown on the listing (the page, the sort, the filters) — GA4 only, the first
// 50 cards, in the order they are drawn. `contents` here may carry no quantity.
export const trackViewItemList = ({ listId, listName, contents = [] }) => {
  const items = contents.slice(0, 50).map((c, i) => gaItem({ ...c, quantity: 1 }, i))
  if (!items.length) return
  ga('view_item_list', {
    ...(listId ? { item_list_id: String(listId).slice(0, 100) } : {}),
    ...(listName ? { item_list_name: String(listName).slice(0, 100) } : {}),
    items,
  })
}

// A line added to the cart, from ANY surface (card, PDP, offer, or the drawer's
// "+"). Fired from cartStore once the server has confirmed the line, so every
// entry point is covered exactly once and a failed sync counts as nothing.
// `value` is the value of THIS action (unit price × the quantity added).
export const trackAddToCart = ({ id, name, value, quantity = 1 }) => {
  const q = Number(quantity) || 1
  const v = money(value)
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
  ga('add_to_cart', {
    currency: CURRENCY,
    value: v,
    items: gaItems([{ id, name, price: q ? v / q : v, quantity: q }]),
  })
}

// A line removed, or its quantity cut: `quantity` is what LEFT the cart and `value` its worth.
// GA4 only — neither pixel has a standard event for it.
export const trackRemoveFromCart = ({ id, name, value, quantity = 1 }) => {
  const q = Number(quantity) || 1
  const v = money(value)
  if (id == null && !name) return
  ga('remove_from_cart', {
    currency: CURRENCY,
    value: v,
    items: gaItems([{ id, name, price: q ? v / q : v, quantity: q }]),
  })
}

// The cart page, once per visit with something in it. GA4 only. `value` is the merchandise
// subtotal, as the cart page itself counts it (lines that cannot be bought are left out).
export const trackViewCart = ({ value, contents = [] }) => {
  ga('view_cart', {
    currency: CURRENCY,
    value: money(value),
    items: gaItems(contents),
  })
}

// Checkout started — the shopper has reached the checkout page with something in
// the cart. `value` is the MERCHANDISE subtotal: shipping is not known until a
// governorate is chosen, and it is not merchandise value in any case.
export const trackInitiateCheckout = ({ value, contents = [] }) => {
  const v = money(value)
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
  ga('begin_checkout', {
    currency: CURRENCY,
    value: v,
    items: gaItems(contents),
  })
}

// ── Purchase ─────────────────────────────────────────────────────────────────
//
// Deduped per order number, and the record is PERSISTED rather than held only in a
// Set: an in-memory guard dies with the page, so a shopper who reloads the
// confirmation page, or opens it again in a new tab, would be counted as a second
// sale. The last few order numbers are enough — nobody reloads a confirmation page
// from twenty orders ago — and storage being unavailable degrades to the
// in-memory guard rather than to a double count within the session. The key keeps
// its 2026 name: the records already in shoppers' browsers stay valid.
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
// The event id the server's Conversions API purchase carries for the same order (core,
// MetaConversions::purchaseEvent): the browser event with the same id is the one Meta drops.
export const purchaseEventId = (orderNumber) => `purchase-${orderNumber}`

// Order placed and (for a card) paid. `value` is the MERCHANDISE value — it equals what
// InitiateCheckout reported, or the funnel shows every order growing by its delivery cost
// between the two steps; `shipping` rides beside it for GA4, which has a field for it.
// The caller decides WHEN: cash on the confirmation page; card on the paid landing only.
export const trackPurchase = ({ orderNumber, value, shipping = 0, contents = [] }) => {
  const key = orderNumber != null ? String(orderNumber) : ''
  if (key && alreadyPurchased(key)) return false
  if (key) rememberPurchased(key)
  const v = money(value)
  const eventId = key ? purchaseEventId(key) : undefined
  const numItems = contents.reduce((s, c) => s + (Number(c.quantity) || 0), 0)
  fb(
    'Purchase',
    {
      content_type: 'product',
      contents: contents
        .filter((c) => c.id != null)
        .map((c) => ({ id: String(c.id), quantity: Number(c.quantity) || 1 })),
      content_ids: contents.filter((c) => c.id != null).map((c) => String(c.id)),
      ...(numItems ? { num_items: numItems } : {}),
      value: v,
      currency: CURRENCY,
    },
    eventId,
  )
  tt(
    'CompletePayment',
    {
      contents: contents.map((c) => ({
        content_id: c.id != null ? String(c.id) : undefined,
        content_name: c.name,
        quantity: Number(c.quantity) || 1,
        price: Number(c.price) || 0,
      })),
      value: v,
      currency: CURRENCY,
    },
    eventId,
  )
  ga('purchase', {
    ...(key ? { transaction_id: key } : {}),
    currency: CURRENCY,
    value: v,
    shipping: money(shipping),
    items: gaItems(contents),
  })
  return true
}

// ── The card hand-off ────────────────────────────────────────────────────────
//
// What the Purchase needs, saved just before Checkout sends the browser to Paymob and read
// back on the landing. localStorage, not sessionStorage: a wallet or Apple Pay flow can come
// back in the same tab after an app switch, and a tab's session copy is the one thing that
// does not survive every such return. One slot: the next checkout overwrites it, and a
// snapshot nobody came back for is harmless — the landing fires only for ITS order number.
const PENDING_KEY = 'wz_pending_purchase'
export const savePendingPurchase = ({ orderNumber, value, shipping = 0, contents = [] }) => {
  if (orderNumber == null) return
  try {
    localStorage.setItem(
      PENDING_KEY,
      JSON.stringify({ orderNumber: String(orderNumber), value: money(value), shipping: money(shipping), contents, at: Date.now() }),
    )
  } catch {
    // storage unavailable: the server's Conversions API purchase still counts the sale for Meta
  }
}
// Reads AND clears, whatever the outcome: a snapshot is for one landing.
export const takePendingPurchase = () => {
  try {
    const raw = localStorage.getItem(PENDING_KEY)
    localStorage.removeItem(PENDING_KEY)
    const o = raw ? JSON.parse(raw) : null
    return o && typeof o === 'object' && o.orderNumber ? o : null
  } catch {
    return null
  }
}
