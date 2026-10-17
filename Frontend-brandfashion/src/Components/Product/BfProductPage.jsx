'use client'
import { useMemo, useRef, useState } from 'react'
import { useUIStore } from '@/src/Store/uiStore'
import { useToastStore } from '@/src/Store/toastStore'
import useCart from '@/src/Hooks/useCart'
import { refusedAddMessage } from '@/src/Store/cartStore'
import { txt, money } from '@/src/lib/cardData'
import { PLACEHOLDER_IMG } from '@/src/utils/imageUrl'
import { localizeHref } from '@/src/utils/localePath'
import BfRail from '@/src/Components/Listing/BfRail'
import './BfProductPage.css'

/*
 * B5 — the Brand Fashion product page (spec v2.9 §10.3; built 2026-10-17 on v2 `products/{slug}` and
 * `products/{slug}/related` — the same data path as the listing's cards).
 *
 *  • desktop 7/5: gallery (main 1:1 on the tile + 72px thumb strip) · buy box 460 sticky at 80;
 *    phone: full-width swipe gallery with dots, buy box under, sticky bottom bar 64 with price + CTA.
 *  • buy-box order: brand → h1 → price row (old struck + sale pill with the %; "Incl. VAT") → stock pill →
 *    the picker → CTA → delivery promises → attributes → description → ratings (only when any).
 *  • the picker (§17.5, matrix rule): a variant is ONE row carrying a colour and/or a size with its own stock
 *    and price. Choose a colour → only the sizes that exist for it are enabled (a sold-out size is struck, still
 *    tappable → "notify me"); choose a size → the colours narrow the same way. Size-only rows → the size picker
 *    alone; colour-only rows → the colour picker alone; no rows → NO picker (§11.5). The price is the chosen
 *    row's; until a row is chosen the CTA is disabled with "Choose a colour and size" (or the one that applies).
 *  • attributes (§11.4) degrade: specs → the pivots (brand, family, categories, gender, colours, features) →
 *    nothing (the block is not rendered and the description moves up).
 *  • out of stock is NOT dimmed (§21): the badge is a filled ink-3 pill, the CTA a secondary "Notify me".
 *  • no price-changed notice (§19-8), no authenticity line (§19-2), no size guide link, no empty stars (§11.3).
 *  • adding a chosen VARIANT to the cart is B7's gate: today's cart (compat) refuses a product with variants
 *    and the refusal is shown as it is — recorded, not papered over.
 */

const STR = {
  en: {
    home: 'Home', inStock: 'In stock', onlyLeft: (n) => `Only ${n} left`, soldOut: 'Sold out', add: 'Add to cart', notify: 'Notify me when it’s back',
    notifySize: 'Notify me for this size', chooseBoth: 'Choose a colour and size', chooseColour: 'Choose a colour', chooseSize: 'Choose a size',
    vat: 'Incl. VAT', colour: 'Colour', size: 'Size', promises: ['Delivery across Egypt — EGP 100–300 by governorate', 'Pay on delivery or by card', 'Returns: the customer pays return shipping'],
    attributes: 'Details', description: 'Description', ratings: 'Ratings', brand: 'Brand', family: 'Type', category: 'Category', gender: 'Gender', colours: 'Colours', features: 'Features',
    goesWith: 'Goes with this', similar: 'Similar', seeAll: 'See all', was: 'was', added: 'Added to cart', notified: 'We’ll let you know when it’s back',
    families: { watch: 'Watch', bag: 'Bag', wallet: 'Wallet', fashion: 'Fashion', electronics: 'Electronics', perfume: 'Perfume' },
  },
  ar: {
    home: 'الرئيسية', inStock: 'متوفر', onlyLeft: (n) => `باقي ${n} فقط`, soldOut: 'نفد', add: 'أضف إلى السلة', notify: 'نبّهني عند توفره',
    notifySize: 'نبّهني لهذا المقاس', chooseBoth: 'اختر اللون والمقاس', chooseColour: 'اختر اللون', chooseSize: 'اختر المقاس',
    vat: 'شامل الضريبة', colour: 'اللون', size: 'المقاس', promises: ['التوصيل لجميع المحافظات — من 100 إلى 300 ج.م حسب المحافظة', 'الدفع عند الاستلام أو بالبطاقة', 'الإرجاع: العميل يتحمّل شحن الإرجاع'],
    attributes: 'التفاصيل', description: 'الوصف', ratings: 'التقييمات', brand: 'العلامة', family: 'الصنف', category: 'الفئة', gender: 'الجنس', colours: 'الألوان', features: 'المميزات',
    goesWith: 'يناسبه', similar: 'مشابه', seeAll: 'عرض الكل', was: 'كان', added: 'أُضيف إلى السلة', notified: 'سنخبرك عند توفره',
    families: { watch: 'ساعة', bag: 'حقيبة', wallet: 'محفظة', fashion: 'أزياء', electronics: 'إلكترونيات', perfume: 'عطر' },
  },
}

