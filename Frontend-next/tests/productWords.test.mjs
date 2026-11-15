import { describe, expect, it } from 'vitest'
import { imageAlt, isCopyOrStyle, productDescription, productMaterial, productName, productTitle } from '../src/lib/productWords.js'

/*
 * A PRODUCT'S TITLE AND DESCRIPTION FROM THE CATALOGUE (2026-11-14, SEO Phase 1 item 3). A twin. The brief's own
 * example is the first case; every word must come from the record.
 */

const grandPrix = {
  family: 'watch',
  title: { en: 'Hugo Boss Grand Prix Chronograph Black Dial Men Watch 1513477', ar: 'ساعة هوجو بوس جراند بري' },
  model_number: '1513477',
  model_name: { en: 'Grand Prix', ar: 'جراند بري' },
  brand: { name: { en: 'Hugo Boss', ar: 'هوجو بوس' } },
  categories: [
    { depth: 1, is_primary: true, name: { en: 'Watches', ar: 'ساعات' } },
    { depth: 2, is_primary: false, name: { en: 'Chronograph', ar: 'كرونوغراف' } },
  ],
  attributes: { genders: [{ name: { en: 'Men', ar: 'رجالي' } }], features: [{ name: { en: 'Water Resistant', ar: 'مقاوم للماء' } }, { name: { en: 'Date Display', ar: 'عرض التاريخ' } }, { name: { en: 'Luminous Hands', ar: 'عقارب مضيئة' } }] },
}

describe('the title', () => {
  it('is brand + model name + model number + gender + type, in each language (the brief\'s example)', () => {
    expect(productTitle(grandPrix, 'en', 'watchizer')).toBe("Hugo Boss Grand Prix 1513477 Men's Chronograph Watch | Watchizer")
    expect(productTitle(grandPrix, 'ar', 'watchizer')).toBe('ساعة هوجو بوس جراند بري 1513477 كرونوغراف رجالي | واتشايزر')
    expect(productTitle(grandPrix, 'en', 'brandfashion')).toMatch(/\| Brand Fashion$/)
  })

  it('leaves out what the record does not hold, and strips the noun from a watch type', () => {
    const casual = { ...grandPrix, model_name: [], model_number: 'gw0571g1', brand: { name: { en: 'Guess', ar: 'جيس' } },
      categories: [{ depth: 2, name: { en: 'Casual', ar: 'ساعات كاجوال' } }], attributes: { genders: [] } }
    expect(productName(casual, 'en')).toBe('Guess GW0571G1 Casual Watch')
    expect(productName(casual, 'ar')).toBe('ساعة جيس GW0571G1 كاجوال')
    const smart = { ...casual, categories: [{ depth: 2, name: { en: 'Smart Watch', ar: 'ساعات ذكية' } }] }
    expect(productName(smart, 'en')).toBe('Guess GW0571G1 Smart Watch')
  })

  it('names a non-watch by its sub-category, and two genders as unisex', () => {
    const belt = { family: 'fashion', title: { en: 'x', ar: 'x' }, model_number: '11020', brand: { name: { en: 'Tommy Hilfiger', ar: 'تومي هيلفيجر' } },
      categories: [{ depth: 2, is_primary: true, name: { en: 'Belts', ar: 'أحزمة' } }],
      attributes: { genders: [{ name: { en: 'Men' } }, { name: { en: 'Women' } }] } }
    expect(productName(belt, 'en')).toBe('Tommy Hilfiger 11020 Unisex Belt')
    expect(productName(belt, 'ar')).toBe('حزام تومي هيلفيجر 11020 للجنسين')
  })

  it('keeps a product\'s own title when nothing tells it apart, adding the brand the thin Arabic titles lack', () => {
    const charger = { family: 'electronics', title: { en: 'Joyroom JR-TCF02 PD 20W Mini Charger', ar: 'شاحن JR-TCF02' }, model_number: null,
      brand: { name: { en: 'Joyroom', ar: 'جوي روم' } }, categories: [{ depth: 2, name: { en: 'Chargers & Cables' } }] }
    expect(productName(charger, 'en')).toBe('Joyroom JR-TCF02 PD 20W Mini Charger')
    expect(productName(charger, 'ar')).toBe('شاحن جوي روم JR-TCF02')
  })

  it('leaves a Copy or Style product exactly as it is', () => {
    const copy = { ...grandPrix, title: { en: 'Mini Focus MF0612G Chronograph Watch Copy', ar: 'ساعة' } }
    expect(isCopyOrStyle(copy)).toBe(true)
    expect(productTitle(copy, 'en', 'watchizer')).toBeNull()
    expect(productDescription(copy, 'en', { price: { now: 1000 } })).toBeNull()
    expect(isCopyOrStyle({ title: { en: 'Lifestyle bag' } })).toBe(false)   // a word, not a part of one
  })
})

