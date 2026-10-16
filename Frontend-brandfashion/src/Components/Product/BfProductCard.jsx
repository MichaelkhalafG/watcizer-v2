'use client'
import { useState } from 'react'
import { PLACEHOLDER_IMG } from '@/src/utils/imageUrl'
import { aisleTint } from '@/src/lib/aisleTint'
import { txt, money, cardColours } from '@/src/lib/cardData'
import './BfProductCard.css'

/*
 * The Brand Fashion product card — direction C "Shelf", BRAND_FASHION_UI_SPEC v2.6 §8, build step
 * B3 (§20: "the card survives the real catalogue at both price extremes").
 *
 * Data: a v2 card row (`/v2/{storefront}/products` → `data[]`) or a v2 product detail — both carry
 * id, slug, family, title{}, brand{name{}}, image{src}, price{amount, sale_amount, has_sale,
 * discount_pct, currency}, stock{in_stock} and the ATTRIBUTE colours (card rows: `colors[]`;
 * details: `attributes.colors.{dial,band,main}`). Images are absolute URLs from the storefront's
 * asset host; when there is none, or one fails, PLACEHOLDER_IMG (the inline SVG) — never a file.
 *
 * What is on it, in order (§8.1): photo on the aisle tint → at most one badge (sold out › new) →
 * brand → 2-line title → the swatch row (§8.2, always reserved, describes, selects nothing) →
 * price + struck old price + red −% → button. No sale pill (§19-4). No rating until 10 (§8.3).
 * No wishlist, no stock count, no delivery promise (§8.4). The whole card is ONE link and one Tab
 * stop; the button is the second (§7.3). The accessible name is one sentence in §7.4's order.
 *
 * Props (§8.5): lang · density (comfortable | compact) · tintMode (auto | always | never) ·
 * showRating · plus `ground` ('white' | 'tint' — the tint rule: on a tinted ground the card drops
 * its tint for `tile`), `isNew` (what "new" means is §19-13, open — the caller decides), `href`,
 * and `onAdd` (returns false to refuse; the button becomes a stepper in place when it succeeds).
 */

const STR = {
  en: { add: 'Add to cart', notify: 'Notify me', soldOut: 'Sold out', isNew: 'New', was: 'was', colours: 'Colours', qty: 'Quantity', minus: 'Remove one', plus: 'Add one', off: 'off' },
  ar: { add: 'أضف إلى السلة', notify: 'نبّهني', soldOut: 'نفد', isNew: 'جديد', was: 'كان', colours: 'الألوان', qty: 'الكمية', minus: 'أنقص واحدًا', plus: 'أضف واحدًا', off: 'خصم' },
}

export default function BfProductCard({
  product,
  lang = 'en',
  density = 'comfortable',
  tintMode = 'auto',
  ground = 'white',
  showRating = false,
  isNew = false,
  href,
  onAdd,
}) {
  const [qty, setQty] = useState(0)
  const [broken, setBroken] = useState(false)
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
  // one badge at a time, enforced here: sold out › new (§8.3)
  const badge = soldOut ? 'sold-out' : isNew ? 'new' : null

  // the tint rule (§8.3): the family tint on a white ground; `tile` on any tinted ground
  const tintVar = tintMode === 'never' || (tintMode === 'auto' && ground === 'tint') ? 'var(--wz-tile)' : `var(--wz-${aisleTint(product.family)})`

  const colours = cardColours(product)
  const shown = colours.slice(0, 4)
  const src = !broken && product.image?.src ? product.image.src : PLACEHOLDER_IMG
  const isPhoto = src !== PLACEHOLDER_IMG

  // §7.4: brand, title, price, "was" old price, state — one sentence, the pills inside it
  const name = [brand, title, now, was ? `${t.was} ${was}` : null, soldOut ? t.soldOut : badge === 'new' ? t.isNew : null].filter(Boolean).join(', ')

  const rating = product.rating ?? {}
  const showStars = showRating && Number(rating.count) >= 10

  const add = async (e) => {
    e.preventDefault()
    if (soldOut) return
    const ok = onAdd ? await onAdd(product) : true
    if (ok !== false) setQty(1)
  }

  return (
    <article className={`bf-card ${density === 'compact' ? 'bf-card--compact' : ''} ${soldOut ? 'is-sold-out' : ''}`} dir={dir} lang={lang} data-family={product.family}>
      <a className="bf-card__link" href={href ?? `/product/${product.slug}`} aria-label={name}>
        <div className="bf-card__tile" style={{ background: tintVar }} aria-hidden="true">
          {/* eslint-disable-next-line @next/next/no-img-element -- absolute asset-host URL, fallback is an inline SVG */}
          <img className={`bf-card__img ${isPhoto ? 'is-photo' : 'is-placeholder'}`} src={src} alt="" loading="lazy" decoding="async" onError={() => setBroken(true)} />
          {badge ? <span className={`bf-card__badge bf-card__badge--${badge}`}>{badge === 'sold-out' ? t.soldOut : t.isNew}</span> : null}
        </div>
        <div className="bf-card__body" aria-hidden="true">
          <div className="bf-card__brand">{brand}</div>
          <h3 className="bf-card__title">{title}</h3>
          <div className="bf-card__swatches" aria-label={colours.length ? `${t.colours}: ${colours.map((c) => txt(c.name, lang)).join(', ')}` : undefined}>
            {shown.map((c) => <span key={c.id} className="bf-card__dot" style={{ background: c.hex }} />)}
            {colours.length > 4 ? <span className="bf-card__more">+{colours.length - 4}</span> : null}
          </div>
          <div className="bf-card__price">
            <span className="bf-card__now">{now}</span>
            <span className="bf-card__old">
              {was ? <s className="bf-card__was">{was}</s> : null}
              {pct != null ? <span className="bf-card__pct">−{pct}%</span> : null}
            </span>
          </div>
          {showStars ? <div className="bf-card__rating">★ {Number(rating.avg).toFixed(1)} ({rating.count})</div> : null}
        </div>
      </a>
      <div className="bf-card__cta">
        {qty > 0 ? (
          <div className="bf-card__stepper" role="group" aria-label={t.qty}>
            <button type="button" className="bf-card__step" aria-label={t.minus} onClick={() => setQty((q) => Math.max(0, q - 1))}>−</button>
            <span className="bf-card__qty" aria-live="polite">{qty}</span>
            <button type="button" className="bf-card__step" aria-label={t.plus} onClick={() => setQty((q) => q + 1)}>+</button>
          </div>
        ) : (
          <button type="button" className={`bf-card__btn ${soldOut ? 'bf-card__btn--secondary' : ''}`} onClick={add}>
            {soldOut ? t.notify : t.add}
          </button>
        )}
      </div>
    </article>
  )
}
