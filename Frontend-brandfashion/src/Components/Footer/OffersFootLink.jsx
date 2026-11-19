'use client'
import Link from 'next/link'
import { useHasOffers } from '../../Hooks/queries/useRunningOffers'
import { localizeHref } from '../../utils/localePath'

// Brand Fashion's footer: "Offers" in the Shop column only while an offer runs (2026-11-19, as the old footer did).
// Brand Fashion only, not a twin.
export default function OffersFootLink({ lang = 'en' }) {
  const has = useHasOffers()
  if (!has) return null
  return (
    <li>
      <Link className="wz-sf__link" href={localizeHref('/offers', lang)}>{lang === 'ar' ? 'العروض' : 'Offers'}</Link>
    </li>
  )
}