describe('the description', () => {
  it('sells within 155 characters: attributes, price, warranty, 24-hour delivery, cash on delivery, 14-day returns', () => {
    for (const lang of ['en', 'ar']) {
      const d = productDescription(grandPrix, lang, { price: { now: 9450, was: 10500 }, warrantyYears: 2 })
      expect(d.length).toBeLessThanOrEqual(155)
      expect(d).toContain(lang === 'ar' ? '٢٤ ساعة' : '24 hours')
      expect(d).toContain(lang === 'ar' ? 'دفع عند الاستلام' : 'cash on delivery')
      expect(d).toContain('9,450')
      expect(d).not.toMatch(/free (shipping|delivery)|شحن مجاني|توصيل مجاني/i)
    }
    // the pre-sale price goes first, then the attributes; the warranty stays (English: no attribute fits, the sale comes back)
    expect(productDescription(grandPrix, 'en', { price: { now: 9450, was: 10500 }, warrantyYears: 2 }))
      .toBe("Hugo Boss Grand Prix 1513477 Men's Chronograph Watch. EGP 9,450 (was 10,500), 2-year warranty. Delivery in 24 hours, cash on delivery, free 14-day returns.")
    expect(productDescription(grandPrix, 'ar', { price: { now: 9450, was: 10500 }, warrantyYears: 2 }))
      .toBe('ساعة هوجو بوس جراند بري 1513477 كرونوغراف رجالي: مقاوم للماء. السعر 9,450 ج.م، ضمان سنتين. توصيل خلال ٢٤ ساعة، دفع عند الاستلام، استرجاع مجاني ١٤ يومًا.')
    // a short name keeps its attributes
    const short = { ...grandPrix, model_name: [], brand: { name: { en: 'Boss', ar: 'بوس' } }, attributes: { ...grandPrix.attributes, features: grandPrix.attributes.features.slice(0, 1) } }
    expect(productDescription(short, 'en', { price: { now: 9450 }, warrantyYears: 1 }))
      .toBe("Boss 1513477 Men's Chronograph Watch: water resistant. EGP 9,450, 1-year warranty. Delivery in 24 hours, cash on delivery, free 14-day returns.")
  })

  it('states no warranty for none, and no price it was not given', () => {
    const d = productDescription(grandPrix, 'en', { warrantyYears: 0 })
    expect(d).not.toMatch(/warranty|EGP/)
  })
})

