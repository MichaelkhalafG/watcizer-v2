/*
 * The coupon code a shopper typed (M1u, 2026-10-30) — kept in this browser, sent to core with every cart request
 * (`?coupon=` on the v2 cart) and with the order. Core prices it: the cart answer's `totals.promotion` says what it
 * takes off each line and whether the code applies, and this page only SHOWS that. Nothing here computes a
 * discount, and the order total the checkout sends stays goods + delivery (core's guard); core takes the discount
 * off after the guard. A TWIN (Frontend-next and Frontend-brandfashion, identical).
 */

const KEY = 'wz_coupon'

// THE PHONE THE CHECKOUT HAS TYPED (2026-11-07): a wheel code (App\Domain\Wheel in core) is bound to the phone it was
// won with, and the checkout page sets this as the number is typed so the cart's preview can say "not this number"
// before the order is placed. Memory only — never stored, never sent without a kept code.
let checkoutPhone = ''
export function setCouponPhone(phone) {
  checkoutPhone = String(phone ?? '').trim()
}

// A tiny store over the kept code and the last refusal, so the coupon field and the screens read them with
// useSyncExternalStore instead of copying them into React state.
const listeners = new Set()
let notice = null
const emit = () => listeners.forEach((listener) => listener())

/** Subscribe to the kept code and the notice (for useSyncExternalStore). */
export function subscribeCoupon(listener) {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

/** The last thing core said about a code that did not apply — `{ messages: { en, ar } }` — or null. */
export function couponNotice() {
  return notice
}

export function setCouponNotice(value) {
  notice = value && value.messages ? { messages: value.messages } : null
  emit()
}

/** The code this browser is showing, or null. Storage may be unavailable (private mode): then no code. */
export function storedCoupon() {
  try {
    const code = (globalThis.localStorage?.getItem(KEY) || '').trim()
    return code === '' ? null : code
  } catch {
    return null
  }
}

/** Keep (or, with an empty value, forget) the code. */
export function setStoredCoupon(code) {
  try {
    const value = String(code ?? '').trim()
    if (value === '') globalThis.localStorage?.removeItem(KEY)
    else globalThis.localStorage?.setItem(KEY, value.slice(0, 40))
  } catch {
    // storage unavailable: the code lives only until the next read, which is the safe direction
  }
  emit()
}

/** The request params for a cart call: `{ coupon }`, or none. Sent as axios `params`, so the path stays literal. */
export function couponParams() {
  const code = storedCoupon()
  if (!code) return {}
  return checkoutPhone ? { coupon: code, phone: checkoutPhone } : { coupon: code }
}

/** `?coupon=…` for a cart request, or '' when no code is kept. */
export function couponQuery() {
  const code = storedCoupon()
  if (!code) return ''
  return `?coupon=${encodeURIComponent(code)}${checkoutPhone ? `&phone=${encodeURIComponent(checkoutPhone)}` : ''}`
}

/**
 * The cart answer's promotion, read defensively: an older core sends none, which reads as "no promotion".
 *
 * @returns {{ discount: number, applied: Array<{rule_id:number, code:string|null, label_en:string|null, label_ar:string|null, amount:number}>, lines: Record<number, number>, coupon: null | {code:string, status:string, messages:{en:string, ar:string}}, rewards: Array<{product_id:number, quantity:number, title:{en:string|null, ar:string|null}}> }}
 */
export function promotionOf(totals) {
  const p = totals && typeof totals === 'object' ? totals.promotion : null
  const lines = {}
  for (const line of Array.isArray(p?.lines) ? p.lines : []) {
    const id = Number(line?.item_id)
    const amount = Number(line?.amount)
    if (Number.isFinite(id) && Number.isFinite(amount) && amount > 0) lines[id] = amount
  }
  const discount = Number(p?.discount)
  return {
    // core with promotions always sends `totals.promotion`, even for an empty cart; an older core never does. The
    // coupon field shows only when it is there, so a storefront deployed before its core has no field that does
    // nothing (2026-10-30, the deploy order).
    available: Boolean(p && typeof p === 'object'),
    discount: Number.isFinite(discount) && discount > 0 ? Math.round(discount * 100) / 100 : 0,
    applied: Array.isArray(p?.applied) ? p.applied : [],
    lines,
    coupon: p?.coupon && typeof p.coupon === 'object' && p.coupon.code ? p.coupon : null,
    // the free items the winning rule grants — a gift won on the wheel (2026-11-09); an older core sends none
    rewards: Array.isArray(p?.rewards) ? p.rewards.filter((r) => r && typeof r === 'object' && Number(r.product_id) > 0) : [],
  }
}

/** The gift line's words: the product's name in the page's language. */
export function rewardTitle(reward, isRTL) {
  const t = reward?.title || {}
  return (isRTL ? t.ar || t.en : t.en || t.ar) || ''
}

/** The wheel's facts on a coupon answer — `{ kind, phone_hint, expires_at, order_phone_hint }` — or null for any other code. */
export function wheelOf(coupon) {
  const w = coupon && typeof coupon === 'object' ? coupon.wheel : null
  return w && typeof w === 'object' && w.phone_hint ? w : null
}

/**
 * The note beside the field for a wheel code, in the page's language, by the code's KIND (2026-11-09): an invite is a
 * friend's — the number in the order must be one that never ordered; a gift names how it reaches the order; a code is
 * bound to the phone it was won with.
 */
export function wheelNote(coupon, isRTL) {
  const w = wheelOf(coupon)
  if (!w) return ''
  if (w.kind === 'invite') {
    return isRTL
      ? 'هذه دعوة من صديق: تعمل على أول طلب فقط — رقم الهاتف في بيانات طلبك يجب ألا يكون قد طلب من قبل.'
      : "This is a friend's invite: it works on a first order only — the phone number in your order details must be one that has never ordered from us."
  }
  if (w.kind === 'gift') {
    return isRTL
      ? `هديتك تُضاف إلى طلبك مجانًا. الكود يعمل فقط مع رقم الهاتف الذي رُبح به (${w.phone_hint}).`
      : `Your gift is added to the order free. The code works only with the phone number it was won with (${w.phone_hint}).`
  }
  return isRTL
    ? `هذا الكود يعمل فقط مع رقم الهاتف الذي رُبح به (${w.phone_hint}). استخدم الرقم نفسه في بيانات طلبك.`
    : `This code works only with the phone number it was won with (${w.phone_hint}). Use the same number in your order details.`
}

/**
 * Is a refused code one the shopper can put right ON THIS PAGE — the phone at the checkout (a wheel code's binding, an
 * invite used with the winner's own number or with a number that ordered before), the cart's items (a scoped wheel
 * code: "two watches", "a wallet")? Then the code is HELD with its sentence, not forgotten (2026-11-09). Every other
 * refusal forgets the code so it never rides along to the order.
 */
export function isHeldRefusal(coupon) {
  const status = coupon?.status
  if (status === 'phone_mismatch' || status === 'invite_own' || status === 'invite_not_first' || status === 'invite_used') return true
  return status === 'no_eligible_items' && !!wheelOf(coupon)
}

/** The words beside the discount line: the rule's own label in the page's language, else a plain one. */
export function discountLabel(applied, isRTL) {
  const labels = (applied || [])
    .map((rule) => (isRTL ? rule?.label_ar || rule?.label_en : rule?.label_en || rule?.label_ar) || '')
    .filter((label) => label !== '')
  if (labels.length > 0) return labels.join(isRTL ? '، ' : ', ')
  return isRTL ? 'الخصم' : 'Discount'
}

/** The sentence core gave for a code, in the page's language. */
export function couponMessage(coupon, isRTL) {
  if (!coupon || !coupon.messages) return ''
  return (isRTL ? coupon.messages.ar : coupon.messages.en) || coupon.messages.en || coupon.messages.ar || ''
}

/** What the shopper pays: goods − discount + delivery, never below zero. */
export function payable(subtotal, discount, shipping) {
  const total = (Number(subtotal) || 0) - (Number(discount) || 0) + (Number(shipping) || 0)
  return Math.max(0, Math.round(total * 100) / 100)
}
