import { useQuery } from '@tanstack/react-query'
import publicHttp from '../../Context/publicApi'
import { STOREFRONT_CODE } from '../../lib/env'

// The social sign-in providers core can run for THIS storefront — v2 `auth/providers` (L3, 2026-10-19). Brand
// Fashion draws its Google button, and its privacy page names Google sign-in, only while Google is listed: until its
// own Google project is set up in core's .env the list is empty, and the login page offers e-mail and password alone
// rather than a button that fails. Credentials set on the server show on the next read (no rebuild).
// `enabled: false` skips the read (the trust pages other than privacy).
export const useSocialProviders = ({ enabled = true } = {}) =>
  useQuery({
    queryKey: ['v2-auth-providers', STOREFRONT_CODE],
    queryFn: async () => {
      const { data } = await publicHttp.get(`v2/${STOREFRONT_CODE}/auth/providers`)
      return Array.isArray(data?.providers) ? data.providers : []
    },
    enabled,
    staleTime: 60 * 1000,
    retry: 1,
  }).data ?? []

/** True only once core has said this storefront runs Google sign-in; false while loading and on an error. */
export const useGoogleSignIn = (opts) => useSocialProviders(opts).includes('google')
