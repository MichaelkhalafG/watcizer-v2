import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import { INSPECT_FULL, INSPECT_LONG, INSPECT_SHORT } from '../src/content/inspection.js'
import { FAQ } from '../src/content/faq.js'
import { TRUST_PAGES } from '../src/content/trustPages.js'
import { trustSignals } from '../src/config/trust.config.js'

/*
 * INSPECTION BEFORE PAYMENT (2026-11-14, Michael and the client): the same words in every place. A twin. The long
 * sentence with the client's condition (no delivery fee for a refused order) on the FAQ, the terms, the About page and
 * /llms.txt; the short one in the strips and the product promises; the long one at the checkout's cash option.
 */
const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')
const blocksText = (page) => JSON.stringify(page)

describe('inspection before payment', () => {
  it('is the approved wording', () => {
    expect(INSPECT_SHORT).toEqual({ en: 'Inspect before you pay (cash on delivery)', ar: 'المعاينة قبل الدفع (الدفع عند الاستلام)' })
    expect(INSPECT_LONG.en).toBe('Inspect before you pay: with cash on delivery, you can open your order and check it in front of the courier before paying.')
    expect(INSPECT_LONG.ar).toBe('المعاينة قبل الدفع: عند الدفع نقدًا عند الاستلام، يمكنك فتح طلبك ومعاينته أمام المندوب قبل أن تدفع.')
    expect(INSPECT_FULL.en).toContain('you pay no delivery fee')
  })
  it('is in the FAQ, the terms and the About page, with the condition, in both languages', () => {
    for (const lang of ['en', 'ar']) {
      expect(JSON.stringify(FAQ[lang])).toContain(INSPECT_FULL[lang])
      expect(blocksText(TRUST_PAGES.terms[lang])).toContain(INSPECT_FULL[lang])
      expect(TRUST_PAGES.about[lang].service).toContain(INSPECT_FULL[lang])
    }
  })
  it('is the trust strip\'s short line, the checkout\'s cash note, and /llms.txt\'s', () => {
    expect(trustSignals.inspect.label).toEqual(INSPECT_SHORT)
    expect(read('../src/PageViews/Checkout/Checkout.jsx')).toContain('INSPECT_LONG')
    expect(read('../app/llms.txt/route.js')).toContain('INSPECT_FULL.en')
  })
})
