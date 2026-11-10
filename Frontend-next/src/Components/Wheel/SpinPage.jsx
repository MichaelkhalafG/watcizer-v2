'use client'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import dynamic from 'next/dynamic'
import Image from 'next/image'
import { FaFacebookMessenger, FaInstagram, FaTiktok, FaWhatsapp } from 'react-icons/fa'
import Link from '../LocaleLink'
import publicHttp from '../../Context/publicApi'
import http from '../../Context/api'
import { STOREFRONT_CODE } from '../../lib/env'
import { useUIStore } from '../../Store/uiStore'
import { useAuthStore } from '../../Store/authStore'
import { trackCouponWon, trackSpin } from '../../scripts/pixels'
import { conditionLines, dayOf, deviceId, headline, inviteLink, inviteMessage, isEgyptianMobile, kindOf, maskPhone, normalizePhone, readSpin, shareTargets, targetRotation, timeLeft, two, writeSpin } from '../../lib/wheel'
import { keepUtm, readUtm } from '../../lib/utm'
import { setAuthReturn } from '../../lib/authReturn'
import SpinWheel from './SpinWheel'
import Backdrop from './Backdrop'
import './wheel.css'

// THE SPIN PAGE (2026-11-07; the carnival version of 2026-11-08; the client's prizes and the design pass of 2026-11-09;
// Michael's review the same evening, WATCHIZER ONLY) — /spin and /ar/spin, a landing page for ads, in its own shell (no
// header, no footer): the brand's mark at its own proportions, a small "Home" pill, a gold headline and an honest line
// (not every spin wins), the wheel in the team's colours on a festive violet sky, the phone field and one gold button,
// the prizes as a legend. On a phone one centred column; on a computer the words and the form on one side and the wheel
// on the other, nothing to scroll. Three states:
//
//   wheel    the phone field and the wheel; a spin asks the SERVER, which decides the prize and issues what there is to
//            issue; the page winds the disc back a little, lets it go (one CSS transition, five turns and a bit, a
//            pointer that ticks, the rim's lamps chasing), lets it settle, lights the won segment — then THE REVEAL: a
//            layer over the whole screen with the result, then the card
//   card     what this device or this phone already got this round — the reveal layer with the card at once, no burst.
//            FOUR KINDS of card (src/lib/wheel.js kindOf):
//              code    a discount or free shipping: the code, copy, the time left (live), the conditions it was won
//                      with, the phone it is bound to (masked), "Shop now"
//              gift    the same, with the product's name and how the gift reaches the order
//              invite  the prize first, then — until the winner has signed in — "Sign in to activate your invite"
//                      (the shop's own sign-in and register pages, the social buttons included; the page comes back
//                      here and activates the invite for the account); once active: the invite code, Copy, the share
//                      buttons (WhatsApp and Messenger with the message pre-filled; Instagram and TikTok copy the
//                      message and open the app, with a note; the phone's own share sheet where there is one; Copy
//                      link), the day it lasts to, the two rules, how many friends ordered and how many orders arrived,
//                      the reward codes as they are earned, the e-mail the reward goes to (masked)
//              none    "better luck": no code, said kindly — confetti and the burst stay away — and "Shop now"
//   ended    the wheel is switched off on the dashboard: a polite line and a way home (never a 404)
//
// The server renders the wheel (or "ended") from the answer it holds, so the HTML and the hydration render are one;
// only a returning visitor's card is decided after mount (storage). Every text is in both languages; the layout is
// logical (RTL by `dir`); every control is a real button or input; all motion is off under prefers-reduced-motion.
// Nothing on the page may change the document's size (Michael, 2026-11-09 evening: the scrollbar flickered): every
// turning layer lives in a clipped box (wheel.css: the stage clips, the sky and the reveal are fixed).

const Confetti = dynamic(() => import('./Confetti'), { ssr: false })

