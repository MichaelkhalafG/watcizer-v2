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

  useEffect(() => {
    if (!isAuthenticated) router.replace('/login')
  }, [isAuthenticated, router])

  if (!isAuthenticated) return null
  return (
    <Suspense fallback={null}>
      <Account />
    </Suspense>
  )
}
