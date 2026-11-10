import { describe, expect, it } from 'vitest'
import { codeRows, daysText, leftParts, leftText } from '../src/lib/wheelCodes'

// The account's wheel codes (2026-11-10, a twin): every code, its state, active first.

const NOW = Date.parse('2026-11-10T12:00:00Z')
const label = (en) => ({ en, ar: en })

const SPINS = [
  { campaign: 'round-1', code: 'SPIN-SHIP', state: 'active', expires_at: '2026-11-12T12:00:00Z', prize: { type: 'free_shipping', label: label('Free shipping'), color: '#0F9D6E' } },
  { campaign: 'round-1', code: 'SPIN-USED', state: 'used', used_order_number: '000031', expires_at: '2026-11-11T12:00:00Z', prize: { type: 'percent_discount', value: 30, label: label('30% off wallets'), color: '#C8102E' } },
  { campaign: 'round-1', code: 'SPIN-OLD', state: 'active', expires_at: '2026-11-09T12:00:00Z', prize: { type: 'percent_discount', value: 10, label: label('10% off the 2nd watch'), color: '#1F4FD8' } },
  { campaign: 'round-1', code: 'SPIN-GIFT', state: 'active', expires_at: '2026-11-11T00:00:00Z', prize: { type: 'gift', label: label('Free perfume gift'), color: '#E0457B' }, gift: { title: { en: 'Asad 100 ml', ar: null } } },
  { campaign: 'round-1', code: null, state: 'none', expires_at: '2026-11-12T12:00:00Z', prize: { type: 'none', label: label('Better luck next time') } },
  {
    campaign: 'round-1',
    code: null,
    state: 'active',
    expires_at: '2026-11-17T12:00:00Z',
    prize: { type: 'referral', value: 15, label: label('Invite a friend: 15% for both'), color: '#7B3FE4' },
    invite: {
      active: true,
      code: 'FRIEND-AAAA',
      expires_at: '2026-11-17T12:00:00Z',
      friends: { placed: 1, delivered: 1 },
      rewards: [
        { code: 'SPIN-RWD1', expires_at: '2026-11-11T06:00:00Z', state: 'active' },
        { code: 'SPIN-RWD0', expires_at: '2026-11-08T06:00:00Z', state: 'expired' },
      ],
    },
  },
  // the reward spin the account also holds: listed inside its invite, never twice
  { campaign: 'referral-3', code: 'SPIN-RWD1', state: 'active', expires_at: '2026-11-11T06:00:00Z', prize: { type: 'percent_discount', value: 15, label: label('Invite reward: 15% off') } },
]

describe('the account’s wheel codes', () => {
  it('lists every code once, of every kind, active first (soonest end on top), then used and expired (latest first)', () => {
    const rows = codeRows(SPINS, NOW)
    expect(rows.map((r) => `${r.code}:${r.kind}:${r.state}`)).toEqual([
      'SPIN-GIFT:gift:active',
      'SPIN-RWD1:reward:active',
      'SPIN-SHIP:code:active',
      'FRIEND-AAAA:invite:active',
      'SPIN-USED:code:used',
      'SPIN-OLD:code:expired',
      'SPIN-RWD0:reward:expired',
    ])
    const used = rows.find((r) => r.code === 'SPIN-USED')
    expect(used.orderNumber).toBe('000031')
    expect(rows.find((r) => r.kind === 'invite').friends).toEqual({ ordered: 2, delivered: 1 })
    expect(rows.find((r) => r.kind === 'gift').giftTitle.en).toBe('Asad 100 ml')
    expect(rows.find((r) => r.code === 'SPIN-RWD1').label.en).toBe('Invite reward: 15% off')
    expect(codeRows(null)).toEqual([])
  })
  it('says what is left as a live clock, with the days when there are any', () => {
    expect(leftText(3 * 3600 * 1000 + 5 * 60 * 1000 + 9000, 'en')).toBe('03:05:09')
    expect(leftText(26 * 3600 * 1000, 'en')).toBe('1 d 02:00:00')
    expect(leftParts(26 * 3600 * 1000)).toEqual({ days: 1, clock: '02:00:00' })
    expect(daysText(1, 'ar')).toBe('1 يوم')
    expect(daysText(0, 'ar')).toBe('')
    expect(leftText(-5, 'en')).toBe('00:00:00')
  })
})
