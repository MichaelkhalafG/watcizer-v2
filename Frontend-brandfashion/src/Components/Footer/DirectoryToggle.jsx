'use client'
import { useState } from 'react'

// The footer directory's open/close on a phone (2026-11-17). A TWIN.
//
// A real button with aria-expanded / aria-controls, shown only under 768 px (SiteDirectory.css); the body is the
// server-rendered links, passed through as children, so they are in the HTML whether it is open or not. From 768 px
// the button is not drawn and the body is always shown, by CSS alone — nothing moves when this hydrates.
export default function DirectoryToggle({ label, bodyId, children }) {
  const [open, setOpen] = useState(false)
  return (
    <>
      <button
        type="button"
        className="wz-dir__toggle"
        aria-expanded={open ? 'true' : 'false'}
        aria-controls={bodyId}
        onClick={() => setOpen((v) => !v)}
      >
        <span>{label}</span>
        <span className="wz-dir__chev" aria-hidden="true" />
      </button>
      <div id={bodyId} className={`wz-dir__body${open ? ' is-open' : ''}`}>
        {children}
      </div>
    </>
  )
}
