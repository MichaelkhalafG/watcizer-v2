import { describe, expect, it } from 'vitest'
import { createElement as h } from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import Disclosure from '../src/Components/UI/Disclosure'

// The shared collapsible section (2026-10-22), rendered as the server renders it: a real button in the heading
// that says which way it is and what it controls, and the content IN the HTML whether open or closed.
const render = (props) => renderToStaticMarkup(h(Disclosure, { id: 'wz-pd-desc', label: 'Description', ...props }, h('p', null, 'The long description.')))

describe('Disclosure', () => {
  it('closed: a button with aria-expanded=false and aria-controls, the text still served', () => {
    const html = render({ open: false, as: 'h2', className: 'wz-pd-section', headClassName: 'wz-pd-section-head' })
    expect(html).toContain('<button type="button" id="wz-pd-desc-head" class="wz-disc__head wz-pd-section-head" aria-expanded="false" aria-controls="wz-pd-desc-body">')
    expect(html).toContain('<h2 class="wz-disc__heading">')
    expect(html).toContain('<section class="wz-disc wz-pd-section" aria-labelledby="wz-pd-desc-head">')
    expect(html).toContain('<div id="wz-pd-desc-body" class="wz-disc__body">')
    expect(html).toContain('The long description.')
    expect(html).not.toContain('is-open')
  })

  it('open: is-open on the section and aria-expanded=true', () => {
    const html = render({ open: true })
    expect(html).toContain('class="wz-disc is-open"')
    expect(html).toContain('aria-expanded="true"')
    expect(html).toContain('<div class="wz-disc__heading">')
  })

  it('the chevron is decoration, the label is text', () => {
    const html = render({ open: false })
    expect(html).toContain('<span class="wz-disc__icon" aria-hidden="true"></span>')
    expect(html).toContain('<span class="wz-disc__label">Description</span>')
  })
})
