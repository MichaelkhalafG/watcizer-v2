/*
 * "Spin the wheel" — the pure rules of the page (2026-11-07; the client's prize kinds since 2026-11-09, WATCHIZER
 * ONLY). Tested in tests/wheel.test.mjs.
 *
 * Nothing here decides a prize: the SERVER does (core, POST /api/v2/watchizer/wheel/spin) and the page only turns
 * the wheel to the segment it is told. What lives here is the arithmetic of that turn, the phone's shape and its
 * masking, the code's time left, the words a segment and a result carry (short on the disc, whole on the card), the
 * conditions a won code is read with, and the small things the browser remembers: the spin it made (so the page
 * opens on the result card next time — a code, an invite code, or "better luck", which has no code at all), a device
 * id (one spin per device, enforced by the server), and the floating button's dismissal for the session.
 */

export const SPIN_PATH = '/spin'

const SPIN_KEY = 'wz_wheel_spin'
const DEVICE_KEY = 'wz_wheel_device'
const DISMISS_KEY = 'wz_wheel_float_dismissed'

/** `01xxxxxxxxx` — Arabic-Indic digits and a +20 prefix accepted — or null. The same rule as the server's. */
export function normalizePhone(raw) {
  if (raw == null) return null
  let digits = String(raw).replace(/[٠-٩]/g, (d) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d))).replace(/\D+/g, '')
  if (digits.startsWith('0020')) digits = '0' + digits.slice(4)
  else if (digits.startsWith('20') && digits.length === 12) digits = '0' + digits.slice(2)
  return /^01\d{9}$/.test(digits) ? digits : null
}

export const isEgyptianMobile = (raw) => normalizePhone(raw) !== null

/** `010•••••678` */
export function maskPhone(phone) {
  const digits = String(phone || '').replace(/\D+/g, '')
  if (digits.length < 7) return '•'.repeat(digits.length)
  return `${digits.slice(0, 3)}•••••${digits.slice(-3)}`
}

/** The centre of segment `index` (0-based, clockwise from the pointer at the top), in degrees. */
export const segmentCentre = (index, count) => ((index + 0.5) * 360) / count

/**
 * The rotation (degrees, clockwise) that brings segment `index` under the pointer after `turns` full turns, landing a
 * little off the exact centre so two spins never look the same — never within 7° of a separator. `seed` makes the
 * offset deterministic for a given code.
 */
export function targetRotation(index, count, turns = 5, seed = 0) {
  const half = 360 / count / 2
  const room = Math.max(0, half - 7)
  const unit = seededUnit(seed) * 2 - 1 // -1 … 1
  const offset = unit * room
  return turns * 360 + (360 - segmentCentre(index, count)) - offset
}

/** Which segment is under the pointer at a rotation. */
export function segmentAt(rotation, count) {
  const angle = ((360 - (rotation % 360)) % 360 + 360) % 360
  return Math.floor(angle / (360 / count)) % count
}

/** 0 … 1 from any string or number, the same for the same input. */
export function seededUnit(seed) {
  const text = String(seed ?? '')
  let h = 2166136261
  for (let i = 0; i < text.length; i++) {
    h ^= text.charCodeAt(i)
    h = Math.imul(h, 16777619) >>> 0
  }
  return (h % 10000) / 10000
}

/** `{ total, h, m, s }` until `expiresAt` on this device's clock; zero when passed. */
export function timeLeft(expiresAt, nowMs = Date.now()) {
  const end = Date.parse(expiresAt)
  const total = Number.isFinite(end) ? Math.max(0, Math.floor((end - nowMs) / 1000)) : 0
  return { total, h: Math.floor(total / 3600), m: Math.floor((total % 3600) / 60), s: total % 60 }
}

export const two = (n) => String(n).padStart(2, '0')

/** `16 Nov` — the day an invite can be used until; western digits in both languages, the month in the page's. */
export function dayOf(iso, lang) {
  const at = Date.parse(iso)
  if (!Number.isFinite(at)) return ''
  try {
    return new Intl.DateTimeFormat(lang === 'ar' ? 'ar-EG-u-nu-latn' : 'en-GB', { day: 'numeric', month: 'short' }).format(new Date(at))
  } catch {
    return new Date(at).toISOString().slice(0, 10)
  }
}

// ── what a spin is to the page ─────────────────────────────────────────────────────────────────────

/**
 * The kind of result card: `code` (a discount or free shipping — a code to copy), `gift` (a product, free, through a
 * code), `invite` (a code for friends; the winner's own codes come later), `none` (better luck — no code).
 */
