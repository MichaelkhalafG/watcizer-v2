import { Suspense } from 'react'
import Recover from '@/src/PageViews/Cart/Recover'

// /cart/recover?t=… — the "bring my order back" link from the payment-expired e-mail (2026-10-05).
// A personal link: never indexed, never followed.
export const metadata = {
  title: 'Bring your order back | Watchizer',
  robots: { index: false, follow: false },
}

export default function RecoverPage() {
  return (
    <Suspense fallback={null}>
      <Recover />
    </Suspense>
  )
}
