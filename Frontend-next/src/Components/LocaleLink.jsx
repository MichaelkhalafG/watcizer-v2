'use client'
import NextLink from 'next/link'
import { forwardRef } from 'react'
import { useUIStore } from '../Store/uiStore'
import { localizeHref } from '../utils/localePath'

// THE link for internal pages (D7, 2026-09-30): next/link, with the target moved into the language the
// page is showing — /ar/… on an Arabic page, bare on an English one. Rendered on the server too (the
// store starts in the request's language), so a crawler reading the HTML follows Arabic links from an
// Arabic page. Import this instead of 'next/link'; core's test StorefrontLinksKeepLanguageTest
// fails on a direct next/link import anywhere else.
const LocaleLink = forwardRef(function LocaleLink({ href, ...rest }, ref) {
  const language = useUIStore((s) => s.language)
  return <NextLink ref={ref} href={localizeHref(href, language)} {...rest} />
})

export default LocaleLink
