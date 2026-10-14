'use client'
import { useCallback } from 'react'
import { useRouter } from 'next/navigation'
import { useUIStore } from '../Store/uiStore'
import { barePath, localePath } from '../utils/localePath'

// The language switch (S-AR stage 1): sets the preference (store + wz-lang cookie) and moves to
// the same page in the other language — /x ↔ /ar/x — so the address always names the language on
// screen, and the page's metadata (canonical, hreflang, title) is re-rendered for it.
export function useSwitchLanguage() {
  const setLanguage = useUIStore((s) => s.setLanguage)
  const router = useRouter()

  return useCallback(
    (next) => {
      setLanguage(next)
      const { pathname, search, hash } = window.location
      const target = localePath(barePath(pathname), next) + search + hash
      if (target !== pathname + search + hash) router.push(target)
    },
    [setLanguage, router],
  )
}
