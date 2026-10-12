import { Suspense } from 'react'
import OrderConfirmation from '@/src/PageViews/Checkout/OrderConfirmation'

// Two ways in: the cash checkout pushes here with the order in the store; the payment callback
// sends a card shopper here with `?order=…&status=paid|failed|pending` (2026-10-12). Reading that
// query (useSearchParams) needs a Suspense boundary, the same as /cart/recover.
export default function OrderConfirmationPage() {
  return (
    <Suspense fallback={null}>
      <OrderConfirmation />
    </Suspense>
  )
}
