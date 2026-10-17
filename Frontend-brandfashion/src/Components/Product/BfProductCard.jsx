'use client'
import { useMemo, useRef, useState } from 'react'
import { PLACEHOLDER_IMG } from '@/src/utils/imageUrl'
import { aisleTint } from '@/src/lib/aisleTint'
import { txt, money, cardColours, cardVariants, isNewProduct } from '@/src/lib/cardData'
import './BfProductCard.css'

/*
 * The Brand Fashion product card — direction C "Shelf", BRAND_FASHION_UI_SPEC v2.11 §8 (B3; re-cut in the
 * visual reset of 2026-10-18 on Watchizer's face).
 *
 * Data: a v2 card row (`/v2/{storefront}/products` → `data[]`, `home`, `related`) or a v2 detail, or the
 * legacy row Watchizer's listing hands its card, mapped by `fromLegacyCard` (ProductCard.jsx). Both carry
 * id, slug, family, title{}, brand{name{}}, image{src}, hover_image{src}, price{}, stock{express, market,
 * in_stock}, the DESCRIPTIVE `colors[]` and the SELECTABLE `variants[]`.
 *
 * What is on it, in order (§8.1): photo on the aisle tint (the second photo on hover, desktop) → at most one
 * badge (sold out › new — "new" is Watchizer's 30-day rule unless the caller decides) → brand → 2-line title →
 * the descriptive colours AS TEXT ("Black, Gold" — they describe, so they never look like a choice) → price +
 * struck old price + red −% → the stock KIND (Express / Market, Watchizer's words) → the picker, only on a
 * product with variant rows (§8.6: colour swatches with a ring on the chosen one, a slash on a colour that is
 * out in every size, a tooltip with the name; hover a colour and the size row shows THAT colour's sizes, a
 * sold-out size struck; on touch the first in-stock colour is chosen on arrival and a tap chooses another) →
 * the button. No rating until 10 (§8.3). No wishlist, no stock count, no delivery promise (§8.4).
 *
 * Tab stops: the card link, the swatch group (one stop, arrows move inside it), each size (a link to the
 * product page with that choice), the button. A product with variants cannot be added from the card today
 * (compat refuses a variant product at the cart — B7's gate), so its button is "Choose options" and leads to
 * the product page with the chosen colour; a sold-out product's "Notify me" leads to the product page's
 * notify form. Nothing on a card claims to do what it cannot.
 */

const STR = {
  en: {
    add: 'Add to cart', notify: 'Notify me', options: 'Choose options', soldOut: 'Sold out', isNew: 'New', was: 'was', colours: 'Colours', colour: 'Colour', size: 'Size',
    qty: 'Quantity', minus: 'Remove one', plus: 'Add one', express: 'Express', market: 'Market', outSize: 'out of stock, notify me', deadColour: 'sold out', more: (n) => `+${n}`,
  },
  ar: {
    add: 'أضف إلى السلة', notify: 'نبّهني', options: 'اختر المقاس واللون', soldOut: 'نفد', isNew: 'جديد', was: 'كان', colours: 'الألوان', colour: 'اللون', size: 'المقاس',
    qty: 'الكمية', minus: 'أنقص واحدًا', plus: 'أضف واحدًا', express: 'إكسبريس', market: 'ماركت', outSize: 'نفد، نبّهني', deadColour: 'نفد', more: (n) => `+${n}`,
  },
}

const uniq = (refs) => {
  const seen = new Set()
  return refs.filter((r) => r && !seen.has(r.id) && (seen.add(r.id), true))
}

