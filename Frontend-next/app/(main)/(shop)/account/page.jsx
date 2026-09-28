'use client'
import { Suspense, useEffect } from 'react'
import { useRouter } from 'next/navigation'
import { useAuthStore } from '@/src/Store/authStore'
import Account from '@/src/PageViews/Account/Account'

// Client-side auth guard: unauthenticated visitors are bounced to /login. The
// store is SSR-guarded (isAuthenticated=false on the server), so the prerender
// renders nothing and Account (which reads useSearchParams) only mounts on the
// client once authenticated — wrapped in Suspense per Next's useSearchParams rule.
export default function AccountPage() {
  const router = useRouter()
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated)
  // Wait for the session to be READ before deciding: the store starts logged-out on
  // the client's first render too (no hydration mismatch), so an unguarded check
  // here sent every signed-in shopper to /login.
  const hydrated = useAuthStore((s) => s.hydrated)

  useEffect(() => {
    if (hydrated && !isAuthenticated) router.replace('/login')
  }, [hydrated, isAuthenticated, router])

  if (!hydrated || !isAuthenticated) return null
  return (
    <Suspense fallback={null}>
      <Account />
    </Suspense>
  )
}
