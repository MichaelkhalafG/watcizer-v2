'use client'
import { FiCheckCircle, FiMail, FiMapPin, FiPhone } from 'react-icons/fi'
import { FaFacebook, FaInstagram, FaWhatsapp } from 'react-icons/fa'
import Link from '@/src/Components/LocaleLink'
import { useUIStore } from '@/src/Store/uiStore'
import { TRUST_PAGES, TRUST_UPDATED } from '@/src/content/trustPages'
// The home sections' label-over-title header (.wz-section-header / .wz-section-title), reused as is.
import '@/src/Components/Home/home.css'
import './trust.css'

// The four trust pages (/about-us, /contact-us, /privacy-policy, /terms-and-conditions) in the
// shopper's language. The text lives in src/content/trustPages.js; this only lays it out.
//
// Design (2026-10-02): built from the storefront's own vocabulary — the home hero's dark band with the
// faint gold grid, gold rule and label and light serif headline (TrustHero, the one new piece); the
// home sections' label-over-title header with its hairline; the account page's white hairline cards;
// the outlined button that fills dark on hover; react-icons as everywhere else. Inline marks
// (**bold**, *italic*, [label](href)) become React ELEMENTS — nothing is ever parsed as HTML.

/** `**bold**`, `*italic*` and `[label](href)` → React nodes. Marks nest (a link inside bold). */
function inline(text, key = 'i') {
  const out = []
  let rest = text
  let n = 0
  while (rest.length) {
    const bold = rest.indexOf('**')
    const link = rest.indexOf('[')
    const ital = rest.search(/(?<!\*)\*(?!\*)/)
    const next = [bold, link, ital].filter((i) => i >= 0).sort((a, b) => a - b)[0]
    if (next === undefined) {
      out.push(rest)
      break
    }
    if (next > 0) out.push(rest.slice(0, next))
    rest = rest.slice(next)
    const k = `${key}.${n++}`
    if (next === bold) {
      const end = rest.indexOf('**', 2)
      if (end < 0) { out.push(rest); break }
      out.push(<strong key={k}>{inline(rest.slice(2, end), k)}</strong>)
      rest = rest.slice(end + 2)
    } else if (next === link) {
      const m = rest.match(/^\[([^\]]+)\]\(([^)\s]+)\)/)
      if (!m) { out.push('['); rest = rest.slice(1); continue }
      const [all, label, href] = m
      const children = inline(label, k)
      if (href.startsWith('/')) out.push(<Link key={k} href={href}>{children}</Link>)
      else if (href.startsWith('https://')) out.push(<a key={k} href={href} target="_blank" rel="noopener noreferrer">{children}</a>)
      else out.push(<a key={k} href={href}>{children}</a>) // tel: / mailto:
      rest = rest.slice(all.length)
    } else {
      const end = rest.indexOf('*', 1)
      if (end < 0) { out.push(rest); break }
      out.push(<em key={k}>{inline(rest.slice(1, end), k)}</em>)
      rest = rest.slice(end + 1)
    }
  }
  return out
}

function Blocks({ blocks }) {
  return blocks.map((b, i) => {
    if (b.p) return <p key={i}>{inline(b.p)}</p>
    if (b.ul) return (
      <ul key={i}>
        {b.ul.map((item, j) => <li key={j}>{inline(item)}</li>)}
      </ul>
    )
    return null
  })
}

/** The page's heading band — the home hero's dark band, gold grid and gold rule, at page-header size. */
function TrustHero({ title, updated }) {
  return (
    <header className="wz-trust-hero">
      <div className="wz-trust-hero__inner">
        <span className="wz-trust-hero__label">
          <span className="wz-trust-hero__rule" aria-hidden="true" />
          <span className="wz-trust-hero__brand">Watchizer</span>
        </span>
        <h1 className="wz-trust-hero__title">{title}</h1>
        {updated ? <p className="wz-trust-hero__updated">{updated}</p> : null}
      </div>
    </header>
  )
}

const ICONS = { phone: FiPhone, whatsapp: FaWhatsapp, mail: FiMail, facebook: FaFacebook, instagram: FaInstagram }