export function kindOf(spin) {
  const type = spin?.prize?.type
  if (type === 'none') return 'none'
  if (type === 'referral') return 'invite'
  if (type === 'gift') return 'gift'
  return 'code'
}

const STOP = {
  en: new Set(['off', 'the', 'a', 'an', 'free', 'gift', 'on', 'your', 'for', 'section', 'both', 'and', 'of', 'to', 'next', 'time']),
  ar: new Set(['خصم', 'على', 'مجاني', 'مجانية', 'مجانا', 'هدية', 'من', 'لك', 'لكما', 'و', 'في', 'المرة', 'القادمة']),
}

/** The label's own words, minus the numbers, the percentages and the filler ("30% off the Wallets section" → "Wallets"). */
export function wordsOf(label, lang) {
  const stop = STOP[lang === 'ar' ? 'ar' : 'en']
  return String(label || '')
    .replace(/[—–:،,.!]/g, ' ')
    .split(/\s+/)
    .filter((w) => w && !/[%٪]|^\d+$|^[٠-٩]+$/.test(w) && !stop.has(w.toLowerCase().replace(/[ً-ْ]/g, '')))
    .join(' ')
}

/** Upper case, whole words, at most `max` characters. */
function fit(text, max) {
  const upper = text.toUpperCase()
  if (upper.length <= max) return upper
  const cut = upper.slice(0, max + 1).lastIndexOf(' ')
  return cut > 0 ? upper.slice(0, cut) : upper.slice(0, max)
}

/**
 * The two lines a segment shows on the disc: the big thing and the small word under it — from the segment's TYPE and
 * the team's own label, shortened to what fits a seventh of a wheel ("10% off the 2nd watch" → 10% / 2ND WATCH;
 * "Free perfume gift" → GIFT / PERFUME; "Invite a friend: 15% for both" → 15% / INVITE).
 */
export function segmentLabel(segment, lang) {
  const ar = lang === 'ar'
  const type = segment?.type
  const label = segment?.label?.[lang] ?? segment?.label?.en ?? ''
  const value = Number(segment?.value)
  const words = fit(wordsOf(label, lang), ar ? 14 : 12)
  if (type === 'free_shipping') return { big: ar ? 'شحن' : 'FREE', small: ar ? 'مجاني' : 'SHIPPING' }
  if (type === 'none') return { big: ar ? 'حظ' : 'LUCK', small: ar ? 'أوفر' : 'NEXT TIME' }
  if (type === 'gift') return { big: ar ? 'هدية' : 'GIFT', small: words || (ar ? 'مجانية' : 'FREE') }
  if (type === 'fixed_discount') return { big: value > 0 ? value.toLocaleString('en-US') : '', small: ar ? 'جنيه خصم' : 'EGP OFF' }
  return { big: value > 0 ? `${trimNumber(value)}%` : '', small: words || (ar ? 'خصم' : 'OFF') }
}

const trimNumber = (n) => String(Number(n.toFixed(2)))

/**
 * The reveal's headline: the big thing and the line under it — the prize said ONCE (Michael, 2026-11-09 evening: "7%"
 * over "7% off" said it twice). A percentage or an amount stands alone, huge, with the REST of the team's label under it
 * ("10%" / "off the 2nd watch"); a label that is only the number ("7% off") becomes one line ("7% OFF"); an invite's label
 * splits at its colon or dash ("Invite a friend" / "15% for both"); the rest are words ("Free shipping", "A gift!" with
 * the product's name, "Better luck next time").
 */
export function headline(spin, lang) {
  const ar = lang === 'ar'
  const type = spin?.prize?.type
  const label = spin?.prize?.label?.[lang] ?? spin?.prize?.label?.en ?? ''
  const value = Number(spin?.prize?.value)
  if (type === 'referral' && value > 0) {
    const m = label.match(/^(.+?)\s*[:—–]\s*(.+)$/)
    if (m) return { big: m[1].trim(), sub: m[2].trim(), words: true }
    return { big: `${trimNumber(value)}%`, sub: label, words: false }
  }
  if ((type === 'percent_discount' || type === 'fixed_discount') && value > 0) {
    const percent = type === 'percent_discount'
    const number = percent ? `${trimNumber(value)}%` : value.toLocaleString('en-US')
    const rest = restOfLabel(label)
    if (rest === '') {
      const alone = ar ? (percent ? `خصم ${trimNumber(value)}٪` : `خصم ${number} ج.م`) : percent ? `${number} OFF` : `${number} EGP OFF`
      return { big: alone, sub: null, words: false }
    }
    return { big: number, sub: rest, words: false }
  }
  if (type === 'gift') {
    const title = spin?.gift?.title?.[lang] || spin?.gift?.title?.en || null
    return { big: ar ? 'هدية!' : 'A gift!', sub: title || label, words: true }
  }
  return { big: label, sub: null, words: true }
}

