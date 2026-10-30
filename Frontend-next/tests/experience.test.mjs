import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { experienceModel, cartLineFor, productSeoFor, specText, boxLines } from '../src/lib/experienceSpecs'
import { PRODUCT_ID, PRODUCT_SLUG, COPY, ASSETS, CAPTION_KEYS } from '../src/content/grandPrix1513477'
import { fromV2Product } from '../src/lib/v2Product'
import { transformProductData } from '../src/utils/transformProduct'
import { buildProductSeo } from '../src/lib/detailSeo'

/*
 * THE EXPERIENCE PAGE PRINTS THE RECORD, NOT THE BRIEF (2026-10-27; the brand model 2026-10-28, Watchizer only).
 *
 * The fixture is core's v2 answer for the product, recorded from the live API on 2026-10-27 (related rails
 * dropped). Every assertion below is about the RULE over that answer — what wins, what renders nothing, what
 * the cart receives — never about a value the page is expected to show forever: when the record changes, the
 * page changes with it, and these tests still pass.
 */

const recorded = () => JSON.parse(readFileSync(new URL('./fixtures/v2-product-26101.json', import.meta.url), 'utf8'))
const CAPTIONS = Object.keys(CAPTION_KEYS)

describe('experienceModel', () => {
  it('is the recorded product, and only that product', () => {
    const m = experienceModel(recorded())
    expect(m.id).toBe(PRODUCT_ID)
    expect(m.slug).toBe(PRODUCT_SLUG)
    expect(m.productPath).toBe(`/product/${PRODUCT_SLUG}`)
    const other = recorded()
    other.product.id = PRODUCT_ID + 1
    expect(experienceModel(other)).toBeNull()
    expect(experienceModel(null)).toBeNull()
  })

  it('prices by the shop contract: the sale counts only when 0 < sale < selling', () => {
    const page = recorded()
    const amount = Number(page.product.price.amount)
    const sale = Number(page.product.price.sale_amount)
    const m = experienceModel(page)
    expect(m.price.hasSale).toBe(sale > 0 && sale < amount)
    expect(m.price.final).toBe(m.price.hasSale ? sale : amount)

    page.product.price.sale_amount = amount // a "sale" at the selling price is no sale
    expect(experienceModel(page).price.final).toBe(amount)
    expect(experienceModel(page).price.hasSale).toBe(false)
    page.product.price.sale_amount = 0
    expect(experienceModel(page).price.hasSale).toBe(false)
  })

  it('every caption fact is a recorded spec, printed as recorded (label and unit), or a recorded colour', () => {
    const page = recorded()
    const m = experienceModel(page)
    const byKey = Object.fromEntries(page.product.specs.map((s) => [s.key, s]))
    for (const id of CAPTIONS) {
      for (const f of m.captions[id].facts) {
        const row = byKey[f.key]
        if (row) {
          expect(f.value.en).toBe(specText(row, 'en'))
          expect(f.value.ar).toBe(specText(row, 'ar'))
          expect(f.label.ar).toBe(row.label.ar)
        } else expect(['dial_colour', 'band_colour']).toContain(f.key)
      }
      // and the facts come in the configured order, keys the record lacks simply absent
      const keys = m.captions[id].facts.map((f) => f.key).filter((k) => !['dial_colour', 'band_colour'].includes(k))
      expect(keys).toEqual(CAPTION_KEYS[id].filter((k) => byKey[k] && specText(byKey[k], 'en')))
    }
  })

  it('a key the record does not hold renders NOTHING — no placeholder row, no figure from anywhere else', () => {
    const page = recorded()
    page.product.specs = page.product.specs.filter((s) => s.key !== 'glass_material')
    const m = experienceModel(page)
    expect(Object.values(m.captions).some((c) => c.facts.some((f) => f.key === 'glass_material'))).toBe(false)
    expect(JSON.stringify(m)).not.toMatch(/PLACEHOLDER|Mineral|30 m\b/)
    // and when the record gains a row, the caption prints it
    page.product.specs.push({ key: 'glass_material', label: { en: 'Glass', ar: 'الزجاج' }, value: { en: 'Sapphire Crystal', ar: 'كريستال سافير' } })
    expect(experienceModel(page).specRows.ar.some((r) => r.value === 'كريستال سافير')).toBe(true)
  })

  it('the sub-dial, crown, exploded and reassembly captions carry nothing the record does not say', () => {
    const m = experienceModel(recorded())
    for (const id of ['subdials', 'crown', 'parts', 'together']) {
      expect(m.captions[id].facts).toEqual([])
      expect(CAPTION_KEYS[id]).toEqual([])
    }
  })

  it('stock is both buckets, as the product page counts them', () => {
    const page = recorded()
    const m = experienceModel(page)
    expect(m.stock.inStock).toBe(Number(page.product.stock.express) > 0 || Number(page.product.stock.market) > 0)
    page.product.stock = { express: 0, market: 0, in_stock: false }
    expect(experienceModel(page).stock.inStock).toBe(false)
  })
})

