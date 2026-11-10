'use client'
import { useEffect, useMemo, useState } from 'react'
import Link from '../LocaleLink'
import http from '../../Context/api'
import { STOREFRONT_CODE } from '../../lib/env'
import { codeRows, daysText, leftParts } from '../../lib/wheelCodes'
import './WheelTab.css'

// THE ACCOUNT'S "SPIN THE WHEEL" TAB (2026-11-09 evening; every code since 2026-11-10; a TWIN): every code this account
// holds from the wheel — discounts, free shipping, gifts, invites and the rewards an invite earned — each as a TICKET:
// the prize's own wheel colour down its edge, the prize, its state, a torn line, the code with Copy and a live
// countdown (or "used on order #…" / "expired"). Active codes first, the one ending soonest on top; used and expired
// below, quieter. A violet band at the top carries the wheel's festive colour into the account's white pages.
// Read from `GET /api/v2/{sf}/wheel/mine` with the account's token; the list logic is src/lib/wheelCodes.js (tested).
// Shown only while the storefront's wheel is switched on (Account.jsx); a shop without a wheel never draws it.

const T = {
  en: {
    title: 'Spin the wheel',
    band: 'Your wheel codes',
    bandLine: 'Every code you won on the wheel, saved to your account. Each works once, with the phone number you spun with.',
    loading: 'Loading your codes…',
    failed: 'Your codes could not be loaded right now. Try again in a moment.',
    empty: 'No codes yet. Spin the wheel with your mobile number and sign in on the result to save what you win here.',
    spin: 'Spin the wheel',
    active: 'Ready to use',
    past: 'Used and expired',
    copy: 'Copy',
    copied: 'Copied',
    left: 'Ends in',
    friendsLeft: 'Friends can use it for',
    usedOn: (n) => (n ? `Used on order #${n}` : 'Used'),
    expired: 'Expired',
    kind: { code: 'Discount', gift: 'Gift', invite: 'Invite', reward: 'Reward' },
    friends: (o, d) => `${o} ${o === 1 ? 'friend' : 'friends'} ordered · ${d} delivered`,
    gift: (title) => `Your gift: ${title}`,
    shop: 'Shop now',
  },
  ar: {
    title: 'عجلة الحظ',
    band: 'أكواد العجلة',
    bandLine: 'كل كود ربحته على العجلة محفوظ في حسابك. يُستخدم كل كود مرة واحدة، مع رقم الهاتف الذي أدرت به العجلة.',
    loading: 'جارٍ تحميل أكوادك…',
    failed: 'تعذّر تحميل أكوادك الآن. حاول بعد قليل.',
    empty: 'لا أكواد بعد. أدِر العجلة برقم موبايلك وسجّل الدخول عند النتيجة لتحفظ ما تربحه هنا.',
    spin: 'أدِر العجلة',
    active: 'جاهزة للاستخدام',
    past: 'مستخدمة ومنتهية',
    copy: 'نسخ',
    copied: 'تم النسخ',
    left: 'ينتهي خلال',
    friendsLeft: 'يستطيع أصدقاؤك استخدامه خلال',
    usedOn: (n) => (n ? `استُخدم في الطلب #${n}` : 'مستخدم'),
    expired: 'منتهي',
    kind: { code: 'خصم', gift: 'هدية', invite: 'دعوة', reward: 'مكافأة' },
    friends: (o, d) => `${o} صديق طلب · ${d} طلب وصل`,
    gift: (title) => `هديتك: ${title}`,
    shop: 'تسوّق الآن',
  },
}

function MiniWheel() {
  const colors = ['#0F9D6E', '#1F4FD8', '#6B7280', '#C8102E', '#7B3FE4', '#E0457B', '#F28C28']
  const step = 360 / colors.length
  return (
    <svg className="wz-wcodes__wheel" viewBox="0 0 40 40" width="40" height="40" aria-hidden="true" focusable="false">
      {colors.map((c, i) => {
        const a0 = ((i * step - 90) * Math.PI) / 180
        const a1 = (((i + 1) * step - 90) * Math.PI) / 180
        return <path key={c} d={`M20 20 L${(20 + 17 * Math.cos(a0)).toFixed(2)} ${(20 + 17 * Math.sin(a0)).toFixed(2)} A17 17 0 0 1 ${(20 + 17 * Math.cos(a1)).toFixed(2)} ${(20 + 17 * Math.sin(a1)).toFixed(2)} Z`} fill={c} />
      })}
      <circle cx="20" cy="20" r="17.5" fill="none" stroke="#F1DFAE" strokeWidth="2" />
      <circle cx="20" cy="20" r="4" fill="#F1DFAE" />
    </svg>
  )
}

