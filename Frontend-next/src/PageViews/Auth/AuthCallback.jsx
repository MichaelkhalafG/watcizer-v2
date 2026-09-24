'use client'
import { useEffect, useRef, useState } from 'react'
import { useRouter, useSearchParams } from 'next/navigation'
import http from '../../Context/api'
import { useAuthStore } from '../../Store/authStore'
import { useUIStore } from '../../Store/uiStore'
import { consumeNonce } from '../../lib/socialNonce'
import './auth.css'

// Lands here after a social provider redirect:
//   /auth/callback?token=…&nonce=…   (or ?error=…&nonce=…)
// Persists the JWT, fetches the user via /auth/me, then sends them home.
//
// THE NONCE IS CHECKED FIRST, before the token is stored or used. It is the nonce
// this tab generated in SocialButtons, echoed through the provider as the OAuth
// `state` and handed back by core. Without that check, a token from a sign-in
// somebody ELSE started would be accepted here — which is what the flow did until
// 2026-09-22, and it signed shoppers into an attacker's account. See
// src/lib/socialNonce.js.
export default function AuthCallback() {
  const params = useSearchParams()
  const router = useRouter()
  const login = useAuthStore((s) => s.login)
  const { language } = useUIStore()
  const isRTL = language === 'ar'
  const [failed, setFailed] = useState(false)
  const ran = useRef(false)

  useEffect(() => {
    if (ran.current) return
    ran.current = true

    const token = params.get('token')
    const error = params.get('error')

    /*
     * The nonce, before anything else — and consumed either way, so one nonce is
     * good for exactly one attempt.
     *
     * A mismatch means this callback belongs to a sign-in this tab did not start.
     * It is treated exactly like a failure: nothing is stored, nothing is
     * fetched, and the shopper goes back to the login page. Deliberately NOT
     * given its own message — the person seeing it is either a victim of
     * something they cannot act on, or somebody reloading a stale callback URL,
     * and neither is helped by being told which.
     */
    if (!consumeNonce(params.get('nonce'))) {
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setFailed(true)
      setTimeout(() => router.replace('/login'), 1500)
      return
    }

    if (error || !token) {
      // One-time OAuth-callback failure handling — intentional. The rule's report now lands on
      // the nonce check above, which carries the disable directive, so this one would be unused.
      setFailed(true)
      setTimeout(() => router.replace('/login'), 1500)
      return
    }

    // Persist the token so the interceptor authenticates the /auth/me call.
    sessionStorage.setItem('token', token)
    http
      .get('/auth/me')
      .then(({ data }) => {
        login({ ...data, token })
        router.replace('/')
      })
      .catch(() => {
        sessionStorage.removeItem('token')
        setFailed(true)
        setTimeout(() => router.replace('/login'), 1500)
      })
  }, [params, router, login])

  return (
    <div className="wz-auth-callback" dir={isRTL ? 'rtl' : 'ltr'}>
      {failed ? (
        <p>{isRTL ? 'تعذّر تسجيل الدخول. جارٍ إعادة التوجيه...' : 'Sign-in failed. Redirecting…'}</p>
      ) : (
        <>
          <span className="wz-auth-spinner wz-auth-spinner--dark" />
          <p>{isRTL ? 'جارٍ تسجيل الدخول...' : 'Signing you in…'}</p>
        </>
      )}
    </div>
  )
}
