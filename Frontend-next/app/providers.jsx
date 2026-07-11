'use client'

import { useEffect, useState } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { useAuthStore } from '@/src/Store/authStore'
import { useUIStore } from '@/src/Store/uiStore'

// Re-read the sessionStorage-backed auth AFTER hydration so the server (which
// renders logged-out) and the client (real session) agree, then flip to the real
// session — no hydration mismatch. Renders nothing.
function AuthHydrator() {
  const rehydrate = useAuthStore((s) => s.rehydrate)
  useEffect(() => {
    rehydrate()
  }, [rehydrate])
  return null
}

// Keep <html dir/lang> in sync with the active language. Replaces the effect at
// App.jsx:164-165 in the Vite app. Client-only (touches document). Renders nothing.
//
// Also initialises the store from the wz-lang cookie ONCE on mount: the server
// renders <html lang/dir> from that same cookie (see app/layout.jsx), so seeding
// the client store from it keeps the two in agreement — no hydration mismatch and
// no English flash. The store default is 'en'; if the cookie says 'ar' we flip it.
function HtmlDirSync() {
  const language = useUIStore((s) => s.language)
  const setLanguage = useUIStore((s) => s.setLanguage)

  // Run-once init from the cookie. Reads the current store language via getState()
  // (not the reactive `language` above) so this effect has no changing deps and
  // cannot loop when setLanguage updates the store.
  useEffect(() => {
    const match = document.cookie.match(/(?:^|;\s*)wz-lang=(ar|en)/)
    const cookieLang = match ? match[1] : null
    if (cookieLang && cookieLang !== useUIStore.getState().language) {
      setLanguage(cookieLang)
    }
  }, [setLanguage])

  useEffect(() => {
    document.documentElement.dir = language === 'ar' ? 'rtl' : 'ltr'
    document.documentElement.lang = language
  }, [language])
  return null
}

export default function Providers({ children }) {
  // One QueryClient, created lazily and held in state so it is stable across
  // re-renders (and, on the server, unique per request). Defaults mirror the Vite
  // app's main.jsx: 5-min staleTime, 10-min gcTime.
  const [queryClient] = useState(
    () =>
      new QueryClient({
        defaultOptions: {
          queries: {
            staleTime: 5 * 60 * 1000,
            gcTime: 10 * 60 * 1000,
          },
        },
      }),
  )

  // NOTE: <AppStateBridge/> (the former MyProvider effects) is intentionally NOT
  // here. It reads the catalog (useTables/useProducts), so it must sit BELOW the
  // per-route HydrationBoundary (in the (main) layout) — otherwise it would create
  // pending query observers before hydration and the server render would show
  // skeletons instead of the data.
  return (
    <QueryClientProvider client={queryClient}>
      <AuthHydrator />
      <HtmlDirSync />
      {children}
    </QueryClientProvider>
  )
}
