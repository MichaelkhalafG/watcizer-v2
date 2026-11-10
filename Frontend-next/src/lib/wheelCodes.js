/*
 * The account's wheel codes (2026-11-10, a TWIN): every code an account holds from the wheel, as rows the account tab
 * draws — a discount or free-shipping code, a gift's code, an invite code, and the reward codes an invite earned.
 * Built from `GET /api/v2/{sf}/wheel/mine` (each saved spin as the wheel's page presents it). Pure; tested in
 * tests/wheelCodes.test.mjs.
 *
 * A row: { key, kind: code|gift|invite|reward, label: {en, ar}, color, code, expiresAt, state: active|used|expired,
 *          orderNumber, giftTitle: {en, ar}|null, friends: {ordered, delivered}|null }
 * Order: ACTIVE first, the one ending soonest on top; then used and expired, the most recent first.
 * A "better luck" spin has no code and is not a row; a reward row is listed once (inside the invite's rewards, never
 * also as the reward spin the account holds).
 */

const REWARD_LABEL = { en: 'Invite reward', ar: 'مكافأة الدعوة' }

export function codeRows(spins, nowMs = Date.now()) {
  const rows = []
  const state = (raw, expiresAt) => {
    if (raw === 'used') return 'used'
    if (raw === 'expired') return 'expired'
    const end = Date.parse(expiresAt)
    return Number.isFinite(end) && end <= nowMs ? 'expired' : 'active'
  }
  for (const spin of Array.isArray(spins) ? spins : []) {
    if (!spin || typeof spin !== 'object') continue
    const type = spin.prize?.type
    const label = spin.prize?.label || {}
    const color = spin.prize?.color || '#C8A45C'
    if (type === 'none') continue
    // a reward spin is shown inside its invite (below), never twice
    if (typeof spin.campaign === 'string' && spin.campaign.startsWith('referral-')) continue
    if (type === 'referral') {
      const invite = spin.invite || {}
      if (invite.code) {
        rows.push({
          key: `invite-${invite.code}`,
          kind: 'invite',
          label,
          color,
          code: invite.code,
          expiresAt: invite.expires_at || spin.expires_at,
          state: state(null, invite.expires_at || spin.expires_at),
          orderNumber: null,
          giftTitle: null,
          friends: { ordered: (Number(invite.friends?.placed) || 0) + (Number(invite.friends?.delivered) || 0), delivered: Number(invite.friends?.delivered) || 0 },
        })
      }
      for (const reward of Array.isArray(invite.rewards) ? invite.rewards : []) {
        if (!reward?.code) continue
        rows.push({
          key: `reward-${reward.code}`,
          kind: 'reward',
          label: { en: `${REWARD_LABEL.en}: ${pct(spin.prize?.value)} off`, ar: `${REWARD_LABEL.ar}: خصم ${pct(spin.prize?.value)}` },
          color,
          code: reward.code,
          expiresAt: reward.expires_at,
          state: state(reward.state, reward.expires_at),
          orderNumber: null,
          giftTitle: null,
          friends: null,
        })
      }
      continue
    }
    if (!spin.code) continue
    rows.push({
      key: `code-${spin.code}`,
      kind: type === 'gift' ? 'gift' : 'code',
      label,
      color,
      code: spin.code,
      expiresAt: spin.expires_at,
      state: state(spin.state, spin.expires_at),
      orderNumber: spin.used_order_number || null,
      giftTitle: type === 'gift' ? spin.gift?.title || null : null,
      friends: null,
    })
  }
  const at = (row) => Date.parse(row.expiresAt) || 0
  return rows.sort((a, b) => {
    if ((a.state === 'active') !== (b.state === 'active')) return a.state === 'active' ? -1 : 1
    return a.state === 'active' ? at(a) - at(b) : at(b) - at(a)
  })
}

const pct = (value) => `${Number(Number(value || 0).toFixed(2))}%`

/**
 * What is left, live, in two parts: the whole days (0 when under a day) and the clock `04:09:59`. The page draws them
 * as two direction runs — the days in the page's language, the clock left to right — so an Arabic day word never
 * reorders the digits (seen on the account tab, 2026-11-10).
 */
export function leftParts(ms) {
  const total = Math.max(0, Math.floor(ms / 1000))
  const two = (n) => String(n).padStart(2, '0')
  return { days: Math.floor(total / 86400), clock: `${two(Math.floor((total % 86400) / 3600))}:${two(Math.floor((total % 3600) / 60))}:${two(total % 60)}` }
}

/** The days as words: "1 d" / "1 يوم" ("" under a day). */
export const daysText = (days, lang) => (days > 0 ? (lang === 'ar' ? `${days} يوم` : `${days} d`) : '')

/** "1 d 04:09:59" / "04:09:59" — the English one-line form (tests, the pixels). */
export function leftText(ms, lang) {
  const { days, clock } = leftParts(ms)
  const d = daysText(days, lang)
  return d === '' ? clock : `${d} ${clock}`
}
