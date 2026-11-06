import { describe, expect, it } from 'vitest'
import { faqFromMarkdown, faqJsonLd , sectionsFromMarkdown, questionsOf } from '../src/lib/pageFaq.js'

// The questions block of a page text → FAQPage structured data (the twelve updates, Part 6.2, 2026-11-05). A TWIN.

const TEXT = `Intro paragraph.

## Good to know

- A bullet that ends with a question mark? It is not a question: it is not a heading.

## Questions and answers

### Are the watches on this page original?
Yes. **Every watch** is original.

### How can I pay?
The methods are shown at checkout. The details are in the [FAQ](/faq).

### A heading that asks nothing
Not a question.

#### هل يمكنني استبدال الساعة؟
نعم، خلال 14 يومًا.
`

describe('the questions in a page text', () => {
  it('takes every sub-heading that ends in a question mark, with the text under it', () => {
    expect(faqFromMarkdown(TEXT)).toEqual([
      { q: 'Are the watches on this page original?', a: 'Yes. Every watch is original.' },
      { q: 'How can I pay?', a: 'The methods are shown at checkout. The details are in the FAQ.' },
      { q: 'هل يمكنني استبدال الساعة؟', a: 'نعم، خلال 14 يومًا.' },
    ])
  })

  it('never makes a question of a section title, a bullet or a question without an answer', () => {
    expect(faqFromMarkdown('## Is this a section?\nText.')).toEqual([])
    expect(faqFromMarkdown('- Is this a bullet?\n- Yes')).toEqual([])
    expect(faqFromMarkdown('### Alone?\n\n### Answered?\nYes.')).toEqual([{ q: 'Answered?', a: 'Yes.' }])
  })

  it('ignores a heading inside a code block', () => {
    expect(faqFromMarkdown('```\n### Code?\nno\n```\n')).toEqual([])
  })

  it('builds FAQPage in the page language, and nothing when the text asks nothing', () => {
    const ld = faqJsonLd(TEXT, 'en')
    expect(ld['@type']).toBe('FAQPage')
    expect(ld.inLanguage).toBe('en')
    expect(ld.mainEntity).toHaveLength(3)
    expect(ld.mainEntity[1]).toEqual({
      '@type': 'Question',
      name: 'How can I pay?',
      acceptedAnswer: { '@type': 'Answer', text: 'The methods are shown at checkout. The details are in the FAQ.' },
    })
    expect(faqJsonLd('Two plain paragraphs.\n\nAs before the rewrite.', 'en')).toBeNull()
    expect(faqJsonLd('', 'ar')).toBeNull()
    expect(faqJsonLd(null, 'ar')).toBeNull()
  })

  it('names no payment method in the answer it tells Google', () => {
    const pay = faqFromMarkdown(TEXT).find((p) => p.q === 'How can I pay?')
    expect(pay.a).not.toMatch(/visa|mastercard|cash|vodafone|valu|wallet/i)
  })
})

// the page-text layout (the review of 2026-11-05): the sections, by what they are
describe('the sections of a page text', () => {
  const text = [
    'Rolex was founded in London in 1905.',
    '',
    '## Rolex at Watchizer',
    '',
    'Watchizer carries Rolex across its families: Datejust, Submariner.',
    '',
    '- **Models listed:** 95.',
    '- **Movement:** Automatic (63), Quartz (28).',
    '- **Price range:** EGP 4,500 to EGP 9,800.',
    '',
    '## Original, with its papers',
    '',
    '- Exchange within 14 days.',
    '',
    '## Questions and answers',
    '',
    '### Are the watches original?',
    'Yes. Every watch comes with its **box**.',
    '',
    '### How fast is delivery?',
    'Within 24 hours.',
  ].join('\n')

  it('names each section by what it is: the lead, the facts sheet, a card, the questions', () => {
    const sections = sectionsFromMarkdown(text)
    expect(sections.map((s) => [s.kind, s.title])).toEqual([
      ['lead', null],
      ['facts', 'Rolex at Watchizer'],
      ['card', 'Original, with its papers'],
      ['faq', 'Questions and answers'],
    ])
    expect(sections[1].body).toContain('**Models listed:** 95.')
  })

  it('keeps a text with no sections as its lead alone, and an empty one as nothing', () => {
    expect(sectionsFromMarkdown('One paragraph.\n\nAnother.')).toEqual([{ kind: 'lead', title: null, body: 'One paragraph.\n\nAnother.' }])
    expect(sectionsFromMarkdown('')).toEqual([])
  })

  it('gives each question with its answer still as Markdown', () => {
    const faq = sectionsFromMarkdown(text).find((s) => s.kind === 'faq')
    expect(questionsOf(faq.body)).toEqual([
      { q: 'Are the watches original?', body: 'Yes. Every watch comes with its **box**.' },
      { q: 'How fast is delivery?', body: 'Within 24 hours.' },
    ])
  })
})