describe('what a sample of 28 real products taught (2026-11-14)', () => {
  const watch = (over) => ({ ...grandPrix, ...over })
  it('drops the placeholder brand, and a model name that only repeats the brand or the number', () => {
    expect(productName(watch({ brand: { name: { en: 'Generic', ar: 'غير محدد' } }, model_name: [], model_number: 'SG689' }), 'en')).toBe("SG689 Men's Chronograph Watch")
    expect(productName(watch({ brand: { name: { en: 'Tommy Hilfiger', ar: 'تومي هيلفيغر' } }, model_name: { en: 'Tommy Hilfiger Owen', ar: 'Tommy Hilfiger Owen' }, model_number: '1791965' }), 'en'))
      .toBe("Tommy Hilfiger Owen 1791965 Men's Chronograph Watch")
    expect(productName(watch({ model_name: { en: 'Hugo Boss 1513477' } }), 'en')).toBe("Hugo Boss 1513477 Men's Chronograph Watch")
    // what is left is a marketplace phrase, not a name
    expect(productName(watch({ brand: { name: { en: 'Le Pliage' } }, model_name: { en: 'Longchamp Le Pliage Energy L Handbag' } }), 'en')).toBe("Le Pliage 1513477 Men's Chronograph Watch")
  })
  it('places the brand of a thin Arabic title just before its model code', () => {
    const headphones = { family: 'electronics', title: { en: 'Joyroom JR-JH1 Headphones', ar: 'سماعة رأس لاسلكي JR-JH1' }, model_number: null, brand: { name: { en: 'Joyroom', ar: 'جوي روم' } } }
    expect(productName(headphones, 'ar')).toBe('سماعة رأس لاسلكي جوي روم JR-JH1')
    expect(productName({ ...headphones, title: { ar: 'حامل أسود' } }, 'ar')).toBe('حامل أسود جوي روم')
  })
  it('does not say the type twice, and keeps the promise whole under a very long own title', () => {
    const chrono = watch({ attributes: { genders: [{ name: { en: 'Men' } }], features: [{ name: { en: 'Chronograph' } }, { name: { en: 'Date Display' } }] }, model_name: [] })
    expect(productDescription(chrono, 'en', { price: { now: 5200 } })).toContain(': date display.')
    const perfume = { family: 'perfume', title: { en: 'Emporio Armani Stronger with You by Giorgio Armani for Men - Eau de Toilette Natural Spray Vaporisateur 100 ml' }, model_number: null, brand: { name: { en: 'Emporio Armani' } } }
    const d = productDescription(perfume, 'en', { price: { now: 4800 } })
    expect(d.length).toBeLessThanOrEqual(155)
    expect(d).toMatch(/… EGP 4,800\. Delivery in 24 hours, cash on delivery, free 14-day returns\.$/)
  })
})

describe('the material (structured data)', () => {
  it("is a bag's material, else a watch's case and band, in the page's language; null when none", () => {
    const ref = (en, ar) => ({ id: 1, name: { en, ar } })
    expect(productMaterial({ specs: [{ key: 'material_id', value: ref('Leather', 'جلد') }] }, 'ar')).toBe('جلد')
    expect(productMaterial({ specs: [{ key: 'case_material', value: ref('Stainless Steel', 'ستانلس ستيل') }, { key: 'band_material', value: ref('Leather', 'جلد') }] }, 'en')).toBe('Stainless Steel / Leather')
    expect(productMaterial({ specs: [{ key: 'case_material', value: ref('Steel', 'ستيل') }, { key: 'band_material', value: ref('Steel', 'ستيل') }] }, 'en')).toBe('Steel')
    expect(productMaterial({ specs: [] }, 'en')).toBeNull()
  })
})

describe("each photo's alt text (2026-11-14)", () => {
  const product = {
    ...grandPrix,
    variants: [{ id: 7, color: { name: { en: 'Black', ar: 'أسود' } } }],
  }
  it("is the team's own, else the name and what the photo shows, else its colour, else photo N of M", () => {
    expect(imageAlt(product, { alt_text: { en: 'On a wrist, steel bracelet' } }, 1, 4, 'en')).toBe('On a wrist, steel bracelet')
    expect(imageAlt(product, { view: 'wrist' }, 1, 4, 'en')).toBe("Hugo Boss Grand Prix 1513477 Men's Chronograph Watch, on the wrist")
    expect(imageAlt(product, { view: 'box' }, 2, 4, 'ar')).toBe('ساعة هوجو بوس جراند بري 1513477 كرونوغراف رجالي، العلبة والأوراق')
    expect(imageAlt(product, { variant_id: 7 }, 0, 4, 'en')).toBe("Hugo Boss Grand Prix 1513477 Men's Chronograph Watch, black")
    expect(imageAlt(product, { variant_id: 7 }, 2, 4, 'en')).toBe("Hugo Boss Grand Prix 1513477 Men's Chronograph Watch, black, photo 3 of 4")
    expect(imageAlt(product, {}, 0, 4, 'en')).toBe("Hugo Boss Grand Prix 1513477 Men's Chronograph Watch")
    expect(imageAlt(product, {}, 3, 4, 'ar')).toBe('ساعة هوجو بوس جراند بري 1513477 كرونوغراف رجالي، صورة 4 من 4')
  })
  it("never puts Arabic letters in an English page's alt", () => {
    expect(imageAlt(product, { alt_text: { en: 'ساعة' } }, 0, 1, 'en')).toBe("Hugo Boss Grand Prix 1513477 Men's Chronograph Watch")
  })
})
