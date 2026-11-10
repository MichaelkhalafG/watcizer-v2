import { beforeEach, describe, expect, it } from 'vitest'
import {
  conditionLines,
  conditionsLine,
  daysOf,
  deviceId,
  dismissFloat,
  headline,
  hoursOf,
  inviteLink,
  inviteMessage,
  restOfLabel,
  shareTargets,
  isEgyptianMobile,
  isFloatDismissed,
  kindOf,
  maskPhone,
  normalizePhone,
  readSpin,
  seededUnit,
  segmentAt,
  segmentCentre,
  segmentLabel,
  targetRotation,
  wordsOf,
  timeLeft,
  writeSpin,
} from '../src/lib/wheel'
import { keepUtm, readUtm, utmFromSearch } from '../src/lib/utm'

// "Spin the wheel" — the page's pure rules (2026-11-07, WATCHIZER ONLY). Nothing here decides a prize: the server
// does; these hold the arithmetic of the turn, the phone's shape, what the browser remembers and the UTM tags.

const mem = () => {
  const store = new Map()
  return { getItem: (k) => (store.has(k) ? store.get(k) : null), setItem: (k, v) => store.set(k, String(v)), removeItem: (k) => store.delete(k), clear: () => store.clear() }
}
Object.defineProperty(globalThis, 'localStorage', { value: mem(), configurable: true, writable: true })
Object.defineProperty(globalThis, 'sessionStorage', { value: mem(), configurable: true, writable: true })
beforeEach(() => {
  globalThis.localStorage.clear()
  globalThis.sessionStorage.clear()
})

describe('the phone', () => {
  it('accepts an Egyptian mobile in every spelling and nothing else', () => {
    expect(normalizePhone('01012345678')).toBe('01012345678')
    expect(normalizePhone(' 010 1234 5678 ')).toBe('01012345678')
    expect(normalizePhone('+20 10 1234 5678')).toBe('01012345678')
    expect(normalizePhone('00201012345678')).toBe('01012345678')
    expect(normalizePhone('٠١٠١٢٣٤٥٦٧٨')).toBe('01012345678')
    expect(normalizePhone('0212345678')).toBeNull() // a landline
    expect(normalizePhone('0101234567')).toBeNull() // ten digits
    expect(normalizePhone('')).toBeNull()
    expect(isEgyptianMobile('01512345678')).toBe(true)
  })
  it('masks to the first three and the last three digits', () => {
    expect(maskPhone('01012345678')).toBe('010•••••678')
    expect(maskPhone('0101')).toBe('••••')
  })
})