function ContactPage({ t }) {
  return (
    <>
      <section className="wz-trust-section">
        <div className="wz-trust-container">
          <div className="wz-section-header wz-trust-section-header">
            <h2 className="wz-section-title">{t.group}</h2>
          </div>
          <ul className="wz-contact-grid">
            {t.cards.map((card) => {
              const Icon = ICONS[card.icon]
              const external = card.href.startsWith('https://')
              return (
                <li key={card.icon}>
                  <a
                    className={`wz-contact-card wz-contact-card--${card.icon}`}
                    href={card.href}
                    {...(external ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
                  >
                    <span className="wz-contact-card__icon" aria-hidden="true">
                      <Icon />
                    </span>
                    <span className="wz-contact-card__label">{card.label}</span>
                    <span className="wz-contact-card__value" dir={card.ltr ? 'ltr' : undefined}>
                      {/* An address may only wrap at its "@", never inside a word. */}
                      {card.icon === 'mail' ? card.value.split('@').flatMap((part, i) => (i ? [<wbr key={i} />, '@' + part] : [part])) : card.value}
                    </span>
                    {card.hours ? <span className="wz-contact-card__hours">{t.hours}</span> : null}
                  </a>
                </li>
              )
            })}
          </ul>
        </div>
      </section>
      <section className="wz-trust-section wz-trust-section--bone">
        <div className="wz-trust-container wz-contact-bottom">
          <div className="wz-contact-store">
            <span className="wz-contact-card__icon" aria-hidden="true">
              <FiMapPin />
            </span>
            <div>
              <span className="wz-contact-card__label">{t.store.label}</span>
              <p className="wz-contact-store__address">{t.store.address}</p>
              <p className="wz-contact-card__hours">{t.hours}</p>
            </div>
          </div>
          <p className="wz-contact-note">{inline(t.note)}</p>
        </div>
      </section>
    </>
  )
}

function AboutPage({ t }) {
  return (
    <>
      <section className="wz-trust-section">
        <div className="wz-trust-container wz-about-intro">
          <p className="wz-about-lead">{inline(t.lead)}</p>
          <div className="wz-about-mark" role="img" aria-label="Watchizer">
            <span className="wz-about-mark__logo" />
          </div>
        </div>
      </section>
      <section className="wz-trust-section wz-trust-section--bone">
        <div className="wz-trust-container wz-about-cards">
          <article className="wz-about-card">
            <span className="wz-contact-card__icon" aria-hidden="true">
              <FiMapPin />
            </span>
            <p>{inline(t.store)}</p>
          </article>
          <article className="wz-about-card">
            <span className="wz-contact-card__icon" aria-hidden="true">
              <FiCheckCircle />
            </span>
            <p>{inline(t.service)}</p>
          </article>
        </div>
      </section>
    </>
  )
}

function LegalPage({ t, ar }) {
  const num = (i) => (ar ? (i + 1).toLocaleString('ar-EG') : String(i + 1))
  return (
    <section className="wz-trust-section">
      <div className="wz-trust-container wz-legal">
        <nav className="wz-legal-toc" aria-label={t.title}>
          <ol>
            {t.sections.map((s, i) => (
              <li key={i}>
                <a href={`#section-${i + 1}`}>
                  <span className="wz-legal-toc__num">{num(i)}</span>
                  <span>{s.title}</span>
                </a>
              </li>
            ))}
          </ol>
        </nav>
        <div className="wz-legal-body">
          {t.intro ? <p className="wz-legal-intro">{inline(t.intro)}</p> : null}
          {t.sections.map((s, i) => (
            <section key={i} id={`section-${i + 1}`} className="wz-legal-section">
              <h2 className="wz-legal-section__title">
                <span className="wz-legal-section__num">{num(i)}</span>
                <span>{s.title}</span>
              </h2>
              <div className="wz-legal-section__body">
                <Blocks blocks={s.blocks} />
              </div>
            </section>
          ))}
        </div>
      </div>
    </section>
  )
}

export default function TrustPage({ page }) {
  const { language } = useUIStore()
  const ar = language === 'ar'
  const def = TRUST_PAGES[page]
  const t = def[ar ? 'ar' : 'en']
  const date = new Date(`${TRUST_UPDATED}T00:00:00Z`).toLocaleDateString(ar ? 'ar-EG' : 'en-GB', {
    day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC',
  })
  const updated = def.updated ? (ar ? `آخر تحديث: ${date}` : `Last updated: ${date}`) : null

  return (
    <main className={`wz-trust wz-trust--${page}`} dir={ar ? 'rtl' : 'ltr'}>
      <TrustHero title={t.title} updated={updated} />
      {page === 'contact' ? <ContactPage t={t} /> : null}
      {page === 'about' ? <AboutPage t={t} /> : null}
      {page === 'privacy' || page === 'terms' ? <LegalPage t={t} ar={ar} /> : null}
    </main>
  )
}