const STR = {
  en: {
    home: 'Home',
    title: ['Spin the wheel,', 'try your luck!'],
    sub: (hours) => `One spin per mobile number each round. Free gifts, free shipping and discounts are on the wheel — not every spin wins. A code you win is yours for ${hours}.`,
    phone: 'Your mobile number',
    phoneHint: 'A code you win works only with this number at checkout.',
    phoneBad: 'Enter an Egyptian mobile number (01xxxxxxxxx).',
    spin: 'Spin the wheel',
    spinning: 'Spinning…',
    checking: 'Checking…',
    congrats: 'Congratulations!',
    thanks: 'Thanks for playing',
    yourSpin: 'Your spin this round',
    yourCode: 'Your code',
    yourInvite: 'Your invite code',
    yourGift: 'Your gift',
    copy: 'Copy code',
    copied: 'Copied!',
    copyLink: 'Copy link',
    share: 'Share…',
    shop: 'Shop now',
    expiresIn: 'Expires in',
    inviteUntil: (day) => `Friends can use it until ${day}`,
    inviteEnded: 'This invite code has ended.',
    expired: 'This code has expired.',
    usedOn: (n) => `Used on order ${n}`,
    boundTo: (p) => `Works only with ${p} at checkout`,
    already: 'This number has already spun this round — here is its result.',
    noneLine: 'This number and this device have had their spin for this round. The shop is open and the offers are on — have a look.',
    giftOut: 'This gift is out of stock right now. Your code stays valid until it ends — try again a little later.',
    friendsOrdered: (n) => (n === 1 ? 'friend ordered' : 'friends ordered'),
    friendsDelivered: (n) => (n === 1 ? 'order delivered' : 'orders delivered'),
    rewards: 'Your reward codes',
    rewardUsed: 'used',
    rewardEnded: 'ended',
    rewardLeft: (h) => `${h} h left`,
    rewardMail: (hint) => `Your reward comes by e-mail to ${hint} and shows here.`,
    activateTitle: 'Sign in to activate your invite',
    activateLine: 'Your invite is tied to this mobile number. Sign in or create an account to activate it — your reward for every friend arrives by e-mail there.',
    signIn: 'Sign in',
    register: 'Create an account',
    activating: 'Activating your invite…',
    saveTitle: 'Save this code to your account',
    saveLine: 'Sign in and the code is kept in your account with its countdown — the code works now either way.',
    saving: 'Saving the code to your account…',
    savedLine: 'Saved to your account.',
    seeCodes: 'See your codes',
    pasted: 'Message copied — paste it in the app.',
    shareWith: 'Share your invite',
    endedTitle: 'This offer has ended.',
    endedSub: 'Thank you for stopping by. The shop is open, as always.',
    backShop: 'Back to the shop',
    failed: 'The wheel could not be spun right now. Please try again.',
    chips: 'Prizes on the wheel',
    chipOff: 'out of stock right now',
    hours: (h) => (h === 48 ? 'two days' : h === 24 ? 'one day' : `${h} hours`),
  },
  ar: {
    home: 'الرئيسية',
    title: ['أدِر العجلة', 'وجرّب حظك!'],
    sub: (hours) => `لفّة واحدة لكل رقم موبايل في الجولة. على العجلة هدايا مجانية وشحن مجاني وخصومات — وليست كل لفّة تربح. الكود الذي تربحه لك خلال ${hours}.`,
    phone: 'رقم موبايلك',
    phoneHint: 'الكود الذي تربحه يعمل فقط مع هذا الرقم عند إتمام الطلب.',
    phoneBad: 'أدخل رقم موبايل مصري (01xxxxxxxxx).',
    spin: 'أدِر العجلة',
    spinning: 'تدور…',
    checking: 'جارٍ التحقق…',
    congrats: 'مبروك!',
    thanks: 'شكرًا لمشاركتك',
    yourSpin: 'لفّتك في هذه الجولة',
    yourCode: 'كودك',
    yourInvite: 'كود دعوتك',
    yourGift: 'هديتك',
    copy: 'نسخ الكود',
    copied: 'تم النسخ!',
    copyLink: 'نسخ الرابط',
    share: 'مشاركة…',
    shop: 'تسوّق الآن',
    expiresIn: 'ينتهي خلال',
    inviteUntil: (day) => `يمكن لأصدقائك استخدامه حتى ${day}`,
    inviteEnded: 'انتهت صلاحية كود الدعوة.',
    expired: 'انتهت صلاحية هذا الكود.',
    usedOn: (n) => `استُخدم في الطلب ${n}`,
    boundTo: (p) => `يعمل فقط مع الرقم ${p} عند إتمام الطلب`,
    already: 'هذا الرقم أدار العجلة في هذه الجولة — هذه نتيجته.',
    noneLine: 'هذا الرقم وهذا الجهاز أدارا العجلة في هذه الجولة. المتجر مفتوح والعروض مستمرة — ألقِ نظرة.',
    giftOut: 'هذه الهدية غير متوفرة الآن. كودك صالح حتى انتهاء مدته — جرّب بعد قليل.',
    friendsOrdered: () => 'صديق طلب',
    friendsDelivered: () => 'طلب وصل',
    rewards: 'أكواد مكافأتك',
    rewardUsed: 'مستخدم',
    rewardEnded: 'انتهى',
    rewardLeft: (h) => `باقٍ ${h} ساعة`,
    rewardMail: (hint) => `مكافأتك تصلك بالبريد على ${hint} وتظهر هنا.`,
    activateTitle: 'سجّل الدخول لتفعيل دعوتك',
    activateLine: 'دعوتك مربوطة برقم الموبايل هذا. سجّل الدخول أو أنشئ حسابًا لتفعيلها — مكافأتك عن كل صديق تصلك بالبريد هناك.',
    signIn: 'تسجيل الدخول',
    register: 'إنشاء حساب',
    activating: 'جارٍ تفعيل دعوتك…',
    saveTitle: 'احفظ هذا الكود في حسابك',
    saveLine: 'سجّل الدخول ويُحفظ الكود في حسابك مع العدّ التنازلي — والكود يعمل الآن في الحالتين.',
    saving: 'جارٍ حفظ الكود في حسابك…',
    savedLine: 'محفوظ في حسابك.',
    seeCodes: 'اعرض أكوادك',
    pasted: 'تم نسخ الرسالة — ألصقها في التطبيق.',
    shareWith: 'شارك دعوتك',
    endedTitle: 'انتهى هذا العرض.',
    endedSub: 'شكرًا لزيارتك. المتجر مفتوح كالعادة.',
    backShop: 'العودة إلى المتجر',
    failed: 'تعذّر إدارة العجلة الآن. حاول مرة أخرى.',
    chips: 'الجوائز على العجلة',
    chipOff: 'غير متوفرة الآن',
    hours: (h) => (h === 48 ? 'يومين' : h === 24 ? 'يوم واحد' : `${h} ساعة`),
  },
}