describe('the turn', () => {
  it('lands the pointer on the segment the server named, after the full turns, never on a separator', () => {
    const n = 6
    for (let index = 0; index < n; index++) {
      for (const seed of ['SPIN-AAAA-BBBB', 'SPIN-ZZZZ-9999', 'x', '']) {
        const rotation = targetRotation(index, n, 5, seed)
        expect(rotation).toBeGreaterThanOrEqual(5 * 360)
        expect(rotation).toBeLessThan(6 * 360 + 60)
        expect(segmentAt(rotation, n)).toBe(index)
        // at least 7° from either separator of the segment
        const angle = ((360 - (rotation % 360)) % 360 + 360) % 360
        const within = angle - index * (360 / n)
        expect(within).toBeGreaterThanOrEqual(7 - 1e-9)
        expect(within).toBeLessThanOrEqual(360 / n - 7 + 1e-9)
      }
    }
    expect(segmentCentre(0, 6)).toBe(30)
    expect(segmentCentre(5, 6)).toBe(330)
  })
  it('is deterministic for a code and spread over codes', () => {
    expect(seededUnit('SPIN-AAAA-BBBB')).toBe(seededUnit('SPIN-AAAA-BBBB'))
    const values = new Set(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'].map(seededUnit))
    expect(values.size).toBeGreaterThan(5)
    for (const v of values) expect(v).toBeGreaterThanOrEqual(0) && expect(v).toBeLessThan(1)
  })
})

describe('the time left', () => {
  it('counts down to the expiry and stops at zero', () => {
    const now = Date.parse('2026-11-07T10:00:00Z')
    expect(timeLeft('2026-11-09T10:00:00Z', now)).toEqual({ total: 172800, h: 48, m: 0, s: 0 })
    expect(timeLeft('2026-11-07T10:01:05Z', now)).toEqual({ total: 65, h: 0, m: 1, s: 5 })
    expect(timeLeft('2026-11-07T09:00:00Z', now).total).toBe(0)
    expect(timeLeft('garbage', now).total).toBe(0)
  })
})

describe('what the browser remembers', () => {
  it('keeps the spin for the same campaign only, a device id once, and the dismissal for the session', () => {
    expect(readSpin()).toBeNull()
    writeSpin({ code: 'SPIN-AAAA-BBBB', campaign: 'wheel-2026-11', phone: '01012345678' })
    expect(readSpin('wheel-2026-11')?.code).toBe('SPIN-AAAA-BBBB')
    expect(readSpin('wheel-2027-01')).toBeNull()
    expect(readSpin()?.code).toBe('SPIN-AAAA-BBBB')
    writeSpin(null)
    expect(readSpin()).toBeNull()

    const id = deviceId()
    expect(id).toBeTruthy()
    expect(deviceId()).toBe(id)

    expect(isFloatDismissed()).toBe(false)
    dismissFloat()
    expect(isFloatDismissed()).toBe(true)
  })
  it('words the conditions from the terms the spin was won with: the masked phone, its own hours, its cap, its minimum', () => {
    const spin = {
      phone_hint: '010•••••678',
      created_at: '2026-11-08T10:00:00Z',
      expires_at: '2026-11-10T10:00:00Z',
      prize: { type: 'percent_discount', value: 10, cap: 1500, min_order: null },
    }
    const line = conditionsLine(spin, 'en')
    expect(line).toContain('010•••••678')
    expect(line).toContain('1,500')
    expect(line).toContain('48 hours')
    expect(line).toContain('no minimum')
    expect(conditionsLine(spin, 'ar')).toContain('48')
    // another spin, won under other terms, says its own: 24 hours, a minimum, no cap line for free shipping
    const other = { ...spin, expires_at: '2026-11-09T10:00:00Z', prize: { type: 'free_shipping', value: null, cap: null, min_order: 2000 } }
    const line2 = conditionsLine(other, 'en')
    expect(line2).toContain('24 hours')
    expect(line2).toContain('EGP 2,000')
    expect(line2).not.toContain('up to')
    expect(hoursOf({})).toBe(48)
  })
})

describe('the UTM tags', () => {
  it('reads utm_* from a query string, keeps the FIRST landing of the session, and hands them back', () => {
    expect(utmFromSearch('?utm_source=facebook&utm_campaign=nov&x=1')).toEqual({ source: 'facebook', campaign: 'nov' })
    expect(utmFromSearch('')).toEqual({})
    expect(keepUtm('?utm_source=facebook&utm_medium=cpc')).toEqual({ source: 'facebook', medium: 'cpc' })
    // a later landing with other tags does not replace the first
    expect(keepUtm('?utm_source=tiktok')).toEqual({ source: 'facebook', medium: 'cpc' })
    expect(readUtm()).toEqual({ source: 'facebook', medium: 'cpc' })
    // a plain navigation keeps what the session has
    expect(keepUtm('')).toEqual({ source: 'facebook', medium: 'cpc' })
  })
})

// ── the client's prize kinds (2026-11-09) ──────────────────────────────────────────────────────────

const PRIZES = [
  { type: 'free_shipping', value: null, label: { en: 'Free shipping', ar: 'شحن مجاني' } },
  { type: 'percent_discount', value: 10, label: { en: '10% off the 2nd watch', ar: 'خصم ١٠٪ على الساعة الثانية' } },
  { type: 'none', value: null, label: { en: 'Better luck next time', ar: 'حظًا أوفر المرة القادمة' } },
  { type: 'percent_discount', value: 30, label: { en: '30% off wallets', ar: 'خصم ٣٠٪ على المحافظ' } },
  { type: 'referral', value: 15, label: { en: 'Invite a friend: 15% for both', ar: 'ادعُ صديقًا: خصم ١٥٪ لكما' } },
  { type: 'gift', value: null, label: { en: 'Free perfume gift', ar: 'هدية: عطر مجاني' } },
  { type: 'gift', value: null, label: { en: 'Free sunglasses gift', ar: 'هدية: نظارة شمسية مجانية' } },
]

describe('the kinds of result', () => {
  it('names the card a spin gets: code, gift, invite, none', () => {
    expect(kindOf({ prize: { type: 'percent_discount' } })).toBe('code')
    expect(kindOf({ prize: { type: 'free_shipping' } })).toBe('code')
    expect(kindOf({ prize: { type: 'gift' } })).toBe('gift')
    expect(kindOf({ prize: { type: 'referral' } })).toBe('invite')
    expect(kindOf({ prize: { type: 'none' } })).toBe('none')
    expect(kindOf(null)).toBe('code')
  })
  it('remembers a "better luck" spin and an invite, which have no code, as this round\'s spin', () => {
    writeSpin({ campaign: 'r3', code: null, prize: { type: 'none' } })
    expect(readSpin('r3')?.prize.type).toBe('none')
    expect(readSpin('r4')).toBeNull()
    writeSpin({ campaign: 'r3', code: null, prize: { type: 'referral' }, invite: { code: 'FRIEND-AAAA-BBBB' } })
    expect(readSpin('r3')?.invite.code).toBe('FRIEND-AAAA-BBBB')
    // a DORMANT invite (not activated yet: no code at all) is this round's spin too
    writeSpin({ campaign: 'r3', code: null, prize: { type: 'referral' }, invite: { active: false, code: null } })
    expect(readSpin('r3')?.prize.type).toBe('referral')
    writeSpin({ campaign: 'r3', code: null, prize: { type: 'percent_discount' } })
    expect(readSpin('r3')).toBeNull()
  })
  it('shortens the seven labels to two legible words on the disc, from the type and the label', () => {
    const en = PRIZES.map((p) => segmentLabel(p, 'en'))
    expect(en.map((l) => `${l.big} / ${l.small}`)).toEqual([
      'FREE / SHIPPING',
      '10% / 2ND WATCH',
      'LUCK / NEXT TIME',
      '30% / WALLETS',
      '15% / INVITE',
      'GIFT / PERFUME',
      'GIFT / SUNGLASSES',
    ])
    const ar = PRIZES.map((p) => segmentLabel(p, 'ar'))
    expect(ar.map((l) => `${l.big} / ${l.small}`)).toEqual(['شحن / مجاني', '10% / الساعة الثانية', 'حظ / أوفر', '30% / المحافظ', '15% / ادعُ صديقًا', 'هدية / عطر', 'هدية / نظارة شمسية'])
    for (const l of [...en, ...ar]) expect(l.small.length).toBeLessThanOrEqual(14)
    expect(wordsOf('25% off everything in the Watches section', 'en')).toBe('everything in Watches')
    expect(segmentLabel({ type: 'percent_discount', value: 7, label: { en: '7% off' } }, 'en')).toEqual({ big: '7%', small: 'OFF' })
    expect(segmentLabel({ type: 'fixed_discount', value: 150, label: { en: 'EGP 150 off' } }, 'en')).toEqual({ big: '150', small: 'EGP OFF' })
  })
  it('headlines a number alone with the label under it, or words at the smaller size', () => {
    expect(headline({ prize: PRIZES[1] }, 'en')).toEqual({ big: '10%', sub: 'off the 2nd watch', words: false })
    expect(headline({ prize: PRIZES[1] }, 'ar')).toEqual({ big: '10%', sub: 'على الساعة الثانية', words: false })
    expect(headline({ prize: PRIZES[3] }, 'en')).toEqual({ big: '30%', sub: 'off wallets', words: false })
    // a label that is only the number says it once; the invite's label splits at its colon
    expect(headline({ prize: { type: 'percent_discount', value: 7, label: { en: '7% off', ar: 'خصم ٧٪' } } }, 'en')).toEqual({ big: '7% OFF', sub: null, words: false })
    expect(headline({ prize: { type: 'percent_discount', value: 7, label: { en: '7% off', ar: 'خصم ٧٪' } } }, 'ar')).toEqual({ big: 'خصم 7٪', sub: null, words: false })
    expect(headline({ prize: { type: 'fixed_discount', value: 150, label: { en: 'EGP 150 off', ar: 'خصم ١٥٠ جنيه' } } }, 'en')).toEqual({ big: '150 EGP OFF', sub: null, words: false })
    expect(headline({ prize: PRIZES[4] }, 'en')).toEqual({ big: 'Invite a friend', sub: '15% for both', words: true })
    expect(headline({ prize: PRIZES[4] }, 'ar')).toEqual({ big: 'ادعُ صديقًا', sub: 'خصم ١٥٪ لكما', words: true })
    expect(restOfLabel('10% off the 2nd watch')).toBe('off the 2nd watch')
    expect(restOfLabel('7% off')).toBe('')
    expect(headline({ prize: PRIZES[0] }, 'en')).toEqual({ big: 'Free shipping', sub: null, words: true })
    expect(headline({ prize: PRIZES[2] }, 'ar')).toEqual({ big: 'حظًا أوفر المرة القادمة', sub: null, words: true })
    expect(headline({ prize: PRIZES[5], gift: { title: { en: 'Lattafa Asad 100 ml', ar: null } } }, 'en')).toEqual({ big: 'A gift!', sub: 'Lattafa Asad 100 ml', words: true })
    expect(headline({ prize: PRIZES[5], gift: null }, 'ar')).toEqual({ big: 'هدية!', sub: 'هدية: عطر مجاني', words: true })
  })
  it('builds the invite link, the message and the share targets by device', () => {
    const link = inviteLink('https://watchizereg.com', 'FRIEND-AAAA-BBBB', 'ar')
    expect(link).toBe('https://watchizereg.com/ar/?invite=FRIEND-AAAA-BBBB')
    const msg = inviteMessage('FRIEND-AAAA-BBBB', '15', link, 'en')
    expect(msg).toContain('FRIEND-AAAA-BBBB')
    expect(msg).toContain('15% off')
    expect(msg).toContain(link)
    expect(inviteMessage('FRIEND-AAAA-BBBB', '15', link, 'ar')).toContain('١٥٪'.replace('١٥', '15'))
    const phone = shareTargets(msg, link, true)
    expect(phone.whatsapp.href).toBe(`https://wa.me/?text=${encodeURIComponent(msg)}`)
    expect(phone.whatsapp.copyFirst).toBe(false)
    expect(phone.messenger.href).toBe(`fb-messenger://share?link=${encodeURIComponent(link)}`)
    expect(phone.instagram.copyFirst).toBe(true)
    expect(phone.tiktok.copyFirst).toBe(true)
    const desk = shareTargets(msg, link, false)
    expect(desk.messenger.href).toBe('https://www.messenger.com/')
    expect(desk.instagram.href).toContain('instagram.com')
  })
  it('words the conditions by kind: nothing for better luck, the two rules for an invite, the gift\'s way in, the category need', () => {
    const base = { phone_hint: '010•••••678', created_at: '2026-11-09T10:00:00Z', expires_at: '2026-11-11T10:00:00Z' }
    expect(conditionLines({ ...base, prize: PRIZES[2] }, 'en')).toEqual([])
    const invite = conditionLines({ ...base, expires_at: '2026-11-16T10:00:00Z', prize: { ...PRIZES[4], cap: 1500 }, invite: { code: 'FRIEND-AAAA-BBBB', expires_at: '2026-11-16T10:00:00Z' } }, 'en')
    expect(invite).toHaveLength(4)
    expect(invite[0]).toContain('15% off')
    expect(invite[0]).toContain('7 days')
    expect(invite[1]).toContain('48 hours')
    expect(invite[2]).toContain('1,500')
    expect(invite[3]).toContain('does not combine')
    expect(daysOf(base.created_at, '2026-11-16T10:00:00Z')).toBe(7)
    expect(daysOf(null, null)).toBe(7)
    const gift = conditionLines({ ...base, prize: PRIZES[5] }, 'en')
    expect(gift[0]).toContain('added to the order free')
    expect(gift[1]).toContain('48 hours')
    expect(gift[1]).toContain('010•••••678')
    expect(gift.join(' ')).not.toContain('no minimum')
    const watch = conditionLines({ ...base, prize: { ...PRIZES[1], cap: 1500, min_order: null, needs: { en: 'This code needs 2 or more items from Watches in your cart.', ar: 'يحتاج هذا الكود قطعتين أو أكثر من الساعات في سلتك.' } } }, 'en')
    expect(watch).toContain('This code needs 2 or more items from Watches in your cart.')
    expect(watch.some((l) => l.includes('up to EGP 1,500'))).toBe(true)
    expect(watch.some((l) => l.includes('no minimum order'))).toBe(true)
    const watchAr = conditionLines({ ...base, prize: { ...PRIZES[1], needs: { en: 'x', ar: 'يحتاج هذا الكود قطعتين أو أكثر من الساعات في سلتك.' } } }, 'ar')
    expect(watchAr).toContain('يحتاج هذا الكود قطعتين أو أكثر من الساعات في سلتك.')
  })
})