const LOW_STOCK = 3

export default function BfProductPage({ detail, related }) {
  const { language } = useUIStore()
  const lang = language === 'ar' ? 'ar' : 'en'
  const t = STR[lang]
  const isRTL = lang === 'ar'
  const p = detail.product
  const variants = useMemo(() => (Array.isArray(p.variants) ? p.variants : []), [p.variants])

  // ── the matrix: which rows exist, which colours / sizes they carry
  const colours = useMemo(() => uniq(variants.map((v) => v.color).filter(Boolean)), [variants])
  const sizes = useMemo(() => uniq(variants.map((v) => v.size).filter(Boolean)).sort((a, b) => (a.sort ?? 0) - (b.sort ?? 0)), [variants])
  const hasColour = colours.length > 0
  const hasSize = sizes.length > 0
  const [colorId, setColorId] = useState(null)
  const [sizeId, setSizeId] = useState(null)
  const rowsFor = (c, s) => variants.filter((v) => (c == null || v.color?.id === c) && (s == null || v.size?.id === s))
  const chosen = variants.length ? (rowsFor(hasColour ? colorId : null, hasSize ? sizeId : null).length === 1 && (!hasColour || colorId != null) && (!hasSize || sizeId != null) ? rowsFor(hasColour ? colorId : null, hasSize ? sizeId : null)[0] : null) : null
  // a colour is "dead" when every row of it is out of stock; a size is absent for the chosen colour when no row exists
  const colourState = (c) => {
    const rows = rowsFor(c.id, hasSize ? sizeId : null)
    return { absent: rows.length === 0, dead: rows.length > 0 && rows.every((r) => !r.stock?.in_stock) }
  }
  const sizeState = (s) => {
    const rows = rowsFor(hasColour ? colorId : null, s.id)
    return { absent: rows.length === 0, out: rows.length > 0 && rows.every((r) => !r.stock?.in_stock) }
  }

  // ── price and stock follow the chosen row, else the product
  const price = chosen?.price || p.price || {}
  const stock = chosen?.stock || p.stock || {}
  const hasSale = Boolean(price.has_sale) && Number(price.sale_amount) > 0 && Number(price.sale_amount) < Number(price.amount)
  const now = money(hasSale ? price.sale_amount : price.amount, lang, price.currency)
  const was = hasSale ? money(price.amount, lang, price.currency) : null
  const pct = hasSale ? price.discount_pct ?? Math.round((1 - price.sale_amount / price.amount) * 100) : null
  const units = Number(stock.express || 0) + Number(stock.market || 0)
  const inStock = Boolean(stock.in_stock)
  const low = inStock && units > 0 && units <= LOW_STOCK

  // ── CTA
  const { addItem } = useCart()
  const showToast = useToastStore((s) => s.showToast)
  const [busy, setBusy] = useState(false)
  const needs = variants.length ? (hasColour && hasSize && (colorId == null || sizeId == null) ? t.chooseBoth : hasColour && colorId == null ? t.chooseColour : hasSize && sizeId == null ? t.chooseSize : null) : null
  const onAdd = async () => {
    if (busy) return
    if (!inStock) {
      showToast?.(t.notified, 'success')
      return
    }
    setBusy(true)
    try {
      const r = await addItem({
        product_id: p.id,
        quantity: 1,
        piece_price: hasSale ? price.sale_amount : price.amount,
        type_stock: Number(stock.express) > 0 ? 'Express' : 'Market',
        name: txt(p.title, 'en'),
        ...(chosen ? { variant_id: chosen.id } : {}),
      })
      if (r && r.ok === false) showToast?.(refusedAddMessage(r, isRTL), 'error')
      else showToast?.(t.added, 'success')
    } finally {
      setBusy(false)
    }
  }

  // ── gallery
  const images = (Array.isArray(p.images) && p.images.length ? p.images : p.image ? [p.image] : []).filter((i) => i?.src)
  const [slide, setSlide] = useState(0)
  const trackRef = useRef(null)
  const goSlide = (i) => {
    setSlide(i)
    const el = trackRef.current
    if (el) el.scrollTo({ left: el.clientWidth * i * (isRTL ? -1 : 1), behavior: 'smooth' })
  }
  const onScroll = () => {
    const el = trackRef.current
    if (!el || !el.clientWidth) return
    setSlide(Math.round(Math.abs(el.scrollLeft) / el.clientWidth))
  }

  // ── attributes (§11.4): specs first, else the pivots, else nothing
  const specs = Array.isArray(p.specs) ? p.specs : []
  const attrs = p.attributes || {}
  const pivotRows = []
  if (p.brand?.name) pivotRows.push({ k: t.brand, v: txt(p.brand.name, lang) })
  if (p.family) pivotRows.push({ k: t.family, v: t.families[p.family] || p.family })
  if (Array.isArray(p.categories) && p.categories.length) pivotRows.push({ k: t.category, v: p.categories.map((c) => txt(c.name, lang)).join(isRTL ? '، ' : ', ') })
  if (Array.isArray(attrs.genders) && attrs.genders.length) pivotRows.push({ k: t.gender, v: attrs.genders.map((g) => txt(g.name, lang)).join(isRTL ? '، ' : ', ') })
  const allColours = [...(attrs.colors?.dial || []), ...(attrs.colors?.band || []), ...(attrs.colors?.main || [])].filter((c, i, a) => a.findIndex((y) => y.id === c.id) === i)
  if (allColours.length) pivotRows.push({ k: t.colours, colours: allColours })
  if (Array.isArray(attrs.features) && attrs.features.length) pivotRows.push({ k: t.features, v: attrs.features.map((f) => txt(f.name, lang)).join(isRTL ? '، ' : ', ') })
  // a spec value is a number (with a unit), a lookup ref ({id, name}), a flag or a string — never an object on screen
  const specValue = (sp) => {
    const v = sp.value
    if (v == null || v === '') return null
    if (typeof v === 'object') return txt(v, lang) || txt(v.name, lang) || null   // a lookup value is served as {en, ar}
    if (typeof v === 'boolean') return v ? (isRTL ? 'نعم' : 'Yes') : (isRTL ? 'لا' : 'No')
    return `${v}${sp.unit ? ` ${txt(sp.unit.name, lang)}` : ''}`
  }
  const specRows = specs.map((sp) => ({ k: txt(sp.label, lang), v: specValue(sp) })).filter((r) => r.v)
  const rows = specRows.length ? [...specRows, ...pivotRows] : pivotRows

  const description = txt(p.long_description, lang) || txt(p.short_description, lang)
  const rating = p.rating || {}
  const addons = related?.addons || []
  const similar = related?.similar || []
  const crumbs = Array.isArray(p.breadcrumb) ? p.breadcrumb : []

  return (
    <div className="bf-pdp" dir={isRTL ? 'rtl' : 'ltr'} lang={lang}>
      <div className="bf-pdp__inner">
        <nav className="bf-pdp__crumb" aria-label="Breadcrumb">
          <a href={localizeHref('/', lang)}>{t.home}</a>
          {crumbs.map((c) => (
            <span key={c.id}>
              <span aria-hidden="true">/ </span>
              <a href={localizeHref(`/category/${c.path}`, lang)}>{txt(c.name, lang)}</a>
            </span>
          ))}
        </nav>

        <div className="bf-pdp__top">
          <div className="bf-gal">
            <div className="bf-gal__main">
              <div className="bf-gal__track" ref={trackRef} onScroll={onScroll}>
                {(images.length ? images : [{ src: PLACEHOLDER_IMG }]).map((img, i) => (
                  <div className="bf-gal__slide" key={img.id ?? i}>
                    {/* eslint-disable-next-line @next/next/no-img-element -- absolute asset-host URL */}
                    <img src={img.src} alt={i === 0 ? txt(p.title, lang) : ''} loading={i === 0 ? 'eager' : 'lazy'} decoding="async" />
                  </div>
                ))}
              </div>
              {!p.stock?.in_stock && !variants.length ? <span className="bf-gal__badge">{t.soldOut}</span> : null}
            </div>
            {images.length > 1 ? (
              <>
                <div className="bf-gal__dots" aria-hidden="true">
                  {images.map((img, i) => <button key={img.id ?? i} type="button" className="bf-gal__dot" aria-current={i === slide} onClick={() => goSlide(i)} tabIndex={-1} />)}
                </div>
                <div className="bf-gal__thumbs">
                  {images.map((img, i) => (
                    <button key={img.id ?? i} type="button" className="bf-gal__thumb" aria-current={i === slide} onClick={() => goSlide(i)} aria-label={`${i + 1} / ${images.length}`}>
                      {/* eslint-disable-next-line @next/next/no-img-element */}
                      <img src={img.src} alt="" loading="lazy" decoding="async" />
                    </button>
                  ))}
                </div>
              </>
            ) : null}
          </div>

          <div className="bf-pdp__buy">
            <div className="bf-buy__brand">{txt(p.brand?.name, lang)}</div>
            <h1 className="bf-buy__title">{txt(p.title, lang)}</h1>
            <div className="bf-buy__price">
              <span className="bf-buy__now">{now}</span>
              {was ? <s className="bf-buy__was">{was}</s> : null}
              {pct != null ? <span className="bf-buy__pct">−{pct}%</span> : null}
            </div>
            <div className="bf-buy__vat">{t.vat}</div>

            <div className="bf-buy__stock">
              {!variants.length || chosen ? (
                inStock ? (
                  <span className={`bf-pill ${low ? 'bf-pill--alert' : 'bf-pill--ok'}`}>{low ? t.onlyLeft(units) : t.inStock}</span>
                ) : (
                  <span className="bf-pill bf-pill--out">{t.soldOut}</span>
                )
              ) : null}
            </div>

            {hasColour ? (
              <div className="bf-pick">
                <div className="bf-pick__label">
                  {t.colour}{colorId != null ? <b>{txt(colours.find((c) => c.id === colorId)?.name, lang)}</b> : null}
                </div>
                <div className="bf-pick__colours" role="radiogroup" aria-label={t.colour}>
                  {colours.map((c) => {
                    const st = colourState(c)
                    if (st.absent) return null
                    return (
                      <button key={c.id} type="button" role="radio" aria-checked={colorId === c.id} className={`bf-pick__swatch ${st.dead ? 'is-dead' : ''}`} style={{ background: c.hex }} aria-label={`${txt(c.name, lang)}${st.dead ? ` — ${t.soldOut}` : ''}`} title={txt(c.name, lang)} onClick={() => setColorId(colorId === c.id ? null : c.id)}>
                        <span className="bf-swatch__hit" aria-hidden="true" />
                      </button>
                    )
                  })}
                </div>
              </div>
            ) : null}

            {hasSize ? (
              <div className="bf-pick">
                <div className="bf-pick__label">
                  {t.size}{sizeId != null ? <b>{txt(sizes.find((s) => s.id === sizeId)?.name, lang)}</b> : null}
                </div>
                <div className="bf-pick__sizes" role="radiogroup" aria-label={t.size}>
                  {sizes.map((s) => {
                    const st = sizeState(s)
                    return (
                      <button key={s.id} type="button" role="radio" aria-checked={sizeId === s.id} className={`bf-pick__size ${st.out ? 'is-out' : ''} ${st.absent ? 'is-absent' : ''}`} aria-label={`${txt(s.name, lang)}${st.out ? ` — ${t.soldOut}` : ''}`} onClick={() => setSizeId(sizeId === s.id ? null : s.id)}>
                        {txt(s.name, lang)}
                      </button>
                    )
                  })}
                </div>
                {chosen && !chosen.stock?.in_stock ? <div className="bf-pick__hint">{t.notifySize}</div> : null}
              </div>
            ) : null}

            <div className="bf-buy__cta">
              <button type="button" className={`bf-cta ${!inStock && !needs ? 'bf-cta--secondary' : ''}`} disabled={Boolean(needs) || busy} onClick={onAdd}>
                {needs ? needs : inStock ? t.add : t.notify}
              </button>
            </div>

            <ul className="bf-buy__promises">
              {t.promises.map((line) => <li key={line}>{line}</li>)}
            </ul>

            {rows.length ? (
              <section className="bf-attrs" aria-label={t.attributes}>
                <h2 className="bf-attrs__title">{t.attributes}</h2>
                <dl className="bf-attrs__grid">
                  {rows.map((r, i) => (
                    <div className="bf-attrs__row" key={`${r.k}-${i}`}>
                      <dt>{r.k}</dt>
                      <dd>
                        {r.colours ? r.colours.map((c) => <span key={c.id} title={txt(c.name, lang)}><span className="bf-attrs__dot" style={{ background: c.hex }} /> {txt(c.name, lang)}</span>) : r.v}
                      </dd>
                    </div>
                  ))}
                </dl>
              </section>
            ) : null}

            {description ? (
              <section className="bf-desc" aria-label={t.description}>
                <h2 className="bf-desc__title">{t.description}</h2>
                {description}
              </section>
            ) : null}

            {Number(rating.count) > 0 ? (
              <section className="bf-ratings" aria-label={t.ratings}>
                <h2 className="bf-ratings__title">{t.ratings}</h2>
                ★ {Number(rating.avg).toFixed(1)} ({rating.count})
              </section>
            ) : null}
          </div>
        </div>

        <div className="bf-pdp__rails">
          <BfRail title={t.goesWith} cards={addons} lang={lang} seeAllHref={addons[0]?.primary_category?.path ? `/category/${addons[0].primary_category.path}` : '/listing'} />
          <BfRail title={t.similar} cards={similar} lang={lang} seeAllHref={p.primary_category?.path ? `/category/${p.primary_category.path}` : '/listing'} />
        </div>
      </div>

      <div className="bf-pdp__bar" aria-hidden={false}>
        <span className="bf-buy__now">{now}</span>
        <button type="button" className={`bf-cta ${!inStock && !needs ? 'bf-cta--secondary' : ''}`} disabled={Boolean(needs) || busy} onClick={onAdd}>
          {needs ? needs : inStock ? t.add : t.notify}
        </button>
      </div>
    </div>
  )
}

function uniq(refs) {
  const seen = new Set()
  return refs.filter((r) => (seen.has(r.id) ? false : (seen.add(r.id), true)))
}