const WINDUP_MS = 380
const SPIN_MS = 5600
const LIT_MS = 900 // the won segment lit before the reveal
const BURST_MS = 1300 // the result alone, huge, before the card

export default function SpinPage({ wheel }) {
  const language = useUIStore((s) => s.language)
  const lang = language === 'ar' ? 'ar' : 'en'
  const dir = lang === 'ar' ? 'rtl' : 'ltr'
  const t = STR[lang]
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated)
  const segments = useMemo(() => (Array.isArray(wheel?.segments) ? wheel.segments : []), [wheel])
  const enabled = wheel?.enabled === true && segments.length > 0
  const colors = useMemo(() => segments.map((s) => s.color).filter(Boolean), [segments])

  const [state, setState] = useState(enabled ? 'wheel' : 'ended') // wheel | card | ended
  const [spin, setSpin] = useState(null)
  const [note, setNote] = useState(null)
  const [phone, setPhone] = useState('')
  const [busy, setBusy] = useState(false)
  const [rotation, setRotation] = useState(0)
  const [phase, setPhase] = useState('idle') // idle | windup | spinning | landed
  const [reveal, setReveal] = useState('none') // none | burst | card
  const [celebrate, setCelebrate] = useState(false)
  const [copied, setCopied] = useState(null) // which text was copied: 'code' | 'link' | 'app' | a reward code
  const [activating, setActivating] = useState(false)
  const [now, setNow] = useState(0)
  const reduced = useRef(false)
  const pending = useRef(null)
  const activated = useRef(false)
  const mounted = useRef(true)
  const stageRef = useRef(null)
  const glowRef = useRef(null)

  // THE GLOW FOLLOWS THE DISC (2026-11-10): the fixed layer learns the disc's centre and width in viewport pixels on
  // mount, on resize and on scroll (a phone scrolls the page; the layer must stay under the wheel), one measure per frame
  useEffect(() => {
    const stage = stageRef.current
    const glow = glowRef.current
    if (!stage || !glow) return undefined
    let frame = 0
    const measure = () => {
      frame = 0
      const disc = stage.querySelector('.wz-wheel__disc') || stage
      const r = disc.getBoundingClientRect()
      glow.style.setProperty('--gx', `${(r.left + r.width / 2).toFixed(1)}px`)
      glow.style.setProperty('--gy', `${(r.top + r.height / 2).toFixed(1)}px`)
      glow.style.setProperty('--gs', `${r.width.toFixed(1)}px`)
    }
    const ask = () => {
      if (!frame) frame = requestAnimationFrame(measure)
    }
    measure()
    const ro = typeof ResizeObserver === 'function' ? new ResizeObserver(ask) : null
    ro?.observe(stage)
    window.addEventListener('resize', ask)
    window.addEventListener('scroll', ask, { passive: true })
    return () => {
      cancelAnimationFrame(frame)
      ro?.disconnect()
      window.removeEventListener('resize', ask)
      window.removeEventListener('scroll', ask)
    }
  }, [state])
  useEffect(() => {
    mounted.current = true
    return () => {
      mounted.current = false
    }
  }, [])

  useEffect(() => {
    reduced.current = typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
    keepUtm(window.location.search)
    const kept = readSpin(wheel?.campaign || null)
    if (!kept) return
    // eslint-disable-next-line react-hooks/set-state-in-effect -- storage is only readable after mount; the server renders the wheel
    setSpin(kept)
    setState('card')
    setReveal('card')
    publicHttp
      .get(`v2/${STOREFRONT_CODE}/wheel/spin`, { params: { phone: kept.phone || '' } })
      .then(({ data }) => {
        if (data?.data?.spin) {
          setSpin({ ...data.data.spin, phone: kept.phone })
          writeSpin({ ...data.data.spin, phone: kept.phone })
        }
      })
      .catch(() => {})
  }, [wheel?.campaign])

  useEffect(() => {
    if (state !== 'card') return undefined
    // eslint-disable-next-line react-hooks/set-state-in-effect -- this device's clock is only read after mount
    setNow(Date.now())
    const id = setInterval(() => setNow(Date.now()), 1000)
    return () => clearInterval(id)
  }, [state])

  // the reveal: the result alone, then the card
  useEffect(() => {
    if (reveal !== 'burst') return undefined
    const id = setTimeout(() => {
      setReveal('card')
      setState('card')
    }, BURST_MS)
    return () => clearTimeout(id)
  }, [reveal])

  // the invite's activation: the winner is back from the sign-in with a session — the invite becomes the account's.
  // Keyed on the FACTS (a dormant invite on the card, a session), not on the spin object: the mount effect's own
  // refresh of the spin must not restart or discard this request (it did, and the card stayed on "activating").
  // Since 2026-11-10 EVERY prize with something to keep is saved this way (a code, a gift, an invite); "better luck" is not.
  const unsaved = state === 'card' && !!spin && kindOf(spin) !== 'none' && spin.saved !== true && (kindOf(spin) !== 'invite' || spin.invite?.active !== true)
  const spinPhone = spin?.phone || ''
  useEffect(() => {
    if (!unsaved || !isAuthenticated || activated.current) return
    activated.current = true
    // eslint-disable-next-line react-hooks/set-state-in-effect -- the session is only known after mount
    setActivating(true)
    http
      .post(`/v2/${STOREFRONT_CODE}/wheel/claim`, { phone: spinPhone })
      .then(({ data }) => {
        if (!mounted.current || !data?.data?.spin) return
        setSpin((prev) => {
          const next = { ...data.data.spin, phone: prev?.phone || spinPhone }
          writeSpin(next)
          return next
        })
      })
      .catch((err) => {
        if (!mounted.current) return
        activated.current = false
        const body = err?.response?.data?.data || err?.response?.data || {}
        if (body.messages) setNote({ kind: 'error', text: body.messages[lang] || body.messages.en })
      })
      .finally(() => {
        if (mounted.current) setActivating(false)
      })
  }, [unsaved, isAuthenticated, spinPhone, lang])

  const landed = useCallback(() => {
    const result = pending.current
    pending.current = null
    setPhase('landed')
    if (!result) return
    setSpin(result)
    writeSpin(result)
    if (reduced.current) {
      setReveal('card')
      setState('card')
      return
    }
    const won = kindOf(result) !== 'none'
    try {
      navigator.vibrate?.(won ? [24, 40, 60] : [30])
    } catch {
      // no haptics here
    }
    setTimeout(() => {
      if (won) setCelebrate(true)
      setReveal('burst')
    }, LIT_MS)
  }, [])
  const endCelebration = useCallback(() => setCelebrate(false), [])

  const submit = async (event) => {
    event.preventDefault()
    if (busy || phase === 'windup' || phase === 'spinning') return
    const normalized = normalizePhone(phone)
    if (!normalized) {
      setNote({ kind: 'error', text: t.phoneBad })
      return
    }
    setNote(null)
    setBusy(true)
    try {
      const utm = readUtm()
      const { data } = await publicHttp.post(`v2/${STOREFRONT_CODE}/wheel/spin`, { phone: normalized, device: deviceId(), locale: lang, utm })
      const body = data?.data || {}
      const result = body.spin ? { ...body.spin, phone: normalized } : null
      if (!result) throw new Error('no spin')
      if (body.status === 'already_spun') {
        setSpin(result)
        writeSpin(result)
        setNote({ kind: 'info', text: t.already })
        setReveal('card')
        setState('card')
        return
      }
      trackSpin({ campaign: result.campaign })
      const wonCode = result.code || result.invite?.code || null
      if (wonCode) trackCouponWon({ code: wonCode, prize: result.prize?.label?.en || null, campaign: result.campaign })
      const index = Number.isInteger(result.prize?.index) ? result.prize.index : Math.max(0, segments.findIndex((s) => s.id === result.prize?.id))
      pending.current = result
      const seed = wonCode || `${result.campaign}-${normalized}`
      if (reduced.current) {
        setRotation(targetRotation(index, segments.length, 0, seed))
        landed()
      } else {
        setPhase('windup')
        setRotation((r) => r - 28)
        setTimeout(() => {
          setPhase('spinning')
          setRotation((r) => r + 28 + targetRotation(index, segments.length, 5, seed) - ((r + 28) % 360))
        }, WINDUP_MS)
      }
    } catch (err) {
      const body = err?.response?.data?.data || err?.response?.data || {}
      const status = err?.response?.status
      if (status === 410 || body.error === 'wheel_off') {
        setState('ended')
        return
      }
      setNote({ kind: 'error', text: body.messages?.[lang] || body.messages?.en || t.failed })
    } finally {
      setBusy(false)
    }
  }

  const copyText = async (text, key) => {
    if (!text) return false
    try {
      await navigator.clipboard.writeText(text)
      setCopied(key)
      setTimeout(() => setCopied(null), 2600)
      return true
    } catch {
      return false // an old browser: the code is selectable text
    }
  }

  const kind = spin ? kindOf(spin) : 'code'
  const left = spin && now ? timeLeft(spin.expires_at, now) : null
  const cardState = spin ? (spin.state === 'used' ? 'used' : (left && left.total === 0) || spin.state === 'expired' ? 'expired' : 'active') : null
  const inviteActive = spin?.invite?.active === true
  const inviteLeft = spin?.invite?.expires_at && now ? timeLeft(spin.invite.expires_at, now) : null
  const inviteLive = inviteLeft ? inviteLeft.total > 0 : true
  const head = spin ? headline(spin, lang) : { big: '', sub: null, words: true }
  const prizeColor = spin?.prize?.color || colors[0] || '#F0C75E'
  const wonIndex = spin && Number.isInteger(spin.prize?.index) ? spin.prize.index : null
  const ttl = Number(wheel?.ttl_hours) > 0 ? Number(wheel.ttl_hours) : 48
  const spinningNow = phase === 'windup' || phase === 'spinning'
  const percent = Number(spin?.prize?.value) || 0
  const percentText = String(Number(percent.toFixed(2)))
  const friendsPlaced = Number(spin?.invite?.friends?.placed) || 0
  const friendsDelivered = Number(spin?.invite?.friends?.delivered) || 0
  const rewards = Array.isArray(spin?.invite?.rewards) ? spin.invite.rewards : []
  const lines = spin ? conditionLines(spin, lang) : []
  const origin = typeof window !== 'undefined' ? window.location.origin : ''
  const link = inviteActive ? inviteLink(origin, spin.invite.code, lang) : ''
  const message = inviteActive ? inviteMessage(spin.invite.code, percentText, link, lang) : ''
  const canSheet = typeof navigator !== 'undefined' && typeof navigator.share === 'function'
  const targets = inviteActive ? shareTargets(message, link, typeof navigator !== 'undefined' && /Android|iPhone|iPad|iPod/i.test(navigator.userAgent)) : null

  const openApp = async (target) => {
    if (target.copyFirst) {
      await copyText(message, 'app')
    }
    window.open(target.href, '_blank', 'noopener')
  }
  const sheet = async () => {
    try {
      await navigator.share({ text: message })
    } catch {
      // the visitor closed the sheet
    }
  }
  const goSignIn = () => setAuthReturn('/spin')

  const congrats = kind === 'none' ? t.thanks : note?.kind === 'info' ? t.yourSpin : t.congrats

  return (
    <div className={`sp sp--${state}${reveal !== 'none' ? ' sp--revealing' : ''}`} dir={dir} lang={lang} data-page="spin">
      <Backdrop colors={colors} />
      {/* the glow, the turning ring and the landing flash behind the disc: fixed to the viewport, never clipped, never layout */}
      {state !== 'ended' ? (
        <div className={`sp-glow${spinningNow ? ' is-spinning' : ''}${phase === 'landed' ? ' is-landed' : ''}`} ref={glowRef} style={{ '--prize': prizeColor }} aria-hidden="true">
          <div className="sp-glow__shadow" />
          <div className="sp-glow__soft" />
          <div className="sp-glow__ring" />
          <div className="sp-glow__flash" />
        </div>
      ) : null}

      <Link href="/" className="sp-home" prefetch={false}>
        <span className="sp-home__arrow" aria-hidden="true">
          {dir === 'rtl' ? '→' : '←'}
        </span>
        {t.home}
      </Link>

      <div className="sp-frame">
        <div className="sp-brand">
          {/* the mark at its own proportions (1265×851): width and height agree, the CSS sets the width only */}
          <Image src="/logo.webp" alt="Watchizer" width={120} height={81} priority />
        </div>

        {state === 'ended' ? (
          <section className="sp-ended" aria-labelledby="sp-title">
            <h1 className="sp-title" id="sp-title">
              {t.endedTitle}
            </h1>
            <p className="sp-sub">{t.endedSub}</p>
            <Link className="sp-btn" href="/" prefetch={false}>
              {t.backShop}
            </Link>
          </section>
        ) : (
          <>
            <div className="sp-side">
              <header className="sp-head">
                <h1 className="sp-title" id="sp-title">
                  {t.title[0]}
                  <br />
                  {t.title[1]}
                </h1>
                <p className="sp-sub">{t.sub(t.hours(ttl))}</p>
              </header>

              <form className="sp-form" onSubmit={submit} noValidate>
                <label className="sp-form__label" htmlFor="sp-phone">
                  {t.phone}
                </label>
                <div className="sp-form__row">
                  <input
                    id="sp-phone"
                    className={`sp-input${note?.kind === 'error' ? ' is-error' : ''}`}
                    type="tel"
                    inputMode="numeric"
                    autoComplete="tel-national"
                    dir="ltr"
                    maxLength={14}
                    placeholder="01xxxxxxxxx"
                    value={phone}
                    onChange={(e) => {
                      setPhone(e.target.value)
                      if (note) setNote(null)
                    }}
                    disabled={spinningNow || state === 'card'}
                    aria-describedby="sp-hint"
                    aria-invalid={note?.kind === 'error' ? true : undefined}
                  />
                  <button type="submit" className="sp-btn" disabled={spinningNow || busy || state === 'card' || !isEgyptianMobile(phone)}>
                    {spinningNow ? t.spinning : busy ? t.checking : t.spin}
                  </button>
                </div>
                <p className="sp-form__hint" id="sp-hint">
                  {t.phoneHint}
                </p>
                {note && reveal === 'none' ? (
                  <p className={`sp-note sp-note--${note.kind}`} role={note.kind === 'error' ? 'alert' : 'status'}>
                    {note.text}
                  </p>
                ) : null}
              </form>

              <ul className="sp-chips" aria-label={t.chips}>
                {segments.map((s) => (
                  <li key={s.id} className={s.drawable === false ? 'is-off' : undefined} style={{ '--c': s.color || '#F0C75E' }} title={s.drawable === false ? t.chipOff : (s.label?.[lang] ?? s.label?.en)}>
                    <i aria-hidden="true" />
                    <span>{s.label?.[lang] ?? s.label?.en}</span>
                  </li>
                ))}
              </ul>
            </div>

            <div className={`sp-stage${phase === 'landed' ? ' is-landed' : ''}`} style={{ '--prize': prizeColor }} ref={stageRef}>
              <div className="sp-stage__tilt">
                <SpinWheel segments={segments} rotation={rotation} lang={lang} phase={phase} highlight={wonIndex} />
              </div>
            </div>
          </>
        )}
      </div>

      {/* THE REVEAL — a layer over the whole screen: nothing of it can be cut by a container */}
      {reveal !== 'none' && spin ? (
        <div className={`sp-reveal sp-reveal--${reveal} sp-reveal--${kind}`} style={{ '--prize': prizeColor }} role="dialog" aria-modal="true" aria-labelledby="sp-reveal-title" dir={dir}>
          <div className="sp-reveal__dim" aria-hidden="true" />
          {kind !== 'none' ? <div className="sp-reveal__burst" aria-hidden="true" /> : null}
          {celebrate ? <Confetti color={prizeColor} onDone={endCelebration} /> : null}
          {/* the one scroller of the result: over the dim and the rays, so a wheel or a swipe anywhere reaches the card's
              end on a short screen; inside the fixed layer, so the page behind never changes size */}
          <div className="sp-reveal__scroll">
            <div className="sp-reveal__centre">
              <p className="sp-reveal__congrats">{congrats}</p>
              <h2 className={`sp-reveal__prize${head.words ? ' sp-reveal__prize--words' : ''}`} id="sp-reveal-title">
                {head.big}
              </h2>
              {head.sub ? <p className="sp-reveal__prize-sub">{head.sub}</p> : null}

              <section className={`sp-card sp-card--${kind}`} aria-live="polite">
                {note ? <p className={`sp-note sp-note--${note.kind}`}>{note.text}</p> : null}

                {kind === 'none' ? (
                  <>
                    <p className="sp-card__kind">{t.noneLine}</p>
                    <Link className="sp-btn" href="/" prefetch={false}>
                      {t.shop}
                    </Link>
                  </>
                ) : null}

                {kind === 'invite' && !inviteActive ? (
                  <>
                    <p className="sp-card__kind sp-card__kind--title">{t.activateTitle}</p>
                    <p className="sp-card__kind">{t.activateLine}</p>
                    {activating ? (
                      <p className="sp-card__state sp-card__state--wait" role="status">
                        {t.activating}
                      </p>
                    ) : (
                      <div className="sp-card__row2">
                        <Link className="sp-btn" href="/login" prefetch={false} onClick={goSignIn}>
                          {t.signIn}
                        </Link>
                        <Link className="sp-btn sp-btn--ghost" href="/register" prefetch={false} onClick={goSignIn}>
                          {t.register}
                        </Link>
                      </div>
                    )}
                    <p className="sp-card__bound">{t.boundTo(spin.phone_hint || maskPhone(spin.phone || ''))}</p>
                  </>
                ) : null}

                {kind === 'invite' && inviteActive ? (
                  <>
                    <p className="sp-card__label">{t.yourInvite}</p>
                    <output className="sp-card__code" dir="ltr">
                      {spin.invite.code}
                    </output>
                    <div className="sp-card__row2">
                      <button type="button" className="sp-btn" onClick={() => copyText(spin.invite.code, 'code')} disabled={!inviteLive}>
                        {copied === 'code' ? t.copied : t.copy}
                      </button>
                      <button type="button" className="sp-btn sp-btn--ghost" onClick={() => copyText(link, 'link')} disabled={!inviteLive}>
                        {copied === 'link' ? t.copied : t.copyLink}
                      </button>
                    </div>
                    {inviteLive && targets ? (
                      <div className="sp-share" role="group" aria-label={t.shareWith}>
                        <p className="sp-card__label">{t.shareWith}</p>
                        <div className="sp-share__row">
                          <a className="sp-share__btn sp-share__btn--wa" href={targets.whatsapp.href} target="_blank" rel="noopener noreferrer" aria-label="WhatsApp">
                            <FaWhatsapp aria-hidden="true" />
                            <span>WhatsApp</span>
                          </a>
                          <button type="button" className="sp-share__btn sp-share__btn--ms" onClick={() => openApp(targets.messenger)} aria-label="Messenger">
                            <FaFacebookMessenger aria-hidden="true" />
                            <span>Messenger</span>
                          </button>
                          <button type="button" className="sp-share__btn sp-share__btn--ig" onClick={() => openApp(targets.instagram)} aria-label="Instagram">
                            <FaInstagram aria-hidden="true" />
                            <span>Instagram</span>
                          </button>
                          <button type="button" className="sp-share__btn sp-share__btn--tt" onClick={() => openApp(targets.tiktok)} aria-label="TikTok">
                            <FaTiktok aria-hidden="true" />
                            <span>TikTok</span>
                          </button>
                          {canSheet ? (
                            <button type="button" className="sp-share__btn sp-share__btn--more" onClick={sheet}>
                              <span>{t.share}</span>
                            </button>
                          ) : null}
                        </div>
                        {copied === 'app' ? (
                          <p className="sp-share__note" role="status">
                            {t.pasted}
                          </p>
                        ) : null}
                      </div>
                    ) : null}
                    {inviteLive && spin.invite?.expires_at ? <p className="sp-card__left">{t.inviteUntil(dayOf(spin.invite.expires_at, lang))}</p> : null}
                    {!inviteLive ? <p className="sp-card__state sp-card__state--expired">{t.inviteEnded}</p> : null}
                    <ul className="sp-card__lines">
                      {lines.map((line) => (
                        <li key={line}>{line}</li>
                      ))}
                    </ul>
                    <div className="sp-card__friends">
                      <div>
                        <strong>{friendsPlaced + friendsDelivered}</strong>
                        <span>{t.friendsOrdered(friendsPlaced + friendsDelivered)}</span>
                      </div>
                      <div>
                        <strong>{friendsDelivered}</strong>
                        <span>{t.friendsDelivered(friendsDelivered)}</span>
                      </div>
                    </div>
                    {rewards.length > 0 ? (
                      <div className="sp-card__rewards">
                        <p>{t.rewards}</p>
                        {rewards.map((r) => {
                          const rl = now ? timeLeft(r.expires_at, now) : null
                          const active = r.state === 'active' && (!rl || rl.total > 0)
                          return (
                            <div className="sp-card__reward" key={r.code}>
                              <output dir="ltr">{r.code}</output>
                              <span>{r.state === 'used' ? t.rewardUsed : !active ? t.rewardEnded : rl ? t.rewardLeft(rl.h) : ''}</span>
                              {active ? (
                                <button type="button" onClick={() => copyText(r.code, r.code)}>
                                  {copied === r.code ? t.copied : t.copy}
                                </button>
                              ) : null}
                            </div>
                          )
                        })}
                      </div>
                    ) : null}
                    {spin.invite?.email_hint ? <p className="sp-card__bound">{t.rewardMail(spin.invite.email_hint)}</p> : null}
                    <p className="sp-card__bound">{t.boundTo(spin.phone_hint || maskPhone(spin.phone || ''))}</p>
                    <Link className="sp-btn sp-btn--ghost" href="/" prefetch={false}>
                      {t.shop}
                    </Link>
                  </>
                ) : null}

                {kind === 'code' || kind === 'gift' ? (
                  <>
                    {kind === 'gift' && spin.gift?.in_stock === false ? <p className="sp-note sp-note--info">{t.giftOut}</p> : null}
                    <p className="sp-card__label">{t.yourCode}</p>
                    <div className="sp-card__code-row">
                      <output className="sp-card__code" dir="ltr">
                        {spin.code}
                      </output>
                      <button type="button" className="sp-btn" onClick={() => copyText(spin.code, 'code')} disabled={cardState !== 'active'}>
                        {copied === 'code' ? t.copied : t.copy}
                      </button>
                    </div>
                    {cardState === 'active' && left ? (
                      <p className="sp-card__left">
                        <span>{t.expiresIn}</span>
                        <span className="sp-card__clock" dir="ltr">
                          {two(left.h)}:{two(left.m)}:{two(left.s)}
                        </span>
                      </p>
                    ) : null}
                    {cardState === 'used' ? <p className="sp-card__state sp-card__state--used">{t.usedOn(spin.used_order_number || '')}</p> : null}
                    {cardState === 'expired' ? <p className="sp-card__state sp-card__state--expired">{t.expired}</p> : null}
                    <ul className="sp-card__lines">
                      {lines.map((line) => (
                        <li key={line}>{line}</li>
                      ))}
                    </ul>
                    {spin.saved ? (
                      <p className="sp-saved">
                        <svg aria-hidden="true" viewBox="0 0 16 16" width="15" height="15">
                          <path d="M3 8.5l3.2 3L13 4.5" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
                        </svg>
                        <span>{t.savedLine}</span>
                        <Link href="/account?tab=wheel" prefetch={false}>
                          {t.seeCodes}
                        </Link>
                      </p>
                    ) : activating || isAuthenticated ? (
                      <p className="sp-card__state sp-card__state--wait" role="status">
                        {t.saving}
                      </p>
                    ) : cardState === 'active' ? (
                      <div className="sp-save">
                        <p className="sp-save__title">{t.saveTitle}</p>
                        <p className="sp-save__line">{t.saveLine}</p>
                        <div className="sp-card__row2">
                          <Link className="sp-btn sp-btn--ghost sp-btn--small" href="/login" prefetch={false} onClick={goSignIn}>
                            {t.signIn}
                          </Link>
                          <Link className="sp-btn sp-btn--ghost sp-btn--small" href="/register" prefetch={false} onClick={goSignIn}>
                            {t.register}
                          </Link>
                        </div>
                      </div>
                    ) : null}
                    <Link className="sp-btn sp-btn--ghost" href="/" prefetch={false}>
                      {t.shop}
                    </Link>
                  </>
                ) : null}
              </section>
            </div>
          </div>
        </div>
      ) : null}
      <SpinEnd active={phase === 'spinning'} ms={SPIN_MS} onEnd={landed} />
    </div>
  )
}

/** Calls `onEnd` once the disc's transition is over (its duration is the CSS's, SPIN_MS). */
function SpinEnd({ active, ms, onEnd }) {
  useEffect(() => {
    if (!active) return undefined
    const id = setTimeout(onEnd, ms + 80)
    return () => clearTimeout(id)
  }, [active, ms, onEnd])
  return null
}