export default function WheelTab({ isRTL }) {
  const lang = isRTL ? 'ar' : 'en'
  const s = T[lang]
  const [spins, setSpins] = useState(null)
  const [failed, setFailed] = useState(false)
  const [copied, setCopied] = useState(null)
  const [now, setNow] = useState(() => Date.now())

  useEffect(() => {
    let alive = true
    http
      .get(`/v2/${STOREFRONT_CODE}/wheel/mine`)
      .then(({ data }) => {
        if (alive) setSpins(Array.isArray(data?.data?.spins) ? data.data.spins : [])
      })
      .catch(() => {
        if (alive) setFailed(true)
      })
    return () => {
      alive = false
    }
  }, [])

  const rows = useMemo(() => codeRows(spins || [], now), [spins, now])
  const live = rows.filter((r) => r.state === 'active')
  const past = rows.filter((r) => r.state !== 'active')

  // the countdowns tick only while an active code is on screen
  useEffect(() => {
    if (live.length === 0) return undefined
    const id = setInterval(() => setNow(Date.now()), 1000)
    return () => clearInterval(id)
  }, [live.length])

  const copy = async (code) => {
    try {
      await navigator.clipboard.writeText(code)
      setCopied(code)
      setTimeout(() => setCopied(null), 1800)
    } catch {
      // the code is selectable text
    }
  }

  const ticket = (row) => {
    const ms = Date.parse(row.expiresAt) - now
    const giftTitle = row.giftTitle ? row.giftTitle[lang] || row.giftTitle.en || row.giftTitle.ar : null
    return (
      <li key={row.key} className={`wz-wcodes__ticket is-${row.state}`} style={{ '--prize': row.color }}>
        <div className="wz-wcodes__top">
          <div className="wz-wcodes__what">
            <span className="wz-wcodes__kind">{s.kind[row.kind]}</span>
            <p className="wz-wcodes__prize">{row.label?.[lang] || row.label?.en}</p>
            {giftTitle ? <p className="wz-wcodes__note">{s.gift(giftTitle)}</p> : null}
            {row.friends ? <p className="wz-wcodes__note">{s.friends(row.friends.ordered, row.friends.delivered)}</p> : null}
          </div>
          <span className={`wz-wcodes__state is-${row.state}`}>
            {row.state === 'active' ? (
              <>
                <span className="wz-wcodes__state-label">{row.kind === 'invite' ? s.friendsLeft : s.left}</span>
                <span className="wz-wcodes__clock">
                  {/* two direction runs: the days in the page's language, the clock left to right */}
                  {leftParts(ms).days > 0 ? <span>{daysText(leftParts(ms).days, lang)} </span> : null}
                  <span dir="ltr">{leftParts(ms).clock}</span>
                </span>
              </>
            ) : row.state === 'used' ? (
              s.usedOn(row.orderNumber)
            ) : (
              s.expired
            )}
          </span>
        </div>
        <div className="wz-wcodes__tear" aria-hidden="true" />
        <div className="wz-wcodes__bottom">
          <output className="wz-wcodes__code" dir="ltr">
            {row.code}
          </output>
          {row.state === 'active' ? (
            <button type="button" className="wz-wcodes__copy" onClick={() => copy(row.code)} aria-label={`${s.copy} ${row.code}`}>
              {copied === row.code ? s.copied : s.copy}
            </button>
          ) : null}
        </div>
      </li>
    )
  }

  return (
    <section className="wz-wcodes" dir={isRTL ? 'rtl' : 'ltr'} aria-labelledby="wz-wcodes-title">
      <div className="wz-acc-sectitle">
        <h2 id="wz-wcodes-title">{s.title}</h2>
        <span className="wz-acc-sectitle-rule" />
      </div>

      <div className="wz-wcodes__band">
        <MiniWheel />
        <div>
          <p className="wz-wcodes__band-title">{s.band}</p>
          <p className="wz-wcodes__band-line">{s.bandLine}</p>
        </div>
      </div>

      {failed ? <p className="wz-wcodes__msg">{s.failed}</p> : null}
      {!failed && spins === null ? <p className="wz-wcodes__msg wz-wcodes__loading">{s.loading}</p> : null}
      {!failed && spins !== null && rows.length === 0 ? (
        <div className="wz-wcodes__empty">
          <p className="wz-wcodes__msg">{s.empty}</p>
          <Link className="wz-wcodes__cta" href="/spin" prefetch={false}>
            {s.spin}
          </Link>
        </div>
      ) : null}

      {live.length > 0 ? (
        <>
          <h3 className="wz-wcodes__group">
            {s.active} <span>{live.length}</span>
          </h3>
          <ul className="wz-wcodes__list">{live.map(ticket)}</ul>
        </>
      ) : null}
      {past.length > 0 ? (
        <>
          <h3 className="wz-wcodes__group wz-wcodes__group--past">
            {s.past} <span>{past.length}</span>
          </h3>
          <ul className="wz-wcodes__list wz-wcodes__list--past">{past.map(ticket)}</ul>
        </>
      ) : null}

      {rows.length > 0 ? (
        <div className="wz-wcodes__foot">
          <Link className="wz-wcodes__cta" href="/" prefetch={false}>
            {s.shop}
          </Link>
          <Link className="wz-wcodes__link" href="/spin" prefetch={false}>
            {s.spin}
          </Link>
        </div>
      ) : null}
    </section>
  )
}
