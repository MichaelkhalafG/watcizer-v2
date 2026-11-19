'use client'
import { useState } from 'react'

// One column of the footer (2026-11-19). A TWIN.
//
// On a computer: a plain heading over its links, always open. On a phone (under 768 px): a closed section that opens on
// a tap — a real button with aria-expanded / aria-controls. Both the heading and the button are in the HTML and CSS
// shows one (SiteFooter.css), and the links are in the HTML whether the section is open or not: nothing moves when
// this hydrates, and a crawler reads every link.
export default function FootSection({ id, title, children }) {
  const [open, setOpen] = useState(false)
  return (
    <section className="wz-sf__sec" aria-labelledby={`${id}-title`}>
      <h2 className="wz-sf__title" id={`${id}-title`}>
        <span className="wz-sf__title-text">{title}</span>
        <button type="button" className="wz-sf__toggle" aria-expanded={open ? 'true' : 'false'} aria-controls={`${id}-body`} onClick={() => setOpen((v) => !v)}>
          <span>{title}</span>
          <span className="wz-sf__chev" aria-hidden="true" />
        </button>
      </h2>
      <div id={`${id}-body`} className={`wz-sf__body${open ? ' is-open' : ''}`}>
        {children}
      </div>
    </section>
  )
}