/** The team's label without its leading number phrase: "10% off the 2nd watch" → "off the 2nd watch"; "7% off" → "". */
export function restOfLabel(label) {
  let s = String(label || '').trim()
  s = s.replace(/^(EGP\s*)?[\d.,٠-٩]+\s*[%٪]?\s*(EGP|ج\.م|جنيه)?\s*/i, '')
  s = s.replace(/^خصم\s*[\d.,٠-٩]+\s*[%٪]?\s*(ج\.م|جنيه)?\s*/, '')
  s = s.replace(/^[:—–-]\s*/, '').trim()
  return /^(off|خصم)$/i.test(s) ? '' : s
}

// ── the invite's link, message and share targets ───────────────────────────────────────────────────

/** The shop's address with the friend's code ready: the header keeps it as the typed coupon on landing (InviteFromLink). */
export function inviteLink(origin, code, lang) {
  return `${origin}${lang === 'ar' ? '/ar' : ''}/?invite=${encodeURIComponent(code)}`
}

/** One short message in the page's language, with the code and the link. */
export function inviteMessage(code, percent, link, lang) {
  return lang === 'ar'
    ? `أدعوك إلى Watchizer: استخدم كودي ${code} لخصم ${percent}٪ على أول طلب لك — ${link}`
    : `I'm inviting you to Watchizer: use my code ${code} for ${percent}% off your first order — ${link}`
}

/**
 * Where each share button goes. WhatsApp and Messenger take a pre-filled message or link; Instagram and TikTok take
 * nothing from a web page, so those buttons COPY the message first (the page says so) and open the app — on a phone the
 * app's own address, on a computer the web inbox. `mobile` is the device's kind (user agent).
 */
export function shareTargets(message, link, mobile) {
  const text = encodeURIComponent(message)
  return {
    whatsapp: { href: `https://wa.me/?text=${text}`, copyFirst: false },
    messenger: mobile ? { href: `fb-messenger://share?link=${encodeURIComponent(link)}`, copyFirst: true } : { href: 'https://www.messenger.com/', copyFirst: true },
    instagram: { href: mobile ? 'instagram://direct-inbox' : 'https://www.instagram.com/direct/inbox/', copyFirst: true },
    tiktok: { href: mobile ? 'snssdk1233://' : 'https://www.tiktok.com/', copyFirst: true },
  }
}

// ── what the browser remembers ─────────────────────────────────────────────────────────────────────

const storage = (kind) => {
  try {
    return kind === 'session' ? globalThis.sessionStorage : globalThis.localStorage
  } catch {
    return null
  }
}

/**
 * The spin this device made (as the server presented it), or null. A spin is kept when it holds a code, an invite
 * code, or is a "better luck" result — that one has no code, but the device has spun this round all the same.
 */
export function readSpin(campaign = null) {
  try {
    const raw = storage('local')?.getItem(SPIN_KEY)
    const spin = raw ? JSON.parse(raw) : null
    if (!spin || typeof spin !== 'object') return null
    const kept = typeof spin.code === 'string' || spin.prize?.type === 'referral' || spin.prize?.type === 'none'
    if (!kept) return null
    if (campaign && spin.campaign && spin.campaign !== campaign) return null
    return spin
  } catch {
    return null
  }
}

export function writeSpin(spin) {
  try {
    if (spin) storage('local')?.setItem(SPIN_KEY, JSON.stringify(spin))
    else storage('local')?.removeItem(SPIN_KEY)
  } catch {
    // private mode: the page still shows the card for this view
  }
}

/** A random id for this browser, made once; the server keeps only an HMAC of it. */
export function deviceId() {
  try {
    const store = storage('local')
    let id = store?.getItem(DEVICE_KEY)
    if (!id) {
      id = globalThis.crypto?.randomUUID?.() || `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`
      store?.setItem(DEVICE_KEY, id)
    }
    return id
  } catch {
    return null
  }
}

export function isFloatDismissed() {
  try {
    return storage('session')?.getItem(DISMISS_KEY) === '1'
  } catch {
    return false
  }
}

