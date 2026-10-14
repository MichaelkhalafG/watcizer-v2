'use client'

import { useEffect, useState } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { useAuthStore } from '@/src/Store/authStore'
import { useUIStore, UIStoreProvider } from '@/src/Store/uiStore'

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
// The store no longer needs seeding from the cookie after mount: it STARTS in the request's
// language (S-AR stage 1 — the root layout passes it to <UIStoreProvider>), so the server HTML,
// the hydration pass and the first client render already agree.
function HtmlDirSync() {
  const language = useUIStore((s) => s.language)

  useEffect(() => {
    document.documentElement.dir = language === 'ar' ? 'rtl' : 'ltr'
    document.documentElement.lang = language
  }, [language])
  return null
}

export default function Providers({ language = 'en', children }) {
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
  // here. It reads the lookup tables (useTables), so it must sit BELOW the
  // per-route HydrationBoundary (in the (main) layout) — otherwise it would create
  // pending query observers before hydration and the server render would show
  // skeletons instead of the data.
  return (
    <UIStoreProvider language={language}>
      <QueryClientProvider client={queryClient}>
        <AuthHydrator />
        <HtmlDirSync />
        {children}
      </QueryClientProvider>
    </UIStoreProvider>
  )
}
