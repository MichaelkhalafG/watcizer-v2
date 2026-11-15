'use client'
import { usePathname, useSearchParams } from 'next/navigation'
import { writeLanguageCookie } from '../../Store/uiStore'
import { barePath, localePath } from '../../utils/localePath'

// THE LANGUAGE SWITCH AS A REAL LINK (2026-11-14, SEO Phase 1 item 10). A TWIN. It was a button that navigated in
// script, so a crawler saw no link between a page and its other language in the page itself (hreflang only). Now it is
// an <a href hreflang> to the same page in the other language — /x ↔ /ar/x, the query kept — rendered on the server.
// A click still writes the language cookie (the bare home's redirect reads it) and the browser follows the link: the
// same full navigation the button made with window.location.assign().
export function useLanguageHref(lang) {
  const pathname = usePathname() || '/'
  const search = useSearchParams()?.toString() || ''
  return localePath(barePath(pathname), lang === 'ar' ? 'ar' : 'en') + (search ? `?${search}` : '')
}

export default function LanguageLink({ lang, className = '', onNavigate, children, ...rest }) {
  const href = useLanguageHref(lang)
  return (
    <a
      href={href}
      hrefLang={lang}
      lang={lang}
      className={className}
      onClick={() => {
        writeLanguageCookie(lang === 'ar' ? 'ar' : 'en')
        onNavigate?.()
      }}
      {...rest}
    >
      {children}
    </a>
  )
}
