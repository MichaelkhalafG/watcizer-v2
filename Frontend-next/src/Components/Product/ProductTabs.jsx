'use client'
import { useRef } from 'react'
import './ProductTabs.css'

// THE PRODUCT'S TABS (2026-11-19) — a TWIN. Description / Specifications / Reviews under the product's info, on every
// width. A real tab set: a tablist of buttons, each controlling its panel; the chosen tab is the only one in the tab
// order, the arrows move between tabs (reversed in Arabic, so "next" is always the reading direction), Home and End go
// to the ends. Every panel is in the HTML from the start (search engines and the review markup read it); the hidden
// ones carry `hidden`. The parent owns which tab is open, so a link (#reviews, the star line) can open one.
//
// tabs: [{ id, label, panel }]  ·  active: an id  ·  onChange(id)  ·  id: the block's anchor (the product page: "reviews")
export default function ProductTabs({ tabs, active, onChange, isRTL = false, id, label }) {
  const refs = useRef({})
  const ids = tabs.map((t) => t.id)
  const focus = (tabId) => {
    onChange(tabId)
    refs.current[tabId]?.focus()
  }
  const onKeyDown = (e) => {
    const i = ids.indexOf(active)
    const next = isRTL ? 'ArrowLeft' : 'ArrowRight'
    const prev = isRTL ? 'ArrowRight' : 'ArrowLeft'
    if (e.key === next) focus(ids[(i + 1) % ids.length])
    else if (e.key === prev) focus(ids[(i - 1 + ids.length) % ids.length])
    else if (e.key === 'Home') focus(ids[0])
    else if (e.key === 'End') focus(ids[ids.length - 1])
    else return
    e.preventDefault()
  }
  return (
    <section className="wz-tabs" id={id} dir={isRTL ? 'rtl' : 'ltr'}>
      <div className="wz-tabs__list" role="tablist" aria-label={label} onKeyDown={onKeyDown}>
        {tabs.map((t) => (
          <button
            key={t.id}
            ref={(el) => {
              refs.current[t.id] = el
            }}
            type="button"
            role="tab"
            id={`wz-tab-${t.id}`}
            aria-controls={`wz-panel-${t.id}`}
            aria-selected={active === t.id}
            tabIndex={active === t.id ? 0 : -1}
            className={`wz-tabs__tab${active === t.id ? ' is-active' : ''}`}
            onClick={() => onChange(t.id)}
          >
            {t.label}
          </button>
        ))}
      </div>
      {tabs.map((t) => (
        <div
          key={t.id}
          role="tabpanel"
          id={`wz-panel-${t.id}`}
          aria-labelledby={`wz-tab-${t.id}`}
          tabIndex={0}
          hidden={active !== t.id}
          className="wz-tabs__panel"
        >
          {t.panel}
        </div>
      ))}
    </section>
  )
}