export default function BfProductCard({
  product,
  lang = 'en',
  density = 'comfortable',
  tintMode = 'auto',
  ground = 'white',
  showRating = false,
  isNew,
  href,
  onAdd,
}) {
  const [qty, setQty] = useState(0)
  const [broken, setBroken] = useState(false)
  const variants = useMemo(() => cardVariants(product), [product])
  const colourRefs = useMemo(() => uniq(variants.map((v) => v.color)), [variants])
  const sizeRefs = useMemo(() => uniq(variants.map((v) => v.size)).sort((a, b) => (a.sort ?? 0) - (b.sort ?? 0)), [variants])
  const [chosen, setChosen] = useState(() => (colourRefs.find((c) => variants.some((v) => v.color?.id === c.id && v.in_stock)) ?? colourRefs[0])?.id ?? null)
  const [preview, setPreview] = useState(null)
  const groupRef = useRef(null)
  if (!product) return null
  const t = STR[lang] ?? STR.en
  const dir = lang === 'ar' ? 'rtl' : 'ltr'

  const title = txt(product.title, lang)
  const brand = txt(product.brand?.name, lang)
  const price = product.price ?? {}
  const hasSale = Boolean(price.has_sale) && Number(price.sale_amount) > 0 && Number(price.sale_amount) < Number(price.amount)
  const now = money(hasSale ? price.sale_amount : price.amount, lang, price.currency)
  const was = hasSale ? money(price.amount, lang, price.currency) : null
  const pct = hasSale ? (price.discount_pct ?? Math.round((1 - price.sale_amount / price.amount) * 100)) : null
  const soldOut = !product.stock?.in_stock
  const fresh = typeof isNew === 'boolean' ? isNew : isNewProduct(product.created_at)
  // one badge at a time, enforced here: sold out › new (§8.3)
  const badge = soldOut ? 'sold-out' : fresh ? 'new' : null
  // the stock KIND, Watchizer's two words and tones (ProductCard.jsx:90); the count lives on the product page (§8.4)
  const kind = soldOut ? null : Number(product.stock?.express) > 0 ? 'express' : Number(product.stock?.market) > 0 ? 'market' : null

  // the tint rule (§8.3): the family tint on a white ground; `tile` on any tinted ground
  const tintVar = tintMode === 'never' || (tintMode === 'auto' && ground === 'tint') ? 'var(--wz-tile)' : `var(--wz-${aisleTint(product.family)})`

  const colours = cardColours(product)
  const colourNames = colours.map((c) => txt(c.name, lang)).filter(Boolean)
  const shownNames = colourNames.slice(0, 3)
  const src = !broken && product.image?.src ? product.image.src : PLACEHOLDER_IMG
  const isPhoto = src !== PLACEHOLDER_IMG
  const hoverSrc = isPhoto && product.hover_image?.src && product.hover_image.src !== src ? product.hover_image.src : null
  const link = href ?? `/product/${product.slug}`

  // ── the picker (only with variant rows, §11.5 / §8.6)
  const hasPicker = variants.length > 0
  const shownColour = hasPicker ? (preview ?? chosen) : null
  const rowsFor = (cid) => variants.filter((v) => cid == null || v.color?.id === cid)
  const colourDead = (cid) => rowsFor(cid).every((v) => !v.in_stock)
  const sizesShown = sizeRefs.filter((s) => rowsFor(shownColour).some((v) => v.size?.id === s.id))
  const sizeOut = (sid) => rowsFor(shownColour).filter((v) => v.size?.id === sid).every((v) => !v.in_stock)
  const withChoice = (sid) => {
    const p = new URLSearchParams()
    if (shownColour != null) p.set('color', String(shownColour))
    if (sid != null) p.set('size', String(sid))
    const q = p.toString()
    return q ? `${link}${link.includes('?') ? '&' : '?'}${q}` : link
  }
  const onGroupKey = (e) => {
    if (!colourRefs.length) return
    const i = Math.max(0, colourRefs.findIndex((c) => c.id === chosen))
    let next = null
    if (e.key === 'ArrowRight' || e.key === 'ArrowDown') next = (i + 1) % colourRefs.length
    if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') next = (i - 1 + colourRefs.length) % colourRefs.length
    if (next == null) return
    e.preventDefault()
    setChosen(colourRefs[next].id)
    groupRef.current?.querySelectorAll('[role=radio]')[next]?.focus()
  }

  // §7.4: brand, title, price, "was" old price, state — one sentence, the pills inside it
  const name = [brand, title, now, was ? `${t.was} ${was}` : null, soldOut ? t.soldOut : badge === 'new' ? t.isNew : null].filter(Boolean).join(', ')

  const rating = product.rating ?? {}
  const showStars = showRating && Number(rating.count) >= 10

  const add = async (e) => {
    e.preventDefault()
    if (soldOut || hasPicker) return
    const ok = onAdd ? await onAdd(product) : true
    if (ok !== false) setQty(1)
  }

  return (
    <article className={`bf-card ${density === 'compact' ? 'bf-card--compact' : ''} ${soldOut ? 'is-sold-out' : ''} ${hasPicker ? 'has-picker' : ''}`} dir={dir} lang={lang} data-family={product.family}>
      <a className="bf-card__link" href={link} aria-label={name}>
        <div className="bf-card__tile" style={{ background: tintVar }} aria-hidden="true">
          {/* eslint-disable-next-line @next/next/no-img-element -- absolute asset-host URL, fallback is an inline SVG */}
          <img className={`bf-card__img ${isPhoto ? 'is-photo' : 'is-placeholder'}`} src={src} alt="" loading="lazy" decoding="async" onError={() => setBroken(true)} />
          {hoverSrc ? (
            // eslint-disable-next-line @next/next/no-img-element -- the second photo, shown on hover (desktop)
            <img className="bf-card__img bf-card__img--hover is-photo" src={hoverSrc} alt="" loading="lazy" decoding="async" />
          ) : null}
          {badge ? <span className={`bf-card__badge bf-card__badge--${badge}`}>{badge === 'sold-out' ? t.soldOut : t.isNew}</span> : null}
        </div>
        <div className="bf-card__body" aria-hidden="true">
          <div className="bf-card__brand">{brand}</div>
          <h3 className="bf-card__title">{title}</h3>
          <div className="bf-card__colours">
            {shownNames.length ? (
              <>
                {shownNames.join(lang === 'ar' ? '، ' : ', ')}
                {colourNames.length > 3 ? <span className="bf-card__more"> {t.more(colourNames.length - 3)}</span> : null}
              </>
            ) : null}
          </div>
          <div className="bf-card__price">
            <span className="bf-card__now">{now}</span>
            <span className="bf-card__old">
              {was ? <s className="bf-card__was">{was}</s> : null}
              {pct != null ? <span className="bf-card__pct">−{pct}%</span> : null}
            </span>
          </div>
          <div className={`bf-card__kind ${kind ? `bf-card__kind--${kind}` : ''}`}>{kind ? t[kind] : ''}</div>
          {showStars ? <div className="bf-card__rating">★ {Number(rating.avg).toFixed(1)} ({rating.count})</div> : null}
        </div>
      </a>

      {hasPicker ? (
        <div className="bf-pick" aria-label={t.options}>
          {colourRefs.length ? (
            <div className="bf-pick__colours" role="radiogroup" aria-label={t.colour} ref={groupRef} onKeyDown={onGroupKey} onMouseLeave={() => setPreview(null)}>
              {colourRefs.map((c) => {
                const dead = colourDead(c.id)
                const on = chosen === c.id
                const cname = txt(c.name, lang)
                return (
                  <button
                    key={c.id}
                    type="button"
                    role="radio"
                    aria-checked={on}
                    tabIndex={on ? 0 : -1}
                    className={`bf-pick__dot ${dead ? 'is-dead' : ''} ${preview === c.id ? 'is-preview' : ''}`}
                    style={{ background: c.hex }}
                    data-name={dead ? `${cname} — ${t.deadColour}` : cname}
                    aria-label={dead ? `${cname}, ${t.deadColour}` : cname}
                    onMouseEnter={() => setPreview(c.id)}
                    onFocus={() => setPreview(c.id)}
                    onBlur={() => setPreview(null)}
                    onClick={() => setChosen(c.id)}
                  />
                )
              })}
            </div>
          ) : null}
          {sizesShown.length ? (
            <div className="bf-pick__sizes" aria-label={t.size} aria-live="polite">
              {sizesShown.map((s) => {
                const out = sizeOut(s.id)
                const sname = txt(s.name, lang)
                return (
                  <a key={s.id} className={`bf-pick__size ${out ? 'is-out' : ''}`} href={withChoice(s.id)} aria-label={out ? `${sname}, ${t.outSize}` : sname}>
                    {sname}
                  </a>
                )
              })}
            </div>
          ) : null}
        </div>
      ) : null}

      <div className="bf-card__cta">
        {hasPicker ? (
          <a className="bf-card__btn bf-card__btn--secondary" href={withChoice(null)}>{t.options}</a>
        ) : soldOut ? (
          <a className="bf-card__btn bf-card__btn--secondary" href={`${link}#notify`}>{t.notify}</a>
        ) : qty > 0 ? (
          <div className="bf-card__stepper" role="group" aria-label={t.qty}>
            <button type="button" className="bf-card__step" aria-label={t.minus} onClick={() => setQty((q) => Math.max(0, q - 1))}>−</button>
            <span className="bf-card__qty" aria-live="polite">{qty}</span>
            <button type="button" className="bf-card__step" aria-label={t.plus} onClick={() => setQty((q) => q + 1)}>+</button>
          </div>
        ) : (
          <button type="button" className="bf-card__btn" onClick={add}>{t.add}</button>
        )}
      </div>
    </article>
  )
}
