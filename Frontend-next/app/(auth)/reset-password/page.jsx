'use client'
import { Suspense } from 'react'
import ResetPassword from '@/src/PageViews/Auth/ResetPassword'

// ResetPassword reads the token via useSearchParams → wrap in Suspense per Next's rule.
export default function ResetPasswordPage() {
  return (
    <Suspense fallback={null}>
      <ResetPassword />
    </Suspense>
  )
}
