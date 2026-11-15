'use client'
import { useCallback } from 'react'
import { writeLanguageCookie } from '../Store/uiStore'
import { barePath, localePath } from '../utils/localePath'

// The language switch (S-AR stage 1): sets the preference (the wz-lang cookie) and moves to the same page in the
// other language — /x ↔ /ar/x — so the address always names the language on screen, and the page's metadata
// (canonical, hreflang, title) is rendered for it.
//
// A FULL navigation, not a store update + router.push (2026-11-13, the INP pass). Setting the store's language re-rendered
// the whole page in the other language and direction INSIDE the tap — 1.7 s of React work on a 4x-throttled phone, the
// slowest interaction on the shop (2.0-2.9 s to the next paint). The server renders the language of the ADDRESS on the
// first paint (middleware.js; since 2026-11-14 a bare URL is always English), so the tap writes the cookie — which now
// only sends the visitor from the bare home page to /ar next time — and leaves for the same page in the other language.
export function useSwitchLanguage() {
  return useCallback((next) => {
    const lang = next === 'ar' ? 'ar' : 'en'
    writeLanguageCookie(lang)
    const { pathname, search, hash } = window.location
    const target = localePath(barePath(pathname), lang) + search + hash
    if (target !== pathname + search + hash) window.location.assign(target)
    else window.location.reload()
  }, [])
}
