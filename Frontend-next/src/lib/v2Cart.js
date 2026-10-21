import http from '../Context/api'
import { STOREFRONT_CODE } from './env'

// THE CART on core's v2 API — `/api/v2/{storefront}/cart` (slice 3, 2026-10-21).
//
// A TWIN, like v2Listing.js and v2Product.js, and for the same reason: one file, both shops, no drift.
//
// ── What v2 does differently, and why each difference is an improvement ──────────────────────
//
//  • THE SERVER PRICES THE CART. compat's `add_to_cart` takes `piece_price` and `total_price` FROM THE
//    BROWSER and stores them; v2 takes a product, a variant and a quantity, and prices the line itself on
//    every read. Measured: a line added through compat claiming 100 came back from v2 at the real 3,090.
//    So nothing here sends money, and a stale tab can no longer book an old price.
//  • A LINE IS IDENTIFIED BY ITS OWN ID. compat removes by PRODUCT (`remove_from_cart`), which is why the
//    storefront's line key never had a variant dimension and why two sizes of one shirt could not be two
//    lines. v2 deletes and updates by line id, so the key here IS the line id — which is what finally makes
//    the picker's two sizes two lines (gap 7).
//  • THERE IS NO `cart/validate`. Every read re-checks every line and says what is wrong with it per line
//    (`problem`: unavailable, variant_required, variant_unavailable, insufficient_stock) and leaves a bad
//    line out of the subtotal. The separate validate call is not replaced — it is answered by the read.
//  • `blocked` COUNTS OFFER ROWS (2026-10-21). `cart_items` is shared with compat, which sells offers; v2
//    cannot price one. They are not items here, but they are counted, and the checkout refuses while any
//    exists rather than writing an order without them. Production holds no offers at all today.
//
// ── What stays on compat, deliberately ──────────────────────────────────────────────────────
//
//  • `cart/merge` (the guest cart folded into the user's at login) and `cart/recover` (the abandoned-cart
//    link) have NO v2 equivalent — and need none: both operate on the SAME `carts` / `cart_items` rows that
//    v2 reads, so they keep working whichever API is reading. Listed, not invented.

/** The legacy line shape the cart store and the cart screen already read. */
const legacyLine = (item) => ({
  id: item.id,
  product_id: item.product?.id ?? null,
  offer_id: null,
  variant_id: item.variant?.id ?? null,
  quantity: item.quantity,
  // strings, as compat sent them, so every `parseFloat` downstream behaves identically
  piece_price: String(item.unit_price ?? 0),
  total_price: String(item.line_total ?? 0),
  type_stock: item.bucket ?? 'Express',
  // v2 has no colour on a cart line; the colour a shopper picked is the VARIANT, carried above
  color_band: null,
  color_dial: null,
  // what only v2 knows, carried alongside so the screen can say why a line cannot be bought
  problem: item.problem ?? null,
  available: item.available ?? null,
  product: item.product ?? null,
  variant: item.variant ?? null,
})

/**
 * A v2 cart payload → the `{ id, cart_item, totals, warnings }` shape the store writes and the screen reads.
 *
 * `warnings` is built from the per-line `problem` and from `blocked`, because that is what `cart/validate`
 * used to return and what the checkout gate reads. One place, so the two cannot drift.
 */
export function fromV2Cart(payload) {
  const data = payload?.data ?? payload ?? {}
  const items = Array.isArray(data.items) ? data.items : []
  const lines = items.map(legacyLine)
  const warnings = []
  for (const line of lines) {
    if (line.problem) warnings.push({ line: line.id, product_id: line.product_id, reason: line.problem })
  }
  const blocked = Number(data.blocked || 0)
  if (blocked > 0) warnings.push({ line: null, product_id: null, reason: 'offer_in_cart', count: blocked })

  return {
    id: data.id ?? null,
    user_id: null,
    cart_item: lines,
    totals: data.totals ?? null,
    warnings,
    blocked,
    // the raw page, for anything that wants v2 directly
    v2: data,
  }
}

/*
 * A REFUSAL IS A VALUE, NOT AN EXCEPTION — the shape every caller in the cart already handles.
 *
 * v2 refuses with a 4xx and `{ "error": "<code>" }`: `insufficient_stock`, `variant_required`,
 * `variant_not_allowed`, `variant_mismatch`, `unavailable`, `not_found`. compat refused with
 * `{ success: false, message: "<sentence>" }`, and the storefront's add path resolves to
 * `{ ok, status, message }` either way — including the AddToCart pixel, which must not count a refused add.
 * So these return that same shape, and the CODE is carried as `code` for anything that wants to be precise
 * (`refusedAddMessage` reads the sentence; the cart screen reads the code).
 *
 * Verified against the running API: a quantity above the bucket's stock comes back 422
 * `{"error":"insufficient_stock"}`, and the same line at a quantity that fits comes back 200 with the cart.
 */
const ok = (data) => ({ ok: true, status: 200, code: null, message: null, cart: fromV2Cart(data) })

const refused = (err) => ({
  ok: false,
  status: err?.response?.status ?? 0,
  code: err?.response?.data?.error ?? null,
  // the store's `refusedAddMessage` matches on compat's sentence; give it one that matches for the case
  // it names, and otherwise let it fall through to its generic line
  message: err?.response?.data?.error === 'insufficient_stock' ? 'exceeds available stock' : null,
  cart: null,
})

/** The shopper's cart as it stands on the server. Throws only when core could not answer at all. */
export const v2CartRead = async () => {
  const { data } = await http.get(`v2/${STOREFRONT_CODE}/cart`)
  return fromV2Cart(data)
}

/**
 * Set a line's quantity — the same "absolute quantity" contract `add_to_cart` had, so every caller that
 * already passes an absolute number keeps working. No price is sent: the server prices it.
 */
export const v2CartSet = async ({ productId, variantId = null, bucket = 'Express', quantity }) => {
  try {
    const { data } = await http.post(`v2/${STOREFRONT_CODE}/cart/items`, {
      product_id: productId,
      variant_id: variantId,
      bucket,
      quantity,
    })
    return ok(data)
  } catch (err) {
    return refused(err)
  }
}

/** Change a line's quantity, by the line's own id. */
export const v2CartUpdate = async (lineId, quantity) => {
  try {
    const { data } = await http.patch(`v2/${STOREFRONT_CODE}/cart/items/${lineId}`, { quantity })
    return ok(data)
  } catch (err) {
    return refused(err)
  }
}

/** Remove a line, by the line's own id — never by product, which is what compat had to do. */
export const v2CartRemove = async (lineId) => {
  try {
    const { data } = await http.delete(`v2/${STOREFRONT_CODE}/cart/items/${lineId}`)
    return ok(data)
  } catch (err) {
    return refused(err)
  }
}
