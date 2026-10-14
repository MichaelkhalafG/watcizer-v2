'use client'
import { useMemo } from 'react'
import { useRouter as useNextRouter } from 'next/navigation'
import { useUIStoreApi } from '../Store/uiStore'
import { localizeHref } from '../utils/localePath'

// THE router for internal navigation (D7, 2026-09-30): next/navigation's router, with push, replace and
// prefetch moving their target into the page's language — the same rule as <LocaleLink>. The language
// is read when the call HAPPENS, not when the component rendered. Import `useRouter` from here instead
// of 'next/navigation'. The one exception is the language switch (useSwitchLanguage), whose whole job
// is to cross languages.
export function useRouter() {
  const router = useNextRouter()
  const store = useUIStoreApi()
  return useMemo(() => {
    const here = (href) => localizeHref(href, store.getState().language)
    return {
      ...router,
      push: (href, options) => router.push(here(href), options),
      replace: (href, options) => router.replace(here(href), options),
      prefetch: (href, options) => router.prefetch(here(href), options),
    }
  }, [router, store])
}

export default useRouter
