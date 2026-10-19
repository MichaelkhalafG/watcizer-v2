'use client'
import { useMemo, useRef, useState } from 'react'
import Link from '@/src/Components/LocaleLink'
import { PLACEHOLDER_IMG } from '@/src/utils/imageUrl'
import { aisleTint } from '@/src/lib/aisleTint'
import { txt, money, cardColours, cardVariants, isNewProduct } from '@/src/lib/cardData'
import useCart, { getItemKey } from '@/src/Hooks/useCart'
import { refusedAddMessage } from '@/src/Store/cartStore'
import { useToastStore } from '@/src/Store/toastStore'
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
 * the descriptive colours AS TEXT ("Black, Gold" — they describe, so they never look like a choice; on a card
 * with a picker this line names the chosen or previewed colour instead) → price + struck old price + red −% →
 * the stock KIND (Express / Market, Watchizer's words) → the button. No rating until 10 (§8.3). No wishlist, no
 * stock count, no delivery promise (§8.4).
 *
 * The picker (§8.6), only on a product with variant rows, is laid OVER THE PHOTO's bottom edge (Option A,
 * 2026-10-18 afternoon) and takes no room in the card, so every card in a row is the same height: colour
 * swatches in ONE tab — the card's white body rising into the photo's corner (round 4) — a ring on the chosen one, a slash on a colour out in every size; the size row rises
 * above them when the card is engaged — hover or focus on a hover device, a swatch tap on touch (`engaged`) —
 * and shows THAT colour's sizes, a sold-out size struck; a product with sizes and no colours shows its sizes at
 * rest. On touch the first in-stock colour is chosen on arrival and a tap chooses another.
 *
 * Every link on the card is the twin LocaleLink (next/link): a client-side navigation, so coming BACK from a
 * product restores the listing's scroll position — Watchizer's card's behaviour (2026-10-18 night run: a plain
 * <a> had lost it, measured 1,500 → 0). Size labels render in ENGLISH in both languages (a size is a code, not a
 * word — "S", "36mm"; the lookup's Arabic rows are transliterations); colour names stay translated.
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

// many colours: the card shows this many swatches, then "+N" to the product page (the CSS cap must match:
// `.bf-pick__dot:nth-child(n + 6)` and, on a phone, `(n + 4)`)
const DOTS_DESKTOP = 5
const DOTS_PHONE = 3
const MORE_COLOURS = { en: (n) => `${n} more colours`, ar: (n) => `ألوان أخرى: ${n}` }

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
  // The stepper IS the cart line (2026-10-19): it shows the cart's quantity for this product and every tap
  // writes it. It used to be a local number — after the first add, + and − changed the card and not the cart.
  const { cart, addItem, updateQuantity } = useCart()
  const showToast = useToastStore((s) => s.showToast)
  const line = product ? (cart?.cart_item || []).find((i) => i.product_id === product.id && !i.variant_id) : null
  const qty = line?.quantity ?? 0
  const [broken, setBroken] = useState(false)
  const variants = useMemo(() => cardVariants(product), [product])
  const colourRefs = useMemo(() => uniq(variants.map((v) => v.color)), [variants])
  const sizeRefs = useMemo(() => uniq(variants.map((v) => v.size)).sort((a, b) => (a.sort ?? 0) - (b.sort ?? 0)), [variants])
  const [chosen, setChosen] = useState(() => (colourRefs.find((c) => variants.some((v) => v.color?.id === c.id && v.in_stock)) ?? colourRefs[0])?.id ?? null)
  const [preview, setPreview] = useState(null)
  // touch has no hover: a swatch tap "engages" the card and the size row rises over the photo (desktop: hover/focus, CSS)
  const [engaged, setEngaged] = useState(false)
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
  // arrows move through the VISIBLE swatches (the cap hides the rest; a hidden button cannot take focus)
  const onGroupKey = (e) => {
    const radios = [...(groupRef.current?.querySelectorAll('[role=radio]') ?? [])].filter((el) => el.offsetParent !== null)
    if (!radios.length) return
    const i = Math.max(0, radios.findIndex((el) => el.getAttribute('aria-checked') === 'true'))
    let next = null
    if (e.key === 'ArrowRight' || e.key === 'ArrowDown') next = (i + 1) % radios.length
    if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') next = (i - 1 + radios.length) % radios.length
    if (next == null) return
    e.preventDefault()
    const ref = colourRefs[Number(radios[next].dataset.i)]
    if (ref) setChosen(ref.id)
    radios[next].focus()
  }

  // §7.4: brand, title, price, "was" old price, state — one sentence, the pills inside it
  const name = [brand, title, now, was ? `${t.was} ${was}` : null, soldOut ? t.soldOut : badge === 'new' ? t.isNew : null].filter(Boolean).join(', ')

  const rating = product.rating ?? {}
  const showStars = showRating && Number(rating.count) >= 10

  // A caller's onAdd wins (Watchizer's listing adapter, ProductCard.jsx); without one — the home and the product
  // page's rails — the card adds itself, on the same rule (the effective price, the refusal said in a toast).
  // It used to fall back to `true`: the rails' button showed "1" and added nothing.
  const refused = (r) => {
    if (r && r.ok === false) {
      showToast?.(refusedAddMessage(r, lang === 'ar'), 'error')
      return true
    }
    return false
  }
  const add = async (e) => {
    e.preventDefault()
    if (soldOut || hasPicker) return
    if (onAdd) {
      await onAdd(product)
      return
    }
    const r = await addItem({ product_id: product.id, quantity: 1, piece_price: hasSale ? price.sale_amount : price.amount, name: txt(product.title, 'en') })
    refused(r)
  }
  const step = async (by) => {
    if (!line) return
    refused(await updateQuantity(getItemKey(line), qty + by))
  }

  return (
    <article className={`bf-card ${density === 'compact' ? 'bf-card--compact' : ''} ${soldOut ? 'is-sold-out' : ''} ${hasPicker ? 'has-picker' : ''} ${engaged ? 'is-engaged' : ''}`} dir={dir} lang={lang} data-family={product.family}>
      <Link className="bf-card__link" href={link} aria-label={name}>
        <div className="bf-card__tile" style={{ background: tintVar }} aria-hidden="true">
          {/* eslint-disable-next-line @next/next/no-img-element -- absolute asset-host URL, fallback is an inline SVG */}
          <img className={`bf-card__img ${isPhoto ? 'is-photo' : 'is-placeholder'}`} src={src} alt="" loading="lazy" decoding="async" onError={() => setBroken(true)} />
          {hoverSrc ? (
            // the second photo, crossfaded in on hover (desktop) inside its own wrapper on the tile's tint (T1, round 4)
            <span className="bf-card__ph2" style={{ background: tintVar }}>
              {/* eslint-disable-next-line @next/next/no-img-element -- the second photo, absolute asset-host URL */}
              <img className="bf-card__img bf-card__img--hover is-photo" src={hoverSrc} alt="" loading="lazy" decoding="async" />
            </span>
          ) : null}
          {badge ? <span className={`bf-card__badge bf-card__badge--${badge}`}>{badge === 'sold-out' ? t.soldOut : t.isNew}</span> : null}
        </div>
        <div className="bf-card__body" aria-hidden="true">
          <div className="bf-card__brand">{brand}</div>
          <h3 className="bf-card__title">{title}</h3>
          <div className="bf-card__colours">
            {hasPicker && colourRefs.length ? (
              txt(colourRefs.find((c) => c.id === (preview ?? chosen))?.name, lang)
            ) : shownNames.length ? (
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
      </Link>

      {hasPicker ? (
        <div className={`bf-pick ${colourRefs.length ? '' : 'bf-pick--sizes-only'}`} aria-label={t.options}>
          <div className="bf-pick__tab">
          {colourRefs.length ? (
            <div className="bf-pick__row">
            <div className="bf-pick__colours" role="radiogroup" aria-label={t.colour} ref={groupRef} onKeyDown={onGroupKey} onMouseLeave={() => setPreview(null)}>
              {colourRefs.map((c, idx) => {
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
                    data-i={idx}
                    data-name={dead ? `${cname} — ${t.deadColour}` : cname}
                    aria-label={dead ? `${cname}, ${t.deadColour}` : cname}
                    onMouseEnter={() => setPreview(c.id)}
                    onFocus={() => setPreview(c.id)}
                    onBlur={() => setPreview(null)}
                    onClick={() => {
                      setChosen(c.id)
                      setEngaged(true)
                    }}
                  />
                )
              })}
            </div>
            {colourRefs.length > DOTS_DESKTOP ? (
              <Link className="bf-pick__more bf-pick__more--desktop" href={link} aria-label={(MORE_COLOURS[lang] ?? MORE_COLOURS.en)(colourRefs.length - DOTS_DESKTOP)}>
                +{colourRefs.length - DOTS_DESKTOP}
              </Link>
            ) : null}
            {colourRefs.length > DOTS_PHONE ? (
              <Link className="bf-pick__more bf-pick__more--phone" href={link} aria-label={(MORE_COLOURS[lang] ?? MORE_COLOURS.en)(colourRefs.length - DOTS_PHONE)}>
                +{colourRefs.length - DOTS_PHONE}
              </Link>
            ) : null}
            </div>
          ) : null}
          {sizesShown.length ? (
            <div className="bf-pick__sizes" aria-label={t.size} aria-live="polite">
              {sizesShown.map((s) => {
                const out = sizeOut(s.id)
                const sname = txt(s.name, 'en')
                return (
                  <Link key={s.id} className={`bf-pick__size ${out ? 'is-out' : ''}`} href={withChoice(s.id)} aria-label={out ? `${sname}, ${t.outSize}` : sname}>
                    {sname}
                  </Link>
                )
              })}
            </div>
          ) : null}
          </div>
        </div>
      ) : null}

      <div className="bf-card__cta">
        {hasPicker ? (
          <Link className="bf-card__btn bf-card__btn--secondary" href={withChoice(null)}>{t.options}</Link>
        ) : soldOut ? (
          <Link className="bf-card__btn bf-card__btn--secondary" href={`${link}#notify`}>{t.notify}</Link>
        ) : qty > 0 ? (
          <div className="bf-card__stepper" role="group" aria-label={t.qty}>
            <button type="button" className="bf-card__step" aria-label={t.minus} onClick={() => step(-1)}>−</button>
            <span className="bf-card__qty" aria-live="polite">{qty}</span>
            <button type="button" className="bf-card__step" aria-label={t.plus} onClick={() => step(1)}>+</button>
          </div>
        ) : (
          <button type="button" className="bf-card__btn" onClick={add}>{t.add}</button>
        )}
      </div>
    </article>
  )
}