describe('cartLineFor', () => {
  it('is the product page’s line for a product without variants, field for field', () => {
    const page = recorded()
    const m = experienceModel(page)
    const line = cartLineFor(m, 1)
    expect(line).toEqual({
      product_id: PRODUCT_ID,
      quantity: 1,
      piece_price: m.price.final,
      type_stock: Number(page.product.stock.express) > 0 ? 'Express' : 'Market',
      color_band: page.product.attributes.colors.band.map((c) => c.hex).join('/') || null,
      color_dial: page.product.attributes.colors.dial.map((c) => c.hex).join('/') || null,
      name: page.product.title.en,
    })
    expect(line).not.toHaveProperty('variant_id')
    expect(line).not.toHaveProperty('offer_id')
  })

  it('express stock before market, as the product page chooses', () => {
    const page = recorded()
    page.product.stock = { express: 1, market: 5, in_stock: true }
    expect(cartLineFor(experienceModel(page)).type_stock).toBe('Express')
    page.product.stock = { express: 0, market: 5, in_stock: true }
    expect(cartLineFor(experienceModel(page)).type_stock).toBe('Market')
  })
})

describe('productSeoFor', () => {
  it('is the product page’s Product JSON-LD, built by its own pipeline over the same answer', () => {
    const page = recorded()
    const tables = {}
    for (const lang of ['en', 'ar']) {
      // what app/(main)/product/[slug]/page.jsx does (resolveFromV2 → buildProductSeo), step for step
      const adapted = fromV2Product(page)
      const [product] = transformProductData(adapted.payload.products, tables, adapted.payload.ratings, adapted.payload.images, 'en')
      const localized = transformProductData(adapted.payload.products, tables, adapted.payload.ratings, adapted.payload.images, lang)[0]
      const pdp = buildProductSeo(product, { ratings: [], tables, lang, localized, meta: adapted.payload.meta })
      const here = productSeoFor(page, lang, tables)
      expect(here.productLd).toEqual(pdp.productLd)
      expect(here.canonicalPath).toBe(`/product/${PRODUCT_SLUG}`)
      const m = experienceModel(page)
      expect(here.productLd.offers.price).toBe(m.price.final)
      expect(here.productLd.offers.url).toContain(`/product/${PRODUCT_SLUG}`)
      expect(here.productLd.sku).toBe(String(PRODUCT_ID))
    }
  })
})