export function dismissFloat() {
  try {
    storage('session')?.setItem(DISMISS_KEY, '1')
  } catch {
    // nothing to remember with: the button hides for this page view through the component's own state
  }
}

// ── the conditions under a code ────────────────────────────────────────────────────────────────────

/**
 * The conditions under the code, one short line each, in the page's language — from the TERMS THE SPIN WAS WON WITH
 * (the server's snapshot on the spin: its prize's cap, category need and minimum, its own expiry), never the wheel's
 * current ones, which the team may have changed since. Nothing for "better luck". An invite reads the rule for the
 * friend and the rule for the winner; a gift says how it reaches the order.
 */
export function conditionLines(spin, lang) {
  const ar = lang === 'ar'
  const kind = kindOf(spin)
  if (kind === 'none') return []
  const hint = spin?.phone_hint || ''
  const hours = hoursOf(spin)
  const cap = Number(spin?.prize?.cap)
  const min = Number(spin?.prize?.min_order)
  const percent = trimNumber(Number(spin?.prize?.value) || 0)
  const needs = spin?.prize?.needs?.[lang] || spin?.prize?.needs?.en || null
  const stacking = ar ? 'لا يُجمع مع العروض الأخرى — إذا كان على طلبك عرض أكبر يُطبَّق العرض الأكبر' : 'does not combine with other offers — a bigger offer on your order wins'
  const lines = []

  if (kind === 'invite') {
    const days = daysOf(spin?.created_at, spin?.invite?.expires_at)
    lines.push(
      ar
        ? `لأصدقاء لم يطلبوا منا من قبل: أول طلب لهم بهذا الكود عليه خصم ${percent}٪ — خلال ${days} أيام من اليوم`
        : `For friends who never ordered from us: their first order with this code gets ${percent}% off, within ${days} days from today`,
    )
    lines.push(ar ? `عند توصيل طلب الصديق تصلك مكافأتك: كود خصم ${percent}٪ خاص بك لمدة 48 ساعة — كود عن كل صديق` : `When a friend's order is delivered you get your reward: your own ${percent}% code for 48 hours — one for every friend`)
    if (cap > 0) lines.push(ar ? `كل خصم حتى ${cap.toLocaleString('en-US')} ج.م` : `each discount up to EGP ${cap.toLocaleString('en-US')}`)
    lines.push(stacking)
    return lines
  }

  if (kind === 'gift') {
    lines.push(ar ? 'أضِف أي شيء إلى سلتك وطبّق الكود عند الدفع: هديتك تُضاف إلى طلبك مجانًا، طالما هي متوفرة' : 'Add anything to your cart and apply the code at checkout: your gift is added to the order free, while it is in stock')
  }
  lines.push(ar ? `استخدام واحد خلال ${hours} ساعة، مع الرقم ${hint} عند إتمام الطلب` : `single use within ${hours} hours, with ${hint} at checkout`)
  if (needs) lines.push(needs)
  if (kind === 'code' && spin?.prize?.type === 'percent_discount' && cap > 0) lines.push(ar ? `الخصم حتى ${cap.toLocaleString('en-US')} ج.م` : `up to EGP ${cap.toLocaleString('en-US')} off`)
  if (min > 0) lines.push(ar ? `للطلبات من ${min.toLocaleString('en-US')} ج.م` : `on orders from EGP ${min.toLocaleString('en-US')}`)
  else if (kind === 'code') lines.push(ar ? 'بدون حد أدنى للطلب' : 'no minimum order')
  // Michael, 2026-11-08: said up front — the code does not combine with other offers, and a bigger automatic offer wins
  lines.push(stacking)
  return lines
}

/** The same conditions as one line (the pixels, the tests). */
export const conditionsLine = (spin, lang) => conditionLines(spin, lang).join(' · ')

/** The code's validity in whole hours, from the spin's own stamps; 48 when they are missing. */
export function hoursOf(spin) {
  const from = Date.parse(spin?.created_at)
  const to = Date.parse(spin?.expires_at)
  if (!Number.isFinite(from) || !Number.isFinite(to) || to <= from) return 48
  return Math.max(1, Math.round((to - from) / 3600000))
}

/** Whole days between two stamps; 7 when they are missing (the invite's rule). */
export function daysOf(fromIso, toIso) {
  const from = Date.parse(fromIso)
  const to = Date.parse(toIso)
  if (!Number.isFinite(from) || !Number.isFinite(to) || to <= from) return 7
  return Math.max(1, Math.round((to - from) / 86400000))
}
