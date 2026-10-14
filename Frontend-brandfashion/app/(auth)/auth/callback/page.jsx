'use client'
import { Suspense } from 'react'
import AuthCallback from '@/src/PageViews/Auth/AuthCallback'

// AuthCallback reads the OAuth token via useSearchParams → wrap in Suspense.
export default function AuthCallbackPage() {
  return (
    <Suspense fallback={null}>
      <AuthCallback />
    </Suspense>
  )
}