describe('the page’s words and assets', () => {
  it('shows no placeholder anywhere, and the "with your watch" lines only when the record carries a warranty length', () => {
    const m = experienceModel(recorded())
    // the live record for 26101 carries no warranty length today: nothing renders
    expect(m.warrantyYears).toBeNull()
    expect(boxLines(m, 'en')).toEqual([])
    expect(boxLines(m, 'ar')).toEqual([])
    const withWarranty = recorded()
    withWarranty.product.warranty_years = 2
    const w = experienceModel(withWarranty)
    expect(boxLines(w, 'en')).toEqual(['A 2-year warranty on the movement against manufacturing defects', 'A warranty card and the sales invoice', 'The brand box and catalogue'])
    expect(boxLines(w, 'ar')[0]).toBe('ضمان لمدة سنتين على الحركة ضد عيوب الصناعة')
    withWarranty.product.warranty_years = 3
    expect(boxLines(experienceModel(withWarranty), 'ar')[0]).toBe('ضمان لمدة ٣ سنوات على الحركة ضد عيوب الصناعة')
    expect(JSON.stringify(COPY)).not.toMatch(/PLACEHOLDER|\[\[/)
  })

  it('types no specification figure into a caption, either language', () => {
    for (const lang of ['en', 'ar']) {
      for (const id of CAPTIONS) {
        // the one figure allowed in a line is the caseback's own engraving ("3 ATM"), stated as what the watch carries
        if (id !== 'open') expect(COPY[lang].sections[id].line).not.toMatch(/[0-9٠-٩]/)
        else expect(COPY[lang].sections[id].line).toMatch(/3 ATM/)
        expect(COPY[lang].sections[id].title).not.toMatch(/[0-9٠-٩]/)
        expect(COPY[lang].sections[id].eyebrow).not.toMatch(/[0-9٠-٩]/)
      }
      expect(COPY[lang].home.line).not.toMatch(/[0-9٠-٩]/)
    }
  })

  it('makes no claim about the movement beyond the record: no calibre, no jewels, no frequency in any text', () => {
    expect(JSON.stringify(COPY)).not.toMatch(/calib|jewel|hz|Hz|كاليبر|جوهر|هرتز/i)
  })

  it('names the product in English on both pages, the model number in Latin digits (Michael, 2026-10-29)', () => {
    for (const lang of ['en', 'ar']) {
      expect(COPY[lang].home.name).toBe('Grand Prix 1513477')
      expect(COPY[lang].close.title).toBe('Grand Prix 1513477')
      expect(COPY[lang].metaTitle).toMatch(/^Hugo Boss Grand Prix 1513477/)
      expect(COPY[lang].labels.posterAlt).toMatch(/^Hugo Boss Grand Prix 1513477/)
    }
    expect(JSON.stringify(COPY.ar)).not.toMatch(/جراند|هوجو بوس|١٥١٣٤٧٧/)
  })

  it('prints every Arabic figure in Arabic-Indic digits, except the model number, and the facts as the English ones', () => {
    const m = experienceModel(recorded())
    const latin = /[0-9]/
    for (const r of m.specRows.ar) {
      if (r.label === 'رقم الموديل') expect(r.value).toBe('1513477')
      else expect(String(r.value)).not.toMatch(latin)
    }
    // the same number of rows, and every Arabic figure is the English one in the other digits
    expect(m.specRows.ar.length).toBe(m.specRows.en.length)
    const toLatin = (v) => String(v).replace(/[٠-٩]/g, (d) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)))
    m.specRows.ar.forEach((r, i) => {
      const en = String(m.specRows.en[i].value).match(/\d+(?:\.\d+)?/g) || []
      const ar = toLatin(r.value).match(/\d+(?:\.\d+)?/g) || []
      expect(ar).toEqual(en)
    })
    for (const id of CAPTIONS) for (const f of m.captions[id].facts) expect(f.value.ar).not.toMatch(latin)
  })

  it('names the band colour, the clasp and the band width as the record does, in Arabic too', () => {
    const m = experienceModel(recorded())
    const facts = Object.fromEntries(m.captions.bracelet.facts.map((f) => [f.key, f.value]))
    expect(facts.band_colour).toEqual({ en: 'Silver', ar: 'فضي' })
    expect(facts.band_closure).toEqual({ en: 'Folding Clasp', ar: 'مشبك قابل للطي' })
    expect(facts.band_width).toEqual({ en: '22 mm', ar: '٢٢ مم' })
  })

  it('names the brand’s assets under public/experience and the home hero’s studio', () => {
    expect(ASSETS.glb).toBe('/experience/grand-prix-1513477.glb')
    expect(ASSETS.glbPhone).toBe('/experience/grand-prix-1513477-phone.glb')
    expect(ASSETS.logo).toBe('/experience/hugo-boss-logo.webp')
    expect(ASSETS.env).toBe('/studio_small_03_512.hdr')
  })
})
