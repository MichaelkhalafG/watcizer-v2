import Checkout from '@/src/PageViews/Checkout/Checkout'
import { getServerV2Pickup } from '@/src/lib/serverV2'

/*
 * The checkout. A SERVER page since click & collect (2026-11-14): it reads whether this shop offers "pick up in store"
 * (held 30 s, never the page's failure) and the cart's "pick up in store instead" (?fulfilment=pickup), so the choice
 * is in the first paint instead of arriving later and pushing the form down. The page itself is the client Checkout.
 */
export default async function CheckoutPage({ searchParams }) {
  const query = (await searchParams) || {}
  const pickup = await getServerV2Pickup().then((body) => body || null, () => null)
  return <Checkout initialPickup={pickup} initialFulfilment={query.fulfilment === 'pickup' ? 'pickup' : 'delivery'} />
}
